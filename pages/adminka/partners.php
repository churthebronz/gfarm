<?php
// pages/adminka/partners.php
// GreenFarm Admin — Partner / Ambassador guardians

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $adm;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['admin'])) {
  echo '<div class="alert alert-danger text-center">Admin access required.</div>';
  return;
}

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/vx_partners.php';

$opt['title'] = 'Admin • Partners';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$now = time();
$msg = '';
$ok = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $uid = (int)($_POST['uid'] ?? 0);
  $title = trim((string)($_POST['title'] ?? 'Ambassador Seed'));
  $vp = (int)($_POST['vp'] ?? 25);
  $lp = (int)($_POST['lp'] ?? 3);
  $res = vx_partner_grant($db, $uid, $now, $title, $vp, $lp);
  $ok = !empty($res['ok']);
  if ($ok && !empty($res['already'])) {
    $msg = 'Partner guardian already exists for this user.';
  } elseif ($ok) {
    $msg = 'Partner guardian granted.';
  } else {
    $msg = 'Failed: ' . h((string)($res['err'] ?? 'unknown'));
  }
}

$cap = (int)vx_app_setting('partner_guardian_cap', 50);
$cap = $cap < 1 ? 50 : $cap;
$total = vx_partner_total_granted($db);

?>

<style>
.vx-box{max-width:920px;margin:18px auto;padding:16px;border-radius:16px;border:1px solid rgba(255,255,255,.14);background:rgba(2,6,23,.24);}
.vx-title{font-weight:1000;margin:0 0 6px;}
.vx-sub{color:rgba(226,232,240,.78);margin:0 0 14px;}
.rowx{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
@media(max-width:900px){.rowx{grid-template-columns:1fr;}}
label{display:block;font-weight:900;margin-bottom:6px;}
input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:#eaf0ff;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,255,224,.26);background:linear-gradient(135deg,rgba(0,255,224,.18),rgba(88,166,255,.10));color:#eaf0ff;font-weight:950;text-decoration:none;cursor:pointer;}
.btn:hover{filter:brightness(1.08)}
.pill{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.22);font-weight:900;}
.alertx{margin-top:12px;padding:10px 12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);}
.ok{border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.10);}
.bad{border-color:rgba(239,68,68,.45);background:rgba(239,68,68,.10);}
</style>

<div class="vx-box">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <h1 class="vx-title">Partner / Ambassador Seeds</h1>
      <p class="vx-sub">Grant a special partner seed to influencers/partners. These are <b>always-on</b> point generators (VP + optional LP) not tied to deposits.</p>
    </div>
    <div>
      <span class="pill">Granted: <b><?= (int)$total; ?></b> / <?= (int)$cap; ?></span>
    </div>
  </div>

  <form method="post" action="">
    <div class="rowx">
      <div>
        <label>User UID</label>
        <input name="uid" type="number" min="1" required placeholder="e.g. 123" value="<?= isset($_POST['uid'])?h($_POST['uid']):''; ?>">
      </div>
      <div>
        <label>Title</label>
        <input name="title" type="text" placeholder="Ambassador Seed" value="<?= isset($_POST['title'])?h($_POST['title']):'Ambassador Seed'; ?>">
      </div>
      <div>
        <label>VP per day</label>
        <input name="vp" type="number" min="1" max="500" value="<?= isset($_POST['vp'])?h($_POST['vp']):'25'; ?>">
      </div>
      <div>
        <label>LP per day</label>
        <input name="lp" type="number" min="0" max="100" value="<?= isset($_POST['lp'])?h($_POST['lp']):'3'; ?>">
      </div>
    </div>
    <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button class="btn" type="submit"><i class="fa-solid fa-handshake"></i> Grant Partner Seed</button>
      <span class="vx-sub" style="margin:0">Tip: keep LP modest to protect the leaderboard economy.</span>
    </div>
  </form>

  <?php if ($msg !== ''): ?>
    <div class="alertx <?= $ok?'ok':'bad'; ?>"><?= $msg; ?></div>
  <?php endif; ?>

</div>
