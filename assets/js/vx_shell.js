  // Mark Telegram WebView
  try{ if (window.Telegram && Telegram.WebApp){ document.documentElement.classList.add('vx-is-tg'); } }catch(_e){}
/* GreenFarm TG Mini App Shell */
(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  // Client error capture (fail-soft)
  // Posts to /api/log/js_error.php; rate-limited server-side
  (function(){
    try{
      var sent = 0;
      function postErr(p){
        if (sent >= 4) return;
        sent++;
        p = p || {};
        p.page = p.page || location.pathname + location.search;
        var body = new URLSearchParams();
        Object.keys(p).forEach(function(k){ body.append(k, String(p[k] ?? "")); });
        fetch("/api/log/js_error.php", {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body: body.toString()}).catch(function(){});
      }
      window.addEventListener("error", function(e){
        postErr({msg: e.message || "error", src: (e.filename || ""), line: (e.lineno||0), col: (e.colno||0), stack: e.error && e.error.stack ? e.error.stack : ""});
      });
      window.addEventListener("unhandledrejection", function(e){
        var r = e.reason;
        var msg = "unhandledrejection";
        var stack = "";
        try{
          if (typeof r === "string") msg = r;
          else if (r && r.message) msg = r.message;
          if (r && r.stack) stack = r.stack;
        }catch(_){}
        postErr({msg: msg, src: "promise", line: 0, col: 0, stack: stack});
      });
    }catch(e){}
  })();


  // Capture Telegram start/ref params from URL *or* Telegram initDataUnsafe.start_param
  // This makes referrals work even when Telegram strips query params in WebView.
  (function(){
    try{
      var raw = "";
      // 1) URL params (works for normal browsers and some TG opens)
      var qs = new URLSearchParams(location.search || "");
      raw = qs.get("ref") || qs.get("start") || qs.get("startapp") || qs.get("start_param") || "";
      raw = (raw||"").trim();

      // 2) Telegram WebApp start param (works reliably for deep links: t.me/<bot>?start=...)
      if (!raw) {
        try{
          if (window.Telegram && Telegram.WebApp && Telegram.WebApp.initDataUnsafe){
            raw = (Telegram.WebApp.initDataUnsafe.start_param || "").toString().trim();
          }
        }catch(_e){}
      }

      if (!raw) return;

      // Normalize ref_ prefix
      if (raw.toLowerCase().indexOf("ref_") === 0 && raw.length > 4) raw = raw.substring(4);

      // Persist (cookie+session+optional DB server-side)
      fetch("/api/start_param.php?ref=" + encodeURIComponent(raw), {credentials:"same-origin"}).catch(function(){});

      // Clean URL (keep app looking tidy)
      try{
        qs.delete("ref"); qs.delete("start"); qs.delete("startapp"); qs.delete("start_param");
        var next = location.pathname + (qs.toString() ? ("?"+qs.toString()) : "") + location.hash;
        history.replaceState(null, "", next);
      }catch(e2){}
    }catch(e){}
  })();

  function haptic(type){
    try{
      if (window.Telegram && Telegram.WebApp && Telegram.WebApp.HapticFeedback){
        const h = Telegram.WebApp.HapticFeedback;
        if (type==='light') h.impactOccurred('light');
        else if (type==='medium') h.impactOccurred('medium');
        else if (type==='success') h.notificationOccurred('success');
        else if (type==='error') h.notificationOccurred('error');
      }
    }catch(e){}
  }

  function tgUser(){
    try{
      if (window.Telegram && Telegram.WebApp && Telegram.WebApp.initDataUnsafe && Telegram.WebApp.initDataUnsafe.user){
        return Telegram.WebApp.initDataUnsafe.user;
      }
    }catch(e){}
    return null;
  }

  async function refreshNotif(){
    const dot = $('#vxNotifDot');
    const earnDot = $('#vxDockEarningsDot');
    try{
      const res = await fetch('/api/user/notifications_count.php', {credentials:'same-origin'});
      const j = await res.json();
      const c = (j && j.ok) ? (j.count|0) : 0;
      if (dot){
        if (c>0) dot.classList.add('is-on'); else dot.classList.remove('is-on');
        dot.setAttribute('data-count', String(c));
      }

      // Bottom dock: subtle dot on Earnings when there are unread notifications.
      if (earnDot){
        if (c>0) earnDot.classList.add('is-on'); else earnDot.classList.remove('is-on');
      }
    }catch(e){
      if (dot) dot.classList.remove('is-on');
      if (earnDot) earnDot.classList.remove('is-on');
    }
  }

  // Lightweight login popup for high-priority notifications (guardian ended, legacy award, season snapshot).
  function ensurePopupHost(){
    let host = document.getElementById('vxPopupHost');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'vxPopupHost';
    host.innerHTML = `
      <style>
        #vxPopupHost{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:14px;}
        #vxPopupHost.is-open{display:flex;}
        #vxPopupHost .vx-pop-bd{position:absolute;inset:0;background:rgba(2,6,23,.62);backdrop-filter:blur(10px);}
        #vxPopupHost .vx-pop{position:relative;max-width:520px;width:100%;max-height:min(86vh, 720px);border-radius:22px;border:1px solid rgba(255,255,255,.12);background:linear-gradient(180deg, rgba(11,16,36,.92), rgba(9,13,28,.98));box-shadow:0 30px 90px rgba(0,0,0,.55);overflow:hidden;}
        #vxPopupHost .vx-pop::after{content:"";position:absolute;inset:-2px;background:linear-gradient(135deg, rgba(0,255,224,.22), rgba(88,166,255,.18), rgba(255,209,102,.14));filter:blur(16px);opacity:.22;z-index:0;}
        #vxPopupHost .vx-pop>*{position:relative;z-index:1;}
        #vxPopupHost .vx-pop-h{padding:16px 16px 8px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
        #vxPopupHost .vx-pop-t{margin:0;font-weight:1000;letter-spacing:.02em;font-size:1.15rem;color:#eaf0ff;}
        #vxPopupHost .vx-pop-x{border:none;background:rgba(255,255,255,.06);color:#fff;border-radius:12px;padding:8px 10px;cursor:pointer}
        #vxPopupHost .vx-pop-b{padding:0 16px 14px;color:rgba(226,232,240,.86);line-height:1.55;overflow:auto;max-height:calc(min(86vh, 720px) - 120px);}
        @media (max-width:420px){
          #vxPopupHost{padding:10px;}
          #vxPopupHost .vx-pop{border-radius:18px;}
          #vxPopupHost .vx-pop-h{padding:14px 14px 8px;}
          #vxPopupHost .vx-pop-b{padding:0 14px 12px;}
          #vxPopupHost .vx-pop-a{padding:0 14px 14px;}
        }
        #vxPopupHost .vx-pop-a{padding:0 16px 16px;display:flex;gap:10px;flex-wrap:wrap}
        #vxPopupHost .vx-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);text-decoration:none;color:#eaf0ff;background:rgba(2,6,23,.22);font-weight:900}
        #vxPopupHost .vx-btn.primary{border-color:rgba(0,255,224,.28);background:linear-gradient(135deg, rgba(0,255,224,.20), rgba(88,166,255,.14));}
      </style>
      <div class="vx-pop-bd" data-pop-close></div>
      <div class="vx-pop" role="dialog" aria-modal="true" aria-label="Notification">
        <div class="vx-pop-h">
          <h3 class="vx-pop-t" id="vxPopTitle">Notice</h3>
          <button type="button" class="vx-pop-x" aria-label="Close" data-pop-close>✕</button>
        </div>
        <div class="vx-pop-b" id="vxPopMsg"></div>
        <div class="vx-pop-a" id="vxPopActions"></div>
      </div>
    `;
    document.body.appendChild(host);
    host.addEventListener('click', (e)=>{
      const t = e.target;
      if (t && t.closest('[data-pop-close]')) closePopup();
    });
    return host;
  }

  function closePopup(){
    const host = document.getElementById('vxPopupHost');
    if (!host) return;
    host.classList.remove('is-open');
    try{ document.documentElement.classList.remove('vx-lock'); document.body.classList.remove('vx-lock'); }catch(e){}
  }

  function showPopup(title, msg, actions){
    const host = ensurePopupHost();
    const t = host.querySelector('#vxPopTitle');
    const m = host.querySelector('#vxPopMsg');
    const a = host.querySelector('#vxPopActions');
    if (t) t.textContent = String(title||'Notice');
    if (m) m.textContent = String(msg||'');
    if (a){
      a.innerHTML = '';
      (actions||[]).forEach(it=>{
        const link = document.createElement('a');
        link.className = 'vx-btn' + (it.primary ? ' primary' : '');
        link.href = it.href || '#';
        link.textContent = it.label || 'Open';
        a.appendChild(link);
      });
      const dismiss = document.createElement('a');
      dismiss.className = 'vx-btn';
      dismiss.href = '#';
      dismiss.textContent = 'Dismiss';
      dismiss.addEventListener('click', (e)=>{ e.preventDefault(); closePopup(); });
      a.appendChild(dismiss);
    }
    host.classList.add('is-open');
    try{ document.documentElement.classList.add('vx-lock'); document.body.classList.add('vx-lock'); }catch(e){}
  }

  async function showLoginPopupIfNeeded(){
    try{
      const r = await fetch('/api/user/notifications_feed.php?limit=8', {credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok || !Array.isArray(j.items)) return;
      const item = j.items.find(x=> (x && (x.is_read|0)===0 && (x.level==='warning' || x.level==='success' || x.type==='guardian_ended' || x.type==='legacy_award' || x.type==='guardian_collector')));
      if (!item) return;

      let data = {};
      try{ data = item.data_json ? JSON.parse(item.data_json) : {}; }catch(_e){ data = {}; }
      const cta = (data && data.cta) ? String(data.cta) : '';
      const actions = [];
      if (cta){ actions.push({label:'Open', href:cta, primary:true}); }
      showPopup(item.title || 'Notice', item.message || '', actions);

      // Mark as read so it won't repeat every load.
      try{
        await fetch('/api/user/notifications_mark.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(item.id)});
      }catch(_e){}
    }catch(_e){}
  }


  // NOTE: legacy API uses "genesis_*" field names, but UI copy is Seasons / Mutant Crops / VP.
  async function refreshGenesisUI(){
    // GreenFarm: season snapshot UI removed.
    return;
  }

function vx_measure_topstack(){
  try{
    const topstack = document.getElementById('site-menu');
    const spacer = document.getElementById('vx-topstack-spacer');
    if (!topstack || !spacer) return;
    const rect = topstack.getBoundingClientRect();
    const h = Math.max(0, Math.round(rect.height));
    document.documentElement.style.setProperty('--vx-topstack-h', h + 'px');
    spacer.style.height = `calc(${h}px + env(safe-area-inset-top))`;
  }catch(e){}
}

function vx_remove_snapshot_bars(){
  try{
    const sel = ['#vxSeasonSnapshot','#vxSeasonSnapshotBar','.vx-season-snapshot','.vx-season-snapshotbar','[data-season-snapshot]'];
    sel.forEach(s=> document.querySelectorAll(s).forEach(el=> el.remove()));
  }catch(e){}
}

// Main init (required by DOMContentLoaded hook below)
function wire(){
  try{ vx_remove_snapshot_bars(); }catch(_e){}
  try{ setTopstackHeight(); }catch(_e){}

  // Keep spacer synced
  try{
    window.addEventListener('resize', function(){ try{ setTopstackHeight(); }catch(_e){} }, {passive:true});
  }catch(_e){}

  // Dashboard-only modules
  try{ vxShowOnboard(); }catch(_e){}
  try{ vxDashWidgets(); }catch(_e){}

  // Virality modules (safe on non-dashboard)
  try { injectFounderVaults(); } catch(_e){}
  try { startWhaleFeed(); } catch(_e){}
}


if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', wire);
} else {
  wire();
}
// ---------- One-time onboarding overlay (dashboard only) ----------
  function vxShowOnboard(){
    try{
      const key = 'vx_onboard_v1';
      if (localStorage.getItem(key)==='1') return;
      const path = (location.pathname||'');
      const isDash = /\/user\/dashboard\/?$/.test(path) || (document.querySelector('.account-shell') && document.querySelector('.account-title'));
      if (!isDash) return;

      const wrap = document.createElement('div');
      wrap.className = 'vx-onboard';
      wrap.innerHTML =
        '<div class="vx-onboard-card" role="dialog" aria-modal="true">' +
          '<button class="vx-onboard-x" aria-label="Close">×</button>' +
          '<div class="vx-onboard-title">Season is live</div>' +
          '<div class="vx-onboard-sub">Season snapshot locks standings. Early activity boosts your placement.</div>' +
          '<ul class="vx-onboard-ul">' +
            '<li>Activate a Mutant Crop to earn VP (Harvest Points)</li>' +
            '<li>Harvest Rush gives bonus multipliers</li>' +
            '<li>Invite friends to push your allocation rank</li>' +
          '</ul>' +
          '<a class="vx-onboard-cta" href="/user/plans">🛡️ Activate Mutant Crops</a>' +
          '<div class="vx-onboard-foot">Snapshot locks this season’s standings. Stay active so you don’t miss out.</div>' +
        '</div>';
      document.body.appendChild(wrap);

      const close = ()=>{
        localStorage.setItem(key,'1');
        wrap.remove();
      };
      wrap.addEventListener('click', (e)=>{
        if (e.target===wrap) close();
      });
      wrap.querySelector('.vx-onboard-x').addEventListener('click', close);
      wrap.querySelector('.vx-onboard-cta').addEventListener('click', ()=>{
        localStorage.setItem(key,'1');
      });

      try{
        if (window.Telegram && Telegram.WebApp && Telegram.WebApp.HapticFeedback){
          Telegram.WebApp.HapticFeedback.impactOccurred('light');
        }
      }catch(_e){}
    }catch(_e){}
  }

  // ---------- Dashboard widgets: Harvest Rush pill + Live activity ----------
  // These MUST appear every time the user loads the dashboard.
  // The dashboard markup can be injected late (route closures / PJAX),
  // so we wait until .account-shell exists, and we never mount outside it.
  function vxDashWidgets(){
    try{
      const path = (location.pathname||'');
      const isDash = /\/user\/dashboard\/?$/.test(path) || (document.querySelector('.account-shell') && document.querySelector('.account-title'));
      if (!isDash) return;

      // Prevent duplicates
      if (document.querySelector('.account-shell .vx-dash-widgets')) return;

      const shell = document.querySelector('.account-shell');
      if (!shell) return;

      const header = shell.querySelector('.header-row') || shell.querySelector('.vx-head') || shell.querySelector('.account-header') || shell.firstElementChild || shell;

      const host = document.createElement('div');
      host.className = 'vx-dash-widgets';
      host.setAttribute('data-vx','dash-widgets');
      header.insertAdjacentElement('afterend', host);

      const rush = document.createElement('div');
      rush.className='vx-rush-pill';
      rush.innerHTML = '<div class="t">Harvest Rush</div><div class="v">Loading…</div>';
      host.appendChild(rush);

      fetch('/api/user/event_status.php', {credentials:'include'})
        .then(r=>r.json()).then(d=>{
          if(!d || !d.ok) throw new Error('bad');
          const v = rush.querySelector('.v');
          const now = Number(d.now)||Math.floor(Date.now()/1000);
          const fmt = (secs)=>{
            secs = Math.max(0, Math.floor(secs||0));
            const h = Math.floor(secs/3600);
            const m = Math.floor((secs%3600)/60);
            const s = secs%60;
            const pad = (x)=> String(x).padStart(2,'0');
            return (h>0? (h+':'+pad(m)+':'+pad(s)) : (m+':'+pad(s)));
          };
          if(d.active){
            rush.classList.add('live');
            v.textContent = 'LIVE · 2× VP · ends in ' + fmt(d.seconds_left);
          } else {
            rush.classList.remove('live');
            const next = Number(d.next_start_at)||0;
            v.textContent = 'Next in ' + fmt(next - now);
          }
        }).catch(()=>{ rush.querySelector('.v').textContent='—'; });

      const proof = document.createElement('div');
      proof.className='vx-proof';
      proof.innerHTML =
        '<div class="vx-proof-title">Live activity</div>' +
        '<div class="vx-proof-grid">' +
          '<div><b id="vxProofVaults">—</b><span>Mutant Crops activated today</span></div>' +
          '<div><b id="vxProofCheckins">—</b><span>Claims today</span></div>' +
          '<div><b id="vxProofActive">—</b><span>Active today</span></div>' +
          '<div><b id="vxProofRank">—</b><span>Your rank today</span></div>' +
        '</div>' +
        '<div class="vx-proof-foot">Seasons lock standings at snapshot. Keep your Mutant Crops active and climb the ranks.</div>';
      host.appendChild(proof);

      fetch('/api/user/social_proof.php', {credentials:'include'})
        .then(r=>r.json()).then(d=>{
          if(!d || !d.ok) throw new Error('bad');
          const set=(id,val)=>{ const el=document.getElementById(id); if(el) el.textContent=String(val); };
          set('vxProofVaults', d.vaults_today);
          set('vxProofCheckins', d.checkins_today);
          set('vxProofActive', d.active_today);
        }).catch(()=>{});

      // Rank today
      fetch('/api/user/rank_today.php', {credentials:'include'})
        .then(r=>r.json()).then(d=>{
          if(!d || !d.ok) throw new Error('bad');
          const el=document.getElementById('vxProofRank');
          if(el) el.textContent = d.rank ? ('#'+String(d.rank)) : '—';
        }).catch(()=>{});
    }catch(_e){}
  }

  // Run dash widgets after DOM is ready, and retry briefly in case the dashboard
  // content is injected after the shell script executes.
  function ensureDashWidgets(){
    try{
      const path = (location.pathname||'');
      const isDash = /\/user\/dashboard\/?$/.test(path);
      if (!isDash) return;
      let tries = 0;
      const timer = setInterval(()=>{
        tries++;
        // stop if we left the dashboard
        if (!/\/user\/dashboard\/?$/.test(location.pathname||'')) { clearInterval(timer); return; }
        vxDashWidgets();
        if (document.querySelector('.account-shell .vx-dash-widgets') || tries >= 40) {
          clearInterval(timer);
        }
      }, 250);
    } catch(_e){}
  }


  // init (after DOM ready). Widgets should show every load; onboarding is one-time.
  function initDashUX(){
    try{ vxShowOnboard(); }catch(_e){}
    try{ ensureDashWidgets(); }catch(_e){}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDashUX);
  else initDashUX();

  // -------- Season Snapshot pill + badge + high-tier activity feed --------
  function fmtDur(sec){
    sec = Math.max(0, parseInt(sec||0,10));
    var d = Math.floor(sec/86400); sec -= d*86400;
    var h = Math.floor(sec/3600); sec -= h*3600;
    var m = Math.floor(sec/60);   sec -= m*60;
    if (d>0) return d+'d '+String(h).padStart(2,'0')+'h';
    if (h>0) return h+'h '+String(m).padStart(2,'0')+'m';
    return m+'m '+String(sec).padStart(2,'0')+'s';
  }

  function openShareModal(text){
    try{
	    try{ document.documentElement.classList.add('vx-lock'); document.body.classList.add('vx-lock'); }catch(e){}
      var wrap = document.createElement('div');
      wrap.className = 'vx-share-modal';
      wrap.innerHTML = `
        <div class="vx-share-card">
          <div class="vx-share-title">Share your proof</div>
          <div class="vx-share-text"></div>
          <div class="vx-share-actions">
            <button class="vx-btn vx-btn-primary" data-act="share">Share in Telegram</button>
            <button class="vx-btn" data-act="copy">Copy</button>
            <button class="vx-btn vx-btn-ghost" data-act="close">Close</button>
          </div>
        </div>`;
      wrap.querySelector('.vx-share-text').textContent = text;
      document.body.appendChild(wrap);

	    function closeWrap(){
	      try{ wrap.remove(); }catch(_e){}
	      try{ document.documentElement.classList.remove('vx-lock'); document.body.classList.remove('vx-lock'); }catch(_e2){}
	    }

	      wrap.addEventListener('click', function(e){
        var act = e.target && e.target.getAttribute('data-act');
	        if (!act) { if (e.target === wrap) { closeWrap(); } return; }
	        if (act === 'close') { closeWrap(); return; }
        if (act === 'copy') {
          try { navigator.clipboard && navigator.clipboard.writeText(text); toast('Copied'); } catch(_){}
          return;
        }
        if (act === 'share') {
          var url = 'https://t.me/share/url?url=&text=' + encodeURIComponent(text);
          try {
            if (window.Telegram && Telegram.WebApp && Telegram.WebApp.openTelegramLink) Telegram.WebApp.openTelegramLink(url);
            else window.open(url,'_blank');
          } catch(_) { window.open(url,'_blank'); }
        }
      }, {passive:true});
    } catch(_){}
  }

  // Expose for pages that want to trigger a one-tap brag prompt
  window.vxOpenShareModal = openShareModal;

  function injectSnapshotPill(){
    var host = document.querySelector('.vx-top, header, .topbar, body'); // best-effort
    if (!host) return;

    // Create container once
    if (document.querySelector('.vx-snapshot-pill')) return;
    var pill = document.createElement('a');
    pill.className = 'vx-snapshot-pill';
    pill.href = '/user/rewards';
    pill.innerHTML = `<span class="vx-snp-dot"></span><span class="vx-snp-t1">Season Snapshot</span><span class="vx-snp-t2">Loading…</span>`;
    // Insert near top
    try {
      var anchor = document.querySelector('.vx-hero, .vx-balance, .container, main');
      if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(pill, anchor);
      else document.body.insertBefore(pill, document.body.firstChild);
    } catch(_) { document.body.appendChild(pill); }

    fetch('/api/user/snapshot_status.php', {credentials:'include'})
      .then(r=>r.json()).then(function(d){
        if(!d || !d.ok) return;
        var sec = d.seconds_left || 0;
        var live = !!d.genesis_live;
        var t2 = live ? ('Ends in ' + fmtDur(sec)) : 'Snapshot closed';
        pill.querySelector('.vx-snp-t2').textContent = t2;

        if (d.has_genesis_badge) {
          pill.classList.add('has-badge');
          pill.querySelector('.vx-snp-t1').textContent = 'Season Holder';
          pill.querySelector('.vx-snp-t2').textContent = live ? ('Snapshot in ' + fmtDur(sec)) : 'Season secured';
          pill.addEventListener('click', function(ev){
            // Share proof
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(_){}
            openShareModal('🏅 I’m active in the current GreenFarm season — join me: ' + location.origin);
            return false;
          });
        }
      }).catch(function(){});
  }

  function injectFounderVaults(){
    // Only on home/dashboard pages (best-effort)
    var path = location.pathname || '';
    if (!(path === '/' || path === '/home' || path.indexOf('/user/dashboard')>=0 || path.indexOf('/pages/home')>=0 || path.indexOf('/user/index')>=0)) return;

    if (document.querySelector('.vx-founder')) return;

    var mount = document.querySelector('.vx-season, .vx-hero, .container, main');
    if (!mount) return;

    var box = document.createElement('section');
    box.className = 'vx-founder';
    box.innerHTML = `
      <div class="vx-founder-h">
        <div class="vx-founder-title">High-tier Mutant Crops</div>
        <div class="vx-founder-sub">Limited season window • Stay active</div>
      </div>
      <div class="vx-founder-grid">
        <button class="vx-founder-card" data-amt="10000">
          <div class="vx-fc-top">
            <div class="vx-fc-name">$10,000 Mutant Crop</div>
            <div class="vx-fc-tag">Cap 5</div>
          </div>
          <div class="vx-fc-mid">Reserve a slot, then deposit.</div>
          <div class="vx-fc-cta">Reserve & Deposit</div>
        </button>
        <button class="vx-founder-card big" data-amt="25000">
          <div class="vx-fc-top">
            <div class="vx-fc-name">$25,000 Mega Seed</div>
            <div class="vx-fc-tag">Cap 2</div>
          </div>
          <div class="vx-fc-mid">Ultimate season flex.</div>
          <div class="vx-fc-cta">Reserve & Deposit</div>
        </button>
      </div>
      <div class="vx-founder-note">📸 Snapshot locks season standings.</div>
    `;

    mount.parentNode.insertBefore(box, mount.nextSibling);

    box.addEventListener('click', function(e){
      var btn = e.target.closest('.vx-founder-card');
      if (!btn) return;
      var amt = btn.getAttribute('data-amt');
      haptic('light');
      fetch('/api/user/founder_reserve.php?amount=' + encodeURIComponent(amt), {method:'POST', credentials:'include'})
        .then(r=>r.json()).then(function(d){
          if (!d || !d.ok) {
            if (d && d.error === 'sold_out') return toast('Sold out');
            if (d && d.error === 'already_reserved') return toast('Already reserved');
            if (d && d.error === 'snapshot_closed') return toast('Snapshot closed');
            return toast('Try again');
          }
          toast('Reserved');
          // Big vault toast
          if (String(amt) === '25000') toast('🐋 Whale vault reserved');
          // Share proof prompt
          setTimeout(function(){
            openShareModal('🐋 I just activated a top-tier Mutant Crop on GreenFarm before the season snapshot. Join me: ' + location.origin);
          }, 600);
          // Redirect
          if (d.redirect) location.href = d.redirect;
        }).catch(function(){ toast('Network error'); });
    }, {passive:false});
  }

  function startWhaleFeed(){
    var lastId = 0;
    try { lastId = parseInt(localStorage.getItem('vx_whale_last')||'0',10)||0; } catch(_){}
    setInterval(function(){
      fetch('/api/user/big_vault_feed.php?limit=5', {credentials:'include'})
        .then(r=>r.json()).then(function(d){
          if(!d || !d.ok || !d.items || !d.items.length) return;
          var newest = d.items[0];
          if(!newest || !newest.id) return;
          if (newest.id <= lastId) return;
          lastId = newest.id;
          try { localStorage.setItem('vx_whale_last', String(lastId)); } catch(_){}
          if (newest.amount >= 25000) toast('🐋 A $25,000 Mega Seed was just reserved');
          else if (newest.amount >= 10000) toast('💎 A $10,000 Mutant Crop was just activated');
        }).catch(function(){});
    }, 20000);
  }

  // Init runs in wire() on DOMContentLoaded.

})();

// --- GF SLIDER GUARD (added) ---
(function(){
  const _set = CSSStyleDeclaration.prototype.setProperty;
  CSSStyleDeclaration.prototype.setProperty = function(prop, value){
    try{
      if(this._ownerElement && this._ownerElement.classList &&
         this._ownerElement.classList.contains('gf-track') &&
         prop === 'transform'){
        return;
      }
    }catch(e){}
    return _set.apply(this, arguments);
  };
})();
