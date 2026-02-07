(function(){
  function postJSON(url, data, btn){
    if(btn){ lock(btn); }
    return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data||{})})
      .then(r=>r.json()).catch(()=>({ok:false,msg:'Network error'}))
      .finally(()=>{ if(btn){ unlock(btn); } });
  }
  function lock(btn){
    btn.setAttribute('disabled','disabled');
    btn.dataset._origHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Please wait…';
  }
  function unlock(btn){
    btn.removeAttribute('disabled');
    if(btn.dataset._origHtml){ btn.innerHTML = btn.dataset._origHtml; delete btn.dataset._origHtml; }
  }
  function onClick(e){
    var t = e.target.closest('[data-action]'); if(!t) return;
    var act = t.getAttribute('data-action');
    if (act === 'collect') {
      e.preventDefault();
      postJSON('/user', { claim: true }, t).then(function(r){
        window.VXToast && VXToast(r.msg || (r.ok?'Collected':'Failed'));
        if (r.ok) location.href='/user';
      });
    } else if (act === 'buy-vault') {
      e.preventDefault();
      // find nearest form data if exists
      var form = t.closest('form');
      var payload = { item: true };
      if(form){
        var fd = new FormData(form);
        fd.forEach((v,k)=>{ payload[k]=v; });
      }
      postJSON('/user/plans', payload, t).then(function(r){
        window.VXToast && VXToast(r.msg || (r.ok?'Activated':'Failed'));
        if (r.ok) location.href='/user/plans';
      });
    }
  }
  document.addEventListener('click', onClick, true);
})();