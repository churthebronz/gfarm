<?php
// pages/adminka/users.php (GreenFarm Admin • Users) — Full Enhanced Panel
declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm, $pg;

require_once __DIR__ . '/inc/admin_ops.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_post(): bool { return (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'); }
function now(): int { return time(); }

// CSRF helpers (fallback safe)
if (!function_exists('vx_admin_csrf_token')) {
  function vx_admin_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['_vx_admin_csrf'])) $_SESSION['_vx_admin_csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['_vx_admin_csrf'];
  }
}
if (!function_exists('vx_admin_csrf_ok')) {
  function vx_admin_csrf_ok($tok): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    return is_string($tok) && !empty($_SESSION['_vx_admin_csrf']) && hash_equals((string)$_SESSION['_vx_admin_csrf'], $tok);
  }
}
if (!function_exists('vx_admin_audit')) {
  function vx_admin_audit($db, string $event, array $meta = []): void {
    // best-effort; ignore failures
    try {
      $uid = 0;
      if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
      $uid = (int)($_SESSION['uid'] ?? 0);
      $db->query(
        "INSERT INTO events_log (uid, event, meta, created_at) VALUES (?, ?, ?, ?)",
        $uid,
        $event,
        json_encode($meta, JSON_UNESCAPED_SLASHES),
        time()
      );
    } catch (Throwable $e) {}
  }
}

// Feature detect (soft controls)
$col_exists = function(string $table, string $col) use ($db): bool {
  try {
    $r = $db->query(
      "SELECT COLUMN_NAME
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?
       LIMIT 1",
      $table, $col
    )->fetchArray();
    return !empty($r);
  } catch (Throwable $e) { return false; }
};

$has_tg_username  = $col_exists('db_users', 'tg_username');
$has_telegram_id  = $col_exists('db_users', 'telegram_id') || $col_exists('db_users', 'tg_id');
$tg_id_col        = $col_exists('db_users', 'telegram_id') ? 'telegram_id' : ($col_exists('db_users', 'tg_id') ? 'tg_id' : '');
$has_ref_ip_hash  = $col_exists('db_users', 'ref_ip_hash');
$has_ref_ua_hash  = $col_exists('db_users', 'ref_ua_hash');
$has_payout_lock  = $col_exists('db_users', 'payout_lock');
$has_risk_tag     = $col_exists('db_users', 'risk_tag');
$has_risk_note    = $col_exists('db_users', 'risk_note');
$has_last         = $col_exists('db_users', 'last');

$csrf = vx_admin_csrf_token();

$view = (string)($pg->segment[2] ?? ''); // 'info' or list
$uid_info = (int)($pg->params[1] ?? 0);

$flash = '';
$flash_kind = 'ok'; // ok|bad|wait

// Resolve db_uips key
$dbuips_key = null; // uid or id
try {
  if ($col_exists('db_uips', 'uid')) $dbuips_key = 'uid';
  else if ($col_exists('db_uips', 'id')) $dbuips_key = 'id';
} catch (Throwable $e) { $dbuips_key = null; }

