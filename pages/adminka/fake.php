<?php
declare(strict_types=1);
if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm;

// ---- Admin gate ----
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['admin'])) {
  echo '<div class="alert alert-danger text-center">Admin access required.</div>';
  return;
}

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/seasons.php'; // ok if present

// ---- Helpers ----
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function now(): int { return time(); }
function valid_float($s): ?float {
  if (is_numeric($s)) return (float)$s;
  $s = str_replace([' ', ','], ['', '.'], (string)$s);
  return is_numeric($s) ? (float)$s : null;
}
function rand_txid(string $prefix='ADM'): string {
  try { return $prefix.'-'.bin2hex(random_bytes(10)); } catch (Throwable $e) { return $prefix.'-'.md5((string)microtime(true)); }
}
function client_ip(): string {
  $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
  if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
  return substr((string)$ip, 0, 64);
}
function client_ua(): string {
  return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

// ---- CSRF (uses your admin_ops.php if available; fallback if not) ----
$csrf = function_exists('vx_admin_csrf_token') ? vx_admin_csrf_token() : (
  $_SESSION['_csrf'] = $_SESSION['_csrf'] ?? bin2hex(random_bytes(16))
);
$csrf_ok = function($token) use ($csrf): bool {
  if (function_exists('vx_admin_csrf_ok')) return vx_admin_csrf_ok($token);
  return is_string($token) && hash_equals((string)($_SESSION['_csrf'] ?? ''), $token);
};

// ---- events_log writer (YOUR schema: user_id,event_type,ctx,ip,ua,created_at) ----
function admin_event($db, int $adminUid, string $type, array $ctx): void {
  $t = now();
  $ip = client_ip();
  $ua = client_ua();
  $payload = json_encode($ctx, JSON_UNESCAPED_SLASHES);
  try {
    $db->query(
      "INSERT INTO events_log (user_id, event_type, ctx, ip, ua, created_at)
       VALUES (?, ?, ?, ?, ?, ?)",
      $adminUid, $type, $payload, $ip, $ua, $t
    );
  } catch (Throwable $e) {
    // don't block admin action if audit insert fails
  }
}

// ---- Detect admin uid (best-effort) ----
$adminUid = (int)($_SESSION['admin_uid'] ?? $_SESSION['uid'] ?? 0);

// ---- Ensure seasons tables exist (safe) ----
try { if (function_exists('vx_seasons_ensure')) vx_seasons_ensure($db); } catch (Throwable $e) {}
$curSeason = [];
$curSeasonId = 0;
try {
  if (function_exists('vx_get_current_season')) {
    $curSeason = vx_get_current_season($db);
    $curSeasonId = (int)($curSeason['id'] ?? 0);
  }
} catch (Throwable $e) { $curSeasonId = 0; }

// ---- Pull currencies map from db_paysystem ----
$currencies = [];
try {
  $psRows = $db->query("SELECT currency, pairs FROM db_paysystem")->fetchAll();
  foreach ($psRows as $ps) {
    $code = (string)($ps['currency'] ?? '');
    if ($code !== '') $currencies[$code] = (float)($ps['pairs'] ?? 1);
  }
} catch (Throwable $e) {}
if (empty($currencies)) $currencies = ['USDT' => 1.0];

// ---- Pull plans for vault grants ----
$plans = [];
try {
  $plans = $db->query("SELECT id, title, price, speed, profit_speed, period FROM db_tarif ORDER BY id ASC")->fetchAll();
} catch (Throwable $e) { $plans = []; }

// ---- Messages ----
$flash_ok = '';
$flash_err = '';

// ======================================================
// ACTION HANDLER
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$csrf_ok($_POST['_csrf'] ?? null)) {
    $flash_err = 'Invalid CSRF token.';
  } else {
    $action = (string)($_POST['action'] ?? '');

    try {

      // 1) CREATE USER
      if ($action === 'create_user') {
        $login = trim((string)($_POST['login'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role  = (int)($_POST['role'] ?? 2); // default fake
        if ($login === '') $login = 'user'.rand(1000,9999);
        if ($email === '') $email = $login.'@example.com';

        // generate pass hash (compat-safe: use md5 string)
        $plain = (string)($_POST['pass_plain'] ?? '');
        if ($plain === '') $plain = substr(md5($login.microtime(true)), 0, 10);
        $pass = md5($plain);

        $t = now();
        $db->query(
          "INSERT INTO db_users (login, username, email, pass, reg, auth, ban, money_b, money_p, sum_in, sum_out, role)
           VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, ?)",
          $login, $login, $email, $pass, $t, $t, $role
        );
        $idRow = $db->query("SELECT LAST_INSERT_ID() AS id")->fetchArray();
        $uid = (int)($idRow['id'] ?? 0);

        admin_event($db, $adminUid, 'admin.create_user', [
          'target_uid' => $uid, 'login' => $login, 'role' => $role, 'pass_plain' => $plain
        ]);

        $flash_ok = "User created: <b>#{$uid}</b> / <b>".h($login)."</b> (role={$role}). Password: <b>".h($plain)."</b>";
      }

      // 2) ADJUST BALANCE
      if ($action === 'adjust_balance') {
        $uid  = (int)($_POST['uid'] ?? 0);
        $col  = (string)($_POST['wallet'] ?? 'money_p'); // money_p or money_b
        $mode = (string)($_POST['mode'] ?? 'add');       // add/sub
        $amt  = valid_float($_POST['amount'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));

        if ($uid <= 0 || !in_array($col, ['money_p','money_b'], true) || $amt === null || $amt <= 0) {
          throw new RuntimeException('Bad inputs.');
        }

        $sign = ($mode === 'sub') ? '-' : '+';
        $db->query("UPDATE db_users SET {$col} = {$col} {$sign} ?, money_p = money_p WHERE id = ? LIMIT 1", $amt, $uid);

        // keep money_b in sync? (your old code did money_b += too) -> we DO NOT force sync; you choose wallet.
        admin_event($db, $adminUid, 'admin.adjust_balance', [
          'target_uid'=>$uid,'wallet'=>$col,'mode'=>$mode,'amount'=>$amt,'note'=>$note
        ]);

        $flash_ok = "Balance updated for UID {$uid}: {$col} {$sign} ".number_format($amt,2);
      }

      // 3) ADJUST POINTS (points_total + points_spendable)
      if ($action === 'adjust_points') {
        $uid  = (int)($_POST['uid'] ?? 0);
        $mode = (string)($_POST['mode'] ?? 'add');
        $amt  = (int)($_POST['amount_points'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));

        if ($uid <= 0 || $amt <= 0) throw new RuntimeException('Bad inputs.');
        $sign = ($mode === 'sub') ? '-' : '+';

        // if subtract, clamp spendable to >=0
        if ($mode === 'sub') {
          $db->query(
            "UPDATE db_users
             SET points_spendable = GREATEST(0, points_spendable - ?),
                 points_total = GREATEST(0, points_total - ?)
             WHERE id=? LIMIT 1",
            $amt, $amt, $uid
          );
        } else {
          $db->query(
            "UPDATE db_users
             SET points_spendable = points_spendable + ?,
                 points_total = points_total + ?
             WHERE id=? LIMIT 1",
            $amt, $amt, $uid
          );
        }

        admin_event($db, $adminUid, 'admin.adjust_points', [
          'target_uid'=>$uid,'mode'=>$mode,'amount'=>$amt,'note'=>$note
        ]);

        $flash_ok = "Points updated for UID {$uid}: {$sign}{$amt}";
      }

      // 4) ADMIN DEPOSIT (real or showcase)
      if ($action === 'admin_deposit') {
        $uid  = (int)($_POST['uid'] ?? 0);
        $sum  = valid_float($_POST['sum'] ?? '');
        $cur  = (string)($_POST['currency'] ?? 'USDT');
        $kind = (string)($_POST['kind'] ?? 'showcase'); // real/showcase

        if ($uid <= 0 || $sum === null || $sum <= 0) throw new RuntimeException('Bad inputs.');
        if (!array_key_exists($cur, $currencies)) throw new RuntimeException('Invalid currency.');

        $pairs = (float)$currencies[$cur];
        $sum_x = round($sum * $pairs, 2);

        $u = $db->query("SELECT id, login, role FROM db_users WHERE id=? LIMIT 1", $uid)->fetchArray();
        if (!$u) throw new RuntimeException('User not found.');

        $t = now();
        $role = (int)($u['role'] ?? 0);
        $insertRole = ($kind === 'showcase') ? 2 : $role;

        // credit user
        $db->query("UPDATE db_users SET sum_in = sum_in + ?, money_p = money_p + ? WHERE id=? LIMIT 1", $sum_x, $sum_x, $uid);

        // insert deposit record (status=1 complete)
        $db->query(
          "INSERT INTO db_insert (uid, login, sum, sum_x, sys, type, status, ref_credited, role, `add`, `end`)
           VALUES (?, ?, ?, ?, ?, 1, 1, 0, ?, ?, ?)",
          $uid, (string)$u['login'], $sum_x, $sum_x, $cur, $insertRole, $t, $t
        );

        // stats
        $db->query("UPDATE db_stats SET inserts = inserts + ? WHERE id=1", $sum_x);

        admin_event($db, $adminUid, 'admin.deposit', [
          'target_uid'=>$uid,'login'=>(string)$u['login'],'kind'=>$kind,'currency'=>$cur,'amount_in'=>$sum,'amount_x'=>$sum_x
        ]);

        $flash_ok = "Deposit added to UID {$uid}: <b>$".number_format($sum_x,2)."</b> ({$cur}) • kind={$kind}";
      }

      // 5) ADMIN WITHDRAWAL (real or showcase)
      if ($action === 'admin_withdraw') {
        $uid    = (int)($_POST['uid'] ?? 0);
        $sum    = valid_float($_POST['sum'] ?? '');
        $status = (int)($_POST['status'] ?? 3); // 0/1 wait,2 cancel,3 success
        $kind   = (string)($_POST['kind'] ?? 'showcase'); // real/showcase
        $allow_negative = !empty($_POST['allow_negative']);

        if ($uid <= 0 || $sum === null || $sum <= 0) throw new RuntimeException('Bad inputs.');
        if (!in_array($status, [0,1,2,3], true)) $status = 3;

        $u = $db->query("SELECT id, login, role, money_p FROM db_users WHERE id=? LIMIT 1", $uid)->fetchArray();
        if (!$u) throw new RuntimeException('User not found.');

        $bal = (float)($u['money_p'] ?? 0);
        if (!$allow_negative && $bal < $sum) throw new RuntimeException('Not enough money_p (disable clamp to allow negative).');

        $t = now();
        $txid = rand_txid('WD');
        $sys = ($kind === 'showcase') ? 'SHOWCASE' : 'ADMIN';

        // deduct
        $db->query("UPDATE db_users SET sum_out = sum_out + ?, money_p = money_p - ? WHERE id=? LIMIT 1", $sum, $sum, $uid);

        // payout log
        $db->query(
          "INSERT INTO db_payout (uid, login, purse, sum, sum2, status, sys, psys, `add`, `del`, txid, paid_at)
           VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          $uid, (string)$u['login'], $sum, $sum, $status, $sys, $sys, $t, $t, $txid, ($status===3 ? $t : 0)
        );

        // stats
        if ($status === 3) $db->query("UPDATE db_stats SET payments = payments + ? WHERE id=1", $sum);

        admin_event($db, $adminUid, 'admin.withdraw', [
          'target_uid'=>$uid,'login'=>(string)$u['login'],'kind'=>$kind,'amount'=>$sum,'status'=>$status,'txid'=>$txid
        ]);

        $flash_ok = "Withdrawal added for UID {$uid}: <b>$".number_format($sum,2)."</b> • status={$status} • kind={$kind}";
      }

      // 6) GRANT VAULT (db_store)
      if ($action === 'grant_vault') {
        $uid = (int)($_POST['uid'] ?? 0);
        $tarif = (int)($_POST['tarif_id'] ?? 0);
        $force_days = (int)($_POST['days'] ?? 0);

        if ($uid <= 0 || $tarif <= 0) throw new RuntimeException('Bad inputs.');

        $u = $db->query("SELECT id, login FROM db_users WHERE id=? LIMIT 1", $uid)->fetchArray();
        if (!$u) throw new RuntimeException('User not found.');

        $plan = $db->query("SELECT id, title, price, speed, profit_speed, period FROM db_tarif WHERE id=? LIMIT 1", $tarif)->fetchArray();
        if (!$plan) throw new RuntimeException('Plan not found.');

        $t = now();
        $title = (string)($plan['title'] ?? ('Tarif '.$tarif));
        $price = (float)($plan['price'] ?? 0);
        $speed = (float)($plan['speed'] ?? 0);
        $hashp = (float)($plan['profit_speed'] ?? $speed);

        $period = (int)($plan['period'] ?? 0);
        if ($force_days > 0) $period = $force_days;
        if ($period <= 0) $period = 30;

        $end = $t + ($period * 86400);

        $db->query(
          "INSERT INTO db_store (uid, title, tarif, hashpower, speed, status, `add`, `end`, `last`, season_id, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))",
          $uid, $title, $tarif, $hashp, $speed, $t, $end, $t, $curSeasonId, $t, $t
        );

        admin_event($db, $adminUid, 'admin.grant_vault', [
          'target_uid'=>$uid,'login'=>(string)$u['login'],'tarif'=>$tarif,'title'=>$title,'days'=>$period,'season_id'=>$curSeasonId
        ]);

        $flash_ok = "Seed granted to UID {$uid}: <b>".h($title)."</b> for {$period} days (season_id={$curSeasonId}).";
      }

      // 7) IMPERSONATE
      if ($action === 'impersonate') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid <= 0) throw new RuntimeException('Bad UID.');

        $u = $db->query("SELECT id, login FROM db_users WHERE id=? LIMIT 1", $uid)->fetchArray();
        if (!$u) throw new RuntimeException('User not found.');

        $_SESSION['impersonated_admin'] = $_SESSION['admin'] ?? 1;
        $_SESSION['impersonated_admin_uid'] = $adminUid;
        $_SESSION['impersonated_at'] = now();
        $_SESSION['impersonating'] = 1;

        // store original user session, then override
        $_SESSION['__orig_uid'] = $_SESSION['uid'] ?? 0;
        $_SESSION['__orig_login'] = $_SESSION['login'] ?? '';

        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['login'] = (string)$u['login'];

        admin_event($db, $adminUid, 'admin.impersonate', ['target_uid'=>$uid,'login'=>(string)$u['login']]);

        header('Location: /user/dashboard', true, 302);
        exit;
      }

      // 8) RETURN TO ADMIN (call manually by visiting this page after leaving impersonation)
      if ($action === 'return_admin') {
        if (!empty($_SESSION['impersonating'])) {
          // restore original
          if (isset($_SESSION['__orig_uid'])) $_SESSION['uid'] = (int)$_SESSION['__orig_uid'];
          if (isset($_SESSION['__orig_login'])) $_SESSION['login'] = (string)$_SESSION['__orig_login'];

          unset($_SESSION['impersonating'], $_SESSION['impersonated_admin'], $_SESSION['impersonated_admin_uid'], $_SESSION['impersonated_at']);
          unset($_SESSION['__orig_uid'], $_SESSION['__orig_login']);

          $flash_ok = "Returned to admin session.";
        } else {
          $flash_ok = "Not impersonating.";
        }
      }

    } catch (Throwable $e) {
      $flash_err = 'Action failed: '.h($e->getMessage());
    }
  }
}

