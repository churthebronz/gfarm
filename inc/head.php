<?php
// File: /views/layouts/base_telegram_minapp.php
if (!defined('FastCore')) { exit('Oops!'); }
$segments  = (isset($pg->segment) && is_array($pg->segment)) ? $pg->segment : [];
$seg0      = $segments[0] ?? '';
$isAccount = ($seg0 === 'user');
$isHome    = ($seg0 === '');
$isDock    = ($isAccount || $isHome);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <?php $pageTitle = isset($opt) && is_array($opt) && !empty($opt['title']) ? (string)$opt['title'] : ''; ?>
  <title><?= htmlspecialchars($config->sitename ?? 'GreenFarm', ENT_QUOTES); ?><?= $pageTitle ? ' - ' . htmlspecialchars($pageTitle, ENT_QUOTES) : ''; ?></title>
  <link rel="stylesheet" href="/assets/css/gf_app_theme.css?v=1">
  <style id="vx-prepaint">html,body{background:#0b0f1a!important;color:#e8f0ff;}#bg-video{background:#0b0f1a}</style>
  <?php if ($seg0 !== 'user'): ?>
    <meta name="description" content="{!DESCRIPTION!}">
  <?php endif; ?>

  <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#0b0f1a">
  <meta name="color-scheme" content="dark">

  <link rel="apple-touch-icon" sizes="180x180" href="/img/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/img/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/img/fav/favicon-16x16.png">
  <link rel="manifest" href="/img/fav/site.webmanifest">

  <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/animate.css">
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= htmlspecialchars((string)($config->asset_ver ?? '1'), ENT_QUOTES); ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <script src="https://telegram.org/js/telegram-web-app.js"></script>

  <style>
    :root {
      --bg:#0b0f1a;
      --panel:rgba(12,18,28,0.9);
      --cyan:#00f3ff;
      --cyan-d:#00baff;
      --text:#e6f7ff;
      --muted:#9bb0c7;

      /* fallback before JS sets it */
      --vh: 100vh;
    }
    html, body {
      height:100%;margin:0;padding:0;
      background:var(--bg);color:var(--text);
      font-family:'Ubuntu',system-ui,-apple-system,Roboto,sans-serif;
      overscroll-behavior:none;
      -webkit-text-size-adjust:100%;
    }
    body.tg-webapp{
      /* why: avoid full-page compositing & initial blur jank */
      max-width:100vw;overflow-x:hidden;
      padding-top:0;padding-bottom:env(safe-area-inset-bottom);
    }
    img{max-width:100%;height:auto;object-fit:contain;}

    /* full-screen layers stabilized via --vh to avoid expand() jump */
    #bg-video{
      position:fixed;inset:0;width:100%;height:var(--vh);
      object-fit:cover;opacity:.18;z-index:0;pointer-events:none;
      /* why: keep video on its own layer, reduce upload cost */
      contain: strict;
    }
    #vaultPlasma{
      position:fixed;inset:0;width:100vw;height:var(--vh);
      z-index:1;pointer-events:none;contain: strict;
      will-change: opacity, transform;
    }

    .wrapper,.vaultenex-navbar-tg,.heightpage{position:relative;z-index:2;}

    .vaultenex-navbar-tg{
      position:sticky;top:env(safe-area-inset-top,0);
      height:clamp(48px,6vh,64px);
      background:rgba(11,15,26,.92);
      /* defer heavy blur until .app-loaded */
      backdrop-filter:none;
      -webkit-backdrop-filter:none;
      will-change: backdrop-filter, background, opacity;
    }
    .app-loaded .vaultenex-navbar-tg{
      backdrop-filter:blur(12px);
      -webkit-backdrop-filter:blur(12px);
    }

    @media (prefers-reduced-motion:reduce){
      #bg-video,#vaultPlasma{display:none!important;}
    }
    .tg-disabled{pointer-events:none;opacity:.6;cursor:default;}
  </style>

  <script nonce="<?= $CSP_NONCE ?>">
  // why: stabilize viewport height across tg.expand() and URL bar changes
  (function setVhUnit(){
    function applyVH(){ document.documentElement.style.setProperty('--vh', window.innerHeight + 'px'); }
    applyVH();
    window.addEventListener('resize', applyVH, { passive: true });
  })();
  </script>

  <script nonce="<?= $CSP_NONCE ?>">
  document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram?.WebApp || null;
    const LOGIN_ENDPOINT = '/api/auth/telegram.php';
    const DASH_URL = '/user';
    const BOT_USER = <?= json_encode($config->telegram_bot ?? 'GreenFarmAppBot'); ?>;
    const HTTPS_DEEP_LINK = 'https://t.me/' + encodeURIComponent(BOT_USER) + '/launch?startapp=webauth';
    // Prefer tg:// scheme to jump straight into Telegram Desktop/Mobile when possible.
    // Falls back to https:// if the scheme is blocked.
    const TG_SCHEME_LINK = 'tg://resolve?domain=' + encodeURIComponent(BOT_USER) + '&startapp=webauth';
    const buttons = document.querySelectorAll('a.js-tg-auth');

    // Also intercept any accidental deep links rendered in-page (prevents Mini App "restarting" into a new instance).
    const deepLinks = Array.from(document.querySelectorAll('a[href^="https://t.me/"]')).filter(a => {
      const h = (a.getAttribute('href') || '');
      return h.indexOf('/launch?startapp=webauth') !== -1;
    });

    // IMPORTANT:
    // We always load https://telegram.org/js/telegram-web-app.js, which defines window.Telegram.WebApp
    // even in a normal browser. That means `!!window.Telegram.WebApp` is NOT a reliable Telegram-context check.
    // If we mis-detect, login links get hijacked and you see: "Telegram payload missing. Reopen from bot."
    //
    // Reliable signal:
    // - Telegram WebView user agent contains "Telegram", OR
    // - initData is present (after a best-effort tg.ready()).
    const UA = navigator.userAgent || '';
    const uaIsTelegram = /Telegram/i.test(UA);
    try { tg && tg.ready && tg.ready(); } catch(e) {}
    const initNow = (tg && (tg.initData || '')) || '';
    const hasInit = !!initNow;
    const isTG = !!tg && (uaIsTelegram || hasInit);

    if (isTG) {
      document.documentElement.classList.add('tg-webapp');
      document.body.classList.add('tg-webapp');

      // why: rAF ensures first paint before expanding (prevents flicker)
      requestAnimationFrame(() => {
        try { tg.ready(); } catch(e){}
        requestAnimationFrame(() => { try { tg.expand(); } catch(e){} });
      });

      // manual click-to-login mode
      function bindLoginAnchor(btn){
        btn.classList.remove('tg-disabled');
        btn.href = '#';
        btn.onclick = async e => {
          e.preventDefault();
          try { tg.ready(); } catch(e){}
          const initData = tg.initData || '';
          if (!initData) { alert('Telegram payload missing. Reopen from bot.'); return; }
          btn.classList.add('tg-disabled');
          btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Logging in...';
          try {
            const res = await fetch(LOGIN_ENDPOINT, {
              method:'POST',
              headers:{'Content-Type':'application/json'},
              credentials:'include',
              body:JSON.stringify({initData})
            });
            const data = await res.json().catch(()=>({}));
            if (res.ok && data && data.ok) {
              window.location.href = DASH_URL;
            } else {
              const err = (data && (data.err || data.error)) ? (data.err || data.error) : 'unknown';
              alert('Login failed: ' + err + '. Try again.');
              btn.classList.remove('tg-disabled');
              btn.innerHTML = '<i class="fa fa-telegram"></i> Login with Telegram';
            }
          } catch(err) {
            console.error('[TG] Auth error:', err);
            alert('Network error.');
            btn.classList.remove('tg-disabled');
            btn.innerHTML = '<i class="fa fa-telegram"></i> Login with Telegram';
          }
        };
      }

      buttons.forEach(bindLoginAnchor);
      deepLinks.forEach(bindLoginAnchor);

      // Silent auth on EVERY open (fixes Telegram multi-account WebView caching).
      (async function silentAuth(){
        try { tg.ready(); } catch(e){}

        const uidNow = (tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.id) ? String(tg.initDataUnsafe.user.id) : '';
        const serverTgId = "<?= isset($_SESSION) ? (int)($_SESSION['tg_id'] ?? 0) : 0; ?>";
        const uidPrev = (() => { try { return sessionStorage.getItem('vx_tg_id') || ''; } catch(e) { return ''; } })();

        // If Telegram account changed in the same WebView storage, clear server + client session first.
        // Also handle the case where sessionStorage got wiped but the server cookie still holds an old identity.
        if (uidNow && ((uidPrev && uidNow !== uidPrev) || (serverTgId && uidNow !== serverTgId))) {
          try { await fetch('/api/logout.php', { method:'GET', credentials:'include' }); } catch(e){}
          try { sessionStorage.removeItem('vx_tg_id'); sessionStorage.removeItem('tg_authed'); } catch(e){}
        }

        const initData = tg.initData || '';
        if (!initData) return;

        const res = await fetch(LOGIN_ENDPOINT, {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          credentials:'include',
          body: JSON.stringify({ initData })
        });
        const data = await res.json().catch(()=>({}));
        if (res.ok && data && data.ok) {
          try {
            if (uidNow) sessionStorage.setItem('vx_tg_id', uidNow);
            sessionStorage.setItem('tg_authed','1');
          } catch(e){}
          // If server had to switch identities, refresh to ensure UI reflects the correct account.
          if (data.switched) {
            try { window.location.reload(); } catch(e) {}
          }
        }
      })();

    } else {
      // Outside Telegram
      // Try to open the Telegram app (tg://) first (works for Telegram Desktop + mobile).
      // If blocked, fall back to the HTTPS t.me link.
      function openTelegramDeepLink(hrefFallback){
        try {
          // In some browsers a direct navigation to tg:// is blocked unless it's inside a user gesture.
          // This runs inside the click handler.
          window.location.href = TG_SCHEME_LINK;
          // Fallback to https after a short delay.
          setTimeout(() => { window.location.href = hrefFallback; }, 700);
        } catch(err) {
          window.location.href = hrefFallback;
        }
      }

      buttons.forEach(btn => {
        btn.href = HTTPS_DEEP_LINK;
        btn.target = '_self';
        btn.rel = 'nofollow noopener noreferrer';
        // Ensure we don't show the TG-payload alert in normal browsers.
        btn.addEventListener('click', (e) => {
          // If the browser can open Telegram app, do it; otherwise it will land on the web link.
          e.preventDefault();
          openTelegramDeepLink(HTTPS_DEEP_LINK);
        }, { passive: false });
      });
    }

    // why: toggle costly effects only after two frames → avoids flash on load
    requestAnimationFrame(() => requestAnimationFrame(() => {
      document.documentElement.classList.add('app-loaded');
    }));
  });
  </script>

  <link rel="stylesheet" href="/assets/css/layout_fix_v8.css" />
  <link rel="stylesheet" href="/assets/css/layout_lock_v8_5.css" />
  <link rel="stylesheet" href="/assets/css/tg_profile_card_v8_7.css" />
  <link rel="stylesheet" href="/assets/css/backtop_orb_v8_7_2.css" />
  <link rel="stylesheet" href="/assets/css/override_cleanup_v8_7_2.css" />
  <link rel="stylesheet" href="/assets/css/vx_modal_v8_8.css" />
  <script src="/js/telegram_auth.js" defer></script>
  <script src="/js/vx_toast.js" defer></script>
  <script src="/js/live_update.js" defer></script>
  <script src="/assets/js/tg_profile_card_v8_6.js" defer></script>
