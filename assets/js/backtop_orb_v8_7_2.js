
(function(){
  var orb, cv, ctx, particles=[], last=0, visible=false;
  function mk(){ 
    orb = document.getElementById('vx-backtop');
    if(!orb){
      orb = document.createElement('div');
      orb.id='vx-backtop';
      orb.innerHTML='<div class="arrow"></div><canvas></canvas>';
      document.body.appendChild(orb);
    }
    cv = orb.querySelector('canvas'); ctx = cv.getContext('2d');
    orb.addEventListener('click', function(){ window.scrollTo({top:0, behavior:'smooth'}); });
    onResize();
    loop(0);
  }
  function onResize(){
    if(!cv) return;
    var r = orb.getBoundingClientRect();
    cv.width = r.width; cv.height = r.height;
  }
  function rand(a,b){ return Math.random()*(b-a)+a; }
  function spawn(){
    if(!visible) return;
    for(var i=0;i<2;i++){
      particles.push({
        x: cv.width*0.5 + rand(-10,10),
        y: cv.height*0.72,
        r: rand(1,2.2),
        vy: rand(-0.8,-1.8),
        vx: rand(-0.15,0.15),
        life: rand(0.8,1.4)
      });
    }
  }
  function step(dt){
    for(var i=particles.length-1;i>=0;i--){
      var p=particles[i];
      p.x += p.vx * dt*60;
      p.y += p.vy * dt*60;
      p.life -= dt;
      if(p.life <= 0 || p.y < -5){ particles.splice(i,1); }
    }
  }
  function draw(){
    ctx.clearRect(0,0,cv.width,cv.height);
    ctx.fillStyle = 'rgba(255,255,255,.55)';
    for(var i=0;i<particles.length;i++){
      var p=particles[i];
      ctx.beginPath(); ctx.arc(p.x,p.y,p.r,0,Math.PI*2); ctx.fill();
    }
  }
  function loop(ts){
    var dt = (ts - last)/1000; if(!isFinite(dt) || dt>0.1) dt = 0.016; last = ts;
    spawn(); step(dt); draw();
    requestAnimationFrame(loop);
  }
  function onScroll(){
    var y = window.scrollY || document.documentElement.scrollTop || 0;
    var should = y > 200;
    if(should !== visible){
      visible = should;
      orb.classList.toggle('show', visible);
    }
  }
  function init(){
    mk();
    window.addEventListener('resize', onResize, {passive:true});
    window.addEventListener('scroll', onScroll, {passive:true});
    onScroll();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();