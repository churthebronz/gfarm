<?php
/**
 * GreenFarm TG Mini App – Production Footer
 * Version: Update_02
 * Notes:
 * - Exact width alignment with header & content shell
 * - Glass / aura / alive styling
 * - Mobile-first, Telegram-safe
 */
if (!defined('FastCore')) { exit('Oops!'); }
?>

<footer class="gf-site-footer" role="contentinfo" aria-label="GreenFarm footer">
  <div class="gf-footer-shell">
    <div class="gf-footer-card">

      <!-- Ambient -->
      <div class="gf-footer-ambient" aria-hidden="true">
        <span class="gf-footer-grid"></span>
        <span class="gf-footer-aura"></span>
      </div>

      <!-- Top glow line -->
      <div class="gf-footer-beam" aria-hidden="true"></div>

      <!-- Content -->
      <div class="gf-footer-inner">
        <div class="gf-footer-left">
          <div class="gf-footer-logo">🌱 GreenFarm</div>
          <div class="gf-footer-copy">
            © <?= date('Y') ?> GreenFarm. All rights reserved.
          </div>
        </div>

        <div class="gf-footer-right">
          <a href="/terms" class="gf-footer-link"><i class="fa-solid fa-file-lines" aria-hidden="true"></i><span>Terms</span></a>
          <a href="/privacy" class="gf-footer-link"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Privacy</span></a>
          <a href="/support" class="gf-footer-link"><i class="fa-solid fa-headset" aria-hidden="true"></i><span>Support</span></a>
        </div>
      </div>

    </div>
  </div>
</footer>

<style>
.gf-site-footer{display:block!important;width:100%!important;}

.gf-site-footer{
  width:100%;
  padding: env(safe-area-inset-bottom) 0 12px;
}
.gf-footer-shell{
  width: var(--gf-app-shell, min(1100px, calc(100vw - 24px)));
  max-width: var(--gf-app-shell, min(1100px, calc(100vw - 24px)));
  margin-left: auto;
  margin-right: auto;
  padding: 0;
}
.gf-footer-card{
  width: 100%;
  position:relative;
  border-radius: 18px;
  background: rgba(10, 40, 25, 0.55);
  backdrop-filter: blur(14px);
  box-shadow: 0 0 0 1px rgba(80,255,170,.12), 0 12px 30px rgba(0,0,0,.35);
  overflow:hidden;
}
.gf-footer-ambient{ position:absolute; inset:0; pointer-events:none; }
.gf-footer-grid{
  position:absolute; inset:0;
  background-image: radial-gradient(rgba(120,255,200,.06) 1px, transparent 1px);
  background-size: 18px 18px;
}
.gf-footer-aura{
  position:absolute; inset:-40%;
  background: radial-gradient(circle at 50% 0%, rgba(120,255,200,.18), transparent 60%);
}
.gf-footer-beam{
  height:2px;
  background: linear-gradient(90deg, transparent, rgba(120,255,200,.6), transparent);
}
.gf-footer-inner{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding: 16px 18px;
  gap: 14px;
}
.gf-footer-logo{
  font-weight: 800;
  letter-spacing: .3px;
  display:flex;
  align-items:center;
  gap:8px;
}
.gf-footer-copy{
  font-size:12px;
  opacity:.75;
  margin-top: 4px;
}
.gf-footer-right{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  justify-content:center;
}

/* Polished pill-links (override any global <a> styles) */
.gf-footer-link{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding: 8px 12px;
  border-radius: 999px;
  background: rgba(120,255,200,.10);
  border: 1px solid rgba(120,255,200,.22);
  color: rgba(234,255,246,.92) !important;
  text-decoration:none !important;
  font-weight: 700;
  font-size: 13px;
  line-height: 1;
  letter-spacing: .2px;
  box-shadow: 0 6px 18px rgba(0,0,0,.18);
  transition: transform .15s ease, background .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.gf-footer-link i{ opacity:.9; }
.gf-footer-link:hover{
  transform: translateY(-1px);
  background: rgba(120,255,200,.18);
  border-color: rgba(120,255,200,.35);
  box-shadow: 0 10px 26px rgba(0,0,0,.28), 0 0 18px rgba(120,255,200,.18);
}
.gf-footer-link:active{
  transform: translateY(0px);
  background: rgba(120,255,200,.14);
}

@media (max-width: 640px){
  .gf-footer-inner{ flex-direction: column; text-align:center; }
  .gf-footer-left{ display:flex; flex-direction:column; align-items:center; }
}
</style>
