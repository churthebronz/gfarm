<?php
// pages/adminka/risk.php (GreenFarm Admin • Risk / Anti-abuse) — Premium UI + Controls
declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm;

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/vx_retention.php';

$opt['title'] = 'Risk';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_post(): bool { return (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'); }

$csrf = vx_admin_csrf_token();
$msg = '';

/* -------------------------------------------------------
   Schema feature-detect (soft actions)
--------------------------------------------------------*/
$has_payout_lock = function_exists('vx_column_exists') ? vx_column_exists($db, 'db_users', 'payout_lock') : false;
$has_risk_tag    = function_exists('vx_column_exists') ? vx_column_exists($db, 'db_users', 'risk_tag') : false;
$has_risk_note   = function_exists('vx_column_exists') ? vx_column_exists($db, 'db_users', 'risk_note') : false;

/* -------------------------------------------------------
   Actions (single + bulk)
--------------------------------------------------------*/
if (is_post()) {
  if (!vx_admin_csrf_ok($_POST['_csrf'] ?? null)) {
    $msg = 'Invalid CSRF token.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    $uid = (int)($_POST['uid'] ?? 0);

    // Bulk selected uids[]
    $uids = [];
    if (isset($_POST['uids']) && is_array($_POST['uids'])) {
      foreach ($_POST['uids'] as $x) {
        $xi = (int)$x;
        if ($xi > 0) $uids[] = $xi;
      }
      $uids = array_values(array_unique($uids));
    }

    try {
      // helper for IN (...)
      $do_in = function(string $sql, array $ids, ...$extra) use ($db) {
        if (!$ids) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->query(str_replace('{IN}', $ph, $sql), ...$extra, ...$ids);
      };

      // SINGLE ops
      if ($uid > 0 && $uids === []) {

        if ($action === 'ban') {
          $db->query('UPDATE db_users SET ban=1 WHERE id=? LIMIT 1', $uid);
          vx_admin_audit($db, 'admin_user_ban', ['uid'=>$uid]);
          $msg = 'User banned.';
        } elseif ($action === 'unban') {
          $db->query('UPDATE db_users SET ban=0 WHERE id=? LIMIT 1', $uid);
          vx_admin_audit($db, 'admin_user_unban', ['uid'=>$uid]);
          $msg = 'User unbanned.';
        } elseif ($action === 'ref_lock') {
          $db->query('UPDATE db_users SET rid_lock=1 WHERE id=? LIMIT 1', $uid);
          vx_admin_audit($db, 'admin_ref_lock', ['uid'=>$uid]);
          $msg = 'Referral locked.';
        } elseif ($action === 'ref_unlock') {
          $db->query('UPDATE db_users SET rid_lock=0 WHERE id=? LIMIT 1', $uid);
          vx_admin_audit($db, 'admin_ref_unlock', ['uid'=>$uid]);
          $msg = 'Referral unlocked.';
        } elseif ($action === 'payout_lock' && $has_payout_lock) {
          $db->query('UPDATE db_users SET payout_lock=1 WHERE id=? LIMIT 1', $uid);
          vx_admin_audit($db, 'admin_payout_lock', ['uid'=>$uid]);
          $msg = 'Payout locked.';
        } elseif ($action === 'payout_unlock' && $has_payout_lock) {
          $db->query('UPDATE db_users SET payout_lock=0 WHERE id=? LIMIT 1', $uid);
          vx_admin_audit($db, 'admin_payout_unlock', ['uid'=>$uid]);
          $msg = 'Payout unlocked.';
        } elseif ($action === 'watch_on' && $has_risk_tag) {
          $db->query("UPDATE db_users SET risk_tag='watch' WHERE id=? LIMIT 1", $uid);
          vx_admin_audit($db, 'admin_watch_on', ['uid'=>$uid]);
          $msg = 'Watchlist ON.';
        } elseif ($action === 'watch_off' && $has_risk_tag) {
          $db->query("UPDATE db_users SET risk_tag='' WHERE id=? LIMIT 1", $uid);
          vx_admin_audit($db, 'admin_watch_off', ['uid'=>$uid]);
          $msg = 'Watchlist OFF.';
        } elseif ($action === 'save_note' && $has_risk_note) {
          $note = (string)($_POST['note'] ?? '');
          if (strlen($note) > 2000) $note = substr($note, 0, 2000);
          $db->query('UPDATE db_users SET risk_note=? WHERE id=? LIMIT 1', $note, $uid);
          vx_admin_audit($db, 'admin_risk_note', ['uid'=>$uid]);
          $msg = 'Note saved.';
        }

      // BULK ops
      } elseif ($uids) {

        if ($action === 'bulk_ref_lock') {
          $do_in('UPDATE db_users SET rid_lock=1 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_ref_lock', ['uids'=>$uids]);
          $msg = 'Bulk: referral locked.';
        } elseif ($action === 'bulk_ref_unlock') {
          $do_in('UPDATE db_users SET rid_lock=0 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_ref_unlock', ['uids'=>$uids]);
          $msg = 'Bulk: referral unlocked.';
        } elseif ($action === 'bulk_ban') {
          $do_in('UPDATE db_users SET ban=1 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_ban', ['uids'=>$uids]);
          $msg = 'Bulk: users banned.';
        } elseif ($action === 'bulk_unban') {
          $do_in('UPDATE db_users SET ban=0 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_unban', ['uids'=>$uids]);
          $msg = 'Bulk: users unbanned.';
        } elseif ($action === 'bulk_payout_lock' && $has_payout_lock) {
          $do_in('UPDATE db_users SET payout_lock=1 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_payout_lock', ['uids'=>$uids]);
          $msg = 'Bulk: payout locked.';
        } elseif ($action === 'bulk_payout_unlock' && $has_payout_lock) {
          $do_in('UPDATE db_users SET payout_lock=0 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_payout_unlock', ['uids'=>$uids]);
          $msg = 'Bulk: payout unlocked.';
        } elseif ($action === 'bulk_watch_on' && $has_risk_tag) {
          $do_in("UPDATE db_users SET risk_tag='watch' WHERE id IN ({IN})", $uids);
          vx_admin_audit($db, 'admin_bulk_watch_on', ['uids'=>$uids]);
          $msg = 'Bulk: watchlist ON.';
        } elseif ($action === 'bulk_watch_off' && $has_risk_tag) {
          $do_in("UPDATE db_users SET risk_tag='' WHERE id IN ({IN})", $uids);
          vx_admin_audit($db, 'admin_bulk_watch_off', ['uids'=>$uids]);
          $msg = 'Bulk: watchlist OFF.';
        } else {
          $msg = 'Bulk action not available.';
        }
      }

    } catch (Throwable $e) {
      $msg = 'Action failed: '.$e->getMessage();
    }
  }
}

/* -------------------------------------------------------
   Dashboard stats
--------------------------------------------------------*/
$stats = [
  'new_24h' => 0, 'new_7d' => 0,
  'banned' => 0, 'ref_locked' => 0,
  'payout_locked' => 0,
  'clusters_ip' => 0, 'clusters_ua' => 0,
  'top_sp' => [],
];

try {
  $now = time();
  $t24 = $now - 86400;
  $t7  = $now - (7 * 86400);

  $r = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE reg >= ?', $t24)->fetchArray();
  $stats['new_24h'] = (int)($r['c'] ?? 0);

  $r = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE reg >= ?', $t7)->fetchArray();
  $stats['new_7d'] = (int)($r['c'] ?? 0);

  $r = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE ban=1')->fetchArray();
  $stats['banned'] = (int)($r['c'] ?? 0);

  $r = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE rid_lock=1')->fetchArray();
  $stats['ref_locked'] = (int)($r['c'] ?? 0);

  if ($has_payout_lock) {
    $r = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE payout_lock=1')->fetchArray();
    $stats['payout_locked'] = (int)($r['c'] ?? 0);
  }

  $r = $db->query(
    "SELECT COUNT(*) AS c FROM (
       SELECT ref_ip_hash
       FROM db_users
       WHERE ref_ip_hash<>'' 
       GROUP BY ref_ip_hash
       HAVING COUNT(*) >= 3
     ) t"
  )->fetchArray();
  $stats['clusters_ip'] = (int)($r['c'] ?? 0);

  $r = $db->query(
    "SELECT COUNT(*) AS c FROM (
       SELECT ref_ua_hash
       FROM db_users
       WHERE ref_ua_hash<>'' 
       GROUP BY ref_ua_hash
       HAVING COUNT(*) >= 4
     ) t"
  )->fetchArray();
  $stats['clusters_ua'] = (int)($r['c'] ?? 0);

  $q = $db->query(
    "SELECT ref_start_param AS k, COUNT(*) AS c
     FROM db_users
     WHERE reg >= ? AND ref_start_param<>'' 
     GROUP BY ref_start_param
     ORDER BY c DESC
     LIMIT 5",
    $t7
  );
  while ($q && ($x = $q->fetchArray())) $stats['top_sp'][] = $x;

} catch (Throwable $e) {}

