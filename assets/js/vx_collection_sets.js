
(function(){
  const done=new Set();
  const sets=[
    {id:'genesis',label:'Genesis Set',test:(o)=>o.includes(1)&&o.includes(2)&&o.includes(3)&&o.includes(4)&&o.includes(5)},
    {id:'archivist3',label:'Archivist I',test:(a)=>a>=3}
  ];
  window.vxEvalCollectionSets=function(ctx){
    try{
      sets.forEach(s=>{
        if(done.has(s.id))return;
        if((Array.isArray(ctx.ownedNos)&&s.test(ctx.ownedNos))||
           (typeof ctx.archivedCount==='number'&&s.test(ctx.archivedCount))){
          done.add(s.id);
          if(window.vxSetToast) vxSetToast(s.label+' completed');
        }
      });
    }catch(e){}
  };
})();