// ---------- Actions (Info view + List bulk) ----------
if (is_post()) {
  if (!vx_admin_csrf_ok($_POST['_csrf'] ?? null)) {
    $flash = 'Invalid CSRF token.';
    $flash_kind = 'bad';
  } else {
    $action = (string)($_POST['action'] ?? '');

    // Bulk list actions
    $uids = [];
    if (isset($_POST['uids']) && is_array($_POST['uids'])) {
      foreach ($_POST['uids'] as $x) {
        $xi = (int)$x;
        if ($xi > 0) $uids[] = $xi;
      }
      $uids = array_values(array_unique($uids));
    }

    // Single-user actions in info view
    $target_uid = (int)($_POST['uid'] ?? 0);

    try {
      $do_in = function(string $sql, array $ids) use ($db) {
        if (!$ids) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->query(str_replace('{IN}', $ph, $sql), ...$ids);
      };

      // BULK
      if ($uids) {
        if ($action === 'bulk_ban') {
          $do_in('UPDATE db_users SET ban=1 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_ban', ['uids'=>$uids]);
          $flash = 'Bulk: banned selected users.';
          $flash_kind = 'ok';
        } elseif ($action === 'bulk_unban') {
          $do_in('UPDATE db_users SET ban=0 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_unban', ['uids'=>$uids]);
          $flash = 'Bulk: unbanned selected users.';
          $flash_kind = 'ok';
        } elseif ($action === 'bulk_ref_lock') {
          $do_in('UPDATE db_users SET rid_lock=1 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_ref_lock', ['uids'=>$uids]);
          $flash = 'Bulk: referral locked.';
          $flash_kind = 'ok';
        } elseif ($action === 'bulk_ref_unlock') {
          $do_in('UPDATE db_users SET rid_lock=0 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_ref_unlock', ['uids'=>$uids]);
          $flash = 'Bulk: referral unlocked.';
          $flash_kind = 'ok';
        } elseif ($action === 'bulk_payout_lock' && $has_payout_lock) {
          $do_in('UPDATE db_users SET payout_lock=1 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_payout_lock', ['uids'=>$uids]);
          $flash = 'Bulk: payout locked.';
          $flash_kind = 'ok';
        } elseif ($action === 'bulk_payout_unlock' && $has_payout_lock) {
          $do_in('UPDATE db_users SET payout_lock=0 WHERE id IN ({IN})', $uids);
          vx_admin_audit($db, 'admin_bulk_payout_unlock', ['uids'=>$uids]);
          $flash = 'Bulk: payout unlocked.';
          $flash_kind = 'ok';
        } elseif ($action === 'bulk_watch_on' && $has_risk_tag) {
          $do_in("UPDATE db_users SET risk_tag='watch' WHERE id IN ({IN})", $uids);
          vx_admin_audit($db, 'admin_bulk_watch_on', ['uids'=>$uids]);
          $flash = 'Bulk: watchlist ON.';
          $flash_kind = 'ok';
        } elseif ($action === 'bulk_watch_off' && $has_risk_tag) {
          $do_in("UPDATE db_users SET risk_tag='' WHERE id IN ({IN})", $uids);
          vx_admin_audit($db, 'admin_bulk_watch_off', ['uids'=>$uids]);
          $flash = 'Bulk: watchlist OFF.';
          $flash_kind = 'ok';
        } else {
          $flash = 'Bulk action not available.';
          $flash_kind = 'wait';
        }
      }

      // SINGLE
      if ($target_uid > 0 && !$uids) {
        if ($action === 'ban') {
          $db->query('UPDATE db_users SET ban=1 WHERE id=? LIMIT 1', $target_uid);
          vx_admin_audit($db, 'admin_user_ban', ['uid'=>$target_uid]);
          $flash = 'User banned.';
          $flash_kind = 'ok';
        } elseif ($action === 'unban') {
          $db->query('UPDATE db_users SET ban=0 WHERE id=? LIMIT 1', $target_uid);
          vx_admin_audit($db, 'admin_user_unban', ['uid'=>$target_uid]);
          $flash = 'User unbanned.';
          $flash_kind = 'ok';
        } elseif ($action === 'ref_lock') {
          $db->query('UPDATE db_users SET rid_lock=1 WHERE id=? LIMIT 1', $target_uid);
          vx_admin_audit($db, 'admin_ref_lock', ['uid'=>$target_uid]);
          $flash = 'Referral locked.';
          $flash_kind = 'ok';
        } elseif ($action === 'ref_unlock') {
          $db->query('UPDATE db_users SET rid_lock=0 WHERE id=? LIMIT 1', $target_uid);
          vx_admin_audit($db, 'admin_ref_unlock', ['uid'=>$target_uid]);
          $flash = 'Referral unlocked.';
          $flash_kind = 'ok';
        } elseif ($action === 'payout_lock' && $has_payout_lock) {
          $db->query('UPDATE db_users SET payout_lock=1 WHERE id=? LIMIT 1', $target_uid);
          vx_admin_audit($db, 'admin_payout_lock', ['uid'=>$target_uid]);
          $flash = 'Payout locked.';
          $flash_kind = 'ok';
        } elseif ($action === 'payout_unlock' && $has_payout_lock) {
          $db->query('UPDATE db_users SET payout_lock=0 WHERE id=? LIMIT 1', $target_uid);
          vx_admin_audit($db, 'admin_payout_unlock', ['uid'=>$target_uid]);
          $flash = 'Payout unlocked.';
          $flash_kind = 'ok';
        } elseif ($action === 'watch_on' && $has_risk_tag) {
          $db->query("UPDATE db_users SET risk_tag='watch' WHERE id=? LIMIT 1", $target_uid);
          vx_admin_audit($db, 'admin_watch_on', ['uid'=>$target_uid]);
          $flash = 'Watchlist ON.';
          $flash_kind = 'ok';
        } elseif ($action === 'watch_off' && $has_risk_tag) {
          $db->query("UPDATE db_users SET risk_tag='' WHERE id=? LIMIT 1", $target_uid);
          vx_admin_audit($db, 'admin_watch_off', ['uid'=>$target_uid]);
          $flash = 'Watchlist OFF.';
          $flash_kind = 'ok';
        } elseif ($action === 'save_profile') {
          $loga = trim((string)($_POST['loga'] ?? ''));
          $emal = trim((string)($_POST['emal'] ?? ''));
          $roll = trim((string)($_POST['role'] ?? ''));

          if ($loga === '') $loga = 'user'.$target_uid;
          if (strlen($loga) > 64) $loga = substr($loga, 0, 64);
          if (strlen($emal) > 128) $emal = substr($emal, 0, 128);
          if (strlen($roll) > 32) $roll = substr($roll, 0, 32);

          $db->query('UPDATE db_users SET login=?, email=?, role=? WHERE id=? LIMIT 1', $loga, $emal, $roll, $target_uid);
          vx_admin_audit($db, 'admin_user_edit', ['uid'=>$target_uid, 'login'=>$loga, 'email'=>$emal, 'role'=>$roll]);
          $flash = 'Profile saved.';
          $flash_kind = 'ok';
        } elseif ($action === 'adjust_balance') {
          $dir = (string)($_POST['dir'] ?? 'add'); // add|sub
          $wallet = (string)($_POST['wallet'] ?? 'money_p'); // whitelist
          $sum = (float)($_POST['sum'] ?? 0);

          $allowed_wallets = ['money_p', 'money_b']; // keep it tight; can add more if you want
          if (!in_array($wallet, $allowed_wallets, true)) {
            $flash = 'Invalid wallet field.';
            $flash_kind = 'bad';
          } else {
            if ($sum < 0) $sum = abs($sum);
            if ($sum > 100000000) $sum = 100000000; // hard cap safety
            $op = ($dir === 'sub') ? '-' : '+';

            // Update wallet and also adjust money_b as "total balance" if wallet != money_b (mirrors your old behavior safely)
            if ($wallet === 'money_b') {
              $db->query("UPDATE db_users SET money_b = money_b {$op} ? WHERE id=? LIMIT 1", $sum, $target_uid);
            } else {
              $db->query("UPDATE db_users SET {$wallet} = {$wallet} {$op} ?, money_b = money_b {$op} ? WHERE id=? LIMIT 1", $sum, $sum, $target_uid);
            }

            vx_admin_audit($db, 'admin_balance_adjust', ['uid'=>$target_uid, 'wallet'=>$wallet, 'dir'=>$dir, 'sum'=>$sum]);
            $flash = ($dir === 'sub' ? 'Subtracted ' : 'Added ').number_format($sum, 2).' to '.$wallet.'.';
            $flash_kind = 'ok';
          }
        } elseif ($action === 'save_note' && $has_risk_note) {
          $note = (string)($_POST['note'] ?? '');
          if (strlen($note) > 2000) $note = substr($note, 0, 2000);
          $db->query('UPDATE db_users SET risk_note=? WHERE id=? LIMIT 1', $note, $target_uid);
          vx_admin_audit($db, 'admin_user_note', ['uid'=>$target_uid]);
          $flash = 'Note saved.';
          $flash_kind = 'ok';
        }
      }

    } catch (Throwable $e) {
      $flash = 'Action failed: '.$e->getMessage();
      $flash_kind = 'bad';
    }
  }
}

// ---------- Premium CSS ----------
?>
<style>
:root{
  --vx-bg1:#0b1020;
  --vx-bg2:#0f1730;
  --vx-text:#e7edf7;
  --vx-muted: rgba(231,237,247,.66);
  --vx-muted2: rgba(231,237,247,.45);
  --vx-shadow: 0 18px 60px rgba(0,0,0,.55);
}
.vx-wrap{
  max-width: 1400px;
  margin: 0 auto;
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
.vx-card{
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
  box-shadow: 0 12px 40px rgba(0,0,0,.40);
  overflow: hidden;
}
.vx-hd{
  display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;
  padding: 14px 14px 12px;
  border-bottom: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.015));
}
.vx-bd{ padding: 12px 14px; }
.vx-kicker{
  display:inline-flex; align-items:center; gap:8px;
  border-radius: 999px;
  padding: 6px 12px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  box-shadow: 0 10px 30px rgba(0,0,0,.35);
}
.vx-title{ margin:0; letter-spacing:.2px; font-weight:1000; color: var(--vx-text); }
.vx-sub{ margin-top:6px; color: var(--vx-muted); }
.vx-mini{ color: var(--vx-muted); font-size: 12px; }
.vx-mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

