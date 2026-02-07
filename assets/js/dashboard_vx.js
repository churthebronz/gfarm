

// extracted from dashboard.phpdocument.body.classList.add('preload');
(() => {
  const el = document.getElementById('mining_run');
  const pending = document.getElementById('vx-pending');
  let sec = <?= json_encode((float)$profit); ?>;
  const perSecond = <?= json_encode((float)$perSecond); ?>;
  const step = perSecond * 0.1;
  setInterval(() => {
    sec += step;
    el.textContent = '$' + sec.toFixed(6);
    pending.textContent = '$' + sec.toFixed(6);
  }, 100);

  document.querySelector('.vx-claim').addEventListener('click', async (event) => { event.preventDefault(); event.stopPropagation(); if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    event.preventDefault(); event.stopPropagation(); if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    const btn = event.currentTarget;
    btn.disabled = true;
    try {
      const res = await fetch('/api/user/earnings.php?action=claim', { method:'POST', credentials:'include' });
      let data; try { data = await res.json(); } catch(e){ data = { ok:false, msg: 'Non-JSON response' }; }
      try{vxToast(data.msg || 'Yield claimed.');}catch(e){vxToast(data.msg || 'Yield claimed.');}
      if (data.ok) {
        sec = 0;
        el.textContent = '$0.000000';
        pending.textContent = '$0.000000';
      }
    } catch (e) {
      try{vxToast('Error claiming yield.');}catch(e){vxToast('Error claiming yield.');}
    }
    btn.disabled = false;
  });

  const tabs = document.querySelectorAll('.vx-tab');
  const panes = document.querySelectorAll('.vx-pane');
  tabs.forEach(btn => {
    btn.addEventListener('click', () => {
      tabs.forEach(b => b.setAttribute('aria-selected','false'));
      btn.setAttribute('aria-selected','true');
      panes.forEach(p => p.classList.remove('active'));
      document.querySelector(`.vx-pane[data-pane="${btn.dataset.tab}"]`).classList.add('active');
    });
  });
  document.addEventListener("DOMContentLoaded", function () {
  document.body.classList.remove("preload");
  document.documentElement.classList.add("vx-ready");
});
})();