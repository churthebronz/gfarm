
(function(){
  function apply(){
    var y = window.scrollY || document.documentElement.scrollTop || 0;
    document.documentElement.classList.toggle('scrolled', y > 2);
  }
  window.addEventListener('scroll', apply, {passive:true});
  document.addEventListener('DOMContentLoaded', apply);
  window.addEventListener('load', apply);
})();