.vx-pill{
  display:inline-flex; align-items:center; gap:8px;
  border-radius: 999px;
  padding: 6px 10px;
  font-size: 12px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
}
.vx-pill.ok{ border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); color: rgba(201,253,216,1); }
.vx-pill.bad{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); color: rgba(255,208,208,1); }
.vx-pill.wait{ border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); color: rgba(255,226,179,1); }
.vx-btn{
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 9px 12px;
  font-weight: 900;
  cursor: pointer;
  display:inline-flex; align-items:center; gap:8px;
  transition: transform .12s ease, border-color .12s ease, background .12s ease, opacity .12s ease;
  text-decoration:none;
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
.vx-btn.slim{ padding: 7px 10px; border-radius: 12px; font-size: 12px; }
.vx-in{
  width:100%;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 10px 12px;
  outline:none;
}
.vx-in::placeholder{ color: var(--vx-muted2); }
.vx-in:focus{
  border-color: rgba(59,130,246,.45);
  box-shadow: 0 0 0 3px rgba(59,130,246,.18);
}
.vx-lab{ display:block; color: var(--vx-muted); font-size: 12px; font-weight: 900; margin:0 0 6px; }
.vx-alert{
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 10px 12px;
  margin-bottom: 12px;
}
.vx-grid{
  display:grid;
  grid-template-columns: 1.2fr .8fr;
  gap: 12px;
}
@media (max-width: 980px){ .vx-grid{ grid-template-columns: 1fr; } }
.vx-row{
  display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between;
}
.vx-tabs{
  display:flex; gap:8px; flex-wrap:wrap; align-items:center;
  margin-top: 10px;
}
.vx-tab{
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.03);
  color: var(--vx-text);
  padding: 7px 10px;
  font-weight: 900;
  cursor: pointer;
}
.vx-tab.active{
  border-color: rgba(59,130,246,.35);
  background: rgba(59,130,246,.10);
}
.vx-pane{ display:none; }
.vx-pane.active{ display:block; }

