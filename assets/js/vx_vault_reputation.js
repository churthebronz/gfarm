
(function(){
  window.vxComputeVaultReputation = function(stats){
    if(!stats) return {key:'stable', label:'Stable'};
    if(stats.archived>=5 && stats.codexPct>=80) return {key:'ascended', label:'Ascended'};
    if(stats.archived>=3 && stats.codexPct>=60) return {key:'prime', label:'Prime'};
    if(stats.archived>=1 && stats.codexPct>=35) return {key:'mature', label:'Mature'};
    return {key:'stable', label:'Stable'};
  };
})();
