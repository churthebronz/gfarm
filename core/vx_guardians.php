<?php
// File: /core/vx_guardians.php
// GreenFarm — Guardian Chains (Crossbreed + VP/LP accrual + Mutant Index)
//
// We avoid invasive changes to legacy tables by introducing a new table
// `vx_guardian_chains` keyed by a "chain" (one guardian across crossbreeds).
// Each activation (purchase) creates a db_store row; we link it to a chain.
//
// Responsibilities:
// - Create/update chains on purchase (crossbreed detection)
// - Track maturity -> crossbreed window (24h) -> codex
// - Accrue VP daily while active (scaled by crossbreed)
// - Accrue LP daily while active if crossbreed_level>=1
// - Aggregate totals for Mutant Index display

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/schema_helpers.php';
require_once __DIR__ . '/vx_app_settings.php';
require_once __DIR__ . '/vx_rarity.php';
require_once __DIR__ . '/vx_points.php';
require_once __DIR__ . '/vx_lp.php';
require_once __DIR__ . "/seasons.php";
require_once __DIR__ . '/vx_guardian_art.php';

/**
 * Max evolve source of truth: db_tarif.max_evolve (merged plans=guardians).
 * Fallback: app setting crossbreed_max, then 5.
 */
function vx_tarif_max_evolve($db, int $tarifId, int $fallback = 5): int {
  $fallback = $fallback > 0 ? $fallback : 5;
  try {
    $row = $db->query("SELECT max_evolve FROM db_tarif WHERE id = ? LIMIT 1", $tarifId)->fetchArray();
    $v = (int)($row['max_evolve'] ?? 0);
    if ($v > 0) return $v;
  } catch (Throwable $e) {}
  return $fallback;
}


