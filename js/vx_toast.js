(function(){
  var STYLE_ID='vx-toast-style', BOX_ID='vx-toast-box';
  function ensureStyle(){
    if(document.getElementById(STYLE_ID)) return;
    var css=document.createElement('style'); css.id=STYLE_ID;
    css.textContent = [
      '#'+BOX_ID+'{position:fixed !important; top:calc(12px + env(safe-area-inset-top)) !important; right:calc(12px + env(safe-area-inset-right)) !important; left:auto !important; z-index:2147483647 !important; display:flex !important; flex-direction:column !important; gap:8px !important; pointer-events:none !important; margin:0 !important; padding:0 !important}',
      '#'+BOX_ID+' .vx-msg{min-width:240px; max-width:92vw; background:#111; color:#fff; padding:10px 14px; border-radius:12px; box-shadow:0 10px 34px rgba(0,0,0,.45); font:14px/1.35 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; transform:translateY(-6px); opacity:0; transition:opacity .18s ease, transform .18s ease; pointer-events:auto}',
      '#'+BOX_ID+' .vx-msg.vx-in{opacity:1; transform:translateY(0)}',
      '#'+BOX_ID+' .vx-row{display:flex; align-items:center; gap:10px}',
      '#'+BOX_ID+' .vx-close{margin-left:auto; background:transparent; border:0; color:#fff; opacity:.75; cursor:pointer; font-size:16px; line-height:1; padding:0 2px}'
    ].join('\n');
    (document.head||document.documentElement).appendChild(css);
  }
  function ensureBox(){
    var box=document.getElementById(BOX_ID);
    if(!box){
      box=document.createElement('div'); box.id=BOX_ID;
      (document.body||document.documentElement).appendChild(box);
    }
    return box;
  }
  function showPopupFallback(msg){
    try{
      var tg = window.Telegram && window.Telegram.WebApp;
      if (tg && typeof tg.showAlert === 'function') { tg.showAlert(String(msg||'')); return true; }
      if (tg && typeof tg.showPopup === 'function') { tg.showPopup({message:String(msg||'')}); return true; }
    }catch(e){}
    return false;
  }
  function createToast(msg, opts){
    opts=opts||{}; var dur=(typeof opts.duration==='number')?opts.duration:2600;
    ensureStyle(); var box=ensureBox();
    var m=document.createElement('div'); m.className='vx-msg';
    var row=document.createElement('div'); row.className='vx-row';
    var txt=document.createElement('div'); txt.textContent=String(msg||''); row.appendChild(txt);
    var x=document.createElement('button'); x.className='vx-close'; x.type='button'; x.textContent='×'; row.appendChild(x);
    m.appendChild(row); box.appendChild(m);
    requestAnimationFrame(function(){ m.classList.add('vx-in'); });
    var rect = m.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > (window.innerHeight||600)) {
      if (showPopupFallback(msg)) { m.remove(); return null; }
    }
    var closed=false; function close(){ if(closed) return; closed=true; m.classList.remove('vx-in'); setTimeout(function(){ m.remove(); }, 180); }
    x.addEventListener('click', function(ev){ ev.preventDefault(); ev.stopPropagation(); close(); });
    if(dur>0) setTimeout(close, dur);
    try { var tg = window.Telegram && window.Telegram.WebApp; if (tg && tg.HapticFeedback) tg.HapticFeedback.notificationOccurred('success'); } catch(e) {}
    return { close: close, el: m };
  }
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function(){ window.vxToast = function(msg, opts){ try{ return createToast(msg, opts); }catch(e){ alert(String(msg||'')); return null; } }; });
})();