// ======================================================
// DATA FOR UI
// ======================================================
$search = trim((string)($_GET['u'] ?? ''));
$users = [];
try {
  if ($search !== '') {
    $users = $db->query("SELECT id, login, role, ban, money_p, money_b, points_spendable, sum_in, sum_out
                         FROM db_users
                         WHERE login LIKE ?
                         ORDER BY id DESC LIMIT 100", '%'.$search.'%')->fetchAll();
  } else {
    $users = $db->query("SELECT id, login, role, ban, money_p, money_b, points_spendable, sum_in, sum_out
                         FROM db_users
                         ORDER BY id DESC LIMIT 100")->fetchAll();
  }
} catch (Throwable $e) { $users = []; }

$fakes = [];
try {
  $fakes = $db->query("SELECT id, login, sum_in, sum_out, money_p, points_spendable
                       FROM db_users
                       WHERE role=2
                       ORDER BY sum_in DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) { $fakes = []; }

$recentIn = [];
try { $recentIn = $db->query("SELECT id, uid, login, sum, sys, `add`, role FROM db_insert ORDER BY id DESC LIMIT 15")->fetchAll(); } catch (Throwable $e) {}
$recentOut = [];
try { $recentOut = $db->query("SELECT id, uid, login, sum, status, sys, `add`, txid FROM db_payout ORDER BY id DESC LIMIT 15")->fetchAll(); } catch (Throwable $e) {}

$impersonating = !empty($_SESSION['impersonating']);

?>
<style>
.vx-sim{max-width:1400px;margin:0 auto;padding:10px}
.vx-sim .vx-hd{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;margin-bottom:10px}
.vx-sim .vx-title{margin:0;font-size:22px;font-weight:800;letter-spacing:.2px}
.vx-sim .vx-sub{opacity:.75;margin-top:4px}
.vx-sim .vx-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:10px}
@media(max-width:1100px){.vx-sim .vx-grid{grid-template-columns:1fr}}
.vx-cardx{background:rgba(15,18,28,.92);border:1px solid rgba(255,255,255,.08);border-radius:14px;overflow:hidden}
.vx-cardx .hd{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between;gap:10px}
.vx-cardx .hd b{font-size:13px;text-transform:uppercase;letter-spacing:.08em;opacity:.9}
.vx-cardx .bd{padding:12px}
.vx-row{display:flex;gap:10px;flex-wrap:wrap}
.vx-in{width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;outline:none}
.vx-in:focus{border-color:rgba(255,255,255,.22)}
.vx-lab{font-size:12px;opacity:.8;margin:0 0 6px 2px}
.vx-btnx{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);
background:rgba(255,255,255,.06);color:#fff;text-decoration:none;cursor:pointer;font-weight:700}
.vx-btnx:hover{background:rgba(255,255,255,.10)}
.vx-btnx.ok{background:rgba(46,204,113,.15);border-color:rgba(46,204,113,.35)}
.vx-btnx.danger{background:rgba(231,76,60,.14);border-color:rgba(231,76,60,.30)}
.vx-pill{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:800;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06)}
.vx-pill.bad{border-color:rgba(231,76,60,.35);background:rgba(231,76,60,.14)}
.vx-pill.ok{border-color:rgba(46,204,113,.35);background:rgba(46,204,113,.14)}
.vx-mini{font-size:12px;opacity:.75}
.vx-tablex{width:100%;border-collapse:separate;border-spacing:0 8px}
.vx-tablex th{font-size:12px;opacity:.75;text-align:left;padding:0 8px}
.vx-tablex td{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);padding:10px 8px}
.vx-tablex td:first-child{border-radius:12px 0 0 12px}
.vx-tablex td:last-child{border-radius:0 12px 12px 0}
.vx-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.vx-alertx{padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);margin-bottom:10px}
.vx-alertx.ok{border-color:rgba(46,204,113,.35);background:rgba(46,204,113,.14)}
.vx-alertx.bad{border-color:rgba(231,76,60,.35);background:rgba(231,76,60,.14)}
</style>