.vx-table{
  width:100%;
  border-collapse: separate;
  border-spacing: 0;
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
.vx-table tbody tr{ background: rgba(255,255,255,.015); }
.vx-table tbody tr:hover{ background: rgba(59,130,246,.06); }

.vx-statgrid{
  display:grid;
  grid-template-columns: repeat(3, minmax(0,1fr));
  gap: 10px;
}
@media (max-width: 980px){ .vx-statgrid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
@media (max-width: 560px){ .vx-statgrid{ grid-template-columns: 1fr; } }
.vx-stat{
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
  padding: 12px 12px;
  display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
}
.vx-stat .t{ color: var(--vx-muted); font-size:12px; font-weight:900; }
.vx-stat .v{ font-size:20px; font-weight: 1000; letter-spacing:.2px; }
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

.vx-actions{
  position: sticky;
  top: 10px;
  z-index: 3;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(10,14,28,.70);
  backdrop-filter: blur(10px);
  padding: 10px 10px;
}

.vx-copy{
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.03);
  color: var(--vx-text);
  padding: 6px 10px;
  border-radius: 12px;
  cursor: pointer;
  font-weight: 900;
  font-size: 12px;
}
.vx-copy:hover{
  border-color: rgba(59,130,246,.35);
  background: rgba(59,130,246,.08);
}
</style>

<?php
// ---------- INFO VIEW ----------
if ($view === 'info'):

  $id = $uid_info;
  if ($id <= 0) {
    echo '<div class="vx-wrap"><div class="vx-alert" style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)">Invalid user ID.</div></div>';
    return;
  }

  // Fetch user
  $user = null;
  try {
    $user = $db->query('SELECT * FROM db_users WHERE id=? LIMIT 1', $id)->fetchArray();
  } catch (Throwable $e) { $user = null; }

  if (!$user || empty($user['id'])) {
    echo '<div class="vx-wrap"><div class="vx-alert" style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)">User not found.</div></div>';
    return;
  }

  // uips
  $userip = [];
  try {
    if ($dbuips_key) {
      $userip = $db->query("SELECT * FROM db_uips WHERE {$dbuips_key}=? LIMIT 1", $id)->fetchArray();
      if (!$userip) $userip = [];
    }
  } catch (Throwable $e) { $userip = []; }

  $ban = ((int)($user['ban'] ?? 0)) > 0;
  $ref_lock = ((int)($user['rid_lock'] ?? 0)) > 0;
  $payout_lock = $has_payout_lock ? (((int)($user['payout_lock'] ?? 0)) > 0) : false;
  $watch = $has_risk_tag ? (((string)($user['risk_tag'] ?? '')) === 'watch') : false;

  $ref_ip_hash = $has_ref_ip_hash ? (string)($user['ref_ip_hash'] ?? '') : '';
  $ref_ua_hash = $has_ref_ua_hash ? (string)($user['ref_ua_hash'] ?? '') : '';

  // Recent deposits/withdrawals
  $inserts = [];
  $pays = [];
  $refs = [];
  $reviews = [];

  try { $inserts = $db->query('SELECT * FROM db_insert WHERE uid=? AND status=1 ORDER BY id DESC LIMIT 20', $id)->fetchAll(); } catch (Throwable $e) { $inserts = []; }
  try { $pays = $db->query('SELECT * FROM db_payout WHERE uid=? ORDER BY id DESC LIMIT 20', $id)->fetchAll(); } catch (Throwable $e) { $pays = []; }
  try { $refs = $db->query('SELECT * FROM db_users WHERE rid=? ORDER BY ref_to DESC, surf_earn DESC LIMIT 200', $id)->fetchAll(); } catch (Throwable $e) { $refs = []; }
  try { $reviews = $db->query('SELECT * FROM db_reviews WHERE uid=? ORDER BY reward DESC LIMIT 50', $id)->fetchAll(); } catch (Throwable $e) { $reviews = []; }

  $status_array = [
    0 => '<span class="vx-pill wait">wait</span>',
    1 => '<span class="vx-pill wait">wait</span>',
    2 => '<span class="vx-pill bad">cancel</span>',
    3 => '<span class="vx-pill ok">success</span>',
  ];

  // stats
  $sum_in = (float)($user['sum_in'] ?? 0);
  $sum_out = (float)($user['sum_out'] ?? 0);
  $money_b = (float)($user['money_b'] ?? 0);
  $money_p = (float)($user['money_p'] ?? 0);
  $refs_n = (int)($user['refs'] ?? 0);
  $reg = (int)($user['reg'] ?? 0);
  $auth = (int)($user['auth'] ?? 0);
  $last = $has_last ? (int)($user['last'] ?? 0) : 0;

  $login = (string)($user['login'] ?? '');
  $email = (string)($user['email'] ?? '');
  $role = (string)($user['role'] ?? '');

  $tgid = ($tg_id_col !== '') ? (string)($user[$tg_id_col] ?? '') : '';
  $tguser = $has_tg_username ? (string)($user['tg_username'] ?? '') : '';

  $ref_start_param = (string)($user['ref_start_param'] ?? '');
  $rid = (int)($user['rid'] ?? 0);
  $referer = (string)($user['referer'] ?? '');
  $refsite = (string)($user['refsite'] ?? '');
  $ip = (string)($userip['ip'] ?? '');
  $ip2 = (string)($userip['ip2'] ?? '');

  $note = $has_risk_note ? (string)($user['risk_note'] ?? '') : '';

?>
<div class="vx-wrap">
  <div class="vx-card">
    <div class="vx-hd">
      <div>
        <div class="vx-kicker"><i class="fa fa-user"></i> User</div>
        <h1 class="vx-title">#<?= (int)$id; ?> • <?= h($login); ?></h1>
        <div class="vx-sub">
          <?= $ban ? '<span class="vx-pill bad">banned</span>' : '<span class="vx-pill ok">active</span>'; ?>
          <?= $ref_lock ? '<span class="vx-pill wait">ref-lock</span>' : ''; ?>
          <?= ($has_payout_lock && $payout_lock) ? '<span class="vx-pill bad">payout-lock</span>' : ''; ?>
          <?= ($has_risk_tag && $watch) ? '<span class="vx-pill wait">watch</span>' : ''; ?>
          <span class="vx-pill"><?= h($email); ?></span>
        </div>

        <div class="vx-tabs" style="margin-top:12px">
          <button class="vx-tab active" data-tab="ov">Overview</button>
          <button class="vx-tab" data-tab="money">Money</button>
          <button class="vx-tab" data-tab="dep">Deposits</button>
          <button class="vx-tab" data-tab="wd">Withdrawals</button>
          <button class="vx-tab" data-tab="refs">Referrals</button>
          <button class="vx-tab" data-tab="risk">Risk</button>
          <button class="vx-tab" data-tab="note">Notes</button>
          <button class="vx-tab" data-tab="rev">Reviews</button>
        </div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="vx-btn" href="/<?= h($adm); ?>/users"><i class="fa fa-arrow-left"></i> Back</a>
        <a class="vx-btn" href="/<?= h($adm); ?>/audit?uid=<?= (int)$id; ?>"><i class="fa fa-list"></i> Audit</a>
        <a class="vx-btn" href="/<?= h($adm); ?>/risk"><i class="fa fa-shield"></i> Risk</a>
      </div>
    </div>

    <div class="vx-bd">
      <?php if ($flash !== ''): ?>
        <div class="vx-alert <?= $flash_kind === 'bad' ? 'bad' : ''; ?>" style="<?= $flash_kind === 'bad' ? 'border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)' : ($flash_kind === 'wait' ? 'border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)' : 'border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.08)'); ?>">
          <?= h($flash); ?>
        </div>
      <?php endif; ?>

      <div class="vx-grid">
        <!-- Left: content panes -->
        <div>

          <!-- OVERVIEW -->
          <div class="vx-pane active" id="pane-ov">
            <div class="vx-statgrid" style="margin-bottom:12px">
              <div class="vx-stat">
                <div><div class="t">Total IN</div><div class="v"><?= h(number_format($sum_in, 2)); ?></div></div>
                <div class="i vx-i-green"><i class="fa fa-arrow-down"></i></div>
              </div>
              <div class="vx-stat">
                <div><div class="t">Total OUT</div><div class="v"><?= h(number_format($sum_out, 2)); ?></div></div>
                <div class="i vx-i-red"><i class="fa fa-arrow-up"></i></div>
              </div>
              <div class="vx-stat">
                <div><div class="t">Balance</div><div class="v"><?= h(number_format($money_b + $money_p, 2)); ?></div></div>
                <div class="i vx-i-blue"><i class="fa fa-wallet"></i></div>
              </div>
            </div>

            <div class="vx-card" style="margin-bottom:12px">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-info-circle"></i> Identity</div><div class="vx-sub">Core user info + quick copy</div></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <button class="vx-copy" type="button" onclick="vxCopy('uid', '<?= (int)$id; ?>')">Copy UID</button>
                  <?php if ($tgid !== ''): ?><button class="vx-copy" type="button" onclick="vxCopy('tg', '<?= h($tgid); ?>')">Copy TG ID</button><?php endif; ?>
                  <?php if ($ref_start_param !== ''): ?><button class="vx-copy" type="button" onclick="vxCopy('sp', '<?= h($ref_start_param); ?>')">Copy StartParam</button><?php endif; ?>
                </div>
              </div>
              <div class="vx-bd">
                <div class="vx-row" style="justify-content:flex-start;gap:14px">
                  <span class="vx-pill"><span style="opacity:.75">UID</span> <b class="vx-mono">#<?= (int)$id; ?></b></span>
                  <span class="vx-pill"><span style="opacity:.75">Login</span> <b><?= h($login); ?></b></span>
                  <span class="vx-pill"><span style="opacity:.75">Role</span> <b><?= h($role); ?></b></span>
                  <span class="vx-pill"><span style="opacity:.75">RID</span> <b class="vx-mono"><?= (int)$rid; ?></b></span>
                  <?php if ($referer !== ''): ?><span class="vx-pill"><span style="opacity:.75">Referer</span> <b><?= h($referer); ?></b></span><?php endif; ?>
                </div>

                <div class="vx-row" style="margin-top:10px;justify-content:flex-start;gap:14px">
                  <span class="vx-pill"><span style="opacity:.75">Joined</span> <b class="vx-mono"><?= $reg ? date('Y-m-d H:i', $reg) : '-'; ?></b></span>
                  <span class="vx-pill"><span style="opacity:.75">Auth</span> <b class="vx-mono"><?= $auth ? date('Y-m-d H:i', $auth) : '-'; ?></b></span>
                  <?php if ($has_last): ?><span class="vx-pill"><span style="opacity:.75">Last</span> <b class="vx-mono"><?= $last ? date('Y-m-d H:i', $last) : '-'; ?></b></span><?php endif; ?>
                  <?php if ($refsite !== ''): ?><span class="vx-pill"><span style="opacity:.75">Refsite</span> <b><?= h($refsite); ?></b></span><?php endif; ?>
                </div>

                <div class="vx-row" style="margin-top:10px;justify-content:flex-start;gap:14px">
                  <?php if ($ip !== ''): ?><span class="vx-pill"><span style="opacity:.75">IP</span> <b class="vx-mono"><?= h($ip); ?></b></span><?php endif; ?>
                  <?php if ($ip2 !== ''): ?><span class="vx-pill"><span style="opacity:.75">IP login</span> <b class="vx-mono"><?= h($ip2); ?></b></span><?php endif; ?>
                  <?php if ($tguser !== ''): ?><span class="vx-pill"><span style="opacity:.75">TG</span> <b>@<?= h($tguser); ?></b></span><?php endif; ?>
                  <?php if ($tgid !== ''): ?><span class="vx-pill"><span style="opacity:.75">TG ID</span> <b class="vx-mono"><?= h($tgid); ?></b></span><?php endif; ?>
                </div>
              </div>
            </div>

          </div>

          <!-- MONEY -->
          <div class="vx-pane" id="pane-money">
            <div class="vx-card">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-wallet"></i> Balances</div><div class="vx-sub">View balances + safe adjust</div></div>
              </div>
              <div class="vx-bd">
                <div class="vx-row" style="justify-content:flex-start;gap:14px;margin-bottom:10px">
                  <span class="vx-pill"><span style="opacity:.75">money_b</span> <b class="vx-mono"><?= h(number_format((float)($user['money_b'] ?? 0), 2)); ?></b></span>
                  <span class="vx-pill"><span style="opacity:.75">money_p</span> <b class="vx-mono"><?= h(number_format((float)($user['money_p'] ?? 0), 2)); ?></b></span>
                  <span class="vx-pill"><span style="opacity:.75">IN</span> <b class="vx-mono"><?= h(number_format($sum_in, 2)); ?></b></span>
                  <span class="vx-pill"><span style="opacity:.75">OUT</span> <b class="vx-mono"><?= h(number_format($sum_out, 2)); ?></b></span>
                </div>

                <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                  <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                  <input type="hidden" name="uid" value="<?= (int)$id; ?>">
                  <input type="hidden" name="action" value="adjust_balance">

                  <div style="width:180px">
                    <label class="vx-lab">Direction</label>
                    <select name="dir" class="vx-in">
                      <option value="add">Add</option>
                      <option value="sub">Subtract</option>
                    </select>
                  </div>

                  <div style="width:220px">
                    <label class="vx-lab">Wallet</label>
                    <select name="wallet" class="vx-in">
                      <option value="money_p">money_p</option>
                      <option value="money_b">money_b</option>
                    </select>
                  </div>

                  <div style="width:220px">
                    <label class="vx-lab">Amount</label>
                    <input class="vx-in" name="sum" value="100" placeholder="e.g. 100">
                  </div>

                  <button class="vx-btn danger" type="submit" onclick="return confirm('Apply balance change to this user?');">
                    <i class="fa fa-bolt"></i> Apply
                  </button>

                  <span class="vx-mini">Safe: wallet field is whitelisted + logged to audit.</span>
                </form>
              </div>
            </div>
          </div>

          <!-- DEPOSITS -->
          <div class="vx-pane" id="pane-dep">
            <div class="vx-card">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-arrow-down"></i> Deposits</div><div class="vx-sub">Last 20 confirmed deposits</div></div>
              </div>
              <div class="vx-bd" style="overflow:auto">
                <table class="vx-table">
                  <thead>
                    <tr>
                      <th style="width:90px">SYS</th>
                      <th style="width:140px">Amount</th>
                      <th>Details</th>
                      <th style="width:190px">Date</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($inserts)): ?>
                    <tr><td colspan="4" style="opacity:.8">No deposits.</td></tr>
                  <?php else: foreach ($inserts as $row): ?>
                    <?php $sumInRow = (float)($row['sum'] ?? 0); ?>
                    <tr class="notranslate">
                      <td class="vx-mono"><?= h($row['sys'] ?? ''); ?></td>
                      <td class="vx-mono"><?= h(number_format($sumInRow, 2)); ?> <span class="vx-mini">{!VAL!}</span></td>
                      <td class="vx-mini"><?= h(($row['comment'] ?? $row['txid'] ?? $row['trx'] ?? '') ?: ''); ?></td>
                      <td class="vx-mono"><?= isset($row['add']) ? date('Y-m-d H:i', (int)$row['add']) : ''; ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- WITHDRAWALS -->
          <div class="vx-pane" id="pane-wd">
            <div class="vx-card">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-arrow-up"></i> Withdrawals</div><div class="vx-sub">Last 20 payout requests</div></div>
              </div>
              <div class="vx-bd" style="overflow:auto">
                <table class="vx-table">
                  <thead>
                    <tr>
                      <th style="width:120px">Status</th>
                      <th style="width:90px">SYS</th>
                      <th style="width:140px">Amount</th>
                      <th>Details</th>
                      <th style="width:190px">Date</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($pays)): ?>
                    <tr><td colspan="5" style="opacity:.8">No withdrawals.</td></tr>
                  <?php else: foreach ($pays as $row): ?>
                    <?php
                      $st = (int)($row['status'] ?? 0);
                      $sumOutRow = (float)($row['sum'] ?? 0);
                    ?>
                    <tr class="notranslate">
                      <td><?= $status_array[$st] ?? '<span class="vx-pill wait">?</span>'; ?></td>
                      <td class="vx-mono"><?= h($row['sys'] ?? ''); ?></td>
                      <td class="vx-mono"><?= h(number_format($sumOutRow, 2)); ?> <span class="vx-mini">{!VAL!}</span></td>
                      <td class="vx-mini"><?= h(($row['comment'] ?? $row['txid'] ?? $row['wallet'] ?? '') ?: ''); ?></td>
                      <td class="vx-mono"><?= isset($row['add']) ? date('Y-m-d H:i', (int)$row['add']) : ''; ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- REFERRALS -->
          <div class="vx-pane" id="pane-refs">
            <div class="vx-card">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-sitemap"></i> Referrals</div><div class="vx-sub">Direct referrals (rid = this UID)</div></div>
                <div class="vx-pill"><?= (int)count($refs); ?> users</div>
              </div>
              <div class="vx-bd" style="overflow:auto">
                <table class="vx-table">
                  <thead>
                    <tr>
                      <th style="width:90px">ID</th>
                      <th>User</th>
                      <th style="width:160px">Balance</th>
                      <th style="width:160px">IN/OUT</th>
                      <th style="width:180px">Joined</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($refs)): ?>
                    <tr><td colspan="5" style="opacity:.8">No referrals.</td></tr>
                  <?php else: foreach ($refs as $r): ?>
                    <?php
                      $rb = ((float)($r['money_b'] ?? 0)) + ((float)($r['money_p'] ?? 0));
                      $rban = ((int)($r['ban'] ?? 0)) > 0;
                    ?>
                    <tr>
                      <td class="vx-mono"><?= (int)$r['id']; ?></td>
                      <td>
                        <a href="/<?= h($adm); ?>/users/info/<?= (int)$r['id']; ?>" style="text-decoration:none;color:inherit">
                          <?= $rban ? '<span style="color:rgba(255,120,120,1)">'.h($r['login'] ?? '').'</span>' : h($r['login'] ?? ''); ?>
                        </a>
                        <?php if ($has_tg_username && !empty($r['tg_username'])): ?><div class="vx-mini">@<?= h($r['tg_username']); ?></div><?php endif; ?>
                      </td>
                      <td class="vx-mono"><?= h(number_format($rb, 2)); ?></td>
                      <td class="vx-mono"><span style="color:rgba(34,197,94,1)"><?= h(number_format((float)($r['sum_in'] ?? 0),2)); ?></span> / <span style="color:rgba(239,68,68,1)"><?= h(number_format((float)($r['sum_out'] ?? 0),2)); ?></span></td>
                      <td class="vx-mono"><?= isset($r['reg']) ? date('Y-m-d H:i', (int)$r['reg']) : ''; ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- RISK -->
          <div class="vx-pane" id="pane-risk">
            <div class="vx-card">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-shield"></i> Risk</div><div class="vx-sub">Hashes + quick jump to cluster</div></div>
              </div>
              <div class="vx-bd">
                <?php if (!$has_ref_ip_hash && !$has_ref_ua_hash): ?>
                  <div class="vx-alert" style="border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)">Your db_users is missing ref_ip_hash/ref_ua_hash. Risk clustering won’t work.</div>
                <?php else: ?>
                  <div class="vx-row" style="justify-content:flex-start;gap:12px">
                    <?php if ($ref_ip_hash !== ''): ?>
                      <span class="vx-pill"><span style="opacity:.75">IP hash</span> <b class="vx-mono"><?= h(substr($ref_ip_hash, 0, 18)); ?>…</b></span>
                      <a class="vx-btn slim" href="/<?= h($adm); ?>/risk?kind=ip&k=<?= h($ref_ip_hash); ?>"><i class="fa fa-search"></i> View IP cluster</a>
                    <?php endif; ?>
                  </div>

                  <div class="vx-row" style="margin-top:10px;justify-content:flex-start;gap:12px">
                    <?php if ($ref_ua_hash !== ''): ?>
                      <span class="vx-pill"><span style="opacity:.75">UA hash</span> <b class="vx-mono"><?= h(substr($ref_ua_hash, 0, 18)); ?>…</b></span>
                      <a class="vx-btn slim" href="/<?= h($adm); ?>/risk?kind=ua&k=<?= h($ref_ua_hash); ?>"><i class="fa fa-search"></i> View UA cluster</a>
                    <?php endif; ?>
                  </div>

                  <div class="vx-row" style="margin-top:12px;justify-content:flex-start;gap:12px;flex-wrap:wrap">
                    <?= $ban ? '<span class="vx-pill bad">banned</span>' : '<span class="vx-pill ok">active</span>'; ?>
                    <?= $ref_lock ? '<span class="vx-pill wait">ref-lock</span>' : ''; ?>
                    <?= ($has_payout_lock && $payout_lock) ? '<span class="vx-pill bad">payout-lock</span>' : ''; ?>
                    <?= ($has_risk_tag && $watch) ? '<span class="vx-pill wait">watch</span>' : ''; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- NOTES -->
          <div class="vx-pane" id="pane-note">
            <div class="vx-card">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-sticky-note"></i> Notes</div><div class="vx-sub">Admin-only notes (saved to db_users.risk_note if exists)</div></div>
              </div>
              <div class="vx-bd">
                <?php if (!$has_risk_note): ?>
                  <div class="vx-alert" style="border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)">
                    Missing column <span class="vx-mono">db_users.risk_note</span>. Add it if you want notes on user profiles.
                  </div>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                    <input type="hidden" name="uid" value="<?= (int)$id; ?>">
                    <input type="hidden" name="action" value="save_note">
                    <label class="vx-lab">Note</label>
                    <textarea class="vx-in" name="note" rows="5" placeholder="Write investigation notes, reasons for locks, etc."><?= h($note); ?></textarea>
                    <div style="margin-top:10px">
                      <button class="vx-btn" type="submit"><i class="fa fa-save"></i> Save note</button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- REVIEWS -->
          <div class="vx-pane" id="pane-rev">
            <div class="vx-card">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-star"></i> Reviews</div><div class="vx-sub">Last reviews for this user</div></div>
              </div>
              <div class="vx-bd" style="overflow:auto">
                <table class="vx-table">
                  <thead>
                    <tr>
                      <th>User</th>
                      <th>Text</th>
                      <th style="width:140px">Reward</th>
                      <th style="width:190px">Date</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($reviews)): ?>
                    <tr><td colspan="4" style="opacity:.8">No reviews.</td></tr>
                  <?php else: foreach ($reviews as $b): ?>
                    <tr>
                      <td><a href="/<?= h($adm); ?>/users/info/<?= (int)$b['uid']; ?>" style="text-decoration:none;color:inherit"><?= h($b['login'] ?? ''); ?></a></td>
                      <td class="vx-mini"><?= h($b['text'] ?? ''); ?></td>
                      <td class="vx-mono"><?= h(number_format((float)($b['reward'] ?? 0), 2)); ?> <span class="vx-mini">{!VAL!}</span></td>
                      <td class="vx-mono"><?= isset($b['date']) ? date('Y-m-d H:i', (int)$b['date']) : ''; ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>

        <!-- Right: actions + profile edit -->
        <div>
          <div class="vx-actions">
            <div class="vx-row" style="justify-content:flex-start;gap:8px;margin-bottom:10px">
              <form method="post" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="uid" value="<?= (int)$id; ?>">
                <?php if ($ban): ?>
                  <button class="vx-btn" name="action" value="unban" type="submit"><i class="fa fa-user"></i> Unban</button>
                <?php else: ?>
                  <button class="vx-btn danger" name="action" value="ban" type="submit" onclick="return confirm('Ban this user?');"><i class="fa fa-ban"></i> Ban</button>
                <?php endif; ?>
              </form>

              <form method="post" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="uid" value="<?= (int)$id; ?>">
                <?php if ($ref_lock): ?>
                  <button class="vx-btn" name="action" value="ref_unlock" type="submit"><i class="fa fa-unlock"></i> Ref unlock</button>
                <?php else: ?>
                  <button class="vx-btn" name="action" value="ref_lock" type="submit"><i class="fa fa-lock"></i> Ref lock</button>
                <?php endif; ?>
              </form>

              <?php if ($has_payout_lock): ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="uid" value="<?= (int)$id; ?>">
                <?php if ($payout_lock): ?>
                  <button class="vx-btn" name="action" value="payout_unlock" type="submit"><i class="fa fa-unlock"></i> Payout unlock</button>
                <?php else: ?>
                  <button class="vx-btn danger" name="action" value="payout_lock" type="submit" onclick="return confirm('Payout lock this user?');"><i class="fa fa-lock"></i> Payout lock</button>
                <?php endif; ?>
              </form>
              <?php endif; ?>

              <?php if ($has_risk_tag): ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="uid" value="<?= (int)$id; ?>">
                <?php if ($watch): ?>
                  <button class="vx-btn" name="action" value="watch_off" type="submit"><i class="fa fa-eye-slash"></i> Watch off</button>
                <?php else: ?>
                  <button class="vx-btn" name="action" value="watch_on" type="submit"><i class="fa fa-eye"></i> Watch</button>
                <?php endif; ?>
              </form>
              <?php endif; ?>
            </div>

            <div class="vx-card" style="margin:0">
              <div class="vx-hd">
                <div><div class="vx-kicker"><i class="fa fa-edit"></i> Edit</div><div class="vx-sub">Update username/email/role</div></div>
              </div>
              <div class="vx-bd">
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                  <input type="hidden" name="uid" value="<?= (int)$id; ?>">
                  <input type="hidden" name="action" value="save_profile">

                  <label class="vx-lab">Username</label>
                  <input class="vx-in" type="text" name="loga" value="<?= h($login); ?>">

                  <div style="height:8px"></div>

                  <label class="vx-lab">Email</label>
                  <input class="vx-in" type="text" name="emal" value="<?= h($email); ?>">

                  <div style="height:8px"></div>

                  <label class="vx-lab">Role</label>
                  <input class="vx-in" type="text" name="role" value="<?= h($role); ?>">

                  <div class="vx-mini" style="margin-top:8px">Tip: keep role values consistent with your app logic.</div>

                  <div style="margin-top:10px">
                    <button class="vx-btn" type="submit"><i class="fa fa-save"></i> Save</button>
                  </div>
                </form>
              </div>
            </div>

          </div><!-- actions -->
        </div><!-- right -->
      </div><!-- grid -->
    </div><!-- bd -->
  </div><!-- card -->
