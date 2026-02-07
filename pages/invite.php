<?php
declare(strict_types=1);

if (!defined('FastCore')) { define('FastCore', true); }

global $db, $config, $opt;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

require_once __DIR__ . '/../core/vx_app_settings.php';

$opt['title'] = 'GreenFarm Invite';

$code = trim((string)($pg->segment[1] ?? ''));
$code = preg_replace('/[^A-Za-z0-9]/', '', $code);

$bot = 'GreenFarmAppBot';
try {
  $bot = (string)(vx_app_setting('telegram_bot', $config->telegram_bot ?? 'GreenFarmAppBot'));
} catch (Throwable $e) {
  $bot = (string)($config->telegram_bot ?? 'GreenFarmAppBot');
}
$bot = ltrim($bot, '@');

$deep = 'https://t.me/' . rawurlencode($bot);
if ($code !== '') {
  $deep .= '?start=' . rawurlencode('ref_' . $code);
}

// Also set vx_start_param for non-TG browsers so the user can click "Open in Telegram" and still keep it.
try {
  if ($code !== '') {
    $_SESSION['vx_start_param'] = $code;
    setcookie('vx_start_param', $code, time()+86400*30, '/', '', isset($_SERVER['HTTPS']), true);
  }
} catch (Throwable $e) {}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars((string)($opt['title'] ?? 'GreenFarm Invite'), ENT_QUOTES, 'UTF-8'); ?></title>
  <meta name="description" content="GreenFarm referral invite. Claim your Founder rewards and start earning.">

  <!-- Social preview (Telegram/Discord/Twitter) -->
  <?php
    $base = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'greenfarm.lol');
    $ogUrl = $base . ($code !== '' ? ('/invite/' . $code) : '/invite');
    $ogImg = $base . '/share/invite_card.php' . ($code !== '' ? ('?ref=' . rawurlencode($code)) : '');
    $ogTitle = $code !== '' ? 'You\'ve been invited to GreenFarm' : 'GreenFarm is ready';
    $ogDesc = $code !== '' ? 'A Founders Guardian is waiting for you. Tap to open GreenFarm in Telegram.' : 'Open GreenFarm in Telegram to access your dashboard, rewards, and guardians.';
  ?>
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:image" content="<?= htmlspecialchars($ogImg, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImg, ENT_QUOTES, 'UTF-8'); ?>">
  <style>
    :root{--bg:#050914;--card:rgba(15,23,42,.72);--bd:rgba(255,255,255,.10);--tx:#eaf0ff;--mut:#a7b3d6;--acc:#00f3ff;}
    body{margin:0;min-height:100vh;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:radial-gradient(1200px 700px at 20% -10%, rgba(0,243,255,.14), transparent 55%),radial-gradient(900px 600px at 90% 0%, rgba(124,92,255,.12), transparent 55%),var(--bg);color:var(--tx)}
    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:18px}
    .card{max-width:560px;width:100%;background:var(--card);border:1px solid var(--bd);border-radius:20px;padding:18px 18px 16px;box-shadow:0 18px 60px rgba(0,0,0,.45)}
    .logo{display:flex;align-items:center;gap:12px;margin-bottom:10px}
    .mark{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg, rgba(0,243,255,.25), rgba(124,92,255,.22));border:1px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-weight:900}
    h1{margin:0;font-size:22px;font-weight:900;letter-spacing:.2px}
    p{margin:10px 0 0;color:var(--mut);line-height:1.55}
    .cta{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    a.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;color:#03121a;font-weight:900;background:linear-gradient(135deg, rgba(0,243,255,1), rgba(124,92,255,1));padding:12px 14px;border-radius:14px;min-width:210px}
    a.btn.secondary{background:rgba(255,255,255,.08);color:var(--tx);border:1px solid rgba(255,255,255,.10);font-weight:800;min-width:210px}
    .fine{margin-top:12px;font-size:12px;opacity:.7}
    .chip{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.10);font-size:12px;color:var(--mut);margin-top:10px}
    code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="logo">
        <div class="mark">V</div>
        <div>
          <h1><?= $code !== '' ? '🎉 You’ve been invited to GreenFarm' : '✨ GreenFarm is ready'; ?></h1>
          <div class="chip">Telegram Mini App • Earn VP/LP • Guardians</div>
        </div>
      </div>

      <?php if ($code !== ''): ?>
        <p>A Founders Guardian is waiting for you. Tap below to open GreenFarm in Telegram and claim your rewards.</p>
        <p class="fine">Invite code: <code><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></code></p>
      <?php else: ?>
        <p>Open GreenFarm in Telegram to access your dashboard, rewards, and guardians.</p>
      <?php endif; ?>

      <div class="cta">
        <a class="btn" href="<?= htmlspecialchars($deep, ENT_QUOTES, 'UTF-8'); ?>">🚀 Open in Telegram</a>
        <a class="btn secondary" href="/">View website</a>
      </div>

      <div class="fine">If you’re on mobile, Telegram will open automatically. On desktop, make sure Telegram Desktop is installed and logged in.</div>
    </div>
  </div>
</body>
</html>
