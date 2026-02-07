(function(){
  // Telegram WebApp bootstrap (no auto-login; user must click a login button)
  const isTG = !!(window.Telegram && Telegram.WebApp);
  if (isTG) {
    Telegram.WebApp.ready();
  }

  function vx_confetti_burst() {
    try {
      const wrap = document.createElement('div');
      wrap.style.position = 'fixed';
      wrap.style.inset = '0';
      wrap.style.pointerEvents = 'none';
      wrap.style.zIndex = '9999';
      document.body.appendChild(wrap);

      const emojis = ['✨','🎉','🪙','💎','🔥'];
      const count = 26;
      for (let i=0;i<count;i++) {
        const el = document.createElement('div');
        el.textContent = emojis[Math.floor(Math.random()*emojis.length)];
        el.style.position = 'absolute';
        el.style.left = (10 + Math.random()*80) + 'vw';
        el.style.top = '-20px';
        el.style.fontSize = (14 + Math.random()*18) + 'px';
        el.style.opacity = '0.95';
        el.style.filter = 'drop-shadow(0 6px 10px rgba(0,0,0,.35))';
        const dur = 1200 + Math.floor(Math.random()*900);
        const drift = (-40 + Math.random()*80);
        el.animate([
          { transform: 'translate(0,0) rotate(0deg)', opacity: 0.95 },
          { transform: `translate(${drift}px, 110vh) rotate(${Math.floor(Math.random()*540)-270}deg)`, opacity: 0.0 }
        ], { duration: dur, easing: 'cubic-bezier(.2,.8,.2,1)' });
        wrap.appendChild(el);
      }

      setTimeout(() => { try { wrap.remove(); } catch(e){} }, 2200);
    } catch(e) {}
  }

  async function postInitData() {
    // ensure initData is available
    try { if (isTG) Telegram.WebApp.ready(); } catch(e) {}
    const initData = isTG ? (Telegram.WebApp.initData || "") : "";
    if (!initData) {
      return { ok:false, err:"NO_INITDATA" };
    }
    const res = await fetch('/api/auth/telegram.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'include',
      body: new URLSearchParams({ initData })
    });
    try { return await res.json(); } catch(e) { return { ok:false, err:'BAD_JSON' }; }
  }

  // Public API
  window.tgAuth = {
    // force=true ignores previous "tg_authed" and retries handshake (fixes re-login after logout)
    login: async function(options) {
      options = options || {};
      const force = !!options.force;
      const redirect = options.redirect || null;

      if (!isTG) {
        throw new Error('TELEGRAM_WEBAPP_REQUIRED');
      }
      const currentTgId = (function(){
        try { return String((Telegram.WebApp.initDataUnsafe && Telegram.WebApp.initDataUnsafe.user && Telegram.WebApp.initDataUnsafe.user.id) || ''); } catch(e) { return ''; }
      })();
      const cachedAuthed = sessionStorage.getItem('tg_authed') === '1';
      const cachedTgId = sessionStorage.getItem('tg_id') || '';

      // Multi-account safety: if Telegram user changed, force a fresh handshake.
      if (!force && cachedAuthed) {
        if (currentTgId && cachedTgId && currentTgId !== cachedTgId) {
          try { sessionStorage.removeItem('tg_authed'); sessionStorage.removeItem('tg_id'); } catch(e) {}
        } else {
          if (redirect) location.assign(redirect);
          return { ok:true, cached:true };
        }
      }
      const j = await postInitData();
      if (j && j.ok) {
        sessionStorage.setItem('tg_authed','1');
        if (j.tg_id) sessionStorage.setItem('tg_id', String(j.tg_id));

        // First-login reward celebration (points + confetti + haptic)
        if (j.first_login_bonus) {
          try {
            if (Telegram.WebApp && Telegram.WebApp.HapticFeedback && Telegram.WebApp.HapticFeedback.notificationOccurred) {
              Telegram.WebApp.HapticFeedback.notificationOccurred('success');
            }
          } catch(e) {}

          try {
            const pts = Number(j.first_login_bonus_points || 0);
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:fixed;inset:0;z-index:99999;pointer-events:none;';
            const msg = document.createElement('div');
            msg.style.cssText = 'position:absolute;left:50%;top:18%;transform:translateX(-50%);padding:12px 14px;border-radius:16px;background:rgba(2,6,23,.82);border:1px solid rgba(148,163,184,.18);color:#fff;font-weight:900;box-shadow:0 18px 40px rgba(0,0,0,.45);max-width:92vw;text-align:center;';
            msg.innerText = pts > 0 ? ('🎉 Welcome! +' + pts + ' points unlocked') : '🎉 Welcome!';
            wrap.appendChild(msg);

            // confetti particles
            for (let i=0;i<60;i++) {
              const c = document.createElement('div');
              const size = 6 + Math.random()*8;
              c.style.cssText = 'position:absolute;left:'+ (Math.random()*100) +'vw;top:-20px;width:'+size+'px;height:'+size+'px;border-radius:2px;background:hsla('+Math.floor(Math.random()*360)+',90%,60%,.95);transform:rotate('+Math.floor(Math.random()*360)+'deg);';
              const dur = 900 + Math.random()*900;
              c.animate([
                { transform: 'translateY(-20px) rotate(0deg)', opacity: 1 },
                { transform: 'translateY('+(window.innerHeight+80)+'px) rotate(720deg)', opacity: 0.9 }
              ], { duration: dur, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'forwards' });
              wrap.appendChild(c);
            }

            document.body.appendChild(wrap);
            setTimeout(()=>{ try{ wrap.remove(); }catch(e){} }, 2200);
          } catch(e) {}
        }
        if (redirect) location.assign(redirect);
        return j;
      }
      throw new Error((j && (j.err || j.error)) || 'LOGIN_FAILED');
    },
    // Client-side cleanup only; server session is cleared by /api/logout.php
    logoutClient: function() {
      try { sessionStorage.removeItem('tg_authed'); sessionStorage.removeItem('tg_id'); } catch(e) {}
    }
  };

  // Auto-bind buttons if present
  function bindButtons(){
    // Any element with data-tg-login triggers login; optional data-redirect for success redirect
    document.querySelectorAll('[data-tg-login]').forEach(function(el){
      if (el._tg_bound) return;
      el._tg_bound = true;
      el.addEventListener('click', async function(ev){
        ev.preventDefault();
        try {
          const redirect = el.getAttribute('data-redirect') || '/user/dashboard';
          await window.tgAuth.login({ force:true, redirect: redirect });
        } catch (e) {
          alert('Login failed. Please try again.');
          console.error('tgAuth.login error:', e);
        }
      });
    });
    // Any element with data-tg-logout calls server logout then returns to home (or data-redirect)
    document.querySelectorAll('[data-tg-logout]').forEach(function(el){
      if (el._tg_bound) return;
      el._tg_bound = true;
      el.addEventListener('click', async function(ev){
        ev.preventDefault();
        try {
          await fetch('/api/logout.php', { method:'GET', credentials:'same-origin' });
        } catch (e) {}
        window.tgAuth.logoutClient();
        const redirect = el.getAttribute('data-redirect') || '/';
        location.assign(redirect);
      });
    });
  }
  document.addEventListener('DOMContentLoaded', bindButtons);
  // In case content is injected dynamically
  const mo = new MutationObserver(bindButtons);
  mo.observe(document.documentElement, { childList:true, subtree:true });
})();

/* Toggle CTA visibility for Telegram WebApp presence */
(function(){
  function toggleWebAppCTAs(){
    var isTG = !!(window.Telegram && Telegram.WebApp);
    var show = document.querySelectorAll('[data-show-in-webapp]');
    var hide = document.querySelectorAll('[data-hide-in-webapp]');
    for (var i=0;i<show.length;i++){ show[i].style.display = isTG ? '' : 'none'; }
    for (var j=0;j<hide.length;j++){ hide[j].style.display = isTG ? 'none' : ''; }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', toggleWebAppCTAs);
  } else { toggleWebAppCTAs(); }
})();
