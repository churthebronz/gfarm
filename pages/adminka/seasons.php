<?php if(!defined('FastCore')){exit('Opss!');}

global $db;

require_once __DIR__ . '/../../core/schema_helpers.php';

$msg = '';
$err = '';

/* -----------------------------
   Safety: DB must exist
------------------------------*/
if (!$db || !is_object($db) || !property_exists($db, 'ok') || !$db->ok) {
  echo '<div class="alert alert-danger">DB connection not available in adminka.</div>';
  return;
}

/* -----------------------------
   Helpers
------------------------------*/
function vx__int_post(string $k, int $def=0): int {
  return isset($_POST[$k]) ? (int)$_POST[$k] : $def;
}
function vx__str_post(string $k, string $def=''): string {
  return isset($_POST[$k]) ? (string)$_POST[$k] : $def;
}
function vx__h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function vx__fmt_dt(int $ts): string {
  if ($ts <= 0) return '-';
  return date('Y-m-d H:i:s', $ts);
}
function vx__fmt_date(int $ts): string {
  if ($ts <= 0) return '-';
  return date('Y-m-d', $ts);
}
function vx__countdown(int $endsAt): string {
  $now = time();
  $d = $endsAt - $now;
  if ($d <= 0) return 'Ended';
  $days = intdiv($d, 86400); $d %= 86400;
  $hrs  = intdiv($d, 3600);  $d %= 3600;
  $mins = intdiv($d, 60);
  return $days.'d '.$hrs.'h '.$mins.'m';
}

/* -----------------------------
   Seasons + Logs (adminka-safe)
------------------------------*/
function vx_admin_season_duration_days(): int {
  return 21;
}

