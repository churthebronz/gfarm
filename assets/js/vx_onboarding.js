(function(){
  "use strict";

  function isTG(){ return !!(window.Telegram && Telegram.WebApp); }
  function haptic(){
    try {
      if (!isTG()) return;
      Telegram.WebApp.HapticFeedback.impactOccurred('light');
    } catch(e){}
  }

  const root = document.getElementById('vx-onboarding');
  if (!root) return;

  const steps = Array.from(root.querySelectorAll('[data-step]'));
  const btnNext = root.querySelector('[data-next]');
  const btnSkip = root.querySelector('[data-skip]');
  const btnDone = root.querySelector('[data-done]');
  const chkHide = root.querySelector('#vxObHide');
  let idx = 0;

  function show(i){
    idx = Math.max(0, Math.min(steps.length-1, i));
    steps.forEach((el, n)=>{ el.style.display = (n===idx)?'block':'none'; });
    if (btnNext) btnNext.style.display = (idx < steps.length-1)?'inline-flex':'none';
    if (btnDone) btnDone.style.display = (idx === steps.length-1)?'inline-flex':'none';
  }

  async function markDone(){
    try {
      await fetch('/api/user/onboarding_done.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}});
    } catch(e){}
  }

  function close(){
    root.classList.remove('is-on');
    root.setAttribute('aria-hidden','true');
    try{ document.documentElement.classList.remove('vx-lock'); document.body.classList.remove('vx-lock'); }catch(e){}
    try{ if (chkHide && chkHide.checked) { markDone(); } }catch(e){}
  }

  if (btnSkip) btnSkip.addEventListener('click', ()=>{ haptic(); try{ if (chkHide) chkHide.checked = true; }catch(e){}
    close(); });
  if (btnNext) btnNext.addEventListener('click', ()=>{ haptic(); show(idx+1); });
  if (btnDone) btnDone.addEventListener('click', ()=>{
    haptic();
    // Nudge share on finish
    try {
      const shareBtn = document.querySelector('[data-vx-share]');
      if (shareBtn) shareBtn.click();
    } catch(e){}
    try{ if (chkHide) chkHide.checked = true; }catch(e){}
    close();
  });

  // Open
  try{ document.documentElement.classList.add('vx-lock'); document.body.classList.add('vx-lock'); }catch(e){}
  root.classList.add('is-on');
  root.setAttribute('aria-hidden','false');
  show(0);
})();
