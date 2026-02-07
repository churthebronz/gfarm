<?php
// file: /pages/menu-h.php (GreenFarm • Homepage Hero Header • FAST TG Edition)
if (!defined('FastCore')) { define('FastCore', true); }
?>

<div id="site-menu-h" class="gf-homeTop" role="banner" aria-label="GreenFarm header">
  <header class="gf-homeHeader" id="gfHomeHeader">

    <!-- Ambient layer (GPU-light) -->
    <div class="gf-homeAmbient" aria-hidden="true">
      <span class="gf-homeGrid"></span>
      <span class="gf-homeAura"></span>
    </div>

    <!-- Top strip -->
    <div class="gf-homeTopbar">
      <div>
        <span class="gf-livePill">
          <span class="gf-liveDot"></span>
          <span class="gf-liveTxt">LIVE</span>
        </span>
      </div>

      <div class="gf-homeTopbar__right">
        <a class="gf-soc" href="#" aria-label="Telegram"><i class="fa-brands fa-telegram"></i></a>
        <a class="gf-soc" href="#" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a>
        <a class="gf-soc" href="#" aria-label="Discord"><i class="fa-brands fa-discord"></i></a>
      </div>
    </div>

    <!-- Logo -->
    <div class="gf-homeHero">
      <a class="gf-homeLogoPod" href="/">
        <img class="gf-homeLogo" src="/homelogo.png" alt="GreenFarm" fetchpriority="high" />
      </a>
    </div>

    <div class="gf-homeLip"></div>
    </div>
</header>
</div>

<div id="menuh-spacer"></div>

<style>
:root{
  --gf-shell: min(1140px, calc(100% - 16px));
  --gf-ink:#eafff6;
  --gf-border:rgba(255,255,255,.1);
  --gf-accent:#2dffb8;
}

/* Container */
.gf-homeTop{
  width:100%;
  padding-top:env(safe-area-inset-top);
}

/* Header */
.gf-homeHeader{
  width:var(--gf-shell);
  margin:auto;
  position:relative;
  overflow:hidden;
  border-radius:0 0 24px 24px;
  border-top:0;
}

/* Ambient (NO filters, NO blur) */
.gf-homeAmbient{
  position:absolute;
  pointer-events:none;
}
.gf-homeGrid{
  position:absolute; inset:-40% -20%;
  background:
    linear-gradient(to right, rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(to bottom, rgba(255,255,255,.02) 1px, transparent 1px);
  background-size:48px 48px;
  opacity:.18;
}
.gf-homeAura{
  position:absolute; inset:0 auto auto 0;
  width:100%; height:180px;
  background:radial-gradient(circle at 50% 0%, rgba(45,255,184,.18), transparent 70%);
  animation: auraFloat 6s ease-in-out infinite;
}

/* Topbar */
.gf-homeTopbar{
  display:flex;
  justify-content:space-between;
  padding:10px 12px 0;
}

/* LIVE */
.gf-livePill{
  display:flex;
  gap:8px;
  padding:6px 12px;
  border-radius:999px;
  background:rgba(255,255,255,.08);
  border:1px solid var(--gf-border);
  font-weight:900;
  color:var(--gf-ink);
}
.gf-liveDot{
  width:8px;height:8px;
  border-radius:50%;
  background:var(--gf-accent);
  animation:pulse 1.2s infinite;
}
.gf-liveTxt{font-size:12px}

/* Social */
.gf-homeTopbar__right{display:flex;gap:10px}
.gf-soc{
  width:42px;height:42px;
  display:grid;place-items:center;
  border-radius:14px;
  background:rgba(255,255,255,.08);
  border:1px solid var(--gf-border);
  color:var(--gf-ink);
}
.gf-soc:active{transform:scale(.97)}
.gf-soc i{font-size:18px}

/* Hero */
.gf-homeHero{
  padding:14px 12px 18px;
  display:grid;
  justify-items:center;
}
.gf-homeLogo{
  width:min(880px,100%);
  max-height:160px;
  object-fit:contain;
}

/* Lip */
.gf-homeLip{
  height:8px;
  margin:0 14px 14px;
  border-radius:999px;
  animation:lip 5s ease-in-out infinite;
}

/* Spacer */
#menuh-spacer{height:0}

/* Animations (transform-only = GPU cheap) */
@keyframes pulse{
  0%,100%{transform:scale(.9);opacity:.7}
  50%{transform:scale(1.1);opacity:1}
}
@keyframes auraFloat{
  0%,100%{transform:translateY(0)}
  50%{transform:translateY(6px)}
}
@keyframes lip{
  0%,100%{opacity:.25}
  50%{opacity:.7}
}

/* Reduced motion */
@media (prefers-reduced-motion:reduce){
  *{animation:none!important}
}
</style>
