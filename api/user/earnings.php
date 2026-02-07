<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
  require_once __DIR__ . '/../../core/config.php';
  require_once __DIR__ . '/../../core/auth_mw.php';
  require_once __DIR__ . '/../../core/schema_helpers.php';
  require_once __DIR__ . '/../../core/earnings_helpers.php';
} catch (Throwable $e) {
  echo json_encode(array('ok'=>false,'msg'=>'Bootstrap failed')); exit;
}

function vx_json($arr){
  if (function_exists('ob_get_level')) { while (ob_get_level()>0) { ob_end_clean(); } }
  echo json_encode($arr);
  exit;
}

global $db;
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
if ($uid <= 0) { http_response_code(401); vx_json(array('ok'=>false,'msg'=>'Unauthorized')); }

$action = strtolower((string)($_GET['action'] ?? $_POST['action'] ?? 'state'));

try {
  if ($action === 'claim') {
    if (!function_exists('vx_earnings_claim')) vx_json(array('ok'=>false,'msg'=>'Helpers missing'));
    $res = vx_earnings_claim($db, $uid);
    vx_json(is_array($res) ? $res : array('ok'=>false,'msg'=>'Claim failed'));
  } else {
    if (!function_exists('vx_earnings_touch') || !function_exists('vx_rate_per_second')) vx_json(array('ok'=>false,'msg'=>'Helpers missing'));
    $st = vx_earnings_touch($db, $uid);
    $st['per_second'] = vx_rate_per_second($db, $uid);
    vx_json(array('ok'=>true,'state'=>$st));
  }
} catch (Throwable $e) {
  vx_json(array('ok'=>false,'msg'=>'Unexpected error'));
}
