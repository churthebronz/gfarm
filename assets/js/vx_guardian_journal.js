
(function(){
  const key=(gid)=>'vx_journal_'+gid;
  window.vxLoadJournal=(gid)=>{try{return localStorage.getItem(key(gid))||''}catch(e){return ''}};
  window.vxSaveJournal=(gid,txt)=>{try{localStorage.setItem(key(gid),txt||'')}catch(e){}};
})();
