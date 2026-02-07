
/* Elite polish JS */
(function(){
  document.addEventListener('click', function(e){
    const card = e.target.closest('.vx-guardian-card');
    if(card){ card.classList.add('vx-guardian-first'); }
  });
  window.vxSetToast = function(msg){
    const t=document.createElement('div');
    t.className='vx-toast';
    t.textContent=msg||'Set Completed';
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),2200);
  };
})();
