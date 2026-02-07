/* v8: compute top menu height and set CSS var so content stays below it */
(function(){
  function setTopPadding(){
    var menu = document.querySelector('.navbar.fixed-top, .navbar.navbar-fixed-top, header.fixed-top, .top-menu, .header-sticky, #top-menu');
    var h = menu ? menu.offsetHeight : 0;
    // add a small gap under the menu
    var total = (h > 0 ? h + 8 : 0);
    document.documentElement.style.setProperty('--app-top', total + 'px');
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setTopPadding);
  } else {
    setTopPadding();
  }
  window.addEventListener('resize', setTopPadding);
})();