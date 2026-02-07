
/* Contextual Guardian Meaning */
(function(){
  window.vxGuardianContext = function(ctx){
    // ctx: {page, rarity, evolved, archived}
    if(!ctx) return '';
    if(ctx.page==='shop'){
      if(ctx.rarity==='legendary') return 'Often chosen by long-term collectors.';
      return 'A common entry point for new vault holders.';
    }
    if(ctx.page==='featured'){
      return ctx.evolved ? 'Typically evolved to peak performance.' : 'Favoured for early stability.';
    }
    if(ctx.page==='codex' && ctx.archived){
      return 'Archived after sustained stability.';
    }
    return '';
  };
})();
