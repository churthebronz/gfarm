/*
  GreenFarm Live Activity Ticker (Marquee)
  - Renders a looping, horizontal ticker inside a root element.
  - Designed for Telegram Mini App + normal web.
  Usage:
    <div id="vx-activity-ticker"></div>
    <script src="/assets/js/activity_ticker.js" data-endpoint="/api/activity_feed.php" data-root="vx-activity-ticker" defer></script>

  Endpoint expected shape:
    { ok:true, items:[ {ts, type, user, delta, amount, currency, ctx, msg} ], server_time }
  Notes:
  - This is a PUBLIC social proof surface; do not include sensitive user data.
*/

(function () {
  const script = document.currentScript;
  if (!script) return;

  const endpoint = script.getAttribute('data-endpoint') || '/api/activity_feed.php';
  const rootId = script.getAttribute('data-root') || '';
  const root = rootId ? document.getElementById(rootId) : null;
  if (!root) return;

  // ---- CSS (injected once) ----
  const STYLE_ID = 'vx-activity-ticker-style';
  if (!document.getElementById(STYLE_ID)) {
    const st = document.createElement('style');
    st.id = STYLE_ID;
    st.textContent = `
      .vx-ticker{position:relative;display:flex;align-items:center;gap:10px;width:100%;
        border-radius:16px;border:1px solid rgba(255,255,255,.10);
        background:linear-gradient(180deg, rgba(5,7,18,0.92), rgba(5,7,18,0.78));
        box-shadow:0 14px 45px rgba(0,0,0,.35);overflow:hidden;}
      .vx-ticker:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;
        background:linear-gradient(90deg, rgba(251,191,36,.95), rgba(0,255,224,.95), rgba(56,189,248,.95));
        opacity:.85;}
      .vx-ticker-label{display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;white-space:nowrap;
        padding:10px 12px 10px 12px;margin:0 0 0 10px;border-radius:999px;
        border:1px solid rgba(255,255,255,.12);background:rgba(2,6,23,.30);
        font-weight:950;letter-spacing:.04em;text-transform:uppercase;
        font-size:clamp(12px, 2.3vw, 13px);color:rgba(248,249,255,.96);}
      .vx-ticker-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;
        box-shadow:0 0 0 0 rgba(34,197,94,.75);animation:vxTickerPulse 1.6s infinite;}
      @keyframes vxTickerPulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.65)}70%{box-shadow:0 0 0 12px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
      .vx-ticker-viewport{position:relative;flex:1 1 auto;min-width:0;padding:10px 10px;}
      .vx-ticker-track{display:flex;gap:14px;align-items:center;white-space:nowrap;will-change:transform;
        animation: vxTickerScroll var(--vx-ticker-duration, 22s) linear infinite;
        transform:translate3d(0,0,0);}
      .vx-ticker:hover .vx-ticker-track{animation-play-state:paused;}
      @keyframes vxTickerScroll{0%{transform:translate3d(0,0,0)}100%{transform:translate3d(calc(-1 * var(--vx-ticker-shift, 50%)),0,0)}}
      .vx-ticker-item{display:inline-flex;align-items:center;gap:10px;flex:0 0 auto;line-height:1.15;
        padding:8px 10px;border-radius:14px;border:1px solid rgba(148,163,184,.16);
        background:rgba(15,23,42,.70);box-shadow:0 10px 26px rgba(0,0,0,.20);}
      .vx-ti-emoji{font-size:18px;line-height:1;}
      .vx-ti-user{font-weight:950;color:rgba(248,249,255,.96);}
      .vx-ti-msg{color:rgba(226,232,240,.86);font-weight:850;}
      .vx-ti-time{color:rgba(226,232,240,.65);font-size:12px;font-weight:850;}
      .vx-ticker-skel{display:flex;align-items:center;gap:10px;padding:12px 14px;color:rgba(226,232,240,.75);}
      .vx-ticker-skel span{display:inline-block;height:10px;border-radius:999px;background:rgba(148,163,184,.18);} 
      .vx-ticker-skel .a{width:60px}.vx-ticker-skel .b{width:160px}.vx-ticker-skel .c{width:90px}
      @media (max-width:520px){
        .vx-ticker{border-radius:14px;}
        .vx-ticker-label{margin-left:8px;padding:8px 10px;font-size:12px;}
        .vx-ticker-viewport{padding:8px 8px;}
        .vx-ticker-item{padding:7px 9px;border-radius:13px;}
        .vx-ti-emoji{font-size:17px;}
      }
      @media (prefers-reduced-motion: reduce){
        .vx-ticker-track{animation:none !important;}
        .vx-ticker-dot{animation:none !important;}
      }
    `;
    document.head.appendChild(st);
  }

  // ---- Helpers ----
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[c]));

  function shortUser(u) {
    const s = String(u || '').trim();
    if (!s) return 'Someone';
    // Viral/social-proof: show Telegram handle when available, truncate very long names.
    const raw = s.startsWith('@') ? s : ('@' + s);
    if (raw.length <= 18) return raw;
    return raw.slice(0, 16) + '…';
  }

  function emojiFor(item) {
    const provided = String(item?.emoji || '').trim();
    if (provided) return provided;
    const t = String(item?.type || '').toLowerCase();
    const ctx = String(item?.ctx || '').toLowerCase();
    const d = Number(item?.delta || 0);
    if (t === 'deposit') return '💰';
    if (t === 'withdraw') return '🏧';
    if (t === 'vault' || ctx.includes('plan')) return '🔒';
    if (t === 'ref' || ctx.includes('ref')) return '🧲';
    if (t === 'points' || ctx.includes('points')) return d >= 0 ? '⭐' : '🧾';
    if (t === 'prime' || ctx.includes('prime')) return '👑';
    return '⚡';
  }

  function labelFor(item) {
    // Prefer server-provided msg if present.
    const m = String(item?.msg || item?.text || '').trim();
    if (m) return m;

    const t = String(item?.type || '').toLowerCase();
    const d = Number(item?.delta || 0);
    const amt = Number(item?.amount || 0);
    const cur = String(item?.currency || '').toUpperCase();
    if (t === 'deposit') return `deposited ${amt ? '$' + amt.toFixed(0) : ''} ${cur || ''}`.trim();
    if (t === 'withdraw') return `withdrew ${amt ? '$' + amt.toFixed(0) : ''} ${cur || ''}`.trim();
    if (t === 'vault') return `activated a vault`;
    if (t === 'ref') return `earned referral rewards`;
    if (t === 'points') return d >= 0 ? `earned ${Math.abs(d).toLocaleString()} Points` : `spent ${Math.abs(d).toLocaleString()} Points`;
    return 'activity recorded';
  }

  function relTime(ts) {
    const n = Number(ts || 0);
    if (!Number.isFinite(n) || n <= 0) return '';
    const sec = Math.max(0, Math.floor(Date.now() / 1000) - n);
    if (sec < 10) return 'now';
    if (sec < 60) return `${sec}s`; 
    const m = Math.floor(sec / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h`;
    const d = Math.floor(h / 24);
    return `${d}d`;
  }

  // ---- Render skeleton immediately ----
  root.innerHTML = `
    <div class="vx-ticker" aria-label="Live activity">
      <div class="vx-ticker-label"><span class="vx-ticker-dot"></span> Live</div>
      <div class="vx-ticker-viewport">
        <div class="vx-ticker-skel" aria-hidden="true"><span class="a"></span><span class="b"></span><span class="c"></span></div>
      </div>
    </div>
  `;

  const viewport = root.querySelector('.vx-ticker-viewport');

  // ---- State ----
  const state = {
    since: 0,
    items: [],
    lastOk: 0,
  };

  function buildTrack(items) {
    const safe = (items || []).slice(0, 18);
    if (!safe.length) {
      viewport.innerHTML = `<div class="vx-ticker-skel"><span class="a"></span><span class="b"></span><span class="c"></span></div>`;
      return;
    }

    const htmlItems = safe.map((it) => {
      const e = emojiFor(it);
      const u = shortUser(it.user || it.username);
      const msg = labelFor(it);
      const t = relTime(it.ts);
      return `
        <span class="vx-ticker-item">
          <span class="vx-ti-emoji">${esc(e)}</span>
          <span class="vx-ti-user">${esc(u)}</span>
          <span class="vx-ti-msg">${esc(msg)}</span>
          <span class="vx-ti-time">${esc(t)}</span>
        </span>
      `.trim();
    }).join('');

    // Duplicate items to create a seamless loop.
    viewport.innerHTML = `<div class="vx-ticker-track" role="marquee">${htmlItems}${htmlItems}</div>`;

    // Set shift and duration based on rendered width.
    requestAnimationFrame(() => {
      const track = viewport.querySelector('.vx-ticker-track');
      if (!track) return;

      // Half width because we duplicated content.
      const total = track.scrollWidth;
      const half = Math.max(200, Math.floor(total / 2));
      track.style.setProperty('--vx-ticker-shift', half + 'px');

      // Duration scaled with distance: slower on desktop, slightly faster on mobile.
      const vw = Math.max(320, window.innerWidth || 1024);
      const pxPerSec = vw < 520 ? 55 : 75;
      const dur = Math.max(14, Math.min(48, Math.round(half / pxPerSec)));
      track.style.setProperty('--vx-ticker-duration', dur + 's');
    });
  }

  // Recompute ticker sizing on resize/orientation changes so it stays smooth on
  // desktop (resizable) and mobile (rotate) without getting clipped or jittery.
  let _resizeT;
  function handleResize() {
    clearTimeout(_resizeT);
    _resizeT = setTimeout(() => {
      if (state.items && state.items.length) buildTrack(state.items);
    }, 180);
  }
  window.addEventListener('resize', handleResize, { passive: true });
  window.addEventListener('orientationchange', handleResize, { passive: true });

  async function fetchFeed() {
    const url = new URL(endpoint, window.location.origin);
    if (state.since) url.searchParams.set('since', String(state.since));
    url.searchParams.set('limit', '16');

    const r = await fetch(url.toString(), { credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const j = await r.json();
    if (!j || j.ok !== true) throw new Error('bad payload');
    return j;
  }

  function mergeItems(newItems) {
    const all = [...(newItems || []), ...state.items];
    const seen = new Set();
    const out = [];
    for (const it of all) {
      const k = `${it.ts || 0}|${it.type || ''}|${it.user || ''}|${it.delta || ''}|${it.amount || ''}|${it.ctx || ''}|${it.msg || ''}`;
      if (seen.has(k)) continue;
      seen.add(k);
      out.push(it);
      if (out.length >= 18) break;
    }
    state.items = out;
    // Move since forward (max ts we have).
    const maxTs = out.reduce((m, x) => Math.max(m, Number(x.ts || 0)), state.since);
    if (maxTs) state.since = maxTs;
  }

  async function tick() {
    try {
      const data = await fetchFeed();
      mergeItems(data.items || []);
      buildTrack(state.items);
      state.lastOk = Date.now();
    } catch (e) {
      // If we have something already, keep it. Otherwise show subtle message.
      if (!state.items.length) {
        viewport.innerHTML = `<div class="vx-ticker-skel">⚠️ Live feed unavailable</div>`;
      }
    }
  }

  // Initial + poll
  tick();
  setInterval(tick, 12000);

  // Re-measure on resize (keeps perfect on mobile/pc)
  let resizeT = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeT);
    resizeT = setTimeout(() => buildTrack(state.items), 250);
  });
})();
