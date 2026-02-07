
(function(){
  function el(id){ return document.getElementById(id); }
  function setText(id, val){ var n = el(id); if(n && val){ n.textContent = val; n.removeAttribute('data-empty'); } }
  function setAttr(id, attr, val){ var n=el(id); if(n && val){ n.setAttribute(attr, val); n.removeAttribute('data-empty'); } }
  function init(){
    var w = window.Telegram && window.Telegram.WebApp;
    if (!w || !w.initDataUnsafe || !w.initDataUnsafe.user) return;
    var u = w.initDataUnsafe.user;
    var name = [u.first_name||'', u.last_name||''].join(' ').trim() || (u.username ? '@'+u.username : '');
    setText('tg-name', name);
    setText('tg-username', u.username ? '@'+u.username : '');
    if (u.photo_url){
      setAttr('tg-avatar', 'src', u.photo_url);
      setAttr('tg-avatar', 'alt', name || 'Avatar');
      var ph = el('tg-avatar-ph'); if(ph){ ph.style.display='none'; }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
})();
