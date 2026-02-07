<?php
// file: /pages/adminka/config.php (GreenFarm Admin • Control Center)
// PHP 5.6+ compatible (no strict_types, no scalar type hints, no ??, no Throwable).
// Uses legacy $db->query($sql) style ONLY.

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm;

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['admin'])) {
  echo '<div class="alert alert-danger text-center">Admin access required.</div>';
  return;
}

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/seasons.php';

$opt['title'] = 'Admin • Control Center';

function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function vx_cfg_get($key, $altKeys = array()) {
  // Supports both legacy array configs and object configs (core/config.php uses an object).
  global $config;
  $keys = array_merge(array((string)$key), is_array($altKeys) ? $altKeys : array());
  foreach ($keys as $k) {
    $k = (string)$k;
    if (is_array($config) && array_key_exists($k, $config) && $config[$k] !== null && $config[$k] !== '') {
      return $config[$k];
    }
    if (is_object($config) && isset($config->$k) && $config->$k !== null && $config->$k !== '') {
      return $config->$k;
    }
  }
  return '';
}

function vx_cc_has_table($db, $t){
  $t = addslashes((string)$t);
  try {
    $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' LIMIT 1");
    $r = $db->fetchArray();
    return !empty($r);
  } catch (Exception $e) { return false; }
}

function vx_cc_has_col($db, $t, $c){
  $t = addslashes((string)$t);
  $c = addslashes((string)$c);
  try {
    $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}' LIMIT 1");
    $r = $db->fetchArray();
    return !empty($r);
  } catch (Exception $e) { return false; }
}

// CSRF (use admin_ops if present; else simple session token)
$csrf = '';
if (function_exists('vx_admin_csrf_token')) {
  $csrf = vx_admin_csrf_token();
} else {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = sha1(uniqid('vx', true));
  }
  $csrf = (string)$_SESSION['_csrf'];
}

function vx_cc_csrf_ok($token){
  if (function_exists('vx_admin_csrf_ok')) return vx_admin_csrf_ok($token);
  $a = isset($_SESSION['_csrf']) ? (string)$_SESSION['_csrf'] : '';
  $b = is_string($token) ? (string)$token : '';
  return ($a !== '' && $b !== '' && $a === $b);
}

$msg = '';
$err = '';

$adminUid = 0;
if (isset($_SESSION['admin_uid'])) $adminUid = (int)$_SESSION['admin_uid'];
else if (isset($_SESSION['uid'])) $adminUid = (int)$_SESSION['uid'];

// Quick actions
if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!vx_cc_csrf_ok(isset($_POST['_csrf']) ? $_POST['_csrf'] : null)) {
    $err = 'Invalid CSRF token.';
  } else {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    try {
      if ($action === 'seed_season') {
        if (function_exists('vx_seasons_ensure')) vx_seasons_ensure($db);
        if (function_exists('vx_auto_create_active_season')) vx_auto_create_active_season($db);
        if (function_exists('vx_seed_caps_if_missing')) vx_seed_caps_if_missing($db);
        if (function_exists('vx_admin_audit')) vx_admin_audit($db, 'admin.control.seed_season', array('admin_uid'=>$adminUid));
        $msg = 'Season ensured (tables + active season + caps best-effort).';

      } elseif ($action === 'clear_locks') {
        $db->query("UPDATE db_users SET rid_lock=0 WHERE rid_lock=1");
        if (function_exists('vx_admin_audit')) vx_admin_audit($db, 'admin.control.clear_ref_locks', array('admin_uid'=>$adminUid));
        $msg = 'All referral locks cleared.';

      } elseif ($action === 'toggle_maintenance') {
        if (vx_cc_has_table($db, 'db_conf') && vx_cc_has_col($db, 'db_conf', 'maintenance')) {
          $db->query("SELECT maintenance FROM db_conf WHERE id=1 LIMIT 1");
          $cur = $db->fetchArray();
          $on = ((int)(isset($cur['maintenance']) ? $cur['maintenance'] : 0) === 1);
          $to = $on ? 0 : 1;
          $db->query("UPDATE db_conf SET maintenance='{$to}' WHERE id=1 LIMIT 1");
          if (function_exists('vx_admin_audit')) vx_admin_audit($db, 'admin.control.toggle_maintenance', array('admin_uid'=>$adminUid,'to'=>$to));
          $msg = 'Maintenance toggled: '.($to ? 'ON' : 'OFF');
        } else {
          $err = 'Maintenance flag not available (no db_conf.maintenance column).';
        }

      } elseif ($action === 'return_admin') {
        unset($_SESSION['impersonating']);
        $msg = 'Returned to admin session.';
      }

    } catch (Exception $e) {
      $err = 'Action failed: '.h($e->getMessage());
    }
  }
}