<?php if ($seg0 === '' || $seg0 === 'home') : ?>
  <link rel="stylesheet" href="/assets/css/home_page_vx.css">
<?php endif; ?>
<?php if ($seg0 === 'user') : ?>
  <link rel="stylesheet" href="/assets/css/dashboard_vx.css">
<?php endif; ?>

  <?php if ($isAccount): ?>
    <?php
      // Retention tick: streak + soft notifications (server-side, no debug output)
      if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
      $vx_uid = (int)($_SESSION['uid'] ?? 0);
      if ($vx_uid > 0) {
        require_once __DIR__ . '/../core/vx_retention.php';
        try { vx_retention_tick($db, $vx_uid); } catch (Throwable $e) {}
      }
    ?>
  <?php endif; ?>

  <?php if ($isAccount): ?>
  <link rel="stylesheet" href="/assets/css/vx_shell.css?v=<?= htmlspecialchars((string)($config->asset_ver ?? '1'), ENT_QUOTES); ?>">
  <link rel="stylesheet" href="/assets/css/vx_tcg_cards.css?v=<?= htmlspecialchars((string)($config->asset_ver ?? '1'), ENT_QUOTES); ?>">

  <link rel="stylesheet" href="/assets/css/vx_elite_polish.css">
  <script defer src="/assets/js/vx_elite_polish.js"></script>

  <link rel="stylesheet" href="/assets/css/vx_vault_reputation.css">
  <link rel="stylesheet" href="/assets/css/vx_guardian_journal.css">
  <script defer src="/assets/js/vx_vault_reputation.js"></script>
  <script defer src="/assets/js/vx_collection_sets.js"></script>
  <script defer src="/assets/js/vx_guardian_journal.js"></script>

  <script defer src="/assets/js/vx_vault_depth_surface.js"></script>

  <link rel="stylesheet" href="/assets/css/vx_guardian_context.css">
  <script defer src="/assets/js/vx_guardian_context.js"></script>
  <script defer src="/assets/js/vx_sound.js"></script>

  <link rel="stylesheet" href="/assets/css/vx_seamless_v9.css?v=9" />
  <?php endif; ?>