/* -------------------------------------------------------
   Clusters list
--------------------------------------------------------*/
$clusters = ['ip' => [], 'ua' => []];

try {
  $q = $db->query(
    "SELECT ref_ip_hash AS k, COUNT(*) AS c
     FROM db_users
     WHERE ref_ip_hash<>'' 
     GROUP BY ref_ip_hash
     HAVING COUNT(*) >= 3
     ORDER BY c DESC
     LIMIT 25"
  );
  while ($q && ($r = $q->fetchArray())) $clusters['ip'][] = $r;
} catch (Throwable $e) {}

try {
  $q = $db->query(
    "SELECT ref_ua_hash AS k, COUNT(*) AS c
     FROM db_users
     WHERE ref_ua_hash<>'' 
     GROUP BY ref_ua_hash
     HAVING COUNT(*) >= 4
     ORDER BY c DESC
     LIMIT 25"
  );
  while ($q && ($r = $q->fetchArray())) $clusters['ua'][] = $r;
} catch (Throwable $e) {}

/* -------------------------------------------------------
   Search user
--------------------------------------------------------*/
$search_q = trim((string)($_GET['s'] ?? ''));
$search_rows = [];
if ($search_q !== '') {
  try {
    $like = '%'.$search_q.'%';
    $uid = ctype_digit($search_q) ? (int)$search_q : 0;

    $sql = "SELECT id, login, tg_username, telegram_id, ban, rid_lock, sum_in, money_p, reg, last, ref_start_param
            ".($has_payout_lock ? ", payout_lock" : "")."
            ".($has_risk_tag ? ", risk_tag" : "")."
            FROM db_users
            WHERE (id = ?)
               OR (telegram_id = ?)
               OR (tg_username LIKE ?)
               OR (login LIKE ?)
               OR (ref_start_param LIKE ?)
               OR (rid = ?)
            ORDER BY id DESC
            LIMIT 50";
    $q = $db->query($sql, $uid, $uid, $like, $like, $like, $uid);
    while ($q && ($r = $q->fetchArray())) $search_rows[] = $r;
  } catch (Throwable $e) {}
}

