
(function(){
  if (window.VXModal) return;
  function ensure(){
    var m=document.getElementById('vx-modal');
    if(m) return m;
    m=document.createElement('div');
    m.id='vx-modal';
    m.innerHTML='<div class="box"><div class="icon ok" id="vx-ico"></div><div class="title">GreenFarm</div><div class="msg" id="vx-msg"></div><button class="ok">OK</button></div>';
    document.body.appendChild(m);
    m.addEventListener('click', function(e){
      if(e.target.id==='vx-modal' || e.target.classList.contains('ok')) m.classList.remove('show');
    });
    return m;
  }
  function icon(ok){
    var svgOk = '<svg viewBox="0 0 24 24"><path d="M9 16.2l-3.5-3.5-1.4 1.4L9 19 20.3 7.7l-1.4-1.4z"/></svg>';
    var svgFail = '<svg viewBox="0 0 24 24"><path d="M18.3 5.71L12 12.01 5.7 5.7 4.29 7.11 10.59 13.4 4.29 19.7 5.7 21.11 12 14.82 18.29 21.1 19.7 19.69 13.41 13.4 19.7 7.11z"/></svg>';
    var el = document.getElementById('vx-ico');
    if (!el) return;
    el.className = 'icon ' + (ok?'ok':'fail');
    el.innerHTML = ok ? svgOk : svgFail;
  }
  window.VXModal = {
    show: function(msg, ok){
      var m=ensure();
      document.getElementById('vx-msg').textContent = msg || (ok?'Success':'Failed');
      icon(!!ok);
      m.classList.add('show');
    }
  };
})();
