
/* Signature Sound Design */
(function(){
  const enabled = localStorage.getItem('vx_sound') === 'on';
  const sounds = {
    acquired: new Audio('/assets/audio/guardian_acquired.mp3'),
    evolved: new Audio('/assets/audio/guardian_evolved.mp3'),
    set: new Audio('/assets/audio/set_completed.mp3')
  };
  Object.values(sounds).forEach(a=>{ a.volume = 0.15; });

  window.vxPlaySound = function(type){
    if(!enabled || !sounds[type]) return;
    try{ sounds[type].currentTime = 0; sounds[type].play(); }catch(e){}
  };

  window.vxToggleSound = function(on){
    localStorage.setItem('vx_sound', on?'on':'off');
  };
})();