/* -------------------------------------------------------
   Drilldown by cluster key
--------------------------------------------------------*/
$kind = (string)($_GET['kind'] ?? '');
$key  = (string)($_GET['k'] ?? '');
$drill = [];
$cluster_size = 0;

if (in_array($kind, ['ip','ua'], true) && $key !== '') {
  $col = ($kind === 'ip') ? 'ref_ip_hash' : 'ref_ua_hash';

  try {
    $sql = "SELECT id, login, tg_username, telegram_id, ban, rid, rid_lock, sum_in, money_p, reg, last, ref_start_param, ref_ip_hash, ref_ua_hash
            ".($has_payout_lock ? ", payout_lock" : "")."
            ".($has_risk_tag ? ", risk_tag" : "")."
            ".($has_risk_note ? ", risk_note" : "")."
            FROM db_users
            WHERE {$col} = ?
            ORDER BY id DESC
            LIMIT 200";
    $q = $db->query($sql, $key);
    while ($q && ($r = $q->fetchArray())) $drill[] = $r;
    $cluster_size = count($drill);
  } catch (Throwable $e) {}
}

/* -------------------------------------------------------
   Risk score — simple/fast
--------------------------------------------------------*/
function risk_score(array $u, int $cluster_size): array {
  $now = time();
  $reg = (int)($u['reg'] ?? 0);
  $sum_in = (float)($u['sum_in'] ?? 0);
  $ban = (int)($u['ban'] ?? 0) === 1;
  $lock = (int)($u['rid_lock'] ?? 0) === 1;

  $age_s = ($reg > 0) ? max(0, $now - $reg) : 99999999;

  $score = 0;
  $flags = [];

  if ($cluster_size >= 4) { $score += 20; $flags[] = 'CL:'.$cluster_size; }
  if ($cluster_size >= 8) { $score += 25; }
  if ($cluster_size >= 15){ $score += 30; }

  if ($age_s < 3600) { $score += 25; $flags[] = 'NEW<1h'; }
  else if ($age_s < 86400) { $score += 12; $flags[] = 'NEW<24h'; }

  if ($sum_in <= 0.00001) { $score += 10; $flags[] = 'NO-IN'; }

  if ($lock) { $flags[] = 'REFLOCK'; }
  if ($ban)  { $flags[] = 'BANNED'; }

  if ($score > 100) $score = 100;

  $label = 'low';
  if ($score >= 70) $label = 'high';
  else if ($score >= 40) $label = 'med';

  return [$score, $label, $flags];
}

?>

