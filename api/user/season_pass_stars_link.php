<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rate_limit.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
global $db, $config;

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

vx_csrf_validate_or_exit();
vx_rate_limit_or_429('season_pass_stars_u'.$uid, 8, 600, true);

$botToken = (string)($config->bot_token ?? '');
if ($botToken === '') { echo json_encode(['ok'=>false,'msg'=>'Bot token not configured']); exit; }

$season = vx_get_current_season($db);
$sid = (int)($season['id'] ?? 0);

$amount = vx_season_pass_stars_xtr();
$title = 'GreenFarm Season Pass';
$desc = 'Season Pass for the current season (perks + cosmetics).';
$payload = 'season_pass:'.$uid.':'.$sid.':'.bin2hex(random_bytes(6));

$prices = [['label'=>'Season Pass','amount'=>$amount]];

$apiUrl = 'https://api.telegram.org/bot'.$botToken.'/createInvoiceLink';
$post = [
  'title'=>$title,
  'description'=>$desc,
  'payload'=>$payload,
  'currency'=>'XTR',
  'prices'=>json_encode($prices),
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>$post,
  CURLOPT_TIMEOUT=>12,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if (!$raw) { echo json_encode(['ok'=>false,'msg'=>'Telegram API error','error'=>$err]); exit; }

$data = json_decode($raw, true);
if (!is_array($data) || empty($data['ok'])) {
  echo json_encode(['ok'=>false,'msg'=>'Telegram API rejected','raw'=>$data ?: $raw]);
  exit;
}

echo json_encode(['ok'=>true,'url'=>$data['result'],'xtr'=>$amount]);
