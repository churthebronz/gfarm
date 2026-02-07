<?php
/*
  File: /inc/menu.php
  GreenFarm • TG Mini-App Header — PERFORMANCE EDITION
*/
if (!defined('FastCore')) { exit('Oops!'); }
global $config;
?>

<div id="site-menu" class="gf-topstack" role="banner" aria-label="GreenFarm header">
  <header class="gf-header" id="gfHeader">

    <!-- Ambient (GPU-cheap) -->
    <div class="gf-ambient" aria-hidden="true">
      <span class="gf-grid"></span>
      <span class="gf-aura"></span>
    </div>

    <!-- Top bar -->
    <div class="gf-topbar">
      <span class="gf-live">
        <span class="gf-dot"></span>
        <span class="gf-liveTxt">LIVE</span>
      </span>

      <div class="gf-topbar__right">
        <a class="gf-action" href="/user/inbox" aria-label="Inbox">
          <i class="fa-regular fa-bell"></i>
          <span class="gf-badge" id="vxBellBadge" hidden>0</span>
        </a>
        <a class="gf-action" href="/user/settings" aria-label="Settings">
          <i class="fa-solid fa-gear"></i>
        </a>
        <a class="gf-action gf-action--logout" href="/user/logout" aria-label="Logout">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
      </div>
    </div>

    <!-- Logo -->
    <div class="gf-hero">
      <a class="gf-logoPod" href="/user/dashboard">
        <img class="gf-logo" src="/homelogo.png" alt="GreenFarm" fetchpriority="high">
      </a>
    </div>

    <div class="gf-lip"></div>
  </header>
</div>

<style>
:root{
  --gf-shell:min(1140px,calc(100% - 16px));
  --gf-ink:#eafff6;
  --gf-border:rgba(255,255,255,.1);
  --gf-accent:#2dffb8;
}

/* Container */
.gf-topstack{
  width:100%;
  padding-top:env(safe-area-inset-top);
}

/* Header */
.gf-header{
  width:var(--gf-shell);
  margin:auto;
  border-radius:0 0 26px 26px;
  background:
    radial-gradient(760px 280px at 50% 0%, rgba(45,255,184,.14), transparent 60%),
    linear-gradient(180deg, rgba(7,10,12,.94), rgba(9,14,16,.94));
  border:1px solid var(--gf-border);
  border-top:0;
  position:relative;
  overflow:hidden;
}

/* Ambient (NO blur / NO filters) */
.gf-ambient{
  position:absolute; inset:0;
  pointer-events:none;
}
.gf-grid{
  position:absolute; inset:-40% -20%;
  background:
    linear-gradient(to right, rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(to bottom, rgba(255,255,255,.02) 1px, transparent 1px);
  background-size:44px 44px;
  opacity:.22;
}
.gf-aura{
  position:absolute;
  inset:0 auto auto 0;
  width:100%; height:200px;
  background:radial-gradient(circle at 50% 0%, rgba(45,255,184,.20), transparent 70%);
  animation:aura 6s ease-in-out infinite;
}

/* Top bar */
.gf-topbar{
  display:flex;
  justify-content:space-between;
  padding:10px 12px 0;
}

/* LIVE */
.gf-live{
  display:flex;
  align-items:center;
  gap:8px;
  padding:6px 12px;
  border-radius:999px;
  background:rgba(255,255,255,.08);
  border:1px solid var(--gf-border);
  font-weight:900;
  color:var(--gf-ink);
}
.gf-dot{
  width:8px;height:8px;
  border-radius:50%;
  background:var(--gf-accent);
  animation:pulse 1.2s infinite;
}
.gf-liveTxt{font-size:12px}

/* Actions */
.gf-topbar__right{display:flex;gap:10px}
.gf-action{
  width:44px;height:44px;
  display:grid;place-items:center;
  border-radius:14px;
  background:rgba(255,255,255,.08);
  border:1px solid var(--gf-border);
  color:var(--gf-ink);
  text-decoration:none;
}
.gf-action:active{transform:scale(.97)}
.gf-action--logout{border-color:rgba(255,77,109,.3)}

.gf-badge{
  position:absolute;
  top:-6px;right:-6px;
  min-width:16px;height:16px;
  font-size:10px;
  border-radius:999px;
  background:#ff3b5c;
  color:#fff;
  display:grid;place-items:center;
}

/* Hero */
.gf-hero{
  padding:12px 12px 16px;
  display:grid;
  justify-items:center;
}
.gf-logo{
  width:min(860px,100%);
  max-height:160px;
  object-fit:contain;
}

/* Lip */
.gf-lip{
  height:8px;
  margin:0 12px 12px;
  border-radius:999px;
  background:linear-gradient(90deg,transparent,rgba(45,255,184,.28),transparent);
  animation:lip 5s ease-in-out infinite;
}

/* Animations (transform + opacity only) */
@keyframes pulse{
  0%,100%{transform:scale(.9);opacity:.7}
  50%{transform:scale(1.1);opacity:1}
}
@keyframes aura{
  0%,100%{transform:translateY(0)}
  50%{transform:translateY(6px)}
}
@keyframes lip{
  0%,100%{opacity:.25}
  50%{opacity:.6}
}

@media (prefers-reduced-motion:reduce){
  *{animation:none!important}
}
</style>

<script>
(function(){
  /* Active route (cheap + safe) */
  try{
    var path = location.pathname.replace(/\/$/, '');
    document.querySelectorAll('#site-menu [data-path]').forEach(function(el){
      if(path.startsWith(el.dataset.path)) el.classList.add('is-active');
    });
  }catch(e){}

  /* Telegram haptics (single delegated listener) */
  try{
    if(window.Telegram?.WebApp?.HapticFeedback){
      document.getElementById('site-menu')
        .addEventListener('click',function(e){
          if(e.target.closest('.gf-action,.gf-btn')){
            Telegram.WebApp.HapticFeedback.impactOccurred('light');
          }
        },{passive:true});
    }
  }catch(e){}
})();
</script>
