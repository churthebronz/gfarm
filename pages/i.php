<?php if (!defined('FastCore')) exit('Opss!');
global $config, $pg;

$code = $pg->segment[1] ?? '';
$code = preg_replace('~[^a-zA-Z0-9_\-]~', '', $code);

if ($code !== '') {
  setcookie('ref_code', $code, time()+60*60*24*30, '/');  // 30 days
}

$botUser = $config->telegram_bot ?? 'YourBotName';
$startapp = $code ? $code : 'webauth';

$deep = 'https://t.me/'.rawurlencode($botUser).'/launch?startapp='.rawurlencode($startapp);
?>
<div class="container py-5 text-center">
  <h2>Open in Telegram</h2>
  <p>Tap the button to continue in Telegram Mini App.</p>
  <a class="btn btn-success btn-lg" href="<?= htmlspecialchars($deep, ENT_QUOTES); ?>" target="_blank" rel="noopener">Open GreenFarm in Telegram</a>
</div>
