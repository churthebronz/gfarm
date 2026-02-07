<?php
declare(strict_types=1);
if (!defined('FastCore')) { define('FastCore', true); }


// Optional settings (file-based) for share previews + Telegram bot deep link
$tg_bot = '';
$tg_enabled = true;
$share_title = 'Claim your free Founders Guardian';
$share_desc  = 'Limited to the first 30 days from launch. Earn VP daily and climb the season rank.';
$share_img   = '/img/founders_share.png';
try {
  require_once __DIR__ . '/../core/vx_app_settings.php';
  $tg_bot = (string)vx_app_setting('tg_bot_username', '');
  $tg_enabled = (bool)vx_app_setting('tg_deeplink_enabled', true);
  $share_title = (string)vx_app_setting('founders_share_title', $share_title);
  $share_desc  = (string)vx_app_setting('founders_share_desc',  $share_desc);
  $share_img   = (string)vx_app_setting('founders_share_image', $share_img);
} catch (Throwable $e) {}
$ref = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['ref'] ?? ''));
$src = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['src'] ?? 'founders_share'));

// Set a referral cookie (30 days) so we can attribute conversion if the user ends up claiming.
if ($ref !== '') {
  // Secure/SameSite=None helps inside Telegram webviews.
  setcookie('vx_ref', $ref, [
    'expires'  => time() + (30 * 86400),
    'path'     => '/',
    'secure'   => true,
    'httponly' => false,
    'samesite' => 'None',
  ]);
}

// Best-effort click tracking (won't break if it fails)
// We don't require DB here; it should work even if bootstrap isn't available.
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($share_title, ENT_QUOTES); ?></title>

  <!-- Open Graph (Telegram link preview) -->
  <meta property="og:title" content="<?= htmlspecialchars($share_title, ENT_QUOTES); ?>" />
  <meta property="og:description" content="<?= htmlspecialchars($share_desc, ENT_QUOTES); ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:image" content="<?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? 'greenfarm.lol') . ($share_img[0] === '/' ? $share_img : ('/' . $share_img)), ENT_QUOTES); ?>" />
  <meta property="og:url" content="<?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'greenfarm.lol') . '/share/founders.php' . ($ref !== '' ? ('?ref=' . urlencode($ref)) : ''), ENT_QUOTES); ?>" />

  <style>
    :root{color-scheme:dark;}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;background:#05070f;color:#e5e7eb;}
    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
    .card{width:min(720px,100%);border:1px solid rgba(255,255,255,.12);background:linear-gradient(135deg, rgba(2,6,23,.92), rgba(15,23,42,.88));border-radius:22px;box-shadow:0 30px 90px rgba(0,0,0,.65);padding:18px;}
    .h{display:flex;gap:14px;align-items:center;}
    .logo{width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.12);}
    .logo img{width:38px;height:38px;object-fit:contain;}
    h1{margin:0;font-size:20px;font-weight:1000;}
    p{margin:10px 0 0;color:rgba(226,232,240,.86);line-height:1.5;}
    .cta{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;}
    a.btn{flex:1;min-width:220px;text-decoration:none;text-align:center;padding:12px 14px;border-radius:14px;font-weight:1000;}
    .primary{background:linear-gradient(135deg,#00ffe0,#38bdf8);color:#001018;}
    .ghost{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:#e5e7eb;}
    .fine{margin-top:12px;font-size:12px;color:rgba(226,232,240,.72);}
    code{background:rgba(255,255,255,.06);padding:2px 6px;border-radius:8px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="h">
        <div class="logo"><img loading="lazy" decoding="async" src="/homelogo.png" alt="GreenFarm" /></div>
        <div>
          <h1><?= htmlspecialchars($share_title, ENT_QUOTES); ?></h1>
          <p><?= htmlspecialchars($share_desc, ENT_QUOTES); ?></p>
        </div>
      </div>

      <div class="cta">
        <a class="btn primary" href="/user/dashboard">Open GreenFarm</a>
        <?php if ($tg_enabled && $tg_bot !== ''):
          $start = ($ref !== '') ? ('?start=ref_' . urlencode($ref)) : '';
          $botLink = 'https://t.me/' . $tg_bot . $start;
        ?>
          <a class="btn ghost" href="<?= htmlspecialchars($botLink, ENT_QUOTES); ?>">Open Bot (Telegram)</a>
        <?php else: ?>
          <a class="btn ghost" href="/">Homepage</a>
        <?php endif; ?>
      </div>

      <div class="fine">
        <?= $ref !== '' ? ('Referral code detected: <code>' . htmlspecialchars($ref, ENT_QUOTES) . '</code>') : 'No referral code provided.'; ?>
      </div>
    </div>
  </div>

  <script>
    // Track click for tuning incentives (fail-soft)
    (function(){
      var ref = <?= json_encode($ref); ?>;
      var src = <?= json_encode($src); ?>;
      try {
        var u = '/api/share/track.php?event=click&ctx=' + encodeURIComponent(src) + (ref ? ('&ref=' + encodeURIComponent(ref)) : '');
        fetch(u, {credentials:'include'}).catch(function(){});
      } catch(e) {}
    })();
  </script>

  <script>
    // If opened inside Telegram webview, open bot link with Telegram API for smoother UX (fail-soft)
    (function(){
      var botUser = <?= json_encode($tg_bot); ?>;
      var deeplinkEnabled = <?= json_encode($tg_enabled); ?>;
      var ref = <?= json_encode($ref); ?>;
      if (!deeplinkEnabled || !botUser) return;
      var botLink = 'https://t.me/' + botUser + (ref ? ('?start=ref_' + encodeURIComponent(ref)) : '');
      try{
        var btn = document.querySelector('a.btn.ghost');
        if (btn) btn.addEventListener('click', function(ev){
          if (window.Telegram && Telegram.WebApp && Telegram.WebApp.openTelegramLink){
            ev.preventDefault();
            Telegram.WebApp.openTelegramLink(botLink);
          }
        });
      }catch(e){}
    })();
  </script>
</body>
</html>