</head>

<body class="vx-app <?= $isAccount ? 'vx-account' : '' ?>" style="background:transparent">
  <div class="gf-bg-dots" aria-hidden="true">
    <i style="left:41%;top:19%;width:5px;height:5px;animation-delay:2.37s"></i>
    <i style="left:9%;top:68%;width:5px;height:5px;animation-delay:0.56s"></i>
    <i style="left:64%;top:27%;width:8px;height:8px;animation-delay:0.22s"></i>
    <i style="left:53%;top:8%;width:8px;height:8px;animation-delay:1.44s"></i>
    <i style="left:7%;top:72%;width:6px;height:6px;animation-delay:0.74s"></i>
    <i style="left:80%;top:80%;width:5px;height:5px;animation-delay:3.5s"></i>
    <i style="left:73%;top:74%;width:6px;height:6px;animation-delay:2.38s"></i>
    <i style="left:5%;top:71%;width:7px;height:7px;animation-delay:5.15s"></i>
    <i style="left:53%;top:18%;width:7px;height:7px;animation-delay:3.24s"></i>
    <i style="left:71%;top:87%;width:6px;height:6px;animation-delay:1.08s"></i>
    <i style="left:47%;top:12%;width:5px;height:5px;animation-delay:3.29s"></i>
    <i style="left:72%;top:7%;width:8px;height:8px;animation-delay:3.71s"></i>
    <i style="left:87%;top:68%;width:7px;height:7px;animation-delay:2.57s"></i>
    <i style="left:59%;top:74%;width:7px;height:7px;animation-delay:5.54s"></i>
    <i style="left:38%;top:31%;width:6px;height:6px;animation-delay:4.77s"></i>
    <i style="left:10%;top:73%;width:8px;height:8px;animation-delay:1.8s"></i>
    <i style="left:43%;top:93%;width:5px;height:5px;animation-delay:2.69s"></i>
    <i style="left:15%;top:65%;width:7px;height:7px;animation-delay:2.51s"></i>
  </div>
  <?php if (!$isAccount): ?>
  <video id="bg-video" autoplay muted loop playsinline preload="metadata" poster="/videos/bg-poster.jpg">
    <source src="/videos/your-background.mp4" type="video/mp4">
  </video>
  <canvas id="vaultPlasma"></canvas>
  <?php endif; ?>

  <div class="heightpage">

  <?php if ($isAccount): ?>
    <style>
      /* Account shell: header flush to top, no legacy wrapper margins/clipping */
      body.vx-account .heightpage{ max-width: 1120px; margin: 0 auto !important; padding: 0 !important; }
      body.vx-account .wrapper{ margin: 0 !important; border-radius: 0 !important; background: transparent !important; overflow: visible !important; }
    </style>
  <?php endif; ?>
    <div class="wrapper">
      <?php
      if (!$isAccount) {
          $file = __DIR__ . '/menu-h.php';
          if (is_file($file)) include $file;
      } else {
          $bal = __DIR__ . '/balance.php';
          $men = __DIR__ . '/menu.php';
          if (is_file($bal)) include $bal;
          if (is_file($men)) include $men;
      }
      ?>

  <?php if ($isDock): ?>
    <!-- Permanent Bottom Navigation (TG-native) -->
    <nav class="vx-dock" aria-label="Quick navigation">
  <?php
    $vx_uid = (int)($_SESSION['uid'] ?? 0);

    $vx_hasPass = false;
    $vx_hasVault = false;

    try {
      if ($vx_uid > 0 && isset($GLOBALS['db'])) {
        require_once __DIR__ . '/../core/seasons.php';
        require_once __DIR__ . '/../core/season_pass.php';

        $sx = vx_get_current_season($GLOBALS['db']);
        $sid = (int)($sx['id'] ?? 0);
        $vx_hasPass = ($sid > 0) ? vx_season_pass_active($GLOBALS['db'], $vx_uid, $sid) : false;

        $row = $GLOBALS['db']->query("SELECT COUNT(*) AS c FROM db_insert WHERE uid=? AND status IN (0,1)", $vx_uid)->fetchArray();
        $vx_hasVault = ((int)($row['c'] ?? 0)) > 0;
      }
    } catch (Throwable $e) {}
  ?>

  <?php if ($isHome): ?>
    <?php if ($_SERVER["REQUEST_URI"] !== "/"): ?>
