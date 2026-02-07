(function(){
  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function $all(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }
  function fmtMoney(v){ try{ return '$' + (Number(v||0)).toFixed(2); }catch(e){ return String(v||0); } }
  function setText(selList, text){
    selList.forEach(function(sel){
      $all(sel).forEach(function(el){ el.textContent = text; });
    });
  }
  function refresh(){
    return fetch('/api/dashboard.php', { credentials:'include' })
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
      .then(function(j){
        if(!j || !j.ok || !j.stats) return j;
        var s=j.stats;
        setText(['[data-vx-balance]','#balance','.user-balance'], fmtMoney((s.inserts||0) - (s.payouts||0)));
        setText(['[data-vx-points]','#points','.user-points'], String(s.points||0));
        setText(['[data-vx-farms]','#farms','.user-farms'], String(s.farms||0));
        setText(['[data-vx-inserts]','#inserts','.user-inserts'], fmtMoney(s.inserts||0));
        setText(['[data-vx-payouts]','#payouts','.user-payouts'], fmtMoney(s.payouts||0));
        return j;
      });
  }
  window.vxRefreshMiniStats = refresh;
})();