// KPIs
$st = array(
  'users' => 0,
  'banned' => 0,
  'fake_users' => 0,
  'pending_payouts' => 0,
  'pending_inserts' => 0,
  'today_inserts' => 0.0,
  'today_payouts' => 0.0,
  'events_today' => 0,
);

$today0 = strtotime(date('Y-m-d 00:00:00'));

try { $db->query("SELECT COUNT(*) AS c FROM db_users"); $r=$db->fetchArray(); $st['users']=(int)(isset($r['c'])?$r['c']:0); } catch (Exception $e) {}
try { $db->query("SELECT COUNT(*) AS c FROM db_users WHERE ban=1"); $r=$db->fetchArray(); $st['banned']=(int)(isset($r['c'])?$r['c']:0); } catch (Exception $e) {}
try { $db->query("SELECT COUNT(*) AS c FROM db_users WHERE role=2"); $r=$db->fetchArray(); $st['fake_users']=(int)(isset($r['c'])?$r['c']:0); } catch (Exception $e) {}
try { $db->query("SELECT COUNT(*) AS c FROM db_payout WHERE status IN (0,1)"); $r=$db->fetchArray(); $st['pending_payouts']=(int)(isset($r['c'])?$r['c']:0); } catch (Exception $e) {}
try { $db->query("SELECT COUNT(*) AS c FROM db_insert WHERE status IN (0)"); $r=$db->fetchArray(); $st['pending_inserts']=(int)(isset($r['c'])?$r['c']:0); } catch (Exception $e) {}

try { $db->query("SELECT COALESCE(SUM(sum),0) AS s FROM db_insert WHERE status=1 AND `add` >= ".(int)$today0); $r=$db->fetchArray(); $st['today_inserts']=(float)(isset($r['s'])?$r['s']:0); } catch (Exception $e) {}
try { $db->query("SELECT COALESCE(SUM(sum),0) AS s FROM db_payout WHERE status=3 AND `add` >= ".(int)$today0); $r=$db->fetchArray(); $st['today_payouts']=(float)(isset($r['s'])?$r['s']:0); } catch (Exception $e) {}

try {
  if (vx_cc_has_table($db, 'events_log')) {
    if (vx_cc_has_col($db, 'events_log', 'created_at')) {
      $db->query("SELECT COUNT(*) AS c FROM events_log WHERE created_at >= ".(int)$today0);
      $r=$db->fetchArray(); $st['events_today']=(int)(isset($r['c'])?$r['c']:0);
    } elseif (vx_cc_has_col($db, 'events_log', 'created')) {
      $db->query("SELECT COUNT(*) AS c FROM events_log WHERE created >= ".(int)$today0);
      $r=$db->fetchArray(); $st['events_today']=(int)(isset($r['c'])?$r['c']:0);
    }
  }
} catch (Exception $e) {}

// Secrets (read-only)
$tg_bot = (string)vx_cfg_get('telegram_bot', array('tg_bot'));
$tg_token = (string)vx_cfg_get('telegram_token', array('tg_token','bot_token','telegram_bot_token'));
$tg_secret = (string)vx_cfg_get('telegram_secret', array('tg_secret','webhook_secret','telegram_secret_token','tg_webhook_secret','tg_webhook_secret_token'));
$webhook_url = (string)vx_cfg_get('telegram_webhook_url', array('tg_webhook_url','webhook_url'));
$pk_mid = (string)vx_cfg_get('paykassa_mid', array('paykassa_shop_id','mid','shop_id'));
$pk_key = (string)vx_cfg_get('paykassa_key', array('paykassa_api_key','api_key','key'));

