

// extracted from home.php/* Reveal after CSS ready (anti-FOUC) */
document.addEventListener('DOMContentLoaded', ()=> {
  const s = document.getElementById('vx-critical-hide');
  if (s) s.remove();
  document.body.style.opacity = '1';
});

/* Expand TG webapp viewport if present */
if (window.Telegram && Telegram.WebApp) { Telegram.WebApp.expand(); }

/* ===== Device-aware video aspect ratio ===== */
(function(){
  const root = document.documentElement;
  const lsOverride = localStorage.getItem('vx_media_ar');
  const isTG = !!(window.Telegram && Telegram.WebApp);
  let platform = isTG ? (Telegram.WebApp.platform || '') : '';
  let ar, mh;

  if (lsOverride) {
    ar = lsOverride;
    mh = (window.innerWidth < 768 ? '180px' : '210px');
  } else if (isTG) {
    root.setAttribute('data-tg','1');
    root.setAttribute('data-tg-platform', platform);
    if (platform === 'ios') { ar = '1 / 1'; mh = '200px'; }
    else { ar = '4 / 3'; mh = '200px'; }
  } else {
    ar = (window.innerWidth < 768) ? '16 / 10' : '16 / 9';
    mh = (window.innerWidth < 768) ? '170px' : '210px';
  }

  root.style.setProperty('--media-aspect-current', ar);
  root.style.setProperty('--media-maxh-current', mh);

  window.addEventListener('resize', ()=>{
    if (lsOverride || isTG) return;
    const ar2 = (window.innerWidth < 768) ? '16 / 10' : '16 / 9';
    const mh2 = (window.innerWidth < 768) ? '170px' : '210px';
    root.style.setProperty('--media-aspect-current', ar2);
    root.style.setProperty('--media-maxh-current', mh2);
  });
})();

/* Card reveal */
const cards = document.querySelectorAll('.vault-card');
const observer = new IntersectionObserver(es=>{
  es.forEach(x=>{ if(x.isIntersecting){ x.target.classList.add('visible'); observer.unobserve(x.target); } });
},{threshold:0.2});
cards.forEach(c=>observer.observe(c));

/* FAQ accordion */
document.querySelectorAll('.faq-card').forEach(card=>{
  const header = card.querySelector('.faq-header');
  const body = card.querySelector('.faq-body');
  const inner = card.querySelector('.faq-inner');
  const arrow = card.querySelector('.faq-arrow i');

  const ro = new ResizeObserver(() => {
    if(card.classList.contains('open') && body.style.maxHeight !== 'none'){
      body.style.maxHeight = inner.offsetHeight + 'px';
    }
  });
  ro.observe(inner);

  const open = () => {
    body.style.maxHeight = inner.offsetHeight + 'px';
    requestAnimationFrame(()=>{ card.classList.add('open'); arrow.style.transform='rotate(180deg)'; });
    const onEnd = (e)=>{
      if(e.propertyName !== 'max-height') return;
      body.style.maxHeight='none';
      body.removeEventListener('transitionend', onEnd);
    };
    body.addEventListener('transitionend', onEnd);
    card.setAttribute('aria-expanded','true');
  };

  const close = () => {
    const currentHeight = (body.style.maxHeight==='none') ? inner.offsetHeight : body.scrollHeight;
    body.style.maxHeight = currentHeight + 'px';
    void body.offsetHeight;
    card.classList.remove('open');
    arrow.style.transform='rotate(0deg)';
    body.style.maxHeight = '0px';
    card.setAttribute('aria-expanded','false');
  };

  const toggle = () => card.classList.contains('open') ? close() : open();
  header.addEventListener('click', toggle);
  card.addEventListener('keydown', e=>{
    if(e.key==='Enter' || e.key===' '){ e.preventDefault(); toggle(); }
  });
});

/* Projection calculator logic (free typing; backspace-friendly; min 100; max 10M) */
(function(){
  const VX_CONV = <?= (int)$VX_CONV ?>;
  const VX_USD  = <?= json_encode($VX_USD) ?>;
  const MINPTS  = <?= (int)$PTS_MIN_UI ?>;
  const MAXPTS  = <?= (int)$PTS_MAX_UI ?>;

  const input = document.getElementById('vxPtsInput');
  const range = document.getElementById('vxPtsRange');
  const vxOut = document.getElementById('vxOut');
  const usdOut= document.getElementById('usdOut');

  let debounceId = null;

  function compute(n){
    if (!Number.isFinite(n) || n < MINPTS || n > MAXPTS) return null;
    const vx  = Math.floor(n / VX_CONV);
    const usd = vx * VX_USD;
    return { vx, usd };
  }
  function render(res){
    if (res){
      vxOut.textContent  = res.vx.toLocaleString(undefined);
      usdOut.textContent = (Math.round(res.usd*100)/100).toFixed(2);
    } else {
      vxOut.textContent  = '—';
      usdOut.textContent = '—';
    }
  }
  function clamp(n){
    if (!Number.isFinite(n)) return MINPTS;
    if (n < MINPTS) return MINPTS;
    if (n > MAXPTS) return MAXPTS;
    return Math.round(n); // allow any integer
  }

  function recalcFromInput(live=false){
    const raw = (input.value || '').trim();
    if (raw === ''){
      if (live) { render(null); return; }
      // On commit with empty, set to MIN
      input.value = MINPTS;
      range.value = MINPTS;
      render(compute(MINPTS));
      return;
    }
    const n = parseInt(raw, 10);
    if (Number.isNaN(n)){
      if (live) { render(null); return; }
      input.value = MINPTS;
      range.value = MINPTS;
      render(compute(MINPTS));
      return;
    }
    // Live preview (no clamp) and keep slider in sync if within bounds
    render(compute(n));
    if (n >= MINPTS && n <= MAXPTS) range.value = n;

    // Debounced commit: after pause, clamp and finalize
    clearTimeout(debounceId);
    debounceId = setTimeout(()=>{
      const committed = clamp(n);
      input.value = committed;
      range.value = committed;
      render(compute(committed));
    }, 900);
  }

  function recalcFromRange(){
    const n = clamp(parseInt(range.value,10));
    range.value = n;
    input.value = n;
    render(compute(n));
  }

  input.addEventListener('input', ()=> recalcFromInput(true));   // backspace-friendly preview
  input.addEventListener('change', ()=> recalcFromInput(false)); // commit on change
  input.addEventListener('blur',   ()=> recalcFromInput(false)); // commit on blur
  range.addEventListener('input',  recalcFromRange);

  // Default to 100 pts specifically when inside Telegram WebApp (homepage)
  const isTG = !!(window.Telegram && Telegram.WebApp);
  if (isTG) {
    input.value = MINPTS;
    range.value = MINPTS;
  }

  // Initial render
  recalcFromInput(false);
})();