</div><!-- wrap -->

<script>
(function(){
  function setTab(name){
    document.querySelectorAll('.vx-tab').forEach(b => b.classList.toggle('active', b.getAttribute('data-tab') === name));
    document.querySelectorAll('.vx-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-'+name));
    try { localStorage.setItem('vx_users_tab', name); } catch(e){}
  }
  document.querySelectorAll('.vx-tab').forEach(b => b.addEventListener('click', () => setTab(b.getAttribute('data-tab'))));
  try {
    var saved = localStorage.getItem('vx_users_tab');
    if (saved && document.getElementById('pane-'+saved)) setTab(saved);
  } catch(e){}
})();

function vxCopy(label, text){
  try{
    navigator.clipboard.writeText(String(text));
    alert('Copied: '+label);
  }catch(e){
    // fallback
    const ta = document.createElement('textarea');
    ta.value = String(text);
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    alert('Copied: '+label);
  }
}
</script>

<?php
return;
endif; // end info view

// ---------- LIST VIEW ----------
$q = trim((string)($_GET['q'] ?? ''));
$f = trim((string)($_GET['f'] ?? '')); // filters: banned|ref_lock|payout_lock|watch|new24h|nodep
$p = max(1, (int)($_GET['p'] ?? 1));
$per = 100;
$off = ($p - 1) * $per;

