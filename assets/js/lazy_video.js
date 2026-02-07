(function(){
  const videos = Array.from(document.querySelectorAll('video.vx-vault-video[data-lazy="1"]'));
  if (!videos.length) return;
  const io = new IntersectionObserver((entries)=>{
    for(const e of entries){
      const v = e.target;
      if (e.isIntersecting){
        if (!v.dataset.hydrated){
          for(const s of v.querySelectorAll('source[data-src]')){ s.src = s.dataset.src; }
          v.load();
          v.dataset.hydrated = '1';
        }
        v.muted = true; v.playsInline = true;
        v.play().catch(()=>{});
      } else {
        v.pause();
      }
    }
  }, {rootMargin:'150px 0px'});
  videos.forEach(v=> io.observe(v));
})();