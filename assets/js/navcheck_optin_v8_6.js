
(function(){
  if (!/\bnavcheck=1\b/.test(location.search)) return;
  function ok(url){
    return fetch(url, {method:'HEAD'}).then(function(r){ return r.ok; }).catch(function(){ return false; });
  }
  document.addEventListener('click', function(e){
    var a = e.target.closest('a,button,[data-href]');
    if(!a) return;
    var href = a.getAttribute('href') || a.getAttribute('data-href');
    if(!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    e.preventDefault();
    ok(href).then(function(isOk){
      if(isOk){ location.href = href; }
      else { alert('This link appears broken: ' + href); }
    });
  }, true);
  console.log('[navcheck] Click checker is ON');
})();
