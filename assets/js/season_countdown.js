(function(){
  const el = document.querySelector('[data-season-countdown]');
  if(!el) return;
  const endTs = parseInt(el.dataset.endTs || '0', 10);
  function fmt(ms){
    const s = Math.max(0, Math.floor(ms/1000));
    const d = Math.floor(s/86400);
    const h = Math.floor((s%86400)/3600);
    const m = Math.floor((s%3600)/60);
    return `${d}d ${h}h ${m}m`;
  }
  function tick(){
    const now = Date.now();
    const left = Math.max(0, endTs*1000 - now);
    el.textContent = fmt(left);
    requestAnimationFrame(()=> setTimeout(tick, 1000*10));
  }
  tick();
})();