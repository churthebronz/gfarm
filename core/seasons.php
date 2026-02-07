<?php
declare(strict_types=1);
// Seed Phases + Active Cap system.
// Safe to include multiple times.

/**
 * Defaults:
 * - Phases are 21 days
 * - Caps are per-season and count ACTIVE vaults only
 * - Existing vault timers NEVER reset at season rollover
 */

if (!function_exists('vx__index_exists')) {
  function vx__index_exists($db, string $table, string $index): bool {
    try {
      // MariaDB/MySQL: INFORMATION_SCHEMA.STATISTICS is reliable and works with prepared statements.
      $sql = "SELECT 1
              FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND index_name = ?
              LIMIT 1";
      $row = $db->query($sql, $table, $index)->fetchArray();
      return !empty($row);
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('vx__safe_add_index')) {
  function vx__safe_add_index($db, string $table, string $index, string $ddl): void {
    try {
      if (!vx__index_exists($db, $table, $index)) {
        $db->query($ddl);
      }
    } catch (Throwable $e) { /* ignore */ }
  }
}

if (!function_exists('vx_seasons_ensure')) {
  function vx_seasons_ensure($db): void {
    try {
      // Ensure schema helpers exist (vx_table_exists + vx_column_exists)
      if (!function_exists('vx_table_exists') || !function_exists('vx_column_exists')) {
        @require_once __DIR__ . '/schema_helpers.php';
      }

      // Phases table
      if (function_exists('vx_table_exists') && !vx_table_exists($db, 'vx_seasons')) {
        $db->exec('CREATE TABLE IF NOT EXISTS vx_seasons (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          season_no INT NOT NULL,
          starts_at INT NOT NULL,
          ends_at INT NOT NULL,
          created_at INT NOT NULL,
          is_locked TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // indexes (safe)
        vx__safe_add_index($db, 'vx_seasons', 'vx_seasons_no_uq', 'CREATE UNIQUE INDEX vx_seasons_no_uq ON vx_seasons (season_no)');
        vx__safe_add_index($db, 'vx_seasons', 'vx_seasons_window_ix', 'CREATE INDEX vx_seasons_window_ix ON vx_seasons (starts_at, ends_at)');
      } else {
        // In case table exists but indexes were never created
        vx__safe_add_index($db, 'vx_seasons', 'vx_seasons_no_uq', 'CREATE UNIQUE INDEX vx_seasons_no_uq ON vx_seasons (season_no)');
        vx__safe_add_index($db, 'vx_seasons', 'vx_seasons_window_ix', 'CREATE INDEX vx_seasons_window_ix ON vx_seasons (starts_at, ends_at)');
      }

      
      // Ensure vx_seasons.is_locked exists
      if (function_exists('vx_column_exists') && !vx_column_exists($db, 'vx_seasons', 'is_locked')) {
        try { $db->exec("ALTER TABLE vx_seasons ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
      }
      // Index for lock
      vx__safe_add_index($db, 'vx_seasons', 'vx_seasons_locked_ix', 'CREATE INDEX vx_seasons_locked_ix ON vx_seasons (is_locked)');

// Caps table
      if (function_exists('vx_table_exists') && !vx_table_exists($db, 'vx_season_caps')) {
        $db->exec('CREATE TABLE IF NOT EXISTS vx_season_caps (
          season_id INT NOT NULL,
          tarif_id INT NOT NULL,
          cap INT NOT NULL DEFAULT 0,
          created_at INT NOT NULL,
          is_locked TINYINT(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (season_id, tarif_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        vx__safe_add_index($db, 'vx_season_caps', 'vx_caps_tarif_ix', 'CREATE INDEX vx_caps_tarif_ix ON vx_season_caps (tarif_id)');
      } else {
        vx__safe_add_index($db, 'vx_season_caps', 'vx_caps_tarif_ix', 'CREATE INDEX vx_caps_tarif_ix ON vx_season_caps (tarif_id)');
      }

      // db_store.season_id
      if (function_exists('vx_column_exists') && !vx_column_exists($db, 'db_store', 'season_id')) {
        $db->exec('ALTER TABLE db_store ADD COLUMN season_id INT NULL DEFAULT NULL');
      }
      // index for store season usage
      vx__safe_add_index($db, 'db_store', 'db_store_season_ix', 'CREATE INDEX db_store_season_ix ON db_store (season_id, tarif, status)');

    } catch (Throwable $e) {
      // Best-effort; don't hard-fail pages.
    }
  }
}

if (!function_exists('vx_season_duration_days')) {
  function vx_season_duration_days(): int {
    return 21;
  }
}

if (!function_exists('vx_seed_caps_for_season')) {
  function vx_seed_caps_for_season($db, int $seasonId, int $defaultTopCap = 4): void {
    try {
      $plans = $db->query('SELECT id, price, title FROM db_tarif ORDER BY id ASC')->fetchAll();
      if (!$plans) return;
      $now = time();

      // Identify the "top" plan: highest price (fall back to highest id).
      $topTarifId = 0;
      $topPrice = -1;
      foreach ($plans as $pp) {
        $tid = (int)($pp['id'] ?? 0);
        $pr = (float)($pp['price'] ?? 0);
        if ($tid <= 0) continue;
        if ($pr > $topPrice || ($pr === $topPrice && $tid > $topTarifId)) {
          $topPrice = $pr;
          $topTarifId = $tid;
        }
      }

      foreach ($plans as $p) {
        $tarifId = (int)($p['id'] ?? 0);
        if ($tarifId <= 0) continue;

        $exists = $db->query(
          'SELECT cap FROM vx_season_caps WHERE season_id = ? AND tarif_id = ? LIMIT 1',
          $seasonId, $tarifId
        )->fetchArray();

        if ($exists) continue;

        $price = (float)($p['price'] ?? 0);
        $title = strtolower((string)($p['title'] ?? ''));

        // cap = 0 means unlimited
        $cap = 0;

      // Always cap the single "top" plan to defaultTopCap (eg, 4 slots) so the launch checklist is satisfied.
        if ($topTarifId > 0 && $tarifId === $topTarifId) {
          $cap = $defaultTopCap;
        } elseif (strpos($title, 'sovereign') !== false || strpos($title, 'omega') !== false) {
          $cap = $defaultTopCap;
        } elseif (strpos($title, 'empire') !== false || strpos($title, 'titan') !== false) {
          $cap = 200;
        } elseif ($price <= 10) {
          $cap = 0;
        } elseif ($price <= 50) {
          $cap = 1000;
        } elseif ($price <= 200) {
          $cap = 500;
        } elseif ($price <= 1000) {
          $cap = 200;
        } elseif ($price >= 8000) {
          $cap = 4;
        } else {
          $cap = 100;
        }

        $db->query(
          'INSERT INTO vx_season_caps (season_id, tarif_id, cap, created_at) VALUES (?, ?, ?, ?)',
          $seasonId, $tarifId, (int)$cap, $now
        );
      }
    } catch (Throwable $e) {
      // ignore
    }
  }
}

if (!function_exists('vx_get_current_season')) {
  function vx_get_current_season($db): array {
    vx_seasons_ensure($db);
    $now = time();

    try {
      $row = $db->query(
        'SELECT * FROM vx_seasons WHERE starts_at <= ? AND ends_at > ? ORDER BY id DESC LIMIT 1',
        $now, $now
      )->fetchArray();

      if ($row) {
        return [
          'ok' => true,
          'id' => (int)$row['id'],
          'season_no' => (int)$row['season_no'],
          'starts_at' => (int)$row['starts_at'],
          'ends_at' => (int)$row['ends_at'],
          'is_locked' => (int)($row['is_locked'] ?? 0),
          'duration_days' => vx_season_duration_days(),
        ];
      }

      // Create first/next season
      $last = $db->query('SELECT season_no, ends_at FROM vx_seasons ORDER BY id DESC LIMIT 1')->fetchArray();
      $nextNo = (int)($last['season_no'] ?? 0) + 1;

      $start = $now;
      if ($last && isset($last['ends_at'])) {
        $le = (int)$last['ends_at'];
        $start = ($le > $now) ? $le : $now;
      }

      $end = $start + (vx_season_duration_days() * 86400);

      $db->query(
        'INSERT INTO vx_seasons (season_no, starts_at, ends_at, created_at, is_locked) VALUES (?, ?, ?, ?, 0)',
        $nextNo, $start, $end, $now
      );

      $sidRow = $db->query('SELECT LAST_INSERT_ID() AS id')->fetchArray();
      $sid = (int)($sidRow['id'] ?? 0);

      if ($sid > 0) {
        vx_seed_caps_for_season($db, $sid, 4);
      }

      return [
        'ok' => true,
        'id' => $sid,
        'season_no' => $nextNo,
        'starts_at' => $start,
        'ends_at' => $end,
        'is_locked' => 0,
        'duration_days' => vx_season_duration_days(),
      ];
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => 'season_init_failed'];
    }
  }
}

function vx_season_is_locked($db, int $seasonId): bool {
    if ($seasonId <= 0) return false;
    try {
      $r = $db->query('SELECT is_locked FROM vx_seasons WHERE id=? LIMIT 1', $seasonId)->fetchArray();
      return $r && (int)($r['is_locked'] ?? 0) === 1;
    } catch (Throwable $e) {
      return false;
    }
}


if (!function_exists('vx_get_caps_for_season')) {
  function vx_get_caps_for_season($db, int $seasonId): array {
    try {
      $rows = $db->query('SELECT tarif_id, cap FROM vx_season_caps WHERE season_id = ?', $seasonId)->fetchAll();
      $out = [];
      foreach ($rows as $r) {
        $out[(int)$r['tarif_id']] = (int)$r['cap'];
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('vx_get_usage_for_season')) {
  function vx_get_usage_for_season($db, int $seasonId): array {
    try {
      $now = time();
      $rows = $db->query(
        'SELECT tarif, COUNT(*) AS c
           FROM db_store
          WHERE season_id = ?
            AND status = 1
            AND `end` > ?
          GROUP BY tarif',
        $seasonId, $now
      )->fetchAll();

      $out = [];
      foreach ($rows as $r) {
        $out[(int)$r['tarif']] = (int)$r['c'];
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('vx_check_season_cap')) {
  function vx_check_season_cap($db, int $seasonId, int $tarifId): array {
    $caps = vx_get_caps_for_season($db, $seasonId);
    $usage = vx_get_usage_for_season($db, $seasonId);

    $cap = (int)($caps[$tarifId] ?? 0);
    $used = (int)($usage[$tarifId] ?? 0);

    $remaining = ($cap > 0) ? max(0, $cap - $used) : 999999999;
    $ok = ($cap <= 0) ? true : ($used < $cap);

    return ['ok' => $ok, 'cap' => $cap, 'used' => $used, 'remaining' => $remaining];
  }
}

/* ===============================
   Extra helpers for UI widgets
================================ */

if (!function_exists('vx_season_progress_pct')) {
  function vx_season_progress_pct(array $season): int {
    $now = time();
    $s = (int)($season['starts_at'] ?? 0);
    $e = (int)($season['ends_at'] ?? 0);
    if ($s <= 0 || $e <= 0 || $e <= $s) return 0;
    $pct = (int)floor((max($s, min($now, $e)) - $s) / max(1, ($e - $s)) * 100);
    return max(0, min(100, $pct));
  }
}

if (!function_exists('vx_plans_with_season_meta')) {
  function vx_plans_with_season_meta($db, int $seasonId, int $limit = 50): array {
    try {
      $plans = $db->query('SELECT id, title, price, speed, period FROM db_tarif ORDER BY id ASC LIMIT ' . (int)$limit)->fetchAll();
      if (!$plans) return [];

      $caps = vx_get_caps_for_season($db, $seasonId);
      $usage = vx_get_usage_for_season($db, $seasonId);

      $out = [];
      foreach ($plans as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) continue;

        $cap = (int)($caps[$id] ?? 0);
        $used = (int)($usage[$id] ?? 0);
        $remaining = ($cap > 0) ? max(0, $cap - $used) : 999999999;
        $locked = ($cap > 0) ? ($used >= $cap) : false;
        $ratio = ($cap > 0) ? ($used / max(1, $cap)) : 0.0;

        $out[] = [
          'id' => $id,
          'title' => (string)($p['title'] ?? ('Seed ' . $id)),
          'price' => (float)($p['price'] ?? 0),
          'speed' => (float)($p['speed'] ?? 0),
          'period' => (int)($p['period'] ?? 0),
          'cap' => $cap,
          'used' => $used,
          'remaining' => $remaining,
          'locked' => $locked,
          'ratio' => $ratio,
        ];
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('vx_user_season_counts')) {
  function vx_user_season_counts($db, int $seasonId, int $uid): array {
    try {
      $now = time();
      $active = $db->query(
        'SELECT COUNT(*) AS c FROM db_store WHERE uid = ? AND season_id = ? AND status = 1 AND `end` > ?',
        $uid, $seasonId, $now
      )->fetchArray();

      $completed = $db->query(
        'SELECT COUNT(*) AS c FROM db_store WHERE uid = ? AND season_id = ? AND (`end` <= ? OR status <> 1)',
        $uid, $seasonId, $now
      )->fetchArray();

      $spent = $db->query(
        'SELECT COALESCE(SUM(t.price),0) AS s
           FROM db_store s
           JOIN db_tarif t ON t.id = s.tarif
          WHERE s.uid = ? AND s.season_id = ?',
        $uid, $seasonId
      )->fetchArray();

      return [
        'active' => (int)($active['c'] ?? 0),
        'completed' => (int)($completed['c'] ?? 0),
        'spent' => (float)($spent['s'] ?? 0),
      ];
    } catch (Throwable $e) {
      return ['active' => 0, 'completed' => 0, 'spent' => 0.0];
    }
  }
}

if (!function_exists('vx_weekly_ref_leaderboard')) {
  function vx_weekly_ref_leaderboard($db, int $limit = 10, ?int $sinceTs = null): array {
    try {
      if (!function_exists('vx_refearn_schema')) {
        @require_once __DIR__ . '/schema_helpers.php';
      }
      $cols = function_exists('vx_refearn_schema')
        ? vx_refearn_schema($db)
        : ['ref_col' => 'ref_uid', 'user_col' => 'user_uid', 'usd_col' => 'usd', 'rate_col' => 'rate', 'reward_col' => null];

      if (empty($cols['ref_col']) || empty($cols['usd_col'])) return [];

      $since = $sinceTs ?? (time() - 7 * 86400);
      $refCol = (string)$cols['ref_col'];
      $userCol = (string)($cols['user_col'] ?: 'user_uid');
      $usdCol = (string)$cols['usd_col'];

      $rows = $db->query(
        "SELECT e.{$refCol} AS uid,
                COALESCE(SUM(e.{$usdCol}),0) AS usd,
                COUNT(DISTINCT e.{$userCol}) AS refs
           FROM db_ref_earn e
          WHERE e.created_at >= ?
          GROUP BY e.{$refCol}
          ORDER BY usd DESC
          LIMIT " . (int)$limit,
        $since
      )->fetchAll();

      if (!$rows) return [];

      $out = [];
      foreach ($rows as $r) {
        $rid = (int)($r['uid'] ?? 0);
        if ($rid <= 0) continue;

        $u = $db->query('SELECT tg_username, tg_name, login FROM db_users WHERE id = ? LIMIT 1', $rid)->fetchArray();
        $name = '';

        // ✅ FIXED: correct ltrim usage (this was your 500)
        if (!empty($u['tg_username'])) $name = '@' . ltrim((string)($u['tg_username'] ?? ''), '@');
        elseif (!empty($u['tg_name'])) $name = (string)$u['tg_name'];
        else $name = (string)($u['login'] ?? ('User ' . $rid));

        $out[] = [
          'uid' => $rid,
          'name' => $name,
          'usd' => (float)($r['usd'] ?? 0),
          'refs' => (int)($r['refs'] ?? 0),
        ];
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('vx_weekly_ref_rank')) {
  function vx_weekly_ref_rank($db, int $uid, ?int $sinceTs = null): array {
    $since = $sinceTs ?? (time() - 7 * 86400);
    $lb = vx_weekly_ref_leaderboard($db, 50, $since);
    $rank = 0; $usd = 0.0;
    foreach ($lb as $i => $row) {
      if ((int)($row['uid'] ?? 0) === $uid) { $rank = $i + 1; $usd = (float)($row['usd'] ?? 0); break; }
    }
    return ['rank' => $rank, 'usd' => $usd, 'since' => $since];
  }
}

if (!function_exists('vx_season_stats_row')) {
  function vx_season_stats_row($db, int $seasonId, int $startsAt, int $endsAt): array {
    try {
      if (!function_exists('vx_table_exists')) {
        @require_once __DIR__ . '/schema_helpers.php';
      }

      $now = time();
      $tot = $db->query('SELECT COUNT(*) AS c FROM db_store WHERE season_id = ?', $seasonId)->fetchArray();
      $active = $db->query('SELECT COUNT(*) AS c FROM db_store WHERE season_id = ? AND status = 1 AND `end` > ?', $seasonId, $now)->fetchArray();
      $completed = $db->query('SELECT COUNT(*) AS c FROM db_store WHERE season_id = ? AND (`end` <= ? OR status <> 1)', $seasonId, $now)->fetchArray();
      $vol = $db->query(
        'SELECT COALESCE(SUM(t.price),0) AS s
           FROM db_store s
           JOIN db_tarif t ON t.id = s.tarif
          WHERE s.season_id = ?',
        $seasonId
      )->fetchArray();

      $topUid = 0; $topUsd = 0.0;
      if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_ref_earn')) {
        $top = $db->query(
          'SELECT ref_uid AS uid, COALESCE(SUM(usd),0) AS usd
             FROM db_ref_earn
            WHERE created_at >= ? AND created_at < ?
            GROUP BY ref_uid
            ORDER BY usd DESC
            LIMIT 1',
          $startsAt, $endsAt
        )->fetchArray() ?: [];
        $topUid = (int)($top['uid'] ?? 0);
        $topUsd = (float)($top['usd'] ?? 0);
      }

      return [
        'total' => (int)($tot['c'] ?? 0),
        'active' => (int)($active['c'] ?? 0),
        'completed' => (int)($completed['c'] ?? 0),
        'volume' => (float)($vol['s'] ?? 0),
        'top_ref_uid' => $topUid,
        'top_ref_usd' => $topUsd,
      ];
    } catch (Throwable $e) {
      return ['total' => 0, 'active' => 0, 'completed' => 0, 'volume' => 0.0, 'top_ref_uid' => 0, 'top_ref_usd' => 0.0];
    }
  }
}

/* ===============================
   Compatibility wrapper
   - Some pages call vx_current_season()
================================ */
if (!function_exists('vx_current_season')) {
  function vx_current_season($db): array {
    $s = vx_get_current_season($db);
    if (!is_array($s) || empty($s['ok'])) return $s;

    $now = time();
    $s['starts_in'] = max(0, (int)($s['starts_at'] ?? 0) - $now);
    $s['ends_in']   = max(0, (int)($s['ends_at'] ?? 0) - $now);
    $s['progress_pct'] = function_exists('vx_season_progress_pct') ? vx_season_progress_pct($s) : 0;

    return $s;
  }
}


/**
 * Cap check with optional Season Pass buffer (extra 5% slots).
 * Returns same shape as vx_check_season_cap().
 */
function vx_check_season_cap_buffered($db, int $seasonId, int $tarifId, bool $hasPass): array {
  $info = vx_check_season_cap($db, $seasonId, $tarifId);

  $info['cap_effective'] = (int)($info['cap'] ?? 0);
  $info['pass_buffer'] = false;

  if (!$hasPass) return $info;

  $cap = (int)($info['cap'] ?? 0);
  $used = (int)($info['used'] ?? 0);
  if ($cap <= 0) return $info;

  $pct = 0.05;
  if (function_exists('vx_season_pass_cap_buffer_pct')) {
    try { $pct = (float)vx_season_pass_cap_buffer_pct(); } catch (Throwable $e) {}
  }

  $buffer = (int)ceil($cap * max(0.0, $pct));
  $effectiveCap = $cap + max(1, $buffer);

  $info['cap_effective'] = $effectiveCap;
  $info['remaining'] = max(0, $effectiveCap - $used);
  $info['ok'] = ($used < $effectiveCap);
  $info['pass_buffer'] = true;

  return $info;
}