<div class="vx-sim">
  <div class="vx-hd">
    <div>
      <div class="vx-pill"><i class="fa fa-magic"></i>&nbsp;Simulator</div>
      <div class="vx-title">Admin Ops: Users • Money • Points • Deposits • Withdrawals • Seeds</div>
      <div class="vx-sub">Everything here writes proper rows into logs/tables so it looks real in UI (ticker/audit).</div>
    </div>
    <div class="vx-row">
      <?php if ($impersonating): ?>
        <form method="post" class="m-0">
          <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
          <input type="hidden" name="action" value="return_admin">
          <button class="vx-btnx danger" type="submit"><i class="fa fa-undo"></i> Return to admin</button>
        </form>
      <?php endif; ?>
      <a class="vx-btnx" href="/<?=h($adm)?>/audit"><i class="fa fa-list"></i> Audit</a>
      <a class="vx-btnx" href="/<?=h($adm)?>/risk"><i class="fa fa-shield"></i> Risk</a>
    </div>
  </div>

  <?php if ($flash_ok !== ''): ?><div class="vx-alertx ok"><?= $flash_ok; ?></div><?php endif; ?>
  <?php if ($flash_err !== ''): ?><div class="vx-alertx bad"><?= $flash_err; ?></div><?php endif; ?>

  <div class="vx-grid">

    <!-- LEFT: Power Actions -->
    <div class="vx-cardx">
      <div class="hd">
        <b>Power actions</b>
        <span class="vx-mini">Season: <?= $curSeasonId ? ('#'.(int)$curSeasonId) : 'none'; ?></span>
      </div>
      <div class="bd">

        <div class="vx-row">

          <!-- Create user -->
          <div style="flex:1;min-width:280px" class="vx-cardx">
            <div class="hd"><b>Create user</b><span class="vx-mini">real or fake</span></div>
            <div class="bd">
              <form method="post" class="m-0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="vx-lab">Username</div>
                <input class="vx-in" name="login" placeholder="e.g. WhaleBoss" value="">
                <div class="vx-row" style="margin-top:8px">
                  <div style="flex:1;min-width:180px">
                    <div class="vx-lab">Email</div>
                    <input class="vx-in" name="email" placeholder="optional">
                  </div>
                  <div style="width:160px">
                    <div class="vx-lab">Role</div>
                    <select class="vx-in" name="role">
                      <option value="2">2 (fake/showcase)</option>
                      <option value="0">0 (real)</option>
                      <option value="1">1</option>
                    </select>
                  </div>
                </div>
                <div class="vx-lab" style="margin-top:8px">Password (shown once)</div>
                <input class="vx-in" name="pass_plain" placeholder="optional (auto if empty)">
                <button class="vx-btnx ok" style="margin-top:10px;width:100%;justify-content:center" type="submit">
                  <i class="fa fa-user-plus"></i> Create
                </button>
              </form>
            </div>
          </div>

          <!-- Deposit / Withdraw -->
          <div style="flex:1;min-width:280px" class="vx-cardx">
            <div class="hd"><b>Deposits / withdrawals</b><span class="vx-mini">real or showcase</span></div>
            <div class="bd">
              <form method="post" class="m-0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <div class="vx-row">
                  <div style="flex:1;min-width:210px">
                    <div class="vx-lab">Target UID</div>
                    <input class="vx-in" name="uid" type="number" min="1" placeholder="e.g. 12">
                  </div>
                  <div style="width:170px">
                    <div class="vx-lab">Kind</div>
                    <select class="vx-in" name="kind">
                      <option value="showcase">showcase</option>
                      <option value="real">real</option>
                    </select>
                  </div>
                </div>

                <div class="vx-row" style="margin-top:8px">
                  <div style="flex:1;min-width:160px">
                    <div class="vx-lab">Amount</div>
                    <input class="vx-in" name="sum" value="10">
                  </div>
                  <div style="width:170px">
                    <div class="vx-lab">Currency</div>
                    <select class="vx-in" name="currency">
                      <?php foreach ($currencies as $code => $pairs): ?>
                        <option value="<?=h($code)?>"><?=h($code)?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="vx-row" style="margin-top:10px">
                  <button class="vx-btnx ok" style="flex:1;justify-content:center" type="submit"
                          name="action" value="admin_deposit">
                    <i class="fa fa-arrow-down"></i> Add deposit
                  </button>

                  <button class="vx-btnx danger" style="flex:1;justify-content:center" type="submit"
                          name="action" value="admin_withdraw">
                    <i class="fa fa-arrow-up"></i> Add withdrawal
                  </button>
                </div>

                <div class="vx-row" style="margin-top:8px">
                  <div style="width:180px">
                    <div class="vx-lab">Withdrawal status</div>
                    <select class="vx-in" name="status">
                      <option value="3">3 success</option>
                      <option value="1">1 wait</option>
                      <option value="0">0 wait</option>
                      <option value="2">2 cancel</option>
                    </select>
                  </div>
                  <label class="vx-mini" style="display:flex;align-items:center;gap:8px;opacity:.9;margin-top:22px">
                    <input type="checkbox" name="allow_negative" value="1"> allow negative (money_p)
                  </label>
                </div>

              </form>
            </div>
          </div>

        </div>

        <!-- Adjust balance / points / grant vault -->
        <div class="vx-row" style="margin-top:10px">

          <div style="flex:1;min-width:280px" class="vx-cardx">
            <div class="hd"><b>Adjust wallet</b><span class="vx-mini">money_p / money_b</span></div>
            <div class="bd">
              <form method="post" class="m-0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="action" value="adjust_balance">
                <div class="vx-row">
                  <div style="width:140px">
                    <div class="vx-lab">UID</div>
                    <input class="vx-in" name="uid" type="number" min="1" placeholder="12">
                  </div>
                  <div style="width:170px">
                    <div class="vx-lab">Wallet</div>
                    <select class="vx-in" name="wallet">
                      <option value="money_p">money_p</option>
                      <option value="money_b">money_b</option>
                    </select>
                  </div>
                  <div style="width:150px">
                    <div class="vx-lab">Mode</div>
                    <select class="vx-in" name="mode">
                      <option value="add">add</option>
                      <option value="sub">sub</option>
                    </select>
                  </div>
                </div>
                <div class="vx-row" style="margin-top:8px">
                  <div style="flex:1;min-width:180px">
                    <div class="vx-lab">Amount</div>
                    <input class="vx-in" name="amount" value="50">
                  </div>
                  <div style="flex:1;min-width:180px">
                    <div class="vx-lab">Note</div>
                    <input class="vx-in" name="note" placeholder="optional reason">
                  </div>
                </div>
                <button class="vx-btnx" style="margin-top:10px;width:100%;justify-content:center" type="submit">
                  <i class="fa fa-bolt"></i> Apply
                </button>
              </form>
            </div>
          </div>

          <div style="flex:1;min-width:280px" class="vx-cardx">
            <div class="hd"><b>Adjust points</b><span class="vx-mini">points_total + spendable</span></div>
            <div class="bd">
              <form method="post" class="m-0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="action" value="adjust_points">
                <div class="vx-row">
                  <div style="width:140px">
                    <div class="vx-lab">UID</div>
                    <input class="vx-in" name="uid" type="number" min="1" placeholder="12">
                  </div>
                  <div style="width:150px">
                    <div class="vx-lab">Mode</div>
                    <select class="vx-in" name="mode">
                      <option value="add">add</option>
                      <option value="sub">sub</option>
                    </select>
                  </div>
                  <div style="flex:1;min-width:180px">
                    <div class="vx-lab">Points</div>
                    <input class="vx-in" name="amount_points" type="number" min="1" value="100">
                  </div>
                </div>
                <div class="vx-lab" style="margin-top:8px">Note</div>
                <input class="vx-in" name="note" placeholder="optional reason">
                <button class="vx-btnx" style="margin-top:10px;width:100%;justify-content:center" type="submit">
                  <i class="fa fa-star"></i> Apply
                </button>
              </form>
            </div>
          </div>

          <div style="flex:1;min-width:280px" class="vx-cardx">
            <div class="hd"><b>Give vault</b><span class="vx-mini">inserts db_store</span></div>
            <div class="bd">
              <form method="post" class="m-0">
                <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                <input type="hidden" name="action" value="grant_vault">
                <div class="vx-row">
                  <div style="width:140px">
                    <div class="vx-lab">UID</div>
                    <input class="vx-in" name="uid" type="number" min="1" placeholder="12">
                  </div>
                  <div style="flex:1;min-width:220px">
                    <div class="vx-lab">Plan</div>
                    <select class="vx-in" name="tarif_id">
                      <?php foreach ($plans as $p):
                        $pid=(int)($p['id']??0); $pt=(string)($p['title']??''); $pr=(float)($p['price']??0);
                      ?>
                        <option value="<?= $pid; ?>">#<?= $pid; ?> • <?= h($pt); ?> ($<?= number_format($pr,2); ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="vx-lab" style="margin-top:8px">Force days (optional)</div>
                <input class="vx-in" name="days" type="number" min="0" placeholder="leave empty = plan period">
                <button class="vx-btnx ok" style="margin-top:10px;width:100%;justify-content:center" type="submit">
                  <i class="fa fa-gift"></i> Grant vault
                </button>
              </form>
            </div>
          </div>

        </div>

      </div>
    </div>

    <!-- RIGHT: Search + recent logs -->
    <div class="vx-cardx">
      <div class="hd">
        <b>Users</b>
        <span class="vx-mini"><?= $impersonating ? '<span class="vx-pill bad">impersonating</span>' : ''; ?></span>
      </div>
      <div class="bd">

        <form method="get" class="vx-row" style="margin-bottom:10px">
          <input class="vx-in" style="flex:1;min-width:220px" name="u" value="<?= h($search); ?>" placeholder="Search username…">
          <button class="vx-btnx" type="submit"><i class="fa fa-search"></i> Search</button>
        </form>

        <div style="overflow:auto">
          <table class="vx-tablex">
            <thead>
              <tr>
                <th style="width:80px">UID</th>
                <th>User</th>
                <th style="width:90px">Role</th>
                <th style="width:110px">money_p</th>
                <th style="width:110px">money_b</th>
                <th style="width:120px">points</th>
                <th style="width:90px">IN</th>
                <th style="width:90px">OUT</th>
                <th style="width:120px">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr><td colspan="9" class="vx-mini">No users found.</td></tr>
              <?php else: foreach ($users as $u):
                $uid=(int)($u['id']??0);
                $ban=(int)($u['ban']??0)===1;
                $role=(int)($u['role']??0);
              ?>
                <tr>
                  <td class="vx-mono"><?= $uid; ?></td>
                  <td>
                    <div style="display:flex;align-items:center;gap:8px">
                      <div style="font-weight:800"><?= h($u['login'] ?? ''); ?></div>
                      <?= $ban ? '<span class="vx-pill bad">ban</span>' : '<span class="vx-pill ok">ok</span>'; ?>
                    </div>
                  </td>
                  <td class="vx-mono"><?= $role; ?></td>
                  <td class="vx-mono"><?= number_format((float)($u['money_p']??0),2); ?></td>
                  <td class="vx-mono"><?= number_format((float)($u['money_b']??0),2); ?></td>
                  <td class="vx-mono"><?= (int)($u['points_spendable']??0); ?></td>
                  <td class="vx-mono"><?= number_format((float)($u['sum_in']??0),2); ?></td>
                  <td class="vx-mono"><?= number_format((float)($u['sum_out']??0),2); ?></td>
                  <td>
                    <div class="vx-row" style="gap:6px">
                      <a class="vx-btnx" href="/<?=h($adm)?>/users/info/<?= $uid; ?>" style="padding:8px 10px"><i class="fa fa-user"></i></a>
                      <a class="vx-btnx" href="/<?=h($adm)?>/audit?uid=<?= $uid; ?>" style="padding:8px 10px"><i class="fa fa-list"></i></a>
                      <form method="post" class="m-0">
                        <input type="hidden" name="_csrf" value="<?= h($csrf); ?>">
                        <input type="hidden" name="action" value="impersonate">
                        <input type="hidden" name="uid" value="<?= $uid; ?>">
                        <button class="vx-btnx danger" type="submit" style="padding:8px 10px" onclick="return confirm('Impersonate this user?');">
                          <i class="fa fa-sign-in"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="vx-row" style="margin-top:10px">
          <div style="flex:1;min-width:260px" class="vx-cardx">
            <div class="hd"><b>Recent deposits</b><span class="vx-mini">db_insert</span></div>
            <div class="bd" style="max-height:260px;overflow:auto">
              <?php if (empty($recentIn)): ?>
                <div class="vx-mini">No rows.</div>
              <?php else: foreach ($recentIn as $r): ?>
                <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed rgba(255,255,255,.08)">
                  <div class="vx-mini">
                    <span class="vx-mono">#<?= (int)$r['id']; ?></span>
                    • UID <span class="vx-mono"><?= (int)$r['uid']; ?></span>
                    • <b><?= h($r['login'] ?? ''); ?></b>
                    • <span class="vx-pill"><?= h($r['sys'] ?? ''); ?></span>
                    <?= ((int)($r['role']??0)===2) ? '<span class="vx-pill">showcase</span>' : ''; ?>
                  </div>
                  <div class="vx-mono" style="font-weight:900">$<?= number_format((float)($r['sum']??0),2); ?></div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>

          <div style="flex:1;min-width:260px" class="vx-cardx">
            <div class="hd"><b>Recent withdrawals</b><span class="vx-mini">db_payout</span></div>
            <div class="bd" style="max-height:260px;overflow:auto">
              <?php if (empty($recentOut)): ?>
                <div class="vx-mini">No rows.</div>
              <?php else: foreach ($recentOut as $r): ?>
                <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed rgba(255,255,255,.08)">
                  <div class="vx-mini">
                    <span class="vx-mono">#<?= (int)$r['id']; ?></span>
                    • UID <span class="vx-mono"><?= (int)$r['uid']; ?></span>
                    • <b><?= h($r['login'] ?? ''); ?></b>
                    • st <span class="vx-mono"><?= (int)($r['status']??0); ?></span>
                    • <span class="vx-pill"><?= h($r['sys'] ?? ''); ?></span>
                  </div>
                  <div class="vx-mono" style="font-weight:900">$<?= number_format((float)($r['sum']??0),2); ?></div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>