<style>
/* ===== GreenFarm Risk UI Skin (premium) ===== */
:root{
  --vx-bg0:#070a12;
  --vx-bg1:#0b1020;
  --vx-bg2:#0f1730;
  --vx-card: rgba(255,255,255,.04);
  --vx-card2: rgba(255,255,255,.03);
  --vx-line: rgba(255,255,255,.10);
  --vx-line2: rgba(255,255,255,.14);
  --vx-text:#e7edf7;
  --vx-muted: rgba(231,237,247,.66);
  --vx-muted2: rgba(231,237,247,.45);
  --vx-shadow: 0 18px 60px rgba(0,0,0,.55);
}

.vx-admin-panel{
  background:
    radial-gradient(1200px 700px at 12% 8%, rgba(59,130,246,.18), transparent 60%),
    radial-gradient(1000px 600px at 92% 12%, rgba(245,158,11,.16), transparent 62%),
    radial-gradient(900px 600px at 60% 110%, rgba(34,197,94,.10), transparent 55%),
    linear-gradient(180deg, var(--vx-bg2), var(--vx-bg1));
  border: 1px solid rgba(255,255,255,.06);
  border-radius: 18px;
  box-shadow: var(--vx-shadow);
  padding: 14px;
}

.vx-admin-panel-hd{
  border-radius: 16px;
  padding: 14px 14px 12px;
  border: 1px solid rgba(255,255,255,.08);
  background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
}

.vx-admin-h1{ letter-spacing:.2px; font-weight: 900; }
.vx-admin-sub{ color: var(--vx-muted); }

.vx-chip{
  display:inline-flex; align-items:center; gap:8px;
  border-radius: 999px;
  padding: 6px 12px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  box-shadow: 0 10px 30px rgba(0,0,0,.35);
}

.vx-grid{ gap: 12px !important; }

.vx-card{
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
  box-shadow: 0 12px 40px rgba(0,0,0,.40);
  overflow: hidden;
}

.vx-card-hd{
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding: 12px 14px;
  border-bottom: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.015));
}

.vx-card-bd{ padding: 12px 14px; }

.vx-mini{ color: var(--vx-muted); font-size: 12px; }
.vx-mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

.vx-admin-alert{
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 10px 12px;
}

.vx-list{ display:flex; flex-direction:column; gap:8px; }
.vx-list-row{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
  color: var(--vx-text);
  text-decoration: none;
  transition: transform .12s ease, border-color .12s ease, background .12s ease;
}
.vx-list-row:hover{
  transform: translateY(-1px);
  border-color: rgba(59,130,246,.35);
  background: rgba(59,130,246,.08);
}

.vx-pill{
  display:inline-flex; align-items:center; gap:6px;
  border-radius: 999px;
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
}
.vx-pill.ok{ border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); color: rgba(201,253,216,1); }
.vx-pill.bad{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); color: rgba(255,208,208,1); }
.vx-pill.wait{ border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); color: rgba(255,226,179,1); }

.vx-btn, .vx-btn.danger{
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 9px 12px;
  font-weight: 900;
  cursor: pointer;
  transition: transform .12s ease, border-color .12s ease, background .12s ease, opacity .12s ease;
  display:inline-flex; align-items:center; gap:8px;
}
.vx-btn:hover{
  transform: translateY(-1px);
  border-color: rgba(59,130,246,.35);
  background: rgba(59,130,246,.10);
}
.vx-btn.danger{
  border-color: rgba(239,68,68,.35);
  background: rgba(239,68,68,.10);
}
.vx-btn.danger:hover{
  border-color: rgba(239,68,68,.55);
  background: rgba(239,68,68,.16);
}
.vx-btn-disabled{ opacity:.6; pointer-events:none; }

.vx-input{
  width: auto;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 10px 12px;
  outline: none;
}
.vx-input::placeholder{ color: var(--vx-muted2); }
.vx-input:focus{
  border-color: rgba(59,130,246,.45);
  box-shadow: 0 0 0 3px rgba(59,130,246,.18);
}

.vx-table{
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  overflow: hidden;
}
.vx-table thead th{
  position: sticky;
  top: 0;
  z-index: 2;
  background: rgba(15,23,48,.92);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(255,255,255,.12);
  color: var(--vx-muted);
  text-transform: uppercase;
  letter-spacing: .35px;
  font-size: 11px;
  padding: 10px 10px;
}
.vx-table td{
  border-bottom: 1px solid rgba(255,255,255,.08);
  padding: 10px 10px;
  color: var(--vx-text);
  vertical-align: top;
}
.vx-table tbody tr{
  background: rgba(255,255,255,.015);
}
.vx-table tbody tr:hover{
  background: rgba(59,130,246,.06);
}

