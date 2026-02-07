
(function(){
  function lock(btn){
    if(!btn) return;
    btn.setAttribute('disabled','disabled');
    btn.dataset._origHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Please wait…';
  }
  function unlock(btn){
    if(!btn) return;
    btn.removeAttribute('disabled');
    if(btn.dataset._origHtml){ btn.innerHTML = btn.dataset._origHtml; delete btn.dataset._origHtml; }
  }
  async function post(url, data){
    try{
      const r = await fetch(url, {
        method:'POST',
        headers:{
          'X-Requested-With':'XMLHttpRequest',
          'Accept':'application/json',
          'Content-Type':'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams(data)
      });
      const ct = r.headers.get('content-type')||'';
      if(ct.includes('application/json')) return await r.json();
      return { ok: r.ok, msg: r.ok ? 'Done' : 'Failed' };
    }catch(e){ return { ok:false, msg:'Network error' }; }
  }

  // 4a) Intercept claim forms (no refresh)
  document.addEventListener('submit', function(e){
    const form = e.target.closest('form');
    if(!form) return;
    const btn = form.querySelector('[type="submit"]');
    const hasClaim = form.querySelector('[name="claim"]') || (btn && btn.name==='claim');
    const isAjaxForm = form.matches('[data-ajax="1"], .vx-ajax');
    if(!hasClaim && !isAjaxForm) return; // Only touch claim or explicit ajax forms
    e.preventDefault();
    lock(btn || form.querySelector('button, input[type=submit]'));

    const data = {};
    const fd = new FormData(form);
    fd.forEach((v,k)=>{ data[k]=v; });

    // If it's a claim handler, guarantee claim=1
    if (hasClaim && !('claim' in data)) data['claim'] = '1';

    const url = (form.getAttribute('action') || location.pathname) + (form.getAttribute('action')?.includes('?') ? '&' : '?') + 'ajax=1';
    post(url, data).then(function(r){
      unlock(btn || form.querySelector('button, input[type=submit]'));
      if (window.VXModal) VXModal.show(r.msg || (r.ok?'Success':'Failed'), !!r.ok);
    });
  }, true);

  // 4b) Buttons using data-action="buy-vault"
  document.addEventListener('click', function(e){
    const t = e.target.closest('button, a');
    if(!t) return;
    if (t.dataset.action === 'buy-vault') {
      e.preventDefault();
      const form = t.closest('form');
      const data = {};
      if (form){
        const fd = new FormData(form);
        fd.forEach((v,k)=>{ data[k]=v; });
      }
      data['item'] = data['item'] || '1';
      lock(t);
      post('/user/plans?ajax=1', data).then(function(r){
        unlock(t);
        if (window.VXModal) VXModal.show(r.msg || (r.ok?'Purchase successful':'Failed'), !!r.ok);
      });
    }
  }, true);
})();\n
  // Claim yield (no refresh)
  document.addEventListener('click', async function(ev){
    const t = ev.target.closest('.vx-claim');
    if(!t) return;
    ev.preventDefault();
    try{
      lock(t);
      const r = await fetch('/api/user/earnings.php?action=claim', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}});
      const j = await r.json();
      unlock(t);
      const ok = !!j.ok;
      const msg = j.msg || (ok ? 'Yield collected' : 'Failed to collect');
      if (window.VXModal) VXModal.show(msg, ok); else alert(msg);
      if (ok){
        // Update pending to 0 and balance rendering if present
        const balEl = document.querySelector('[data-balance],[id="vx-balance"]');
        if (balEl && j.balance != null){
          const n = parseFloat(j.balance);
          if (!isNaN(n)) balEl.textContent = '$' + n.toFixed(2);
        }
        const pendEl = document.querySelector('[data-pending],[id="vx-pending"]');
        if (pendEl){
          pendEl.textContent = '$0.00';
        }
      }
    }catch(e){
      unlock(t);
      if (window.VXModal) VXModal.show('Network error', false); else alert('Network error');
    }
  }, true);

  // Live ticker: pending grows without refresh
  (function(){
    let st = null;
    async function refresh(){
      try{
        const r = await fetch('/api/user/earnings.php?action=touch', {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const j = await r.json();
        if (j && j.ok && j.state){
          st = j.state;
          const pendEl = document.querySelector('[data-pending],[id="vx-pending"]');
          if (pendEl){
            const v = parseFloat(st.pending || 0);
            pendEl.textContent = '$' + (isNaN(v)?0:v).toFixed(2);
          }
        }
      }catch(_){}
    }
    refresh();
    setInterval(async ()=>{
      if (!st){ return refresh(); }
      // increment locally by per_second
      const rate = parseFloat(st.per_second || 0);
      if (!isNaN(rate) && rate>0){
        st.pending = (parseFloat(st.pending||0) + rate);
        const pendEl = document.querySelector('[data-pending],[id="vx-pending"]');
        if (pendEl){ pendEl.textContent = '$' + parseFloat(st.pending).toFixed(6); }
      }
    }, 1000);
  })();
\n