(function(){
  async function run(){
    const badge = document.getElementById('vxVaultRepBadge');
    try{
      const res = await fetch('/api/user/vault_stats.php', {credentials:'same-origin'});
      const j = await res.json();
      if(!j || !j.ok) return;
      const rep = (window.vxComputeVaultReputation) ? window.vxComputeVaultReputation({
        archived: Number(j.archived||0),
        codexPct: Number(j.codexPct||0),
        evolves: Number(j.evolves||0)
      }) : {key:'stable', label:'Stable'};

      if (badge){
        badge.textContent = rep.label;
        badge.classList.remove('stable','mature','prime','ascended');
        badge.classList.add(rep.key);
      }

      // Evaluate invisible sets (ownedNos unknown here; we approximate by numeric guardian_no if available later).
      if (window.vxEvalCollectionSets){
        // Try to build ownedNos from global if present; otherwise use archivedCount trigger sets.
        window.vxEvalCollectionSets({ archivedCount: Number(j.archived||0), ownedNos: (window.vxOwnedNos||[]) });
      }
    }catch(e){}
  }

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
