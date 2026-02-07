<?php
// /inc/vx_toast.php — global toast renderer (safe to include many times)
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$__vx_toast = null;
if (!empty($_SESSION['notification'])) {
  if (is_array($_SESSION['notification'])) {
    $__vx_toast = $_SESSION['notification'];
  } else {
    $__vx_toast = json_decode((string)$_SESSION['notification'], true);
  }
  unset($_SESSION['notification']);
}
if ($__vx_toast && is_array($__vx_toast)) {
  $nType = in_array(($__vx_toast['type'] ?? 'info'), ['success','info','warning','danger']) ? $__vx_toast['type'] : 'info';
  $nMsg  = (string)($__vx_toast['msg'] ?? '');
?>
<style>
.vx-toast { position: fixed; top: calc(var(--nav-offset,78px) + 10px); right: 12px; z-index: 10000;
  min-width: 260px; max-width: 92vw; padding: 12px 14px 12px 14px; border-radius: 12px;
  backdrop-filter: blur(8px) saturate(130%); background: rgba(24,16,10,.85);
  border: 1px solid rgba(255,174,0,.35); color: #ffe9cf;
  box-shadow: 0 10px 30px rgba(0,0,0,.35), inset 0 0 12px rgba(255,174,0,.10);
  display: grid; grid-template-columns: 22px 1fr 24px; align-items: center; gap: 10px;
  animation: vxToastIn .25s ease both;
}
@keyframes vxToastIn { from { opacity:0; transform: translateY(-6px);} to {opacity:1; transform:translateY(0);} }
.vx-toast .vx-ico { font-size: 1rem; }
.vx-toast .vx-msg { font-weight: 700; line-height: 1.25; }
.vx-toast .vx-close { background: transparent; border: 0; color: #ffd9a6; font-weight: 800; font-size: 1.1rem; cursor: pointer; }
.vx-toast .vx-bar { grid-column: 1 / -1; height: 3px; border-radius: 2px; overflow: hidden; background: rgba(255,255,255,.07); margin-top: 6px; }
.vx-toast .vx-fill { display:block; height:100%; width:100%; transform-origin:left; animation: vxToastBar 5s linear forwards; }
@keyframes vxToastBar { from {transform: scaleX(1);} to {transform: scaleX(0);} }
.vx-toast.success { border-color: rgba(0,200,120,.5); color: #eafff4; }
.vx-toast.success .vx-fill { background: rgba(0,200,120,.9); }
.vx-toast.info { border-color: rgba(0,180,255,.5); color: #e6f6ff; }
.vx-toast.info .vx-fill { background: rgba(0,180,255,.9); }
.vx-toast.warning { border-color: rgba(255,174,0,.7); color: #fff2d9; }
.vx-toast.warning .vx-fill { background: rgba(255,174,0,.95); }
.vx-toast.danger { border-color: rgba(255,80,80,.6); color: #ffecec; }
.vx-toast.danger .vx-fill { background: rgba(255,80,80,.9); }
.alert .close-btn, .alert .btn-close { cursor: pointer; }
</style>
<div class="vx-toast <?= htmlspecialchars($nType, ENT_QUOTES) ?>" role="alert" aria-live="assertive" aria-atomic="true">
  <div class="vx-ico">
    <?php if ($nType==='success'): ?><i class="fa-solid fa-circle-check"></i><?php endif; ?>
    <?php if ($nType==='info'): ?><i class="fa-solid fa-circle-info"></i><?php endif; ?>
    <?php if ($nType==='warning'): ?><i class="fa-solid fa-triangle-exclamation"></i><?php endif; ?>
    <?php if ($nType==='danger'): ?><i class="fa-solid fa-circle-exclamation"></i><?php endif; ?>
  </div>
  <div class="vx-msg"><?= htmlspecialchars($nMsg, ENT_QUOTES); ?></div>
  <button class="vx-close" type="button" data-toast-close aria-label="Close">×</button>
  <div class="vx-bar"><span class="vx-fill"></span></div>
</div>
<script>
(function(){
  function kill(el){ if (!el) return; el.style.pointerEvents='none'; el.style.opacity='0';
    setTimeout(()=> el.remove(), 180); }
  document.addEventListener('click', function(e){
    const btn = e.target.closest('[data-toast-close]');
    if (btn) { const box = btn.closest('.vx-toast'); kill(box); }
    const legacy = e.target.closest('.alert .close-btn, .alert .btn-close');
    if (legacy) { const box = legacy.closest('.alert, .vx-toast'); kill(box); }
  });
  const t = document.querySelector('.vx-toast');
  if (t) setTimeout(()=> kill(t), 5100);
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { const top = document.querySelector('.vx-toast, .alert'); if (top) kill(top); }
  });
})();
</script>
<?php } ?>
