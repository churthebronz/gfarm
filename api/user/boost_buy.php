<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    return $needle === '' || strpos($haystack, $needle) !== false;
  }
}

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/rate_limit.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/vx_boosts.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  $resp=[
    'ok' => false,
    'msg' => 'Use POST to buy a boost',
    'how' => [
      'POST JSON' => '{"key":"rush_2x"}',
      'POST form' => 'key=rush_2x',
      'GET test'  => '?key=rush_2x (only if you choose to allow it)'
    ],
    'debug' => [
      'method' => ($_SERVER['REQUEST_METHOD'] ?? ''),
      'content_type' => ($_SERVER['CONTENT_TYPE'] ?? '')
    ]
  ]; vx_idempo_store($db, $idemKey, $resp, 900); echo json_encode($resp); exit;
}

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  http_response_code(401);
  $resp=['ok'=>false,'msg'=>'Unauthorized']; vx_idempo_store($db, $idemKey, $resp, 900); echo json_encode($resp); exit;
}

vx_csrf_validate_or_exit();

// Idempotency (per minute) to prevent double-tap
$bucket = (int)floor(time()/60);
$idemKey = 'boost_buy:'.$uid.':'.$bucket.':'.substr(sha1(json_encode($_POST)),0,10);
$idem = vx_idempo_begin($db, $idemKey, 900);
if (($idem['status'] ?? '') === 'done') { echo json_encode($idem['response']); exit; }

vx_rate_limit_or_429('boost_buy_u'.$uid, 12, 600, true);

$key = '';
$raw = file_get_contents('php://input') ?: '';
$ct  = (string)($_SERVER['CONTENT_TYPE'] ?? '');

if ($raw !== '' && str_contains(strtolower($ct), 'application/json')) {
  $body = json_decode($raw, true);
  if (is_array($body) && isset($body['key'])) $key = (string)$body['key'];
}

if ($key === '' && isset($_POST['key'])) $key = (string)$_POST['key'];
$key = trim($key);

if ($key === '') {
  $resp=[
    'ok' => false,
    'msg' => 'Missing boost key',
    'how' => [
      'POST JSON' => '{"key":"rush_2x"}',
      'POST form' => 'key=rush_2x',
    ],
    'debug' => [
      'content_type' => $ct,
      'raw_len' => strlen($raw),
      'post_keys' => array_keys($_POST ?? []),
      'get_keys' => array_keys($_GET ?? [])
    ]
  ]; vx_idempo_store($db, $idemKey, $resp, 900); echo json_encode($resp); exit;
}

try {
  $res = vx_boost_buy($db, $uid, $key);
  echo json_encode($res);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server error','error'=>$e->getMessage()]);
}