<div class="vx-dock__inner vx-dock__inner--home"></div>
<?php endif; ?>
  <?php else: ?>
    
<!-- App Dock (single, premium) -->
    <div class="vx-dock__grid" role="navigation" aria-label="App navigation">
      <a class="vx-dock-btn" href="/user/dashboard" data-path="/user/dashboard" aria-label="Home">
        <i class="fa-solid fa-house" aria-hidden="true"></i>
        <span>Home</span>
      </a>

      <a class="vx-dock-btn vx-dock-btn--shop is-primary" href="/user/guardians" data-path="/user/guardians" aria-label="Shop">
        <span class="vx-dock-btn__spark" aria-hidden="true"></span>
        <i class="fa-solid fa-store" aria-hidden="true"></i>
        <span>Shop</span>
      </a>

      <a class="vx-dock-btn" href="/user/refs" data-path="/user/refs" aria-label="Referrals">
        <i class="fa-solid fa-users" aria-hidden="true"></i>
        <span>Refs</span>
      </a>

      <a class="vx-dock-btn" href="/user/history" data-path="/user/history" aria-label="History">
        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
        <span>History</span>
      </a>

      <a class="vx-dock-btn" id="vxDockWallet" href="#" data-path="/user/wallet" data-open-wallet="1" aria-label="Wallet">
        <i class="fa-solid fa-wallet" aria-hidden="true"></i>
        <span>Wallet</span>
      </a>
    </div>
  <?php endif; ?>