.vx-statgrid{
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}
@media (max-width: 960px){ .vx-statgrid{ grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px){ .vx-statgrid{ grid-template-columns: 1fr; } }

.vx-stat{
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
  padding: 12px 12px;
  display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
}
.vx-stat .t{ color: var(--vx-muted); font-size:12px; font-weight:900; }
.vx-stat .v{ font-size:22px; font-weight: 1000; letter-spacing:.2px; }
.vx-stat .i{
  width:34px; height:34px;
  border-radius: 12px;
  display:flex; align-items:center; justify-content:center;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.04);
}
.vx-i-blue{ box-shadow: 0 0 0 3px rgba(59,130,246,.14); }
.vx-i-amber{ box-shadow: 0 0 0 3px rgba(245,158,11,.14); }
.vx-i-green{ box-shadow: 0 0 0 3px rgba(34,197,94,.14); }
.vx-i-red{ box-shadow: 0 0 0 3px rgba(239,68,68,.14); }

.vx-riskbar{
  height: 10px;
  border-radius: 999px;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.10);
  overflow:hidden;
}
.vx-riskbar > span{
  display:block; height:100%;
  background: linear-gradient(90deg, rgba(34,197,94,.85), rgba(245,158,11,.85), rgba(239,68,68,.85));
}
</style>