$where = [];
$args = [];

if ($q !== '') {
  // Search by uid/login/email/tg_id/start_param/rid
  $like = '%'.$q.'%';
  if (ctype_digit($q)) {
    $qid = (int)$q;
    $where[] = '(id = ? OR rid = ? '.($tg_id_col ? ' OR '.$tg_id_col.' = ? ' : '').')';
    $args[] = $qid; $args[] = $qid;
    if ($tg_id_col) $args[] = $qid;
  } else {
    $where[] = '(login LIKE ? OR email LIKE ? OR ref_start_param LIKE ?)';
    $args[] = $like; $args[] = $like; $args[] = $like;
    if ($has_tg_username) { $where[count($where)-1] = '(' . $where[count($where)-1] . ' OR tg_username LIKE ?)'; $args[] = $like; }
  }
}

if ($f !== '') {
  if ($f === 'banned') { $where[] = 'ban=1'; }
  else if ($f === 'ref_lock') { $where[] = 'rid_lock=1'; }
  else if ($f === 'payout_lock' && $has_payout_lock) { $where[] = 'payout_lock=1'; }
  else if ($f === 'watch' && $has_risk_tag) { $where[] = "risk_tag='watch'"; }
  else if ($f === 'new24h') { $where[] = 'reg >= ?'; $args[] = (now() - 86400); }
  else if ($f === 'nodep') { $where[] = 'sum_in <= 0'; }
}

