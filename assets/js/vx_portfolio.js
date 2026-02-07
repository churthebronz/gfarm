(function(){
  function onReady(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function(){
    var root = document.getElementById('vxPortfolio');
    if (!root) return;
    var btn = root.querySelector('.vx-portfolio-toggle');
    var body = document.getElementById('vxPortfolioBody');
    if (!btn || !body) return;

    var pill = root.querySelector('.vx-portfolio-pill');
    var KEY = 'vx_portfolio_open';

    function setOpen(open){
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (pill) pill.textContent = open ? 'Tap to collapse' : 'Tap to expand';
      if (open){
        body.hidden = false;
        root.classList.add('is-open');
      } else {
        body.hidden = true;
        root.classList.remove('is-open');
      }
      try { localStorage.setItem(KEY, open ? '1' : '0'); } catch(e) {}
    }

    var saved = '0';
    try { saved = localStorage.getItem(KEY) || '0'; } catch(e) { saved = '0'; }
    setOpen(saved === '1');

    btn.addEventListener('click', function(){
      var open = btn.getAttribute('aria-expanded') === 'true';
      setOpen(!open);
      try{
        if (window.Telegram && Telegram.WebApp && Telegram.WebApp.HapticFeedback){
          Telegram.WebApp.HapticFeedback.impactOccurred('light');
        }
      } catch(e) {}
    });
  });
})();
