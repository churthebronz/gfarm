<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
global $db;

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { echo json_encode(['ok'=>true,'auth'=>false]); exit; }

$season = vx_get_current_season($db);
$sid = (int)($season['id'] ?? 0);

echo json_encode([
  'ok'=>true,
  'auth'=>true,
  'season'=>[
    'id'=>$sid,
    'starts_at'=>(int)($season['starts_at'] ?? 0),
    'ends_at'=>(int)($season['ends_at'] ?? 0),
    'season_no'=>(int)($season['season_no'] ?? 0),
  ],
  'price_usd'=>vx_season_pass_price_usd(),
  'price_xtr'=>vx_season_pass_stars_xtr(),
  'active'=>vx_season_pass_active($db, $uid, $sid),
]);
