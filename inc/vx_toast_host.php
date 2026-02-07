<?php
// /inc/vx_toast_host.php — global top-right toast host + API
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
?>
<style>
#vxToastHost{
  position:fixed;
  top:calc(env(safe-area-inset-top,0px) + 12px);
  right:calc(env(safe-area-inset-right,0px) + 12px);
  z-index:100000;
  display:flex; flex-direction:column; gap:8px;
  pointer-events:none;
}
.vx-toast{
  pointer-events:auto;
  min-width:260px; max-width:min(92vw,420px);
  padding:12px 14px;
  border-radius:12px;
  backdrop-filter:blur(8px) saturate(130%);
  background:rgba(24,16,10,.85);
  border:1px solid rgba(255,174,0,.35); color:#ffe9cf;
  box-shadow:0 10px 30px rgba(0,0,0,.35), inset 0 0 12px rgba(255,174,0,.10);
  display:grid; grid-template-columns:22px 1fr 24px; align-items:center; gap:10px;
  opacity:0; transform:translateY(-6px);
  animation:vxToastIn .22s ease forwards;
}
@keyframes vxToastIn{to{opacity:1; transform:translateY(0);}}
.vx-toast.hide { animation:vxToastOut .18s ease forwards; }
@keyframes vxToastOut{to{opacity:0; transform:translateY(-6px);}}
.vx-toast .vx-bar{ grid-column:1 / -1; height:3px; border-radius:2px; overflow:hidden;
  background:rgba(255,255,255,.07); margin-top:6px; }
.vx-toast .vx-fill{ display:block; height:100%; width:100%; transform-origin:left; }
.vx-toast.success{ border-color:rgba(0,200,120,.5); color:#eafff4; }
.vx-toast.success .vx-fill{ background:rgba(0,200,120,.9); }
.vx-toast.info{ border-color:rgba(0,180,255,.5); color:#e6f6ff; }
.vx-toast.info .vx-fill{ background:rgba(0,180,255,.9); }
.vx-toast.warning{ border-color:rgba(255,174,0,.7); color:#fff2d9; }
.vx-toast.warning .vx-fill{ background:rgba(255,174,0,.95); }
.vx-toast.danger{ border-color:rgba(255,80,80,.6); color:#ffecec; }
.vx-toast.danger .vx-fill{ background:rgba(255,80,80,.9); }
.vx-toast .vx-close{ background:transparent; border:0; color:#ffd9a6;
  font-weight:800; font-size:1.1rem; cursor:pointer; line-height:1; }
</style>
<div id="vxToastHost" aria-live="polite" aria-atomic="true"></div>
<script>
(function(){
  function host(){
    let h = document.getElementById('vxToastHost');
    if(!h){ h = document.createElement('div'); h.id='vxToastHost'; document.body.appendChild(h); }
    return h;
  }
  window.vxToast = function(type, msg, opts){
    const opt = Object.assign({duration: 4500}, opts||{});
    const h = host();
    const box = document.createElement('div');
    box.className = 'vx-toast ' + (type||'info');
    box.setAttribute('role','alert'); box.setAttribute('aria-live','assertive');
    const icon = (type==='success') ? 'fa-circle-check'
               : (type==='warning') ? 'fa-triangle-exclamation'
               : (type==='danger')  ? 'fa-circle-exclamation'
               : 'fa-circle-info';
    box.innerHTML = ''
      + '<div class="vx-ico"><i class="fa-solid '+icon+'"></i></div>'
      + '<div class="vx-msg" style="font-weight:700;line-height:1.25"></div>'
      + '<button class="vx-close" type="button" aria-label="Close">×</button>'
      + '<div class="vx-bar"><span class="vx-fill"></span></div>';
    box.querySelector('.vx-msg').textContent = msg || '';
    h.prepend(box);
    const fill = box.querySelector('.vx-fill');
    fill.style.transition = 'transform '+opt.duration+'ms linear';
    requestAnimationFrame(()=>{ fill.style.transform = 'scaleX(0)'; });
    const kill = () => { if (!box.classList.contains('hide')) { box.classList.add('hide'); setTimeout(()=>box.remove(), 200); } };
    box.querySelector('.vx-close').addEventListener('click', kill);
    const onKey = (e)=>{ if(e.key==='Escape'){ kill(); document.removeEventListener('keydown', onKey);} };
    document.addEventListener('keydown', onKey);
    const timer = setTimeout(()=>{ kill(); document.removeEventListener('keydown', onKey); }, opt.duration);
    return {close: kill, timer};
  };
  // Close legacy alerts
  document.addEventListener('click', (e)=>{
    const legacy = e.target.closest('.alert .close-btn, .alert .btn-close');
    if (legacy) { const box = legacy.closest('.alert'); if (box) box.remove(); }
  });
  // Server-side session notification -> toast on load
  try {
    <?php if (!empty($_SESSION['notification'])):
      $n = is_array($_SESSION['notification']) ? $_SESSION['notification'] : json_decode((string)$_SESSION['notification'], true);
      unset($_SESSION['notification']);
      $t = isset($n['type']) ? $n['type'] : 'info';
      $m = isset($n['msg']) ? $n['msg'] : '';
    ?>
    window.addEventListener('DOMContentLoaded', function(){ vxToast(<?= json_encode($t) ?>, <?= json_encode($m) ?>); });
    <?php endif; ?>
  } catch(e){}
})();
</script>
