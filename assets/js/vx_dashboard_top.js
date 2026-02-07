(function(){
  // --- tiny UI helpers (no deps) ---
  const clamp = (n, a, b) => Math.max(a, Math.min(b, n));
  const pad2 = (n) => (n<10?('0'+n):(''+n));

  // Smooth number animation (Telegram-friendly, lightweight)
  function animateNumber(el, to, opts={}){
    if (!el) return;
    const dur = clamp(Number(opts.duration||650), 250, 1400);
    const decimals = Number.isFinite(opts.decimals) ? opts.decimals : null;
    const prefix = (opts.prefix != null) ? String(opts.prefix) : '';
    const suffix = (opts.suffix != null) ? String(opts.suffix) : '';

    const parseCurrent = () => {
      const t = (el.textContent||'').replace(/[^0-9.\-]/g,'');
      const v = parseFloat(t);
      return Number.isFinite(v) ? v : 0;
    };

    const from = parseCurrent();
    const target = Number(to||0);
    const start = performance.now();

    const ease = (p) => 1 - Math.pow(1-p, 3);

    function render(v){
      let out;
      if (decimals != null) out = v.toFixed(decimals);
      else out = Math.round(v).toString();
      el.textContent = prefix + out + suffix;
    }

    function step(now){
      const p = clamp((now - start) / dur, 0, 1);
      const v = from + (target - from) * ease(p);
      render(v);
      if (p < 1) requestAnimationFrame(step);
    }

    requestAnimationFrame(step);
  }

  function setMoney(el, n, dp=2){
    if (!el) return;
    animateNumber(el, Number(n||0), {decimals: dp, prefix: '$', duration: 700});
  }

  function setInt(el, n){
    if (!el) return;
    animateNumber(el, Number(n||0), {decimals: 0, prefix: '', duration: 600});
  }

  const fmtLeft = (sec) => {
    sec = Math.max(0, Math.floor(sec||0));
    const d = Math.floor(sec/86400); sec -= d*86400;
    const h = Math.floor(sec/3600); sec -= h*3600;
    const m = Math.floor(sec/60); sec -= m*60;
    // Always show seconds (requested)
    if (d>0) return `${d}d ${pad2(h)}h ${pad2(m)}m ${pad2(sec)}s`;
    if (h>0) return `${h}h ${pad2(m)}m ${pad2(sec)}s`;
    return `${m}m ${pad2(sec)}s`;
  };

  let nextMatAt = 0;
  let nowTs = 0;

  async function refreshDaily(){
    try{
      const r = await fetch('/api/user/daily_breakdown.php', {credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok) return;

      const ypd = Number(j.yield?.per_day_est || 0);
      const vp = Number(j.points?.vp_per_day_est || 0);
      const lp = Number(j.points?.lp_per_day_est || 0);

      const ydayEl = document.getElementById('vx-yday');
      const vpEl = document.getElementById('vx-vpday');
      const lpEl = document.getElementById('vx-lpday');
      if (ydayEl) setMoney(ydayEl, ypd, 2);
      if (vpEl) setInt(vpEl, Math.round(vp));
      if (lpEl) setInt(lpEl, Math.round(lp));

      // Pending yield
      const pend = Number(j.yield?.pending || 0);
      const pendEl = document.getElementById('vx-pending');
      if (pendEl) setMoney(pendEl, pend, 6);

      nextMatAt = Number(j.next_maturity_at || 0);
      nowTs = Number(j.now || Math.floor(Date.now()/1000));

      tickNextMat();
    } catch(e) {}
  }

  function tickNextMat(){
    const el = document.getElementById('vx-nextmat');
    const chip = document.getElementById('vxNextMatChip');
    if (!el) return;

    const now = Math.floor(Date.now()/1000);
    if (!nextMatAt || nextMatAt <= now){
      el.textContent = '—';
      if (chip) chip.style.opacity = '0.8';
      return;
    }
    const left = nextMatAt - now;
    el.textContent = fmtLeft(left);
    if (chip) chip.style.opacity = '1';
  }

  async function refreshAffiliate(){
    try{
      const r = await fetch('/api/user/affiliate_status.php', {credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok) return;

      const q = Number(j.qualified||0);
      const need = Number(j.required||10);
      const pct = need>0 ? Math.min(100, Math.round((q/need)*100)) : 0;
      const tier = Number(j.tier||0);
      const vpDay = Number(j.vp_day||0);
      const lpDay = Number(j.lp_day||0);

      const qEl = document.getElementById('vxAffQualified');
      const nEl = document.getElementById('vxAffNeed');
      const bar = document.getElementById('vxAffBar');
      const sub = document.getElementById('vxAffSub');

      if (qEl) setInt(qEl, q);
      if (nEl) setInt(nEl, need);
      if (bar) bar.style.width = pct + '%';

      if (sub){
        if (j.claimed){
          sub.textContent = `Claimed • Tier ${tier} • +${vpDay} VP/day +${lpDay} LP/day`;
        } else if (j.eligible){
          sub.textContent = `Unlocked! Claim your Affiliate Guardian • Tier ${tier} • +${vpDay} VP/day +${lpDay} LP/day`;
        } else {
          const left = Math.max(0, need - q);
          sub.textContent = `Invite friends → they activate a paid Guardian → you progress. (${left} to unlock)`;
        }
      }

      // Achievements row (Founder / Affiliate tier / milestones)
      try{
        const ach = document.getElementById('vx-achievements');
        if (ach){
          const items = [];
          const isTG = !!(window.Telegram && Telegram.WebApp);
          if (isTG) items.push({k:'TG', v:'Connected', tone:'tg'});

          // These flags are rendered server-side into data attributes if present
          const hasFounders = (document.body.getAttribute('data-has-founders')||'0') === '1';
          const hasAffiliateBadge = (document.body.getAttribute('data-has-affiliate')||'0') === '1';
          if (hasFounders) items.push({k:'Founder', v:'Claimed', tone:'founder'});
          if (hasAffiliateBadge || j.claimed) items.push({k:'Affiliate', v:`Tier ${Math.max(1,tier)}`, tone:'affiliate'});

          if (q >= 10) items.push({k:'Milestone', v:'10 Paid Refs', tone:'m1'});
          if (q >= 25) items.push({k:'Milestone', v:'25 Paid Refs', tone:'m2'});
          if (q >= 50) items.push({k:'Milestone', v:'50 Paid Refs', tone:'m3'});
          if (q >= 100) items.push({k:'Milestone', v:'100 Paid Refs', tone:'m4'});

          ach.innerHTML = items.slice(0,6).map(it=>
            `<button type="button" class="vx-ach" data-tip="${it.k}: ${it.v}" data-tone="${it.tone}"><span class="k">${it.k}</span><span class="v">${it.v}</span></button>`
          ).join('');
        }
      }catch(e){}
    } catch(e) {}
  }

  // Lightweight tooltip: tap any element with data-tip
  (function(){
    let tipEl = null;
    function hide(){ if (tipEl) { tipEl.remove(); tipEl = null; } }
    function show(target, text){
      hide();
      tipEl = document.createElement('div');
      tipEl.className = 'vx-tip';
      tipEl.textContent = text;
      document.body.appendChild(tipEl);
      const r = target.getBoundingClientRect();
      const x = Math.max(10, Math.min(window.innerWidth-10, r.left + r.width/2));
      const y = Math.max(10, r.top - 10);
      tipEl.style.left = x + 'px';
      tipEl.style.top = y + 'px';
      tipEl.style.transform = 'translate(-50%, -100%)';
      setTimeout(hide, 2200);
    }
    document.addEventListener('click', (e)=>{
      const t = e.target.closest('[data-tip]');
      if (!t) return;
      const text = t.getAttribute('data-tip')||'';
      if (!text) return;
      e.preventDefault();
      show(t, text);
      try{ if (window.Telegram && Telegram.WebApp && Telegram.WebApp.HapticFeedback) Telegram.WebApp.HapticFeedback.impactOccurred('light'); }catch(_e){}
    }, true);
    document.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
  })();

  // timer
  setInterval(tickNextMat, 1000);

  // initial
  refreshDaily();
  refreshAffiliate();

  // refresh on visibility change (coming back from buy flow)
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden){
      refreshDaily();
      refreshAffiliate();
    }
  });
})();
