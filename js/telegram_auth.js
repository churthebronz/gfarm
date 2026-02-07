(function(){
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  async function postInitData(){
    try{
      var tg = window.Telegram && window.Telegram.WebApp;
      if(!tg) return {ok:false, err:'NO_TG'};
      // Some Telegram clients populate initData only after ready() is called.
      try { tg.ready && tg.ready(); } catch(e) {}
      var initData = tg.initData || '';
      if(!initData) return {ok:false, err:'NO_INITDATA'};
      // Use canonical endpoint in this codebase
      var res = await fetch('/api/auth/telegram.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        credentials:'include',
        body: new URLSearchParams({ initData: initData, start_param: (tg.initDataUnsafe && tg.initDataUnsafe.start_param) ? String(tg.initDataUnsafe.start_param) : '' })
      });
      var j = null;
      try { j = await res.json(); } catch(e){ j = {ok:false, err:'BAD_JSON'}; }
      return j || {ok:false, err:'NO_JSON'};
    }catch(e){
      return {ok:false, err:'NET'};
    }
  }

  function bindLoginClicks(){
    try{
      var tg = window.Telegram && window.Telegram.WebApp;
      // telegram-web-app.js defines window.Telegram.WebApp even in normal browsers.
      // Only hijack login clicks when we're actually inside Telegram (UA contains Telegram
      // or initData exists after ready()).
      var ua = (navigator.userAgent || '');
      var uaIsTG = /Telegram/i.test(ua);
      try { tg && tg.ready && tg.ready(); } catch(e) {}
      var hasInit = !!(tg && tg.initData);
      if(!(tg && (uaIsTG || hasInit))) return;

      document.querySelectorAll('[data-tg-login]').forEach(function(el){
        if(el._vx_bound) return;
        el._vx_bound = true;
        el.addEventListener('click', async function(ev){
          ev.preventDefault();
          try { tg.HapticFeedback && tg.HapticFeedback.impactOccurred && tg.HapticFeedback.impactOccurred('light'); } catch(e){}
          var j = await postInitData();
          if(j && j.ok){
            sessionStorage.setItem('tg_authed','1');
            var redirect = el.getAttribute('data-redirect') || '/user';
            location.assign(redirect);
          }else{
            // Show simple fallback
            try {
              var msg = 'Login failed. Try again.';
              if (j && (j.err || j.error)) msg = 'Login failed: ' + (j.err || j.error);
              if(window.vxToast){ window.vxToast(msg, 'error'); }
              else alert(msg);
              tg.HapticFeedback && tg.HapticFeedback.notificationOccurred && tg.HapticFeedback.notificationOccurred('error');
            } catch(e){}
          }
        });
      });
    }catch(e){}
  }

  ready(function(){
    // Auto-login once inside Telegram to reduce friction
    (async function(){
      try{
        var tg = window.Telegram && window.Telegram.WebApp;
        if(!tg) return;
        if(sessionStorage.getItem('tg_authed') === '1') return;
        var j = await postInitData();
        if(j && j.ok){
          sessionStorage.setItem('tg_authed','1');
        }
      }catch(e){}
    })();

    bindLoginClicks();
  });
})();