</nav>

<script>
/* vxDockAutoHide: hides dock on scroll-down, shows on scroll-up */
(() => {
  const dock = document.querySelector('.vx-dock');
  if (!dock) return;

  let lastY = window.scrollY || 0;
  let hidden = false;
  let ticking = false;

  const THRESH = 10;
  const SHOW_AT_TOP = 80;

  const update = () => {
    const y = window.scrollY || 0;
    const dy = y - lastY;

    if (y < SHOW_AT_TOP) {
      dock.classList.remove('vx-dock--hidden');
      hidden = false;
      lastY = y;
      ticking = false;
      return;
    }

    if (dy > THRESH && !hidden) {
      dock.classList.add('vx-dock--hidden');
      hidden = true;
    } else if (dy < -THRESH && hidden) {
      dock.classList.remove('vx-dock--hidden');
      hidden = false;
    }

    lastY = y;
    ticking = false;
  };

  window.addEventListener('scroll', () => {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(update);
  }, { passive: true });

  update();
})();
</script>


<script>
/* vxDockActive: highlights current tab */
(() => {
  const root = document.querySelector('.vx-dock');
  if (!root) return;

  const path = (location.pathname || '/').replace(/\/$/, '') || '/';
  const links = Array.from(root.querySelectorAll('a[data-path]'));
  if (!links.length) return;

  let best = null;
  let bestLen = -1;

  for (const a of links) {
    const p = (a.getAttribute('data-path') || '').replace(/\/$/, '');
    if (!p) continue;
    if (path === p || (p !== '/' && path.startsWith(p + '/')) || (p !== '/' && path.startsWith(p))) {
      if (p.length > bestLen) { best = a; bestLen = p.length; }
    }
  }

  if (best) best.classList.add('is-active');
})();
</script>

  <?php endif; ?>
