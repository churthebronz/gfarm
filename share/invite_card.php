<?php
declare(strict_types=1);

// share/invite_card.php
// OG image for /invite/{refcode}

define('FastCore', true);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/vx_app_settings.php';
require_once __DIR__ . '/_card_lib.php';

global $db;

$ref = trim((string)($_GET['ref'] ?? ''));
$ref = preg_replace('/[^A-Za-z0-9]/', '', $ref);

$inviter = null;
$invName = '';
$invId = 0;
try {
  if ($ref !== '') {
    $inviter = $db->query('SELECT id, login, tg_username, tg_name FROM db_users WHERE ref_code=? LIMIT 1', $ref)->fetchArray();
  }
} catch (Throwable $e) { $inviter = null; }

if (is_array($inviter)) {
  $invId = (int)($inviter['id'] ?? 0);
  $tg = trim((string)($inviter['tg_username'] ?? ''));
  if ($tg !== '') $invName = '@' . ltrim($tg, '@');
  if ($invName === '') {
    $nm = trim((string)($inviter['tg_name'] ?? ''));
    $invName = $nm !== '' ? $nm : trim((string)($inviter['login'] ?? ''));
  }
}

$im = vx_card_make_bg(1200, 630);
if (!$im) { vx_card_send_png(null); }

$white = imagecolorallocate($im, 234, 240, 255);
$muted = imagecolorallocate($im, 170, 184, 214);
$acc = imagecolorallocate($im, 0, 243, 255);
$acc2 = imagecolorallocate($im, 124, 92, 255);

vx_card_draw_text($im, 92, 130, 'GREENFARM', 28, $white, true);
vx_card_draw_text($im, 92, 170, 'Invite', 16, $muted, false);

if ($ref !== '') {
  vx_card_draw_text($im, 92, 245, '🎉 You\'ve been invited', 34, $white, true);
  $line = $invName !== '' ? ('Invited by ' . vx_card_clip($invName, 36) . ' • User #' . (int)$invId) : 'Invited by a GreenFarm member';
  vx_card_draw_text($im, 92, 285, $line, 16, $muted, false);
  vx_card_draw_text($im, 92, 360, 'Claim a free Founders Guardian (30-day launch window)', 18, $white, false);
  vx_card_draw_text($im, 92, 420, 'Your code:', 16, $muted, false);
  vx_card_draw_text($im, 92, 465, $ref, 40, $acc, true);
} else {
  vx_card_draw_text($im, 92, 245, '✨ GreenFarm is ready', 34, $white, true);
  vx_card_draw_text($im, 92, 290, 'Tap to open the Mini App and start earning VP/LP.', 18, $muted, false);
}

$bot = ltrim((string)vx_app_setting('telegram_bot', 'GreenFarmAppBot'), '@');
vx_card_draw_text($im, 92, 545, 'Open in Telegram: @' . $bot, 16, $muted, false);
vx_card_draw_text($im, 92, 585, vx_card_base_url() . ($ref !== '' ? ('/invite/' . $ref) : '/invite'), 16, $white, false);

vx_card_send_png($im);