function vx_cc_mask($s){
  $s = trim((string)$s);
  if ($s === '') return '';
  if (strlen($s) <= 10) return str_repeat('•', strlen($s));
  return substr($s,0,3).str_repeat('•', max(3, strlen($s)-7)).substr($s,-4);
}

function vx_cc_chip_html($ok, $okText, $badText){
  $ok = (bool)$ok;
  $cls = $ok ? 'ok' : 'bad';
  $txt = $ok ? $okText : $badText;
  return '<span class="vx-cc-chip '.$cls.'">'.h($txt).'</span>';
}

// Maintenance state for badge (optional / best-effort)
$maintenanceOn = false;
try {
  if (vx_cc_has_table($db, 'db_conf') && vx_cc_has_col($db, 'db_conf', 'maintenance')) {
    $db->query("SELECT maintenance FROM db_conf WHERE id=1 LIMIT 1");
    $r = $db->fetchArray();
    $maintenanceOn = ((int)(isset($r['maintenance']) ? $r['maintenance'] : 0) === 1);
  }
} catch (Exception $e) { $maintenanceOn = false; }

// Seasons
$seasonTxt = 'Not configured';
try {
  if (function_exists('vx_seasons_ensure')) vx_seasons_ensure($db);
  if (function_exists('vx_get_current_season')) {
    $curSeason = vx_get_current_season($db);
    if (!empty($curSeason) && !empty($curSeason['ok'])) {
      $seasonTxt = 'Season #'.(int)(isset($curSeason['season_no'])?$curSeason['season_no']:0).' (ID '.(int)(isset($curSeason['id'])?$curSeason['id']:0).')';
    } else {
      $seasonTxt = 'No active season';
    }
  }
} catch (Exception $e) { $seasonTxt = 'Season error'; }

// Recent events
$events = array();
try {
  if (vx_cc_has_table($db,'events_log')) {
    if (vx_cc_has_col($db,'events_log','event_type') && vx_cc_has_col($db,'events_log','ctx') && vx_cc_has_col($db,'events_log','created_at')) {
      $events = $db->query("SELECT id, user_id, event_type, ctx, created_at FROM events_log ORDER BY id DESC LIMIT 20")->fetchAll();
    } elseif (vx_cc_has_col($db,'events_log','event') && vx_cc_has_col($db,'events_log','meta') && vx_cc_has_col($db,'events_log','created_at')) {
      $events = $db->query("SELECT id, uid, event, meta, created_at FROM events_log ORDER BY id DESC LIMIT 20")->fetchAll();
    }
  }
} catch (Exception $e) { $events = array(); }

$impersonating = !empty($_SESSION['impersonating']);
?>

<style>
/* Self-contained, scoped styles so this page ALWAYS looks correct */
.vx-cc{max-width:1400px;margin:0 auto;padding:12px}
.vx-cc *{box-sizing:border-box}
.vx-cc .vx-cc-top{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:2px 0 12px}
.vx-cc .vx-cc-left{min-width:260px}
.vx-cc .vx-cc-badge{
  display:inline-flex;align-items:center;gap:8px;
  padding:7px 10px;border-radius:999px;
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.10);
  font-weight:900;font-size:12px;letter-spacing:.2px;
}
.vx-cc .vx-cc-badge i{opacity:.9}
.vx-cc .vx-cc-h1{margin:8px 0 2px;font-size:22px;font-weight:900;letter-spacing:.2px}
.vx-cc .vx-cc-sub{opacity:.75;font-size:13px}