function vx_guardians_schema_ensure($db): void {
  if (!$db) return;
  try { if (function_exists('vx_guardian_art_schema_ensure')) vx_guardian_art_schema_ensure($db); } catch (Throwable $e) {}
  try {
    $db->query("CREATE TABLE IF NOT EXISTS vault_definitions (
      id INT NOT NULL,
      name VARCHAR(190) NULL,
      rarity VARCHAR(24) NOT NULL DEFAULT 'common',
      vp_per_day INT NOT NULL DEFAULT 0,
      lp_per_day INT NOT NULL DEFAULT 0,
      updated_at INT NOT NULL DEFAULT 0,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}

  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_chains (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      uid INT NOT NULL,
      tarif INT NOT NULL,
      root_store_id INT NOT NULL,
      current_store_id INT NOT NULL,
      kind VARCHAR(24) NOT NULL DEFAULT 'plan',
      title_override VARCHAR(190) NULL,
      vp_per_day_override INT NOT NULL DEFAULT 0,
      lp_per_day_override INT NOT NULL DEFAULT 0,
      season_id INT NOT NULL DEFAULT 0,
      rarity VARCHAR(24) NOT NULL DEFAULT 'common',
      crossbreed_level TINYINT NOT NULL DEFAULT 0,
      max_evolve TINYINT NOT NULL DEFAULT 5,
      shiny TINYINT NOT NULL DEFAULT 0,
      status VARCHAR(16) NOT NULL DEFAULT 'active',
      term_end INT NOT NULL DEFAULT 0,
      matured_at INT NOT NULL DEFAULT 0,
      crossbreed_deadline INT NOT NULL DEFAULT 0,
      vp_total BIGINT NOT NULL DEFAULT 0,
      lp_total BIGINT NOT NULL DEFAULT 0,
      vp_frac DECIMAL(18,6) NOT NULL DEFAULT 0,
      lp_frac DECIMAL(18,6) NOT NULL DEFAULT 0,
      points_last_at INT NOT NULL DEFAULT 0,
      codex_reason VARCHAR(24) NULL,
      codex_at INT NOT NULL DEFAULT 0,
      created_at INT NOT NULL,
      updated_at INT NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_root (root_store_id),
      KEY ix_uid_status (uid, status),
      KEY ix_uid_kind (uid, kind),
      KEY ix_uid_tarif (uid, tarif),
      KEY ix_deadline (crossbreed_deadline)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}

  // Best-effort: migrate older installs to include special guardian fields.
  // Safe to run on SQLite or MySQL/MariaDB.
  try {
    if (function_exists('vx_column_exists')) {
      if (!vx_column_exists($db, 'vx_guardian_chains', 'kind')) {
        $db->query("ALTER TABLE vx_guardian_chains ADD COLUMN kind VARCHAR(24) NOT NULL DEFAULT 'plan'");
      }
      if (!vx_column_exists($db, 'vx_guardian_chains', 'title_override')) {
        $db->query("ALTER TABLE vx_guardian_chains ADD COLUMN title_override VARCHAR(190) NULL");
      }
      if (!vx_column_exists($db, 'vx_guardian_chains', 'vp_per_day_override')) {
        $db->query("ALTER TABLE vx_guardian_chains ADD COLUMN vp_per_day_override INT NOT NULL DEFAULT 0");
      }
      if (!vx_column_exists($db, 'vx_guardian_chains', 'lp_per_day_override')) {
        $db->query("ALTER TABLE vx_guardian_chains ADD COLUMN lp_per_day_override INT NOT NULL DEFAULT 0");
      }
      if (!vx_column_exists($db, 'vx_guardian_chains', 'max_evolve')) {
        $db->query("ALTER TABLE vx_guardian_chains ADD COLUMN max_evolve TINYINT NOT NULL DEFAULT 5");
      }
      if (!vx_column_exists($db, 'vx_guardian_chains', 'shiny')) {
        $db->query("ALTER TABLE vx_guardian_chains ADD COLUMN shiny TINYINT NOT NULL DEFAULT 0");
      }
    }
  } catch (Throwable $e) {}

  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_lp_ledger (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      uid INT NOT NULL,
      delta INT NOT NULL,
      ctx VARCHAR(48) NOT NULL,
      meta_json MEDIUMTEXT NULL,
      created_at INT NOT NULL,
      PRIMARY KEY (id),
      KEY ix_uid_time (uid, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}


  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_chain_seasons (
      chain_id BIGINT UNSIGNED NOT NULL,
      season_id INT NOT NULL,
      vp_total BIGINT NOT NULL DEFAULT 0,
      lp_total BIGINT NOT NULL DEFAULT 0,
      updated_at INT NOT NULL DEFAULT 0,
      PRIMARY KEY (chain_id, season_id),
      KEY ix_season (season_id, vp_total, lp_total)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}

}


function vx_guardian_settings(): array {
  $d = [
    'crossbreed_max' => 5,
    'crossbreed_window_sec' => 86400,
    'rarity_mult' => [
      'common' => 1.0,
      'rare' => 1.5,
      'epic' => 2.25,
      'legendary' => 3.5,
      'mythic' => 5.0,
    ],
    // Multipliers apply to vp_per_day / lp_per_day
    'vp_crossbreed_mult' => [1.0, 1.15, 1.35, 1.60, 1.90, 2.25],
    // LP is more aggressive (crossbreed 1 starts at 1.0)
    'lp_crossbreed_mult' => [0.0, 1.0, 1.55, 2.30, 3.30, 4.80],
    // Fallback rates if vault_definitions not set
    'vp_fallback_per_usd_per_day' => 0.25,
    'lp_fallback_per_usd_per_day' => 0.05,
  ];

  try {
    $d['crossbreed_max'] = (int)vx_app_setting('crossbreed_max', $d['crossbreed_max']);
    $d['crossbreed_window_sec'] = (int)vx_app_setting('crossbreed_window_sec', $d['crossbreed_window_sec']);
    $rm = vx_app_setting('rarity_mult', $d['rarity_mult']);
    if (is_array($rm)) $d['rarity_mult'] = $rm + $d['rarity_mult'];
    $vm = vx_app_setting('vp_crossbreed_mult', $d['vp_crossbreed_mult']);
    if (is_array($vm)) $d['vp_crossbreed_mult'] = array_values($vm) + $d['vp_crossbreed_mult'];
    $lm = vx_app_setting('lp_crossbreed_mult', $d['lp_crossbreed_mult']);
    if (is_array($lm)) $d['lp_crossbreed_mult'] = array_values($lm) + $d['lp_crossbreed_mult'];
    $d['vp_fallback_per_usd_per_day'] = (float)vx_app_setting('vp_fallback_per_usd_per_day', $d['vp_fallback_per_usd_per_day']);
    $d['lp_fallback_per_usd_per_day'] = (float)vx_app_setting('lp_fallback_per_usd_per_day', $d['lp_fallback_per_usd_per_day']);
  } catch (Throwable $e) {}

  // Guardrails
  if ($d['crossbreed_max'] < 1) $d['crossbreed_max'] = 1;
  if ($d['crossbreed_max'] > 10) $d['crossbreed_max'] = 10;
  if ($d['crossbreed_window_sec'] < 3600) $d['crossbreed_window_sec'] = 3600;
  if ($d['crossbreed_window_sec'] > 604800) $d['crossbreed_window_sec'] = 604800;
  return $d;
}

/* ============================================================
   Guardian Chains — Production Implementation
   ============================================================ */

function vx_guardians_term_seconds(): int { return 30 * 86400; }

function vx_guardians_day_start_ts(int $now): int {
  // server timezone; consistent with other code paths
  return (int)strtotime('today', $now);
}

function vx_season_id_at($db, int $ts): int {
  if (!$db || $ts <= 0) return 0;
  try {
    if (function_exists('vx_seasons_ensure')) { vx_seasons_ensure($db); }
    $r = $db->query('SELECT id FROM vx_seasons WHERE starts_at <= ? AND ends_at > ? ORDER BY id DESC LIMIT 1', $ts, $ts)->fetchArray();
    $sid = (int)($r['id'] ?? 0);
    if ($sid > 0) return $sid;
  } catch (Throwable $e) {}

  // Fallback: create/get current season if needed.
  try {
    if (function_exists('vx_get_current_season')) {
      $sx = vx_get_current_season($db);
      return (int)($sx['id'] ?? 0);
    }
  } catch (Throwable $e) {}

  return 0;
}

/**
 * Season row (id + ends_at) at a given timestamp.
 * Used to split accrual exactly at season boundaries (no drift in seasonal rankings).
 */
function vx_season_row_at($db, int $ts): array {
  if (!$db || $ts <= 0) return [];
  try {
    if (function_exists('vx_seasons_ensure')) { vx_seasons_ensure($db); }
    $r = $db->query(
      'SELECT id, starts_at, ends_at FROM vx_seasons WHERE starts_at <= ? AND ends_at > ? ORDER BY id DESC LIMIT 1',
      $ts, $ts
    )->fetchArray();
    if ($r) return $r;
  } catch (Throwable $e) {}
  try {
    if (function_exists('vx_get_current_season')) {
      $sx = vx_get_current_season($db);
      return is_array($sx) ? $sx : [];
    }
  } catch (Throwable $e) {}
  return [];
}



/**
 * Get (and optionally auto-seed) a vault definition for tarif.
 * Source-of-truth preference:
 *  1) vault_definitions (admin-controlled)
 *  2) db_tarif_points (points_award/30)
 *  3) price-based fallback using app settings
 */
function vx_guardian_definition($db, int $tarif, string $title = '', float $price = 0.0): array {
  $tarif = (int)$tarif;
  if ($tarif <= 0 || !$db) return ['tarif'=>$tarif,'rarity'=>'common','vp_per_day'=>0,'lp_per_day'=>0];
  vx_guardians_schema_ensure($db);

  $row = null;
  try {
    $row = $db->query('SELECT id, name, rarity, vp_per_day, lp_per_day FROM vault_definitions WHERE id=? LIMIT 1', $tarif)->fetchArray();
  } catch (Throwable $e) { $row = null; }

  if (!$row) {
    // Load plan basics if not supplied
    try {
      $p = $db->query('SELECT title, price FROM db_tarif WHERE id=? LIMIT 1', $tarif)->fetchArray();
      if ($title === '' && $p) $title = (string)($p['title'] ?? '');
      if ($price <= 0 && $p) $price = (float)($p['price'] ?? 0);
    } catch (Throwable $e) {}

    $rarMeta = [];
    try { $rarMeta = vx_rarity_for_tarif($db, $tarif, $title, $price); } catch (Throwable $e) { $rarMeta=['rarity'=>'common']; }
    $rarity = (string)($rarMeta['rarity'] ?? 'common');

    // Base VP/day from existing buy-award, spread over 30d (keeps economy familiar)
    $vp = 0;
    try {
      $pr = $db->query('SELECT points_award FROM db_tarif_points WHERE tarif_id=? LIMIT 1', $tarif)->fetchArray();
      // Use floor division to avoid inflation from rounding.
      if ($pr && isset($pr['points_award'])) {
        $pa = max(0, (int)$pr['points_award']);
        $vp = (int)floor($pa / 30);
      }
    } catch (Throwable $e) {}
    if ($vp <= 0) {
      $s = vx_guardian_settings();
      $p = max(0.0, (float)$price);
      // Do not force minimum VP/day on free/zero-price plans.
      $vp = ($p > 0.0)
        ? (int)max(1, round($p * (float)$s['vp_fallback_per_usd_per_day']))
        : 0;
    }

    $s = vx_guardian_settings();
    $lp = (int)max(0, round(max(0.0, $price) * (float)$s['lp_fallback_per_usd_per_day']));

    // Seed row for admin tuning later
    try {
      $db->query(
        'INSERT INTO vault_definitions (id, name, rarity, vp_per_day, lp_per_day, updated_at) VALUES (?, ?, ?, ?, ?, ?)\n'
        .'ON DUPLICATE KEY UPDATE name=VALUES(name), rarity=VALUES(rarity), vp_per_day=VALUES(vp_per_day), lp_per_day=VALUES(lp_per_day), updated_at=VALUES(updated_at)',
        $tarif, ($title !== '' ? $title : null), $rarity, $vp, $lp, time()
      );
    } catch (Throwable $e) {}

    return ['tarif'=>$tarif,'rarity'=>$rarity,'vp_per_day'=>$vp,'lp_per_day'=>$lp];
  }

  $rarity = (string)($row['rarity'] ?? 'common');
  $vp = (int)($row['vp_per_day'] ?? 0);
  $lp = (int)($row['lp_per_day'] ?? 0);
  if ($vp < 0) $vp = 0;
  if ($lp < 0) $lp = 0;
  return ['tarif'=>$tarif,'rarity'=>$rarity,'vp_per_day'=>$vp,'lp_per_day'=>$lp];
}

function vx_guardians_chain_create($db, int $uid, int $tarif, int $storeId, int $seasonId, array $def, int $now): array {
  $now = (int)$now;
  $termEnd = $now + vx_guardians_term_seconds();
  $maxEvolve = 5;
  try {
    $s = vx_guardian_settings();
    $maxEvolve = vx_tarif_max_evolve($db, (int)$tarif, (int)$s['crossbreed_max']);
  } catch (Throwable $e) { $maxEvolve = 5; }
  $maxEvolve = max(1, min(10, (int)$maxEvolve));

  // Shiny: rare flex variant (visual only by default)
  $shiny = 0;
  try {
    // 2% chance; deterministic-ish per storeId to prevent spam retries
    $roll = (int)(hexdec(substr(sha1((string)$uid.'-'.$tarif.'-'.$storeId), 0, 4)) % 1000);
    $shiny = ($roll < 20) ? 1 : 0;
  } catch (Throwable $e) { $shiny = 0; }
  try {
    $db->query(
      'INSERT INTO vx_guardian_chains (uid, tarif, root_store_id, current_store_id, season_id, rarity, crossbreed_level, max_evolve, shiny, status, term_end, matured_at, crossbreed_deadline, vp_total, lp_total, vp_frac, lp_frac, points_last_at, created_at, updated_at)\n'
      .'VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, \'active\', ?, 0, 0, 0, 0, 0, 0, ?, ?, ?)',
      $uid, $tarif, $storeId, $storeId, $seasonId, (string)($def['rarity'] ?? 'common'), $maxEvolve, $shiny, $termEnd, $now, $now, $now
    );
    $cid = (int)$db->lastInsert();
    return ['ok'=>true,'chain_id'=>$cid,'crossbreed'=>false,'crossbreed_level'=>0,'rarity'=>(string)($def['rarity'] ?? 'common'),'reason'=>'new'];
  } catch (Throwable $e) {
    return ['ok'=>false,'chain_id'=>0,'crossbreed'=>false,'crossbreed_level'=>0,'rarity'=>(string)($def['rarity'] ?? 'common'),'reason'=>'error'];
  }
}

/**
 * Purchase hook — attach this activation to an existing matured chain if within window,
 * otherwise create a new chain.
 */
function vx_guardian_on_purchase($db, int $uid, int $tarif, int $storeId, int $seasonId, string $title, float $price, int $now): array {
  if ($uid<=0||$tarif<=0||$storeId<=0||!$db) return ['ok'=>false,'reason'=>'bad_args'];
  vx_guardians_schema_ensure($db);
  $s = vx_guardian_settings();
  $def = vx_guardian_definition($db, $tarif, $title, $price);
  $catalogMax = 5;
  try { $catalogMax = vx_tarif_max_evolve($db, (int)$tarif, (int)$s['crossbreed_max']); } catch (Throwable $e) { $catalogMax = (int)$s['crossbreed_max']; }

  // First, advance any expired matured chains to codex
  try { vx_guardians_touch_user($db, $uid, $now); } catch (Throwable $e) {}

  // Look for a matured chain (same tarif) that is still in the crossbreed window
  try {
    $q = $db->query(
      "SELECT * FROM vx_guardian_chains WHERE uid=? AND tarif=? AND status='matured' AND crossbreed_deadline > ? ORDER BY crossbreed_deadline ASC, id DESC LIMIT 1",
      $uid, $tarif, $now
    );
    $c = $q ? $q->fetchArray() : null;
    if ($c) {
      $cid = (int)($c['id'] ?? 0);
      $lvl = (int)($c['crossbreed_level'] ?? 0);
      $chainMax = (int)($c['max_evolve'] ?? 0);
      if ($chainMax <= 0) $chainMax = $catalogMax;
      if ($chainMax <= 0) $chainMax = (int)$s['crossbreed_max'];
      $newLvl = min($chainMax, $lvl + 1);
      $termEnd = $now + vx_guardians_term_seconds();
      $db->query(
        "UPDATE vx_guardian_chains SET current_store_id=?, season_id=?, rarity=?, crossbreed_level=?, max_evolve=?, status='active', term_end=?, matured_at=0, crossbreed_deadline=0, codex_reason=NULL, codex_at=0, points_last_at=?, updated_at=? WHERE id=? LIMIT 1",
        $storeId, $seasonId, (string)($def['rarity'] ?? ($c['rarity'] ?? 'common')), $newLvl, $chainMax, $termEnd, $now, $now, $cid
      );

      // Social proof: log crossbreed action (public-safe).
      try {
        require_once __DIR__ . '/vx_activity.php';
        $meta = [
          'tarif_id' => $tarif,
          'season_id' => $seasonId,
          'crossbreed_level' => $newLvl,
          'rarity' => (string)($def['rarity'] ?? ($c['rarity'] ?? 'common')),
          'title' => (string)($title !== '' ? $title : 'Guardian'),
        ];
        vx_activity_log($db, $uid, 'crossbreed', 0.0, $meta);
      } catch (Throwable $e) {}

      return ['ok'=>true,'chain_id'=>$cid,'crossbreed'=>true,'crossbreed_level'=>$newLvl,'rarity'=>(string)($def['rarity'] ?? ($c['rarity'] ?? 'common')),'reason'=>'crossbreed'];
    }
  } catch (Throwable $e) {
    // fall through
  }

  return vx_guardians_chain_create($db, $uid, $tarif, $storeId, $seasonId, $def, $now);
}

/**
 * Transition chains for a user:
 * - active -> matured (if crossbreed<max) or codex (if crossbreed==max)
 * - matured -> codex if window expired
 */
function vx_guardians_touch_user($db, int $uid, int $now): void {
  if ($uid<=0||!$db) return;
  vx_guardians_schema_ensure($db);
  $s = vx_guardian_settings();
  $win = (int)$s['crossbreed_window_sec'];

  // Active chains that have ended
  try {
    $q = $db->query("SELECT id, crossbreed_level, max_evolve, term_end FROM vx_guardian_chains WHERE uid=? AND status='active' AND term_end > 0 AND term_end <= ?", $uid, $now);
    if ($q) {
      while ($c = $q->fetchArray()) {
        $cid = (int)($c['id'] ?? 0);
        $lvl = (int)($c['crossbreed_level'] ?? 0);
        $termEnd = (int)($c['term_end'] ?? 0);

        $max = (int)($c['max_evolve'] ?? 0);
        if ($max <= 0) $max = (int)$s['crossbreed_max'];
        if ($lvl >= $max) {
          // Finalized: straight to codex
          try {
            $db->query(
              "UPDATE vx_guardian_chains SET status='codex', codex_reason='max', codex_at=?, matured_at=?, crossbreed_deadline=0, updated_at=? WHERE id=? LIMIT 1",
              $now, $termEnd, $now, $cid
            );
          } catch (Throwable $e) {}

          // Social proof: log codex completion.
          try {
            require_once __DIR__ . '/vx_activity.php';
            $title = '';
            try {
              $sr = $db->query('SELECT title FROM db_store WHERE id=? LIMIT 1', [(int)($db->query('SELECT current_store_id FROM vx_guardian_chains WHERE id=? LIMIT 1', [$cid])->fetchArray()['current_store_id'] ?? 0)])->fetchArray();
              $title = (string)($sr['title'] ?? 'Guardian');
            } catch (Throwable $e) {}
            vx_activity_log($db, $uid, 'codex', 0.0, ['reason'=>'max','chain_id'=>$cid,'title'=>($title?:'Guardian')]);
          } catch (Throwable $e) {}
        } else {
          $deadline = $termEnd + $win;
          try {
            $db->query(
              "UPDATE vx_guardian_chains SET status='matured', matured_at=?, crossbreed_deadline=?, updated_at=? WHERE id=? LIMIT 1",
              $termEnd, $deadline, $now, $cid
            );
          } catch (Throwable $e) {}
        }
      }
    }
  } catch (Throwable $e) {}

  // Matured chains that missed window
  try {
    $q = $db->query("SELECT id, crossbreed_deadline, matured_at FROM vx_guardian_chains WHERE uid=? AND status='matured' AND crossbreed_deadline > 0 AND crossbreed_deadline <= ?", $uid, $now);
    if ($q) {
      while ($c = $q->fetchArray()) {
        $cid = (int)($c['id'] ?? 0);
        $mAt = (int)($c['matured_at'] ?? 0);
        try {
          $db->query(
            "UPDATE vx_guardian_chains SET status='codex', codex_reason='missed', codex_at=?, updated_at=? WHERE id=? LIMIT 1",
            $now, $now, $cid
          );
        } catch (Throwable $e) {}

        // Social proof: log missed window -> codex.
        try {
          require_once __DIR__ . '/vx_activity.php';
          vx_activity_log($db, $uid, 'codex', 0.0, ['reason'=>'missed','chain_id'=>$cid,'title'=>'Guardian']);
        } catch (Throwable $e) {}
      }
    }
  } catch (Throwable $e) {}
}

/**
 * Accrue scaled VP/LP for active chains.
 * Returns: ['vp_added'=>int,'lp_added'=>int]
 */
function vx_guardians_accrue_points($db, int $uid, int $now): array {
  if ($uid<=0||!$db) return ['vp_added'=>0,'lp_added'=>0];
  vx_guardians_schema_ensure($db);
  $s = vx_guardian_settings();

  // Keep states fresh
  try { vx_guardians_touch_user($db, $uid, $now); } catch (Throwable $e) {}

  $vpAdded = 0;
  $lpAdded = 0;

  try {
    $q = $db->query(
      "SELECT id, tarif, kind, title_override, vp_per_day_override, lp_per_day_override, season_id, rarity, crossbreed_level, term_end, vp_frac, lp_frac, points_last_at, created_at FROM vx_guardian_chains WHERE uid=? AND status='active'",
      $uid
    );
    if (!$q) return ['vp_added'=>0,'lp_added'=>0];

    while ($c = $q->fetchArray()) {
      $cid = (int)($c['id'] ?? 0);
      $tarif = (int)($c['tarif'] ?? 0);
      $seasonId = (int)($c['season_id'] ?? 0);
      $rarity = (string)($c['rarity'] ?? 'common');
      $lvl = (int)($c['crossbreed_level'] ?? 0);
      $termEnd = (int)($c['term_end'] ?? 0);
      $last = (int)($c['points_last_at'] ?? 0);
      if ($last <= 0) $last = (int)($c['created_at'] ?? $now);

      $to = $now;
      if ($termEnd > 0) $to = min($to, $termEnd);
      if ($to <= $last) continue;

      // Default (plan-based) rates
      $def = vx_guardian_definition($db, $tarif);
      $vpBase = (int)($def['vp_per_day'] ?? 0);
      $lpBase = (int)($def['lp_per_day'] ?? 0);

      // Special guardians may override rates.
      // This allows Founders/Partner guardians to be decoupled from deposits/plan pricing.
      $vpOv = (int)($c['vp_per_day_override'] ?? 0);
      $lpOv = (int)($c['lp_per_day_override'] ?? 0);
      if ($vpOv > 0) $vpBase = $vpOv;
      if ($lpOv > 0) $lpBase = $lpOv;

      $rm = $s['rarity_mult'];
      $rarMult = isset($rm[$rarity]) ? (float)$rm[$rarity] : 1.0;
      $vpMult = (float)($s['vp_crossbreed_mult'][$lvl] ?? end($s['vp_crossbreed_mult']));
      $lpMult = (float)($s['lp_crossbreed_mult'][$lvl] ?? end($s['lp_crossbreed_mult']));

      $vpF = (float)($c['vp_frac'] ?? 0);
      $lpF = (float)($c['lp_frac'] ?? 0);

      // Accrue in segments split at season boundaries for correct seasonal rankings.
      $segFrom = $last;
      $vpTotalInt = 0;
      $lpTotalInt = 0;
      $bySeason = []; // season_id => ['vp'=>int,'lp'=>int,'ts'=>int]

      while ($segFrom < $to) {
        $sr = vx_season_row_at($db, $segFrom);
        $sid = (int)($sr['id'] ?? 0);
        if ($sid <= 0) $sid = $seasonId;

        $segTo = $to;
        $sEnd = (int)($sr['ends_at'] ?? 0);
        if ($sEnd > 0) $segTo = min($segTo, $sEnd);
        if ($segTo <= $segFrom) {
          // Safety: avoid infinite loops on bad season rows.
          $segTo = min($to, $segFrom + 3600);
        }

        $elapsed = $segTo - $segFrom;
        if ($elapsed < 1) { $segFrom = $segTo; continue; }
        $days = $elapsed / 86400.0;

        $vpEarn = $days * $vpBase * $rarMult * $vpMult;
        $vpF += $vpEarn;
        $vpInt = (int)floor($vpF);
        $vpF -= $vpInt;

        $lpInt = 0;
        if ($lvl >= 1 && $lpBase > 0) {
          $lpEarn = $days * $lpBase * $rarMult * $lpMult;
          $lpF += $lpEarn;
          $lpInt = (int)floor($lpF);
          $lpF -= $lpInt;
        }

        if ($vpInt > 0 || $lpInt > 0) {
          if (!isset($bySeason[$sid])) $bySeason[$sid] = ['vp'=>0,'lp'=>0,'ts'=>$segTo];
          $bySeason[$sid]['vp'] += $vpInt;
          $bySeason[$sid]['lp'] += $lpInt;
          $bySeason[$sid]['ts'] = $segTo;
          $vpTotalInt += $vpInt;
          $lpTotalInt += $lpInt;
        }

        $segFrom = $segTo;
      }

      // Optimistic concurrency: only one request can advance points_last_at from $last -> $to.
      // If this update fails, skip all credits (another request already accrued).
      $okAdvance = false;
      try {
        $db->query(
          "UPDATE vx_guardian_chains SET vp_total = vp_total + ?, lp_total = lp_total + ?, vp_frac = ?, lp_frac = ?, points_last_at = ?, updated_at=?\n"
          ."WHERE id=? AND points_last_at=? LIMIT 1",
          $vpTotalInt, $lpTotalInt, $vpF, $lpF, $to, $now, $cid, $last
        );
        $aff = (method_exists($db,'affectedRows') ? (int)$db->affectedRows() : 1);
        $okAdvance = ($aff > 0);
      } catch (Throwable $e) { $okAdvance = false; }

      if (!$okAdvance) {
        continue;
      }

      // Apply user VP/LP and per-season chain totals only after advancing the chain cursor.
      foreach ($bySeason as $sid => $blk) {
        $vpi = (int)($blk['vp'] ?? 0);
        $lpi = (int)($blk['lp'] ?? 0);
        $ts  = (int)($blk['ts'] ?? $to);
        if ($vpi > 0) {
          try {
            vx_points_add($db, $uid, $vpi, 'vp_daily', ['season_id'=>$sid,'tarif_id'=>$tarif,'chain_id'=>$cid,'crossbreed_level'=>$lvl,'rarity'=>$rarity,'ts'=>$ts]);
          } catch (Throwable $e) {}
          $vpAdded += $vpi;
        }
        if ($lpi > 0) {
          try {
            vx_lp_add($db, $uid, $lpi, 'lp_daily', ['season_id'=>$sid,'tarif_id'=>$tarif,'chain_id'=>$cid,'crossbreed_level'=>$lvl,'rarity'=>$rarity,'ts'=>$ts]);
          } catch (Throwable $e) {}
          $lpAdded += $lpi;
        }

        if (($vpi > 0 || $lpi > 0) && function_exists('vx_table_exists')) {
          try {
            vx_guardians_schema_ensure($db);
            $db->query(
              "INSERT INTO vx_guardian_chain_seasons (chain_id, season_id, vp_total, lp_total, updated_at) VALUES (?, ?, ?, ?, ?)\n"
              ."ON DUPLICATE KEY UPDATE vp_total = vp_total + VALUES(vp_total), lp_total = lp_total + VALUES(lp_total), updated_at = VALUES(updated_at)",
              $cid, (int)$sid, $vpi, $lpi, $now
            );
          } catch (Throwable $e) {}
        }
      }
    }
  } catch (Throwable $e) {
    return ['vp_added'=>$vpAdded,'lp_added'=>$lpAdded];
  }

  return ['vp_added'=>$vpAdded,'lp_added'=>$lpAdded];
}

/**
 * Active guardian badges for dashboard (rarity + crossbreed + days left).
 */
function vx_guardians_active_badges($db, int $uid, int $now, int $limit = 8): array {
  if ($uid<=0||!$db) return [];
  vx_guardians_schema_ensure($db);
  $limit = max(1, min(25, $limit));
  $out = [];
  try {
    $q = $db->query(
      "SELECT c.id AS chain_id, c.tarif, c.rarity, c.crossbreed_level, c.term_end, t.title AS plan_title\n"
      ."FROM vx_guardian_chains c LEFT JOIN db_tarif t ON t.id=c.tarif\n"
      ."WHERE c.uid=? AND c.status='active' ORDER BY c.term_end ASC, c.id DESC LIMIT $limit",
      $uid
    );
    if ($q) {
      while ($r = $q->fetchArray()) {
        $termEnd = (int)($r['term_end'] ?? 0);
        $daysLeft = $termEnd > 0 ? max(0, (int)ceil(($termEnd - $now)/86400)) : 0;
        $out[] = [
          'chain_id'=>(int)($r['chain_id'] ?? 0),
          'tarif'=>(int)($r['tarif'] ?? 0),
          'title'=>(string)($r['plan_title'] ?? 'Mutant Crop'),
          'rarity'=>(string)($r['rarity'] ?? 'common'),
          'crossbreed_level'=>(int)($r['crossbreed_level'] ?? 0),
          'term_end'=>$termEnd,
          'days_left'=>$daysLeft,
        ];
      }
    }
  } catch (Throwable $e) {}
  return $out;
}







/**
 * Return chains currently in the crossbreed window (status=matured and deadline not passed).
 * Each item includes seconds_left for pressure UI.
 */
function vx_guardians_pending_crossbreed($db, int $uid, int $now, int $limit = 3): array {
  if ($uid<=0||!$db) return [];
  vx_guardians_schema_ensure($db);
  $limit = max(1, min(25, $limit));

  // Ensure maturity/codex transitions are current.
  try { vx_guardians_touch_user($db, $uid, $now); } catch (Throwable $e) {}

  $out = [];
  try {
    $q = $db->query(
      "SELECT c.id AS chain_id, c.tarif, c.rarity, c.crossbreed_level, c.term_end, c.crossbreed_deadline,
              t.title AS plan_title, t.price AS plan_price
         FROM vx_guardian_chains c
         LEFT JOIN db_tarif t ON t.id = c.tarif
        WHERE c.uid=? AND c.status='matured' AND c.crossbreed_deadline > ?
        ORDER BY c.crossbreed_deadline ASC, c.id DESC
        LIMIT $limit",
      $uid, $now
    );
    if ($q) {
      while ($r = $q->fetchArray()) {
        $deadline = (int)($r['crossbreed_deadline'] ?? 0);
        $out[] = [
          'chain_id' => (int)($r['chain_id'] ?? 0),
          'tarif' => (int)($r['tarif'] ?? 0),
          'title' => (string)($r['plan_title'] ?? 'Mutant Crop'),
          'price' => (float)($r['plan_price'] ?? 0),
          'rarity' => (string)($r['rarity'] ?? 'common'),
          'crossbreed_level' => (int)($r['crossbreed_level'] ?? 0),
          'term_end' => (int)($r['term_end'] ?? 0),
          'deadline' => $deadline,
          'seconds_left' => max(0, $deadline - $now),
        ];
      }
    }
  } catch (Throwable $e) {}

  return $out;
}

/**
 * Mutant Index cards for a user.
 * Returns rows with plan title/price + chain stats.
 */
function vx_guardians_get_codex($db, int $uid, int $limit = 200): array {
  if (!$db || $uid <= 0) return [];
  vx_guardians_schema_ensure($db);
  $limit = max(1, min(500, (int)$limit));
  $out = [];
  try {
    $q = $db->query(
      "SELECT c.*, t.title AS plan_title, t.price AS plan_price
       FROM vx_guardian_chains c
       LEFT JOIN db_tarif t ON t.id = c.tarif
       WHERE c.uid = ? AND c.status = 'codex'
       ORDER BY c.codex_at DESC, c.id DESC
       LIMIT {$limit}",
      $uid
    );
    if ($q) {
      while ($r = $q->fetchArray()) {
        $out[] = $r;
      }
    }
  } catch (Throwable $e) {}
  return $out;
}

/**
 * Special guardians (Founders / Partner) that live outside the normal plan lifecycle.
 * These are typically "always on" and do not depend on deposits.
 */
function vx_guardians_get_special($db, int $uid, int $limit = 50): array {
  if (!$db || $uid <= 0) return [];
  vx_guardians_schema_ensure($db);
  $limit = max(1, min(200, (int)$limit));
  $rows = [];
  try {
    $q = $db->query(
      "SELECT c.* FROM vx_guardian_chains c\n"
      ."WHERE c.uid = ? AND c.kind IN ('founder','partner','affiliate')\n"
      ."ORDER BY c.created_at ASC, c.id ASC\n"
      ."LIMIT {$limit}",
      $uid
    );
    if ($q) {
      while ($r = $q->fetchArray()) $rows[] = $r;
    }
  } catch (Throwable $e) {}
  return $rows;
}

/**
 * Season breakdown for a chain (Mutant Index history).
 */
function vx_guardians_chain_seasons($db, int $chainId): array {
  if (!$db || $chainId <= 0) return [];
  vx_guardians_schema_ensure($db);
  $rows = [];
  try {
    $q = $db->query(
      'SELECT season_id, vp_total, lp_total, updated_at FROM vx_guardian_chain_seasons WHERE chain_id=? ORDER BY season_id DESC',
      $chainId
    );
    if ($q) {
      while ($r = $q->fetchArray()) $rows[] = $r;
    }
  } catch (Throwable $e) {}
  return $rows;
}
