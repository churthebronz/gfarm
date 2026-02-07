<?php
// pages/profile.php
// Public profile card (shareable).

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $pg;

require_once __DIR__ . '/../core/vx_affiliate.php';
require_once __DIR__ . '/../core/seasons.php';
require_once __DIR__ . '/../core/vx_season_points.php';
require_once __DIR__ . '/../core/vx_retention.php';

$pid = 0;
try {
  $pid = (int)($pg->segment[1] ?? 0);
} catch (Throwable $e) {
  $pid = (int)($_GET['id'] ?? 0);
}

if ($pid <= 0) {
  http_response_code(404);
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profile not found</title></head><body style="font-family:system-ui;padding:18px;background:#070b14;color:#e5e7eb">Profile not found.</body></html>';
  exit;
}

$u = [];
try {
  $q = $db->query('SELECT id, login, tg_username, tg_name, ref_code, `add` FROM db_users WHERE id=? LIMIT 1', $pid);
  $u = $q ? ($q->fetchArray() ?: []) : [];
} catch (Throwable $e) { $u = []; }

if (empty($u)) {
  http_response_code(404);
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profile not found</title></head><body style="font-family:system-ui;padding:18px;background:#070b14;color:#e5e7eb">Profile not found.</body></html>';
  exit;
}

$name = '';
$tg = trim((string)($u['tg_username'] ?? ''));
if ($tg !== '') $name = '@' . ltrim($tg, '@');
if ($name === '') {
  $nm = trim((string)($u['tg_name'] ?? ''));
  $name = $nm !== '' ? $nm : trim((string)($u['login'] ?? 'User'));
}

// Season stats
$sx = [];
$sid = 0;
$seasonNo = 0;
try {
  $sx = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
  $sid = (int)($sx['id'] ?? 0);
  $seasonNo = (int)($sx['num'] ?? ($sx['no'] ?? 0));
} catch (Throwable $e) {}

$vp = 0; $lp = 0;
try {
  if ($sid > 0) {
    $r = $db->query('SELECT vp_total, lp_total FROM vx_season_points WHERE uid=? AND season_id=? LIMIT 1', $pid, $sid)->fetchArray();
    $vp = (int)($r['vp_total'] ?? 0);
    $lp = (int)($r['lp_total'] ?? 0);
  }
} catch (Throwable $e) {}

$qualifiedRefs = 0;
try { $qualifiedRefs = vx_affiliate_qualified_paid_refs($db, $pid); } catch (Throwable $e) {}

$refCode = trim((string)($u['ref_code'] ?? ''));
$inviteUrl = ($refCode !== '') ? ('/invite/'.$refCode) : '/';

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?> • GreenFarm Profile</title>
  <?php
    $base = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'greenfarm.lol');
    $ogUrl = $base . '/profile/' . (int)$pid;
    $ogImg = $base . '/share/profile_card.php?id=' . (int)$pid;
    $ogTitle = $name . ' • GreenFarm Profile';
    $ogDesc = 'Season ' . (int)$seasonNo . ' • VP ' . number_format((int)$vp) . ' • LP ' . number_format((int)$lp) . ' • Qualified refs ' . number_format((int)$qualifiedRefs);
  ?>
  <meta property="og:type" content="profile">
  <meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:image" content="<?= htmlspecialchars($ogImg, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImg, ENT_QUOTES, 'UTF-8'); ?>">
  <style>
    :root{--bg:#060816;--card:rgba(11,16,36,.92);--border:rgba(255,255,255,.10);--txt:#eaf0ff;--mut:rgba(234,240,255,.72);}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:
      radial-gradient(1100px 600px at -10% -10%, rgba(0,255,224,.10), transparent 40%),
      radial-gradient(1100px 600px at 110% 110%, rgba(88,166,255,.10), transparent 40%),
      var(--bg);color:var(--txt);}
    .wrap{max-width:820px;margin:0 auto;padding:18px;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:22px;padding:18px;box-shadow:0 24px 80px rgba(0,0,0,.45);}
    .top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
    .name{font-weight:950;font-size:22px;}
    .mut{margin-top:4px;color:var(--mut);}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px;}
    @media(min-width:720px){.grid{grid-template-columns:repeat(4,minmax(0,1fr));}}
    .stat{background:rgba(14,20,48,.55);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:12px;}
    .k{font-size:12px;opacity:.72}
    .v{margin-top:6px;font-weight:950;font-size:18px;}
    .btnrow{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:var(--txt);text-decoration:none;font-weight:900;}
    .btn.primary{background:linear-gradient(135deg, rgba(0,255,224,.16), rgba(88,166,255,.16));border-color:rgba(88,166,255,.22)}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="top">
        <div>
          <div class="name"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="mut">Season <?= (int)$seasonNo; ?> • Profile #<?= (int)$pid; ?></div>
        </div>
        <a class="btn" href="/leaderboards">🏆 Leaderboards</a>
      </div>

      <div class="grid">
        <div class="stat"><div class="k">Season VP</div><div class="v"><?= number_format($vp); ?></div></div>
        <div class="stat"><div class="k">Season LP</div><div class="v"><?= number_format($lp); ?></div></div>
        <div class="stat"><div class="k">Qualified referrals</div><div class="v"><?= number_format($qualifiedRefs); ?></div></div>
        <div class="stat"><div class="k">Invite link</div><div class="v" style="font-size:13px;font-weight:800;opacity:.9;word-break:break-all;"><?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8'); ?></div></div>
      </div>

      <div class="btnrow">
        <a class="btn primary" href="<?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8'); ?>">🎁 Claim a Founders Guardian</a>
        <a class="btn" href="/user/dashboard">🚀 Open Dashboard</a>
      </div>
    </div>
  </div>
</body>
</html>
