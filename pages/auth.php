<?php if(!defined('FastCore')){ exit('Opss!'); }

$opt = array(
  'title' => 'Sign in with Telegram',
  'description' => 'Authenticate via Telegram to continue.'
);

$bot  = htmlspecialchars($config->telegram_bot ?? 'GreenFarmAppBot', ENT_QUOTES);
$deep = 'https://t.me/' . rawurlencode($config->telegram_bot ?? 'GreenFarmAppBot') . '/launch?startapp=webauth';
?>
<div class="container my-5">
  <div class="card shadow-sm">
    <div class="card-body p-4 text-center">
      <h1 class="h4 mb-3">Sign in with Telegram</h1>
      <div id="vxAuthUser" style="display:none;align-items:center;justify-content:center;gap:12px;margin-bottom:12px;">
        <img loading="lazy" decoding="async" id="vxAuthAvatar" src="/assets/images/avatar_placeholder.svg" alt="" style="width:54px;height:54px;border-radius:18px;object-fit:cover;border:1px solid rgba(255,154,46,.35);box-shadow:0 12px 30px rgba(0,0,0,.35)" referrerpolicy="no-referrer"/>
        <div style="text-align:left">
          <div id="vxAuthName" style="font-weight:900;line-height:1.1;">Welcome back</div>
          <div id="vxAuthHint" class="text-muted" style="font-size:12px;">Continue with your Telegram account</div>
        </div>
      </div>
      <p class="text-muted">Fast, passwordless login using your Telegram account.</p>

      <!-- Inside Telegram, the head script intercepts this and posts initData -->
      <a class="btn btn-primary btn-lg js-tg-auth" href="<?= $deep; ?>" data-fallback="/auth" target="_self">
        Login / Register
      </a>

      <div class="mt-3 small text-muted">
        Not in Telegram right now? Open the bot: <a href="<?= $deep; ?>" target="_blank">@<?= $bot; ?></a>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const box = document.getElementById('vxAuthUser');
  const ava = document.getElementById('vxAuthAvatar');
  const name = document.getElementById('vxAuthName');
  const hint = document.getElementById('vxAuthHint');
  try{
    const isTG = !!(window.Telegram && Telegram.WebApp);
    if (isTG && Telegram.WebApp.initDataUnsafe && Telegram.WebApp.initDataUnsafe.user){
      const u = Telegram.WebApp.initDataUnsafe.user;
      if (box) box.style.display='flex';
      if (ava && u.photo_url) ava.src = u.photo_url;
      const full = [u.first_name||'', u.last_name||''].join(' ').trim();
      if (name) name.textContent = full || (u.username ? '@'+u.username : 'Welcome back');
      if (hint) hint.textContent = u.username ? '@'+u.username : 'Continue with Telegram';
      try{ Telegram.WebApp.expand(); }catch(e){}
    } else {
      // Desktop / external browser: keep deep link as primary action.
    }
  }catch(e){}
})();
</script>