<div class="vx-admin-panel">
  <div class="vx-admin-panel-hd">
    <div>
      <div class="vx-chip"><i class="fa fa-shield"></i> Risk</div>
      <h1 class="vx-admin-h1">Anti-abuse / clusters</h1>
      <div class="vx-admin-sub">Use <b>Ref-Lock</b> + <b>Soft locks</b> first. Ban only when obvious.</div>
    </div>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="vx-admin-alert" style="margin-top:10px"><?= h($msg); ?></div>
  <?php endif; ?>

  <?php if (!$has_payout_lock || !$has_risk_tag || !$has_risk_note): ?>
    <div class="vx-admin-alert" style="margin-top:10px;border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)">
      <b>Soft actions not fully enabled.</b>
      <?php if (!$has_payout_lock): ?> <span class="vx-pill wait">missing payout_lock</span><?php endif; ?>
      <?php if (!$has_risk_tag): ?> <span class="vx-pill wait">missing risk_tag</span><?php endif; ?>
      <?php if (!$has_risk_note): ?> <span class="vx-pill wait">missing risk_note</span><?php endif; ?>
      <div class="vx-mini" style="margin-top:6px">Run the SQL I gave you (adds payout lock + watchlist + notes).</div>
    </div>
  <?php endif; ?>

  <div class="vx-grid" style="margin-top:14px">
    <!-- Overview -->
    <div class="vx-card">
      <div class="vx-card-hd"><strong>Overview</strong><span class="vx-mini">signals</span></div>
      <div class="vx-card-bd">
        <div class="vx-statgrid">
          <div class="vx-stat">
            <div><div class="t">New users (24h)</div><div class="v"><?= (int)$stats['new_24h']; ?></div></div>
            <div class="i vx-i-blue"><i class="fa fa-user-plus"></i></div>
          </div>

          <div class="vx-stat">
            <div><div class="t">New users (7d)</div><div class="v"><?= (int)$stats['new_7d']; ?></div></div>
            <div class="i vx-i-blue"><i class="fa fa-users"></i></div>
          </div>

          <div class="vx-stat">
            <div><div class="t">Banned users</div><div class="v"><?= (int)$stats['banned']; ?></div></div>
            <div class="i vx-i-red"><i class="fa fa-ban"></i></div>
          </div>

          <div class="vx-stat">
            <div><div class="t">Ref-locked users</div><div class="v"><?= (int)$stats['ref_locked']; ?></div></div>
            <div class="i vx-i-amber"><i class="fa fa-lock"></i></div>
          </div>

          <?php if ($has_payout_lock): ?>
          <div class="vx-stat">
            <div><div class="t">Payout-locked</div><div class="v"><?= (int)$stats['payout_locked']; ?></div></div>
            <div class="i vx-i-red"><i class="fa fa-money"></i></div>
          </div>
          <?php endif; ?>

          <div class="vx-stat">
            <div><div class="t">IP / UA clusters</div><div class="v"><?= (int)$stats['clusters_ip']; ?> / <?= (int)$stats['clusters_ua']; ?></div></div>
            <div class="i vx-i-green"><i class="fa fa-sitemap"></i></div>
          </div>
        </div>

        <div style="margin-top:12px">
          <div class="vx-mini" style="margin-bottom:6px"><b>Top StartParams (last 7d)</b></div>
          <?php if (empty($stats['top_sp'])): ?>
            <div class="vx-mini">No start_param activity.</div>
          <?php else: ?>
            <div class="vx-list">
              <?php foreach ($stats['top_sp'] as $sp): ?>
                <div class="vx-list-row" style="pointer-events:none">
                  <span class="vx-mono"><?= h((string)$sp['k']); ?></span>
                  <span class="vx-pill ok"><?= (int)$sp['c']; ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Search -->
    <div class="vx-card">
      <div class="vx-card-hd"><strong>Search user</strong><span class="vx-mini">uid / telegram_id / username / rid</span></div>
      <div class="vx-card-bd">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input class="vx-input" name="s" value="<?= h($search_q); ?>" placeholder="UID, telegram_id, tg_username, login, RID, start_param" style="flex:1;min-width:260px">
          <button class="vx-btn" type="submit"><i class="fa fa-search"></i> Search</button>
          <a class="vx-btn" href="/<?= h($adm); ?>/risk"><i class="fa fa-undo"></i> Reset</a>
        </form>

        <?php if ($search_q !== ''): ?>
          <div style="margin-top:10px;overflow:auto">
            <?php if (empty($search_rows)): ?>
              <div class="vx-mini">No matches.</div>
            <?php else: ?>
              <table class="vx-table">
                <thead>
                  <tr>
                    <th>ID</th><th>User</th><th>telegram_id</th><th>RID</th><th>In</th><th>Bal</th><th>StartParam</th><th>Status</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($search_rows as $u): ?>
                  <tr>
                    <td class="vx-mono"><?= (int)$u['id']; ?></td>
                    <td><?= h($u['login'] ?? ''); ?> <span class="vx-mini">@<?= h($u['tg_username'] ?? ''); ?></span></td>
                    <td class="vx-mono"><?= h($u['telegram_id'] ?? ''); ?></td>
                    <td class="vx-mono"><?= h($u['rid'] ?? ''); ?></td>
                    <td class="vx-mono"><?= h(number_format((float)($u['sum_in'] ?? 0), 2)); ?></td>
                    <td class="vx-mono"><?= h(number_format((float)($u['money_p'] ?? 0), 2)); ?></td>
                    <td class="vx-mono"><?= h($u['ref_start_param'] ?? ''); ?></td>
                    <td>
                      <?php if ((int)($u['ban'] ?? 0) === 1): ?><span class="vx-pill bad">banned</span><?php else: ?><span class="vx-pill ok">active</span><?php endif; ?>
                      <?php if ((int)($u['rid_lock'] ?? 0) === 1): ?><span class="vx-pill wait">ref-lock</span><?php endif; ?>
                      <?php if ($has_payout_lock && (int)($u['payout_lock'] ?? 0) === 1): ?><span class="vx-pill bad">payout-lock</span><?php endif; ?>
                      <?php if ($has_risk_tag && (string)($u['risk_tag'] ?? '') === 'watch'): ?><span class="vx-pill wait">watch</span><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Clusters -->
  <div class="vx-grid" style="margin-top:14px">
    <div class="vx-card">
      <div class="vx-card-hd"><strong>IP clusters</strong><span class="vx-mini">3+ users</span></div>
      <div class="vx-card-bd">
        <?php if (empty($clusters['ip'])): ?>
          <div class="vx-mini">No IP clusters found.</div>
        <?php else: ?>
          <div class="vx-list">
            <?php foreach ($clusters['ip'] as $r): ?>
              <a class="vx-list-row" href="/<?= h($adm); ?>/risk?kind=ip&k=<?= h($r['k']); ?>">
                <span class="vx-mono"><?= h(substr((string)$r['k'], 0, 16)); ?>…</span>
                <span class="vx-pill wait"><?= (int)$r['c']; ?> users</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="vx-card">
      <div class="vx-card-hd"><strong>User-Agent clusters</strong><span class="vx-mini">4+ users</span></div>
      <div class="vx-card-bd">
        <?php if (empty($clusters['ua'])): ?>
          <div class="vx-mini">No UA clusters found.</div>
        <?php else: ?>
          <div class="vx-list">
            <?php foreach ($clusters['ua'] as $r): ?>
              <a class="vx-list-row" href="/<?= h($adm); ?>/risk?kind=ua&k=<?= h($r['k']); ?>">
                <span class="vx-mono"><?= h(substr((string)$r['k'], 0, 16)); ?>…</span>
                <span class="vx-pill wait"><?= (int)$r['c']; ?> users</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($drill)): ?>
    <?php
      $banN = 0; $lockN = 0; $pLockN = 0; $inN = 0;
      foreach ($drill as $u) {
        if ((int)($u['ban'] ?? 0) === 1) $banN++;
        if ((int)($u['rid_lock'] ?? 0) === 1) $lockN++;
        if ($has_payout_lock && (int)($u['payout_lock'] ?? 0) === 1) $pLockN++;
        if ((float)($u['sum_in'] ?? 0) > 0.00001) $inN++;
      }
    ?>
    <div class="vx-card" style="margin-top:14px">
      <div class="vx-card-hd">
        <strong>Cluster drilldown</strong>
        <span class="vx-mini"><?= h($kind); ?>: <?= h(substr($key, 0, 18)); ?>… • size <?= (int)$cluster_size; ?> • in>0 <?= (int)$inN; ?> • banned <?= (int)$banN; ?> • ref-lock <?= (int)$lockN; ?><?php if ($has_payout_lock): ?> • payout-lock <?= (int)$pLockN; ?><?php endif; ?></span>
      </div>

      <div class="vx-card-bd" style="overflow:auto">

        <!-- Bulk actions -->
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
          <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
          <select name="action" class="vx-input" style="min-width:240px">
            <option value="">Bulk actions…</option>
            <option value="bulk_ref_lock">Ref lock selected</option>
            <option value="bulk_ref_unlock">Ref unlock selected</option>
            <option value="bulk_ban">Ban selected</option>
            <option value="bulk_unban">Unban selected</option>
            <?php if ($has_payout_lock): ?>
              <option value="bulk_payout_lock">Payout lock selected</option>
              <option value="bulk_payout_unlock">Payout unlock selected</option>
            <?php endif; ?>
            <?php if ($has_risk_tag): ?>
              <option value="bulk_watch_on">Watchlist ON selected</option>
              <option value="bulk_watch_off">Watchlist OFF selected</option>
            <?php endif; ?>
          </select>
          <button class="vx-btn" type="submit" onclick="return vxBulkConfirm(this.form)"><i class="fa fa-bolt"></i> Apply</button>
          <span class="vx-mini">Lock > Soft lock > Ban.</span>
        </form>

        <table class="vx-table">
          <thead>
            <tr>
              <th><input type="checkbox" onclick="vxToggleAll(this)"></th>
              <th>ID</th><th>User</th><th>telegram_id</th><th>RID</th>
              <th>Risk</th><th>Flags</th>
              <th>In</th><th>Bal</th><th>Status</th>
              <th>StartParam</th><th>Joined</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($drill as $u):
            $uid = (int)($u['id'] ?? 0);
            $ban = (int)($u['ban'] ?? 0) === 1;
            $lock = (int)($u['rid_lock'] ?? 0) === 1;
            $pLock = $has_payout_lock ? ((int)($u['payout_lock'] ?? 0) === 1) : false;
            $watch = $has_risk_tag ? ((string)($u['risk_tag'] ?? '') === 'watch') : false;

            [$score, $label, $flags] = risk_score($u, $cluster_size);
            $pillClass = ($label === 'high') ? 'bad' : (($label === 'med') ? 'wait' : 'ok');

            $reg = (int)($u['reg'] ?? 0);
            $last = (int)($u['last'] ?? 0);
            $note = $has_risk_note ? (string)($u['risk_note'] ?? '') : '';
          ?>
            <tr>
              <td><input type="checkbox" class="vxRow" name="uids[]" value="<?= $uid; ?>"></td>

              <td class="vx-mono"><?= $uid; ?></td>

              <td>
                <?= h($u['login'] ?? ''); ?>
                <?php if (!empty($u['tg_username'])): ?>
                  <div class="vx-mini">@<?= h($u['tg_username']); ?></div>
                <?php endif; ?>
              </td>

              <td class="vx-mono"><?= h($u['telegram_id'] ?? ''); ?></td>
              <td class="vx-mono"><?= h($u['rid'] ?? ''); ?></td>

              <td style="min-width:150px">
                <span class="vx-pill <?= $pillClass; ?>"><?= (int)$score; ?></span>
                <div class="vx-riskbar" style="margin-top:8px">
                  <span style="width:<?= (int)$score; ?>%"></span>
                </div>
              </td>

              <td class="vx-mono"><?= h(implode(' ', $flags)); ?></td>

              <td class="vx-mono"><?= h(number_format((float)($u['sum_in'] ?? 0), 2)); ?></td>
              <td class="vx-mono"><?= h(number_format((float)($u['money_p'] ?? 0), 2)); ?></td>

              <td>
                <?php if ($ban): ?><span class="vx-pill bad">banned</span><?php else: ?><span class="vx-pill ok">active</span><?php endif; ?>
                <?php if ($lock): ?><span class="vx-pill wait">ref-lock</span><?php endif; ?>
                <?php if ($has_payout_lock && $pLock): ?><span class="vx-pill bad">payout-lock</span><?php endif; ?>
                <?php if ($has_risk_tag && $watch): ?><span class="vx-pill wait">watch</span><?php endif; ?>
              </td>

              <td class="vx-mono"><?= h($u['ref_start_param'] ?? ''); ?></td>
              <td class="vx-mono"><?= $reg > 0 ? date('Y-m-d H:i', $reg) : ''; ?></td>

              <td>
                <button class="vx-btn" type="button" onclick="vxToggleEvidence(<?= $uid; ?>)"><i class="fa fa-eye"></i> Evidence</button>

                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                  <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                  <input type="hidden" name="uid" value="<?= $uid; ?>">

                  <?php if ($lock): ?>
                    <button class="vx-btn" name="action" value="ref_unlock" type="submit"><i class="fa fa-unlock"></i> Ref unlock</button>
                  <?php else: ?>
                    <button class="vx-btn" name="action" value="ref_lock" type="submit"><i class="fa fa-lock"></i> Ref lock</button>
                  <?php endif; ?>

                  <?php if ($has_payout_lock): ?>
                    <?php if ($pLock): ?>
                      <button class="vx-btn" name="action" value="payout_unlock" type="submit"><i class="fa fa-unlock"></i> Payout unlock</button>
                    <?php else: ?>
                      <button class="vx-btn danger" name="action" value="payout_lock" type="submit" onclick="return confirm('Payout lock this user?');"><i class="fa fa-lock"></i> Payout lock</button>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($has_risk_tag): ?>
                    <?php if ($watch): ?>
                      <button class="vx-btn" name="action" value="watch_off" type="submit"><i class="fa fa-eye-slash"></i> Watch off</button>
                    <?php else: ?>
                      <button class="vx-btn" name="action" value="watch_on" type="submit"><i class="fa fa-eye"></i> Watch</button>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($ban): ?>
                    <button class="vx-btn" name="action" value="unban" type="submit"><i class="fa fa-user"></i> Unban</button>
                  <?php else: ?>
                    <button class="vx-btn danger" name="action" value="ban" type="submit" onclick="return confirm('Ban this user?');"><i class="fa fa-ban"></i> Ban</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>

            <tr id="vx-ev-<?= $uid; ?>" style="display:none">
              <td colspan="13" style="background:rgba(255,255,255,.02)">
                <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
                  <div style="min-width:260px">
                    <div class="vx-mini"><b>Times</b></div>
                    <div class="vx-mini">Joined: <span class="vx-mono"><?= $reg > 0 ? date('Y-m-d H:i:s', $reg) : ''; ?></span></div>
                    <div class="vx-mini">Last: <span class="vx-mono"><?= $last > 0 ? date('Y-m-d H:i:s', $last) : ''; ?></span></div>

                    <div class="vx-mini" style="margin-top:8px"><b>Hashes</b></div>
                    <div class="vx-mini">IP: <span class="vx-mono"><?= h(substr((string)($u['ref_ip_hash'] ?? ''),0,24)); ?>…</span></div>
                    <div class="vx-mini">UA: <span class="vx-mono"><?= h(substr((string)($u['ref_ua_hash'] ?? ''),0,24)); ?>…</span></div>
                  </div>

                  <?php if ($has_risk_note): ?>
                    <div style="flex:1;min-width:320px">
                      <div class="vx-mini"><b>Admin note</b></div>
                      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;margin-top:6px">
                        <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                        <input type="hidden" name="uid" value="<?= $uid; ?>">
                        <textarea class="vx-input" name="note" rows="3" style="width:100%;min-width:320px"><?= h($note); ?></textarea>
                        <button class="vx-btn" type="submit" name="action" value="save_note"><i class="fa fa-save"></i> Save note</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>

          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
// (10) Prevent action spam / double submit
(function(){
  document.querySelectorAll('form').forEach(function(f){
    f.addEventListener('submit', function(){
      try{
        f.querySelectorAll('button[type="submit"]').forEach(function(b){
          b.disabled = true;
          b.classList.add('vx-btn-disabled');
        });
      }catch(e){}
    });
  });
})();

function vxToggleAll(el){
  document.querySelectorAll('.vxRow').forEach(cb => cb.checked = !!el.checked);
}
function vxBulkConfirm(form){
  const action = (form.querySelector('select[name="action"]') || {}).value || '';
  const checked = document.querySelectorAll('.vxRow:checked').length;
  if (!action) { alert('Pick a bulk action.'); return false; }
  if (!checked) { alert('Select at least one user.'); return false; }
  if (action === 'bulk_ban') return confirm('Ban selected users?');
  if (action === 'bulk_payout_lock') return confirm('Payout lock selected users?');
  return true;
}
function vxToggleEvidence(uid){
  const tr = document.getElementById('vx-ev-'+uid);
  if (!tr) return;
  tr.style.display = (tr.style.display === 'none' || tr.style.display === '') ? 'table-row' : 'none';
}
</script>
<?php /* footer rendered by wrapper */ ?>