.vx-cc .vx-cc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
.vx-cc .vx-cc-btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:9px 12px;border-radius:12px;
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.12);
  color:inherit;
  font-weight:900;font-size:13px;
  transition:transform .12s ease, background .12s ease, border-color .12s ease;
  text-decoration:none;
}
.vx-cc .vx-cc-btn i{opacity:.9}
.vx-cc .vx-cc-btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.09);border-color:rgba(255,255,255,.18)}
.vx-cc .vx-cc-btn:active{transform:translateY(0)}
.vx-cc .vx-cc-btn.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35)}
.vx-cc .vx-cc-btn.ok:hover{background:rgba(34,197,94,.18);border-color:rgba(34,197,94,.45)}
.vx-cc .vx-cc-btn.danger{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.30)}
.vx-cc .vx-cc-btn.danger:hover{background:rgba(239,68,68,.16);border-color:rgba(239,68,68,.40)}

.vx-cc .vx-cc-alert{
  margin:10px 0 12px;padding:10px 12px;border-radius:14px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.06);
  font-weight:900;
}
.vx-cc .vx-cc-alert.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
.vx-cc .vx-cc-alert.bad{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)}

.vx-cc .vx-cc-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px}
.vx-cc .vx-cc-card{
  grid-column:span 6;
  background:rgba(15,23,42,.55);
  border:1px solid rgba(255,255,255,.10);
  border-radius:18px;
  overflow:hidden;
  box-shadow:0 12px 30px rgba(0,0,0,.22);
}
.vx-cc .vx-cc-chd{
  display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 14px;
  background:rgba(2,6,23,.45);
  border-bottom:1px solid rgba(255,255,255,.08);
}
.vx-cc .vx-cc-chd b{font-weight:900}
.vx-cc .vx-cc-mini{font-size:12px;opacity:.75}
.vx-cc .vx-cc-cbd{padding:12px 14px}
.vx-cc .vx-cc-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}

.vx-cc .vx-cc-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.vx-cc .vx-cc-kpi{
  border-radius:16px;
  padding:10px 10px;
  background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.10);
}
.vx-cc .vx-cc-kpi .t{font-size:12px;opacity:.72;font-weight:900}
.vx-cc .vx-cc-kpi .v{font-size:16px;font-weight:900;margin-top:2px}
.vx-cc .vx-cc-kpi .v.small{font-size:13px}

.vx-cc .vx-cc-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.vx-cc .vx-cc-line{
  flex:1;
  min-width:260px;
  border-radius:16px;
  padding:10px 10px;
  background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.10);
}
.vx-cc .vx-cc-line .lbl{font-size:12px;opacity:.72;font-weight:900;margin-bottom:4px}
.vx-cc .vx-cc-chip{
  display:inline-flex;align-items:center;
  padding:6px 10px;border-radius:999px;
  font-weight:900;font-size:12px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.06);
}
.vx-cc .vx-cc-chip.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
.vx-cc .vx-cc-chip.bad{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)}

.vx-cc .vx-cc-table{width:100%;border-collapse:separate;border-spacing:0}
.vx-cc .vx-cc-table th,.vx-cc .vx-cc-table td{padding:10px 10px;vertical-align:top}
.vx-cc .vx-cc-table thead th{
  background:rgba(2,6,23,.65);
  border-bottom:1px solid rgba(255,255,255,.10);
  font-size:12px;text-transform:uppercase;letter-spacing:.6px;opacity:.9
}
.vx-cc .vx-cc-table tbody td{border-bottom:1px solid rgba(255,255,255,.06)}
.vx-cc .vx-cc-table tbody tr:hover td{background:rgba(255,255,255,.04)}

@media (max-width:1100px){
  .vx-cc .vx-cc-card{grid-column:span 12}
  .vx-cc .vx-cc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:560px){
  .vx-cc{padding:10px}
  .vx-cc .vx-cc-kpis{grid-template-columns:repeat(1,minmax(0,1fr))}
  .vx-cc .vx-cc-actions{width:100%}
  .vx-cc .vx-cc-btn{width:100%;justify-content:center}
}
</style>

