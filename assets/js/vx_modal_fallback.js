(function(){
  if (window.VXModal) return;
  window.VXModal = {
    show: function(msg, ok){
      try{
        let el = document.getElementById('vx-toast');
        if(!el){
          el = document.createElement('div');
          el.id='vx-toast';
          el.style.cssText='position:fixed;left:50%;top:20px;transform:translateX(-50%);z-index:99999;min-width:240px;padding:12px 16px;border-radius:10px;backdrop-filter:blur(6px);color:#fff;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.35)';
          document.body.appendChild(el);
        }
        el.style.background= ok ? 'rgba(20,160,80,.85)' : 'rgba(200,40,40,.85)';
        el.textContent = msg;
        el.style.opacity='1';
        setTimeout(()=>{ el.style.transition='opacity .4s'; el.style.opacity='0'; }, 1800);
      }catch(_){ alert(msg); }
    }
  };
})();