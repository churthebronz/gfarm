<?php
declare(strict_types=1);

// share/profile_card.php
// OG image for /profile/{uid}

define('FastCore', true);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/seasons.php';
require_once __DIR__ . '/../core/vx_season_points.php';
require_once __DIR__ . '/../core/vx_affiliate.php';
require_once __DIR__ . '/../core/vx_app_settings.php';
require_once __DIR__ . '/_card_lib.php';

global $db;

$uid = (int)($_GET['id'] ?? 0);
if ($uid <= 0) { http_response_code(404); vx_card_send_png(null); }

$u = [];
try {
  $u = $db->query('SELECT id, login, tg_username, tg_name, ref_code FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray() ?: [];
} catch (Throwable $e) { $u = []; }
if (empty($u)) { http_response_code(404); vx_card_send_png(null); }

$name = '';
$tg = trim((string)($u['tg_username'] ?? ''));
if ($tg !== '') $name = '@' . ltrim($tg, '@');
if ($name === '') {
  $nm = trim((string)($u['tg_name'] ?? ''));
  $name = $nm !== '' ? $nm : trim((string)($u['login'] ?? 'User'));
}
$name = vx_card_clip($name, 40);

$sx = [];
$sid = 0;
$seasonNo = 0;
try {
  $sx = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
  $sid = (int)($sx['id'] ?? 0);
  $seasonNo = (int)($sx['num'] ?? ($sx['no'] ?? 0));
} catch (Throwable $e) {}

$vp = 0; $lp = 0;
try {
  if ($sid > 0) {
    $r = $db->query('SELECT vp_total, lp_total FROM vx_season_points WHERE uid=? AND season_id=? LIMIT 1', $uid, $sid)->fetchArray();
    $vp = (int)($r['vp_total'] ?? 0);
    $lp = (int)($r['lp_total'] ?? 0);
  }
} catch (Throwable $e) {}

$qualifiedRefs = 0;
try { $qualifiedRefs = vx_affiliate_qualified_paid_refs($db, $uid); } catch (Throwable $e) {}

$refCode = trim((string)($u['ref_code'] ?? ''));
$inviteUrl = $refCode !== '' ? (vx_card_base_url() . '/invite/' . $refCode) : (vx_card_base_url() . '/invite');

$im = vx_card_make_bg(1200, 630);
if (!$im) { vx_card_send_png(null); }

$white = imagecolorallocate($im, 234, 240, 255);
$muted = imagecolorallocate($im, 170, 184, 214);
$acc = imagecolorallocate($im, 0, 243, 255);
$acc2 = imagecolorallocate($im, 124, 92, 255);

// Brand
vx_card_draw_text($im, 92, 130, 'GREENFARM', 28, $white, true);
vx_card_draw_text($im, 92, 170, 'Profile', 16, $muted, false);

// Name
vx_card_draw_text($im, 92, 240, $name, 34, $white, true);
vx_card_draw_text($im, 92, 280, 'Season ' . (int)$seasonNo . ' • User #' . (int)$uid, 16, $muted, false);

// Stats chips
vx_card_draw_text($im, 92, 350, 'Season VP', 16, $muted, false);
vx_card_draw_text($im, 92, 390, number_format($vp), 30, $acc, true);

vx_card_draw_text($im, 360, 350, 'Season LP', 16, $muted, false);
vx_card_draw_text($im, 360, 390, number_format($lp), 30, $acc2, true);

vx_card_draw_text($im, 628, 350, 'Qualified refs', 16, $muted, false);
vx_card_draw_text($im, 628, 390, number_format($qualifiedRefs), 30, $white, true);

// Invite
vx_card_draw_text($im, 92, 500, 'Invite', 16, $muted, false);
vx_card_draw_text($im, 92, 540, vx_card_clip($inviteUrl, 72), 16, $white, false);
if ($refCode !== '') {
  vx_card_draw_text($im, 92, 575, 'Code: ' . $refCode, 18, $white, true);
}

vx_card_send_png($im);