function vx_admin_seasons_ensure($db): void {
  try {
    if (!function_exists('vx_table_exists') || !function_exists('vx_column_exists')) return;

    // vx_seasons
    if (!vx_table_exists($db, 'vx_seasons')) {
      $db->query("CREATE TABLE IF NOT EXISTS vx_seasons (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        season_no INT NOT NULL,
        starts_at INT NOT NULL,
        ends_at INT NOT NULL,
        created_at INT NOT NULL,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY vx_seasons_no_uq (season_no),
        KEY vx_seasons_window_ix (starts_at, ends_at),
        KEY vx_seasons_locked_ix (is_locked)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
      // ensure column is_locked
      if (!vx_column_exists($db, 'vx_seasons', 'is_locked')) {
        try { $db->query("ALTER TABLE vx_seasons ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
      }
      try { $db->query("CREATE UNIQUE INDEX vx_seasons_no_uq ON vx_seasons (season_no)"); } catch (Throwable $e) {}
      try { $db->query("CREATE INDEX vx_seasons_window_ix ON vx_seasons (starts_at, ends_at)"); } catch (Throwable $e) {}
      try { $db->query("CREATE INDEX vx_seasons_locked_ix ON vx_seasons (is_locked)"); } catch (Throwable $e) {}
    }

    // vx_season_caps
    if (!vx_table_exists($db, 'vx_season_caps')) {
      $db->query("CREATE TABLE IF NOT EXISTS vx_season_caps (
        season_id INT NOT NULL,
        tarif_id INT NOT NULL,
        cap INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL,
        PRIMARY KEY (season_id, tarif_id),
        KEY vx_caps_tarif_ix (tarif_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
      try { $db->query("CREATE INDEX vx_caps_tarif_ix ON vx_season_caps (tarif_id)"); } catch (Throwable $e) {}
    }

    // Optional: db_store.season_id
    if (vx_table_exists($db, 'db_store') && !vx_column_exists($db, 'db_store', 'season_id')) {
      try { $db->query("ALTER TABLE db_store ADD COLUMN season_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
    }
    try { $db->query("CREATE INDEX db_store_season_ix ON db_store (season_id, tarif, status)"); } catch (Throwable $e) {}

    // Admin action log
    if (!vx_table_exists($db, 'vx_admin_actions')) {
      $db->query("CREATE TABLE IF NOT EXISTS vx_admin_actions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at INT NOT NULL,
        admin_uid INT NOT NULL DEFAULT 0,
        action VARCHAR(64) NOT NULL,
        season_id INT NOT NULL DEFAULT 0,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        ua VARCHAR(255) NOT NULL DEFAULT '',
        details TEXT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      try { $db->query("CREATE INDEX vx_admin_actions_ix ON vx_admin_actions (created_at, action, season_id)"); } catch (Throwable $e) {}
    } else {
      try { $db->query("CREATE INDEX vx_admin_actions_ix ON vx_admin_actions (created_at, action, season_id)"); } catch (Throwable $e) {}
    }

  } catch (Throwable $e) {
    // never hard-fail adminka
  }
}

function vx_admin_log_action($db, string $action, int $seasonId = 0, string $details=''): void {
  try {
    $uid = 0;
    if (session_status() === PHP_SESSION_ACTIVE) {
      $uid = (int)($_SESSION['uid'] ?? 0);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($ua) > 250) $ua = substr($ua, 0, 250);

    $db->query(
      'INSERT INTO vx_admin_actions (created_at, admin_uid, action, season_id, ip, ua, details)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      time(), $uid, $action, $seasonId, $ip, $ua, $details
    );
  } catch (Throwable $e) {}
}

function vx_admin_seed_caps_for_season($db, int $seasonId, int $defaultTopCap = 4): void {
  try {
    $plans = $db->query('SELECT id, price, title FROM db_tarif ORDER BY id ASC')->fetchAll();
    if (!$plans) return;

    $now = time();

    // top plan = max price then max id
    $topTarifId = 0; $topPrice = -1;
    foreach ($plans as $pp) {
      $tid = (int)($pp['id'] ?? 0);
      $pr  = (float)($pp['price'] ?? 0);
      if ($tid <= 0) continue;
      if ($pr > $topPrice || ($pr === $topPrice && $tid > $topTarifId)) {
        $topPrice = $pr; $topTarifId = $tid;
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

      $cap = 0; // 0 = unlimited

      if ($topTarifId > 0 && $tarifId === $topTarifId) {
        $cap = max(0, (int)$defaultTopCap);
      } elseif (strpos($title, 'sovereign') !== false || strpos($title, 'omega') !== false) {
        $cap = max(0, (int)$defaultTopCap);
      } elseif ($price <= 10) {
        $cap = 0;
      } elseif ($price <= 50) {
        $cap = 1000;
      } elseif ($price <= 200) {
        $cap = 500;
      } elseif ($price <= 1000) {
        $cap = 200;
      } elseif ($price >= 8000) {
        $cap = max(0, (int)$defaultTopCap);
      } else {
        $cap = 100;
      }

      $db->query(
        'INSERT INTO vx_season_caps (season_id, tarif_id, cap, created_at) VALUES (?, ?, ?, ?)',
        $seasonId, $tarifId, (int)$cap, $now
      );
    }
  } catch (Throwable $e) {}
}

function vx_admin_get_current_season($db): array {
  vx_admin_seasons_ensure($db);
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
      ];
    }

    // Create if none active
    $last = $db->query('SELECT season_no, ends_at FROM vx_seasons ORDER BY id DESC LIMIT 1')->fetchArray();
    $nextNo = (int)($last['season_no'] ?? 0) + 1;

    $start = $now;
    if ($last && isset($last['ends_at'])) {
      $le = (int)$last['ends_at'];
      $start = ($le > $now) ? $le : $now;
    }
    $end = $start + (vx_admin_season_duration_days() * 86400);

    $db->query(
      'INSERT INTO vx_seasons (season_no, starts_at, ends_at, created_at, is_locked) VALUES (?, ?, ?, ?, 0)',
      $nextNo, $start, $end, $now
    );

    $sidRow = $db->query('SELECT LAST_INSERT_ID() AS id')->fetchArray();
    $sid = (int)($sidRow['id'] ?? 0);
    if ($sid > 0) vx_admin_seed_caps_for_season($db, $sid, 4);

    return ['ok'=>true,'id'=>$sid,'season_no'=>$nextNo,'starts_at'=>$start,'ends_at'=>$end,'is_locked'=>0];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'season_init_failed: '.$e->getMessage()];
  }
}

function vx_admin_close_season($db, int $seasonId): void {
  $db->query('UPDATE vx_seasons SET ends_at=? WHERE id=?', time(), $seasonId);
}

function vx_admin_snapshot_season_points($db, int $seasonId): void {
  if (!$db || $seasonId <= 0) return;
  try {
    if (!function_exists('vx_table_exists')) return;
    if (!vx_table_exists($db, 'vx_season_points')) return;
    if (!vx_table_exists($db, 'vx_season_points_snap')) {
      $db->query("CREATE TABLE IF NOT EXISTS vx_season_points_snap (
        season_id INT NOT NULL,
        uid INT NOT NULL,
        vp_total BIGINT NOT NULL DEFAULT 0,
        lp_total BIGINT NOT NULL DEFAULT 0,
        snap_at INT NOT NULL,
        PRIMARY KEY (season_id, uid),
        KEY ix_snap_time (snap_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $now = time();
    // Finalize snapshot (upsert per uid)
    $db->query(
      "INSERT INTO vx_season_points_snap (season_id, uid, vp_total, lp_total, snap_at)
       SELECT season_id, uid, vp_total, lp_total, {$now} AS snap_at
       FROM vx_season_points WHERE season_id = {$seasonId}
       ON DUPLICATE KEY UPDATE vp_total=VALUES(vp_total), lp_total=VALUES(lp_total), snap_at=VALUES(snap_at)"
    );
  } catch (Throwable $e) {}
}
function vx_admin_set_lock($db, int $seasonId, int $locked): void {
  $db->query('UPDATE vx_seasons SET is_locked=? WHERE id=?', $locked ? 1 : 0, $seasonId);
}
function vx_admin_create_phase_now($db, int $topCap = 4): array {
  $now = time();
  $last = $db->query('SELECT season_no FROM vx_seasons ORDER BY id DESC LIMIT 1')->fetchArray();
  $nextNo = (int)($last['season_no'] ?? 0) + 1;
  $start = $now;
  $end = $start + (vx_admin_season_duration_days() * 86400);

  $db->query(
    'INSERT INTO vx_seasons (season_no, starts_at, ends_at, created_at, is_locked) VALUES (?, ?, ?, ?, 0)',
    $nextNo, $start, $end, $now
  );

  $sidRow = $db->query('SELECT LAST_INSERT_ID() AS id')->fetchArray();
  $sid = (int)($sidRow['id'] ?? 0);
  if ($sid > 0) vx_admin_seed_caps_for_season($db, $sid, $topCap);

  return ['id'=>$sid,'season_no'=>$nextNo,'starts_at'=>$start,'ends_at'=>$end];
}

function vx_admin_create_next_after_last($db, int $topCap = 4): array {
  $now = time();
  $last = $db->query('SELECT season_no, ends_at FROM vx_seasons ORDER BY id DESC LIMIT 1')->fetchArray();
  $nextNo = (int)($last['season_no'] ?? 0) + 1;

  $start = $now;
  if ($last && isset($last['ends_at'])) {
    $le = (int)$last['ends_at'];
    $start = ($le > $now) ? $le : $now;
  }
  $end = $start + (vx_admin_season_duration_days() * 86400);

  $db->query(
    'INSERT INTO vx_seasons (season_no, starts_at, ends_at, created_at, is_locked) VALUES (?, ?, ?, ?, 0)',
    $nextNo, $start, $end, $now
  );

  $sidRow = $db->query('SELECT LAST_INSERT_ID() AS id')->fetchArray();
  $sid = (int)($sidRow['id'] ?? 0);
  if ($sid > 0) vx_admin_seed_caps_for_season($db, $sid, $topCap);

  return ['id'=>$sid,'season_no'=>$nextNo,'starts_at'=>$start,'ends_at'=>$end];
}

/* -----------------------------
   Ensure
------------------------------*/
vx_admin_seasons_ensure($db);

/* -----------------------------
   Actions (approved set + lock + log)
------------------------------*/
try {
  $cur = vx_admin_get_current_season($db);
  $curId = (int)($cur['id'] ?? 0);

  // Close current early
  if (isset($_POST['vx_close_current'])) {
    if ($curId > 0) {
      vx_admin_snapshot_season_points($db, $curId);
      vx_admin_close_season($db, $curId);
      vx_admin_log_action($db, 'close_current', $curId);
      $msg = 'Current season closed early.';
    } else $err = 'No active season to close.';
  }

  // Close & Lock current
  if (isset($_POST['vx_close_lock_current'])) {
    if ($curId > 0) {
      vx_admin_set_lock($db, $curId, 1);
      vx_admin_snapshot_season_points($db, $curId);
      vx_admin_close_season($db, $curId);
      vx_admin_log_action($db, 'close_lock_current', $curId);
      $msg = 'Current season locked and closed.';
    } else $err = 'No active season to close.';
  }

  // Lock current
  if (isset($_POST['vx_lock_current'])) {
    if ($curId > 0) {
      vx_admin_set_lock($db, $curId, 1);
      vx_admin_log_action($db, 'lock_current', $curId);
      $msg = 'Current season locked.';
    } else $err = 'No active season to lock.';
  }

  // Unlock current
  if (isset($_POST['vx_unlock_current'])) {
    if ($curId > 0) {
      vx_admin_set_lock($db, $curId, 0);
      vx_admin_log_action($db, 'unlock_current', $curId);
      $msg = 'Current season unlocked.';
    } else $err = 'No active season to unlock.';
  }

  // Close current + create new now (Option A)
  if (isset($_POST['vx_close_and_create_now'])) {
    $topCap = max(0, vx__int_post('vx_topcap', 4));
    if ($curId > 0) {
      vx_admin_snapshot_season_points($db, $curId);
      vx_admin_close_season($db, $curId);
      vx_admin_log_action($db, 'close_current_for_new', $curId);
    }
    $new = vx_admin_create_phase_now($db, $topCap);
    vx_admin_log_action($db, 'create_new_now', (int)$new['id'], 'after_close=1');
    $msg = 'Closed current season and created Season #'.(int)$new['season_no'].' (ID '.(int)$new['id'].') starting now.';
  }

  // Restart current => close + create new now
  if (isset($_POST['vx_restart_current'])) {
    $topCap = max(0, vx__int_post('vx_topcap', 4));
    if ($curId > 0) {
      vx_admin_snapshot_season_points($db, $curId);
      vx_admin_close_season($db, $curId);
      vx_admin_log_action($db, 'restart_close', $curId);
    }
    $new = vx_admin_create_phase_now($db, $topCap);
    vx_admin_log_action($db, 'restart_create', (int)$new['id']);
    $msg = 'Restarted: closed current season and created fresh Season #'.(int)$new['season_no'].' (ID '.(int)$new['id'].') starting now.';
  }

  // Create next season (after last ends)
  if (isset($_POST['vx_create_next'])) {
    $topCap = max(0, vx__int_post('vx_topcap', 4));
    $new = vx_admin_create_next_after_last($db, $topCap);
    vx_admin_log_action($db, 'create_next', (int)$new['id']);
    $msg = 'Season created (Season #'.(int)$new['season_no'].').';
  }

  // Seed caps for selected season
  if (isset($_POST['vx_seed_caps'])) {
    $sid = vx__int_post('vx_sid', 0);
    $topCap = max(0, vx__int_post('vx_topcap', 4));
    if ($sid > 0) {
      vx_admin_seed_caps_for_season($db, $sid, $topCap);
      vx_admin_log_action($db, 'seed_caps', $sid, 'topcap='.$topCap);
      $msg = 'Caps seeded for season id '.$sid.'.';
    }
  }

  // Seed missing caps for ALL seasons
  if (isset($_POST['vx_seed_all_caps'])) {
    $topCap = max(0, vx__int_post('vx_topcap', 4));
    $rows = $db->query('SELECT id FROM vx_seasons ORDER BY id DESC LIMIT 200')->fetchAll();
    $done = 0;
    foreach ($rows as $r) {
      $sid = (int)($r['id'] ?? 0);
      if ($sid <= 0) continue;
      vx_admin_seed_caps_for_season($db, $sid, $topCap);
      $done++;
    }
    vx_admin_log_action($db, 'seed_all_caps', 0, 'count='.$done.', topcap='.$topCap);
    $msg = 'Seeded missing caps for '.$done.' seasons.';
  }

  // Save caps
  if (isset($_POST['vx_save_caps'])) {
    $sid = vx__int_post('vx_sid', 0);
    if ($sid > 0) {
      $plans = $db->query('SELECT id, title, price FROM db_tarif ORDER BY id ASC')->fetchAll();
      $now = time();

      foreach ($plans as $p) {
        $tid = (int)($p['id'] ?? 0);
        if ($tid <= 0) continue;
        $key = 'cap_'.$tid;
        if (!isset($_POST[$key])) continue;

        $cap = (int)$_POST[$key];
        if ($cap < 0) $cap = 0;

        $db->query(
          'INSERT INTO vx_season_caps (season_id, tarif_id, cap, created_at)
           VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE cap=VALUES(cap)',
          $sid, $tid, $cap, $now
        );
      }
      vx_admin_log_action($db, 'save_caps', $sid);
      $msg = 'Caps saved.';
    }
  }

} catch (Throwable $e) {
  $err = 'Error: '.$e->getMessage();
}

/* -----------------------------
   Data
------------------------------*/
$cur = vx_admin_get_current_season($db);
$curId = (int)($cur['id'] ?? 0);
$curLocked = (int)($cur['is_locked'] ?? 0);

$seasons = [];
try { $seasons = $db->query('SELECT * FROM vx_seasons ORDER BY id DESC LIMIT 80')->fetchAll(); } catch (Throwable $e) { $seasons = []; }

$plans = [];
try { $plans = $db->query('SELECT id, title, price FROM db_tarif ORDER BY id ASC')->fetchAll(); } catch (Throwable $e) { $plans = []; }

// Which season are we editing?
$selectedSeasonId = 0;
if (isset($_POST['vx_sid'])) $selectedSeasonId = (int)$_POST['vx_sid'];
elseif (!empty($_GET['sid'])) $selectedSeasonId = (int)$_GET['sid'];
else $selectedSeasonId = $curId;

if ($selectedSeasonId <= 0 && !empty($seasons[0]['id'])) $selectedSeasonId = (int)$seasons[0]['id'];

// Selected season row
$selectedSeason = null;
try {
  if ($selectedSeasonId > 0) $selectedSeason = $db->query('SELECT * FROM vx_seasons WHERE id=? LIMIT 1', $selectedSeasonId)->fetchArray();
} catch (Throwable $e) { $selectedSeason = null; }

// Caps map for selected season
$caps = [];
if ($selectedSeasonId > 0) {
  try {
    $q = $db->query('SELECT tarif_id, cap FROM vx_season_caps WHERE season_id = ?', $selectedSeasonId);
    while ($q && ($r = $q->fetchArray())) $caps[(int)$r['tarif_id']] = (int)$r['cap'];
  } catch (Throwable $e) {}
}

/* -----------------------------
   Stats (sold/revenue + cap exceeded)
   Assumes db_store.season_id and paid status=1
------------------------------*/
$stats_total_sold = 0;
$stats_total_revenue = 0.0;
$stats_by_plan = [];

try {
  if ($selectedSeasonId > 0 && vx_table_exists($db, 'db_store') && vx_column_exists($db, 'db_store', 'season_id')) {
    $paidStatus = 1;

    $rows = $db->query(
      'SELECT s.tarif AS tarif_id,
              COUNT(*) AS sold,
              SUM(COALESCE(t.price,0)) AS revenue
       FROM db_store s
       LEFT JOIN db_tarif t ON t.id = s.tarif
       WHERE s.season_id = ? AND s.status = ?
       GROUP BY s.tarif
       ORDER BY sold DESC',
      $selectedSeasonId, $paidStatus
    )->fetchAll();

    foreach ($rows as $r) {
      $tid = (int)($r['tarif_id'] ?? 0);
      $sold = (int)($r['sold'] ?? 0);
      $rev = (float)($r['revenue'] ?? 0);
      if ($tid <= 0) continue;
      $stats_by_plan[$tid] = ['sold'=>$sold, 'revenue'=>$rev];
      $stats_total_sold += $sold;
      $stats_total_revenue += $rev;
    }
  }
} catch (Throwable $e) {}

/* -----------------------------
   Action log latest
------------------------------*/
$action_log = [];
try {
  if (vx_table_exists($db, 'vx_admin_actions')) {
    $action_log = $db->query('SELECT * FROM vx_admin_actions ORDER BY id DESC LIMIT 30')->fetchAll();
  }
} catch (Throwable $e) { $action_log = []; }

?>

<h3>Seasons & Caps</h3>

<?php if($msg): ?>
  <div class="alert alert-success text-center"><?=$msg;?></div>
<?php endif; ?>
<?php if($err): ?>
  <div class="alert alert-danger text-center"><?=$err;?></div>
<?php endif; ?>

<div class="row">

  <div class="col-lg-6">

    <div class="card mb-3">
      <div class="card-header">Seasons Control Panel</div>
      <div class="p-3">
        <?php if(!empty($cur['ok'])): ?>
          <div>
            <div class="text-muted" style="font-size:12px;">Current Season</div>
            <div style="font-size:18px;">
              <b>#<?= (int)$cur['season_no']; ?></b>
              <span class="text-muted">(ID <?= (int)$cur['id']; ?>)</span>
              <?php if($curLocked): ?>
                <span class="badge badge-danger" style="margin-left:8px;">LOCKED</span>
              <?php else: ?>
                <span class="badge badge-success" style="margin-left:8px;">OPEN</span>
              <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:12px;"><?= vx__fmt_dt((int)$cur['starts_at']); ?> → <?= vx__fmt_dt((int)$cur['ends_at']); ?></div>
            <div style="margin-top:6px;">
              <span class="badge badge-info">Time left: <?= vx__countdown((int)$cur['ends_at']); ?></span>
            </div>
          </div>
        <?php else: ?>
          <div class="text-danger">Unable to resolve current season. <?= isset($cur['error']) ? vx__h((string)$cur['error']) : ''; ?></div>
        <?php endif; ?>

        <hr/>

        <form method="post" class="m-0">
          <div class="form-group">
            <label><b>Top plan cap (default 4)</b></label>
            <input class="form-control" type="number" name="vx_topcap" value="4" min="0" />
            <small class="text-muted">Used when creating / seeding caps. (0 = unlimited)</small>
          </div>

          <div class="d-flex flex-wrap" style="gap:10px;">
            <button class="btn btn-outline-danger" type="submit" name="vx_close_current"
              onclick="return confirm('Close current season early? This ends it immediately.');">
              Close current season early
            </button>

            <button class="btn btn-danger" type="submit" name="vx_close_lock_current"
              onclick="return confirm('CLOSE & LOCK current season? This ends it and locks it (emergency stop).');">
              Close & Lock
            </button>

            <?php if($curLocked): ?>
              <button class="btn btn-outline-success" type="submit" name="vx_unlock_current"
                onclick="return confirm('Unlock current season? Buys can resume (if buy-check enforces lock).');">
                Unlock current
              </button>
            <?php else: ?>
              <button class="btn btn-outline-warning" type="submit" name="vx_lock_current"
                onclick="return confirm('Lock current season? Buys should be blocked.');">
                Lock current
              </button>
            <?php endif; ?>

            <button class="btn btn-warning" type="submit" name="vx_close_and_create_now"
              onclick="return confirm('Close current season and create a NEW season starting now?');">
              Close + create new now
            </button>

            <button class="btn btn-dark" type="submit" name="vx_restart_current"
              onclick="return confirm('Restart = close current + create a fresh season starting now. Continue?');">
              Restart current season
            </button>

            <button class="btn btn-primary" type="submit" name="vx_create_next">
              Create next season (after last ends)
            </button>
          </div>

          <div class="mt-3">
            <button class="btn btn-outline-primary" type="submit" name="vx_seed_all_caps"
              onclick="return confirm('Seed missing caps for ALL seasons?');">
              Seed missing caps for ALL seasons
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Admin Action Log (latest 30)</div>
      <div class="p-2" style="max-height:260px;overflow:auto;">
        <?php if(!$action_log): ?>
          <div class="text-muted p-2">No actions logged yet.</div>
        <?php else: ?>
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Time</th>
                <th>Admin</th>
                <th>Action</th>
                <th>Season</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($action_log as $a): ?>
                <tr>
                  <td><?= vx__fmt_dt((int)$a['created_at']); ?></td>
                  <td><?= (int)$a['admin_uid']; ?></td>
                  <td><b><?= vx__h((string)$a['action']); ?></b></td>
                  <td><?= (int)$a['season_id']; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Existing Seasons</div>
      <div class="p-2" style="max-height:420px;overflow:auto;">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>No</th>
              <th>Starts</th>
              <th>Ends</th>
              <th>Left</th>
              <th>Lock</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($seasons as $s): ?>
              <?php $sid = (int)$s['id']; $locked = (int)($s['is_locked'] ?? 0); ?>
              <tr <?= ($sid === (int)$selectedSeasonId) ? 'style="background:#fff6db;"' : ''; ?>>
                <td><a href="?sid=<?=$sid;?>" style="text-decoration:none;"><b><?=$sid;?></b></a></td>
                <td><?= (int)$s['season_no']; ?></td>
                <td><?= vx__fmt_date((int)$s['starts_at']); ?></td>
                <td><?= vx__fmt_date((int)$s['ends_at']); ?></td>
                <td><span class="badge badge-light"><?= vx__countdown((int)$s['ends_at']); ?></span></td>
                <td><?= $locked ? '<span class="badge badge-danger">LOCKED</span>' : '<span class="badge badge-success">OPEN</span>'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <small class="text-muted d-block mt-2">Click a season ID to edit caps + see stats for that season.</small>
      </div>
    </div>

  </div>

  <div class="col-lg-6">

    <div class="card mb-3">
      <div class="card-header">Season Stats (Selected)</div>
      <div class="p-3">
        <?php if($selectedSeasonId <= 0): ?>
          <div class="text-muted">No season selected.</div>
        <?php else: ?>
          <div class="mb-2">
            <div>
              <b>Editing Season:</b> #<?= (int)($selectedSeason['season_no'] ?? 0); ?>
              <span class="text-muted">(ID <?= (int)$selectedSeasonId; ?>)</span>
              <?php if((int)($selectedSeason['is_locked'] ?? 0) === 1): ?>
                <span class="badge badge-danger" style="margin-left:8px;">LOCKED</span>
              <?php endif; ?>
            </div>
            <?php if($selectedSeason): ?>
              <div class="text-muted" style="font-size:12px;">
                <?= vx__fmt_dt((int)$selectedSeason['starts_at']); ?> → <?= vx__fmt_dt((int)$selectedSeason['ends_at']); ?>
                • <b>Left:</b> <?= vx__countdown((int)$selectedSeason['ends_at']); ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="row">
            <div class="col-sm-6 mb-2">
              <div class="p-2" style="border:1px solid #eee;border-radius:10px;">
                <div class="text-muted" style="font-size:12px;">Total sold (status=1)</div>
                <div style="font-size:22px;"><b><?= (int)$stats_total_sold; ?></b></div>
              </div>
            </div>
            <div class="col-sm-6 mb-2">
              <div class="p-2" style="border:1px solid #eee;border-radius:10px;">
                <div class="text-muted" style="font-size:12px;">Revenue (approx)</div>
                <div style="font-size:22px;"><b>$<?= number_format((float)$stats_total_revenue, 2); ?></b></div>
              </div>
            </div>
          </div>

          <div class="mt-2" style="max-height:220px;overflow:auto;border:1px solid #eee;border-radius:10px;padding:8px;">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Plan</th>
                  <th class="text-right">Sold</th>
                  <th class="text-right">Cap</th>
                  <th class="text-right">Remaining</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($plans as $p):
                  $tid = (int)($p['id'] ?? 0);
                  if ($tid <= 0) continue;
                  $title = (string)($p['title'] ?? '');
                  $sold = (int)($stats_by_plan[$tid]['sold'] ?? 0);
                  $cap  = (int)($caps[$tid] ?? 0); // 0 => unlimited
                  $exceeded = ($cap > 0 && $sold > $cap);
                  $remaining = ($cap > 0) ? max(0, $cap - $sold) : -1;
                ?>
                  <tr <?= $exceeded ? 'style="background:#ffe3e3;"' : ''; ?>>
                    <td>#<?= $tid; ?> <b><?= vx__h($title); ?></b></td>
                    <td class="text-right"><?= $sold; ?></td>
                    <td class="text-right"><?= ($cap === 0 ? '∞' : (int)$cap); ?></td>
                    <td class="text-right">
                      <?php if ($cap === 0): ?>
                        <span class="text-muted">∞</span>
                      <?php else: ?>
                        <span class="<?= $exceeded ? 'text-danger' : 'text-success'; ?>">
                          <?= (int)$remaining; ?>
                          <?= $exceeded ? ' (EXCEEDED)' : ''; ?>
                        </span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <small class="text-muted d-block mt-2">
              If your paid status isn’t <b>1</b>, tell me the correct value and I’ll switch it.
            </small>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Caps (Selected Season)</div>
      <div class="p-3">
        <?php if($selectedSeasonId <= 0): ?>
          <div class="text-muted">No season selected.</div>
        <?php else: ?>

          <form method="post" class="m-0">
            <input type="hidden" name="vx_sid" value="<?=$selectedSeasonId;?>" />

            <div class="form-group">
              <label><b>Seed caps (selected season)</b></label>
              <div class="input-group">
                <input class="form-control" type="number" name="vx_topcap" value="4" min="0" />
                <div class="input-group-append">
                  <button class="btn btn-outline-primary" type="submit" name="vx_seed_caps">Seed missing</button>
                </div>
              </div>
              <small class="text-muted">Adds cap rows only for plans missing in this season.</small>
            </div>

            <hr/>

            <div class="form-group">
              <label><b>Edit caps (0 = unlimited)</b></label>
              <div style="max-height:380px;overflow:auto;border:1px solid #eee;border-radius:8px;padding:8px;">
                <?php foreach($plans as $p):
                  $tid = (int)($p['id'] ?? 0);
                  if ($tid <= 0) continue;
                  $title = (string)($p['title'] ?? '');
                  $price = (float)($p['price'] ?? 0);
                  $cap = isset($caps[$tid]) ? (int)$caps[$tid] : 0;
                  $sold = (int)($stats_by_plan[$tid]['sold'] ?? 0);
                  $exceeded = ($cap > 0 && $sold > $cap);
                ?>
                  <div class="input-group mb-2">
                    <div class="input-group-prepend">
                      <span class="input-group-text" style="min-width:60px;<?= $exceeded ? 'background:#ffd0d0;' : ''; ?>">
                        #<?=$tid;?>
                      </span>
                    </div>
                    <div class="form-control" style="display:flex;align-items:center;gap:10px;">
                      <div style="flex:1;">
                        <b><?=vx__h($title);?></b>
                        <span class="text-muted">($<?=number_format($price,2);?>)</span>
                        <span class="text-muted" style="margin-left:8px;">• Sold: <b><?= (int)$sold; ?></b></span>
                        <?php if ($exceeded): ?>
                          <span class="text-danger" style="margin-left:8px;"><b>CAP EXCEEDED</b></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <input class="form-control" type="number" name="cap_<?=$tid;?>" value="<?=$cap;?>" min="0" style="max-width:120px;" />
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <button class="btn btn-success" type="submit" name="vx_save_caps">Save caps</button>
          </form>

        <?php endif; ?>
      </div>
    </div>

  </div>

</div>
