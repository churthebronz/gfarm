<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rate_limit.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/idk.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
global $db;

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

vx_csrf_validate_or_exit();
vx_rate_limit_or_429('season_pass_buy_u'.$uid, 6, 600, true);

$idk = (string)($_POST['idk'] ?? '');
if ($idk !== '' && !vx_consume_idk('season_pass', $idk)) {
  echo json_encode(['ok'=>false,'msg'=>'Expired action. Refresh and try again.']);
  exit;
}

$season = vx_get_current_season($db);
$sid = (int)($season['id'] ?? 0);
if ($sid <= 0) { echo json_encode(['ok'=>false,'msg'=>'No active season']); exit; }

if (vx_season_pass_active($db, $uid, $sid)) {
  echo json_encode(['ok'=>true,'msg'=>'Season Pass already active','active'=>true]);
  exit;
}

$price = vx_season_pass_price_usd();
$bucket = (int)floor(time()/60);
$idemKey = 'season_pass_buy:'.$uid.':'.$sid.':'.$bucket;

$idem = vx_idempo_begin($db, $idemKey, 900);
if (($idem['status'] ?? '') === 'done') {
  echo json_encode($idem['response']);
  exit;
}

$balRow = $db->query('SELECT money_p FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
$bal = (float)($balRow['money_p'] ?? 0);

if ($bal < $price) {
  $resp = ['ok'=>false,'msg'=>'Insufficient balance'];
  vx_idempo_store($db, $idemKey, $resp, 120);
  echo json_encode($resp);
  exit;
}

$db->query('UPDATE db_users SET money_p = money_p - ? WHERE id=? AND money_p >= ?', $price, $uid, $price);
$rc = (int)($db->query('SELECT ROW_COUNT() AS rc')->fetchArray()['rc'] ?? 0);
if ($rc <= 0) {
  $resp = ['ok'=>false,'msg'=>'Balance changed, try again'];
  vx_idempo_store($db, $idemKey, $resp, 120);
  echo json_encode($resp);
  exit;
}

$ok = vx_season_pass_grant($db, $uid, $sid, 'balance');
if (!$ok) {
  $db->query('UPDATE db_users SET money_p = money_p + ? WHERE id=?', $price, $uid);
  $resp = ['ok'=>false,'msg'=>'Could not activate pass'];
  vx_idempo_store($db, $idemKey, $resp, 120);
  echo json_encode($resp);
  exit;
}

$resp = ['ok'=>true,'msg'=>'Season Pass activated','active'=>true,'season_id'=>$sid];
vx_idempo_store($db, $idemKey, $resp, 900);
echo json_encode($resp);