<div class="vx-cc">
  <div class="vx-cc-top">
    <div class="vx-cc-left">
      <div class="vx-cc-badge">
        <i class="fa fa-sliders"></i> Control Center
        <?php if ($maintenanceOn): ?>
          <span class="vx-cc-chip bad" style="margin-left:8px">MAINTENANCE ON</span>
        <?php endif; ?>
      </div>
      <div class="vx-cc-h1">Admin Control Center</div>
      <div class="vx-cc-sub">Health, queues, wiring, and quick safe actions.</div>
    </div>

    <div class="vx-cc-actions">
      <?php if ($impersonating): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
          <button class="vx-cc-btn danger" name="action" value="return_admin" type="submit">
            <i class="fa fa-undo"></i> Return to admin
          </button>
        </form>
      <?php endif; ?>

      <a class="vx-cc-btn" href="/<?=h($adm)?>/health"><i class="fa fa-heartbeat"></i> Health</a>
      <a class="vx-cc-btn" href="/<?=h($adm)?>/fake"><i class="fa fa-magic"></i> Simulator</a>
      <a class="vx-cc-btn" href="/<?=h($adm)?>/audit"><i class="fa fa-list"></i> Audit</a>
      <a class="vx-cc-btn" href="/<?=h($adm)?>/risk"><i class="fa fa-shield"></i> Risk</a>
    </div>
  </div>

  <?php if ($msg !== ''): ?><div class="vx-cc-alert ok"><?= h($msg); ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="vx-cc-alert bad"><?= h($err); ?></div><?php endif; ?>

  <div class="vx-cc-grid">

    <div class="vx-cc-card">
      <div class="vx-cc-chd"><b>Live KPIs</b><span class="vx-cc-mini">today & queues</span></div>
      <div class="vx-cc-cbd">
        <div class="vx-cc-kpis">
          <div class="vx-cc-kpi"><div class="t">Users</div><div class="v"><?= (int)$st['users']; ?></div></div>
          <div class="vx-cc-kpi"><div class="t">Banned</div><div class="v"><?= (int)$st['banned']; ?></div></div>
          <div class="vx-cc-kpi"><div class="t">Fake users</div><div class="v"><?= (int)$st['fake_users']; ?></div></div>

          <div class="vx-cc-kpi"><div class="t">Pending deposits</div><div class="v"><?= (int)$st['pending_inserts']; ?></div></div>
          <div class="vx-cc-kpi"><div class="t">Pending withdrawals</div><div class="v"><?= (int)$st['pending_payouts']; ?></div></div>
          <div class="vx-cc-kpi"><div class="t">Events today</div><div class="v"><?= (int)$st['events_today']; ?></div></div>

          <div class="vx-cc-kpi"><div class="t">Deposits today</div><div class="v">$<?= number_format((float)$st['today_inserts'],2); ?></div></div>
          <div class="vx-cc-kpi"><div class="t">Withdrawals today</div><div class="v">$<?= number_format((float)$st['today_payouts'],2); ?></div></div>
          <div class="vx-cc-kpi"><div class="t">Season</div><div class="v small"><?= h($seasonTxt); ?></div></div>
        </div>
      </div>
    </div>

    <div class="vx-cc-card">
      <div class="vx-cc-chd"><b>Secrets & wiring</b><span class="vx-cc-mini">read-only</span></div>
      <div class="vx-cc-cbd">

        <div class="vx-cc-row">
          <div class="vx-cc-line">
            <div class="lbl">Telegram bot</div>
            <div style="font-weight:900"><?= ($tg_bot !== '' ? h($tg_bot) : '<span class="vx-cc-mini">not set</span>'); ?></div>
          </div>
          <?= vx_cc_chip_html($tg_token !== '', 'TG token OK', 'TG token missing'); ?>
          <?= vx_cc_chip_html($tg_secret !== '', 'Webhook secret OK', 'Webhook secret missing'); ?>
        </div>

        <div style="height:10px"></div>

        <div class="vx-cc-line" style="min-width:auto">
          <div class="lbl">Webhook URL</div>
          <div class="vx-cc-mono" style="opacity:.92"><?= ($webhook_url !== '' ? h($webhook_url) : '<span class="vx-cc-mini">not stored in config</span>'); ?></div>
        </div>

        <div style="height:10px"></div>

        <div class="vx-cc-row">
          <div class="vx-cc-line">
            <div class="lbl">PayKassa MID</div>
            <div class="vx-cc-mono"><?= ($pk_mid !== '' ? h(vx_cc_mask($pk_mid)) : '<span class="vx-cc-mini">not set</span>'); ?></div>
          </div>
          <div class="vx-cc-line">
            <div class="lbl">PayKassa KEY</div>
            <div class="vx-cc-mono"><?= ($pk_key !== '' ? h(vx_cc_mask($pk_key)) : '<span class="vx-cc-mini">not set</span>'); ?></div>
          </div>
          <?= vx_cc_chip_html(($pk_mid !== '' && $pk_key !== ''), 'PayKassa ready', 'PayKassa missing'); ?>
        </div>

      </div>
    </div>

    <div class="vx-cc-card">
      <div class="vx-cc-chd"><b>Quick safe actions</b><span class="vx-cc-mini">best-effort</span></div>
      <div class="vx-cc-cbd">
        <div class="vx-cc-row">
          <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
            <button class="vx-cc-btn ok" name="action" value="seed_season" type="submit">
              <i class="fa fa-leaf"></i> Ensure season + caps
            </button>
          </form>

          <form method="post" style="margin:0" onsubmit="return confirm('Clear ALL referral locks?');">
            <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
            <button class="vx-cc-btn" name="action" value="clear_locks" type="submit">
              <i class="fa fa-unlock"></i> Clear referral locks
            </button>
          </form>

          <form method="post" style="margin:0" onsubmit="return confirm('Toggle maintenance (if supported)?');">
            <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
            <button class="vx-cc-btn danger" name="action" value="toggle_maintenance" type="submit">
              <i class="fa fa-power-off"></i> Toggle maintenance
            </button>
          </form>
        </div>

        <div class="vx-cc-mini" style="margin-top:10px;opacity:.75">
          Maintenance toggle only works if your DB has <span class="vx-cc-mono">db_conf.maintenance</span>.
        </div>
      </div>
    </div>

    <div class="vx-cc-card">
      <div class="vx-cc-chd"><b>Recent events</b><span class="vx-cc-mini">events_log</span></div>
      <div class="vx-cc-cbd" style="overflow:auto;max-height:420px">
        <?php if (empty($events)): ?>
          <div class="vx-cc-mini">No recent events found (or events_log schema differs).</div>
        <?php else: ?>
          <table class="vx-cc-table">
            <thead>
              <tr><th style="width:90px">ID</th><th>Event</th><th style="width:180px">Time</th></tr>
            </thead>
            <tbody>
            <?php foreach ($events as $e): ?>
              <?php
                $id = (int)(isset($e['id']) ? $e['id'] : 0);
                $etype = '';
                if (isset($e['event_type'])) $etype = (string)$e['event_type'];
                else if (isset($e['event'])) $etype = (string)$e['event'];

                $t = (int)(isset($e['created_at']) ? $e['created_at'] : 0);

                $ctx = '';
                if (isset($e['ctx'])) $ctx = (string)$e['ctx'];
                else if (isset($e['meta'])) $ctx = (string)$e['meta'];

                $short = $ctx;
                if (strlen($short) > 140) $short = substr($short,0,140).'…';
              ?>
              <tr>
                <td class="vx-cc-mono"><?= $id; ?></td>
                <td>
                  <div class="vx-cc-mono" style="font-weight:900"><?= h($etype); ?></div>
                  <div class="vx-cc-mini" style="margin-top:4px"><?= h($short); ?></div>
                </td>
                <td class="vx-cc-mono"><?= ($t ? date('Y-m-d H:i:s',$t) : ''); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