$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$total = 0;
$rows = [];

try {
  $rt = $db->query("SELECT COUNT(*) AS c FROM db_users $wsql", ...$args)->fetchArray();
  $total = (int)($rt['c'] ?? 0);

  $sql = "SELECT id, login, email, ban, rid, rid_lock, sum_in, sum_out, money_b, money_p, reg, refsite, referer, ref_start_param"
    .($has_tg_username ? ", tg_username" : "")
    .($tg_id_col ? ", {$tg_id_col} AS telegram_id" : "")
    .($has_payout_lock ? ", payout_lock" : "")
    .($has_risk_tag ? ", risk_tag" : "")
    ." FROM db_users $wsql ORDER BY id DESC LIMIT $per OFFSET $off";

  $qq = $db->query($sql, ...$args);
  while ($r = $qq->fetchArray()) $rows[] = $r;
} catch (Throwable $e) {
  $flash = 'Query failed: '.$e->getMessage();
  $flash_kind = 'bad';
}

$pages = (int)ceil(max(1, $total) / $per);
$opt['title'] = 'Users';
?>

<div class="vx-wrap">
  <div class="vx-card">
    <div class="vx-hd">
      <div>
        <div class="vx-kicker"><i class="fa fa-users"></i> Users</div>
        <h1 class="vx-title">Users</h1>
        <div class="vx-sub">
          <span class="vx-pill"><span style="opacity:.75">total</span> <b><?= (int)$total; ?></b></span>
          <?php if ($has_payout_lock): ?><span class="vx-pill wait">payout_lock enabled</span><?php endif; ?>
          <?php if ($has_risk_tag): ?><span class="vx-pill wait">watchlist enabled</span><?php endif; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="vx-btn" href="/<?= h($adm); ?>/audit"><i class="fa fa-list"></i> Audit</a>
        <a class="vx-btn" href="/<?= h($adm); ?>/risk"><i class="fa fa-shield"></i> Risk</a>
      </div>
    </div>

    <div class="vx-bd">
      <?php if ($flash !== ''): ?>
        <div class="vx-alert" style="<?= $flash_kind === 'bad' ? 'border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)' : 'border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.08)'; ?>">
          <?= h($flash); ?>
        </div>
      <?php endif; ?>

      <div class="vx-row" style="margin-bottom:12px">
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;flex:1">
          <div style="min-width:280px;flex:1">
            <label class="vx-lab">Search</label>
            <input class="vx-in" name="q" value="<?= h($q); ?>" placeholder="uid / login / email / tg id / start_param / rid">
          </div>

          <div style="width:220px">
            <label class="vx-lab">Filter</label>
            <select class="vx-in" name="f">
              <option value="" <?= $f===''?'selected':''; ?>>All</option>
              <option value="banned" <?= $f==='banned'?'selected':''; ?>>Banned</option>
              <option value="ref_lock" <?= $f==='ref_lock'?'selected':''; ?>>Ref-lock</option>
              <?php if ($has_payout_lock): ?><option value="payout_lock" <?= $f==='payout_lock'?'selected':''; ?>>Payout-lock</option><?php endif; ?>
              <?php if ($has_risk_tag): ?><option value="watch" <?= $f==='watch'?'selected':''; ?>>Watchlist</option><?php endif; ?>
              <option value="new24h" <?= $f==='new24h'?'selected':''; ?>>New (24h)</option>
              <option value="nodep" <?= $f==='nodep'?'selected':''; ?>>No deposits</option>
            </select>
          </div>

          <button class="vx-btn" type="submit"><i class="fa fa-search"></i> Apply</button>
          <a class="vx-btn" href="/<?= h($adm); ?>/users"><i class="fa fa-undo"></i> Reset</a>
        </form>
      </div>

      <!-- Bulk actions -->
      <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
        <select name="action" class="vx-in" style="width:260px">
          <option value="">Bulk actions…</option>
          <option value="bulk_ban">Ban selected</option>
          <option value="bulk_unban">Unban selected</option>
          <option value="bulk_ref_lock">Ref-lock selected</option>
          <option value="bulk_ref_unlock">Ref-unlock selected</option>
          <?php if ($has_payout_lock): ?>
            <option value="bulk_payout_lock">Payout-lock selected</option>
            <option value="bulk_payout_unlock">Payout-unlock selected</option>
          <?php endif; ?>
          <?php if ($has_risk_tag): ?>
            <option value="bulk_watch_on">Watch ON selected</option>
            <option value="bulk_watch_off">Watch OFF selected</option>
          <?php endif; ?>
        </select>
        <button class="vx-btn danger" type="submit" onclick="return vxBulkConfirm();"><i class="fa fa-bolt"></i> Run</button>
        <span class="vx-mini">Tip: use ref-lock + payout-lock before ban.</span>

        <div style="flex:1"></div>

        <div class="vx-mini">Page <?= (int)$p; ?> / <?= (int)$pages; ?></div>
      </form>

      <div style="overflow:auto;border-radius:16px;border:1px solid rgba(255,255,255,.08)">
        <table class="vx-table">
          <thead>
            <tr>
              <th style="width:44px"><input type="checkbox" onclick="vxToggleAll(this)"></th>
              <th style="width:90px">ID</th>
              <th>User</th>
              <th style="width:180px">Balance</th>
              <th style="width:180px">IN/OUT</th>
              <th style="width:180px">Referer</th>
              <th style="width:220px">StartParam</th>
              <th style="width:190px">Joined</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" style="opacity:.8">No users found.</td></tr>
          <?php else: foreach ($rows as $u): ?>
            <?php
              $uid = (int)($u['id'] ?? 0);
              $ban = ((int)($u['ban'] ?? 0)) > 0;
              $rid_lock = ((int)($u['rid_lock'] ?? 0)) > 0;
              $p_lock = $has_payout_lock ? (((int)($u['payout_lock'] ?? 0)) > 0) : false;
              $watch = $has_risk_tag ? (((string)($u['risk_tag'] ?? '')) === 'watch') : false;

              $bal = ((float)($u['money_b'] ?? 0)) + ((float)($u['money_p'] ?? 0));
            ?>
            <tr>
              <td><input type="checkbox" class="vxRow" name="uids[]" value="<?= $uid; ?>"></td>
              <td class="vx-mono">#<?= $uid; ?></td>
              <td>
                <a href="/<?= h($adm); ?>/users/info/<?= $uid; ?>" style="text-decoration:none;color:inherit">
                  <?= $ban ? '<span style="color:rgba(255,120,120,1)">'.h($u['login'] ?? '').'</span>' : h($u['login'] ?? ''); ?>
                </a>
                <div class="vx-mini" style="margin-top:4px">
                  <?= $ban ? '<span class="vx-pill bad">banned</span>' : '<span class="vx-pill ok">active</span>'; ?>
                  <?= $rid_lock ? '<span class="vx-pill wait">ref-lock</span>' : ''; ?>
                  <?= ($has_payout_lock && $p_lock) ? '<span class="vx-pill bad">payout-lock</span>' : ''; ?>
                  <?= ($has_risk_tag && $watch) ? '<span class="vx-pill wait">watch</span>' : ''; ?>
                  <?php if ($has_tg_username && !empty($u['tg_username'])): ?><span class="vx-pill">@<?= h($u['tg_username']); ?></span><?php endif; ?>
                </div>
              </td>
              <td class="vx-mono"><?= h(number_format($bal, 2)); ?></td>
              <td class="vx-mono">
                <span style="color:rgba(34,197,94,1)"><?= h(number_format((float)($u['sum_in'] ?? 0),2)); ?></span>
                /
                <span style="color:rgba(239,68,68,1)"><?= h(number_format((float)($u['sum_out'] ?? 0),2)); ?></span>
              </td>
              <td class="vx-mini">
                <?php
                  $rid = (int)($u['rid'] ?? 0);
                  $referer = (string)($u['referer'] ?? '');
                ?>
                <?php if ($rid > 0): ?>
                  <a href="/<?= h($adm); ?>/users/info/<?= $rid; ?>" style="text-decoration:none;color:inherit">
                    <?= h($referer !== '' ? $referer : ('#'.$rid)); ?>
                  </a>
                <?php else: ?>
                  <span style="opacity:.8">-</span>
                <?php endif; ?>
              </td>
              <td class="vx-mono"><?= h((string)($u['ref_start_param'] ?? '')); ?></td>
              <td class="vx-mono"><?= isset($u['reg']) ? date('Y-m-d H:i', (int)$u['reg']) : ''; ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="vx-row" style="margin-top:12px">
        <div class="vx-mini">Showing <?= (int)count($rows); ?> / <?= (int)$total; ?></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php
            $base = ['q'=>$q, 'f'=>$f];
            if ($p > 1) {
              $prev = http_build_query(array_merge($base, ['p'=>$p-1]));
              echo '<a class="vx-btn" href="?'.h($prev).'"><i class="fa fa-chevron-left"></i> Prev</a>';
            }
            if ($p < $pages) {
              $next = http_build_query(array_merge($base, ['p'=>$p+1]));
              echo '<a class="vx-btn" href="?'.h($next).'">Next <i class="fa fa-chevron-right"></i></a>';
            }
          ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
function vxToggleAll(el){
  document.querySelectorAll('.vxRow').forEach(cb => cb.checked = !!el.checked);
}
function vxBulkConfirm(){
  const action = (document.querySelector('select[name="action"]') || {}).value || '';
  const checked = document.querySelectorAll('.vxRow:checked').length;
  if (!action) { alert('Pick a bulk action.'); return false; }
  if (!checked) { alert('Select at least one user.'); return false; }
  if (action === 'bulk_ban') return confirm('Ban selected users?');
  if (action === 'bulk_payout_lock') return confirm('Payout lock selected users?');
  return true;
}
</script>
