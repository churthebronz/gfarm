<?php
declare(strict_types=1);

define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/vx_boosts.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));

if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Unauthorized']);
  exit;
}

try {
  echo json_encode(vx_boosts_status($db, $uid));
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server error','error'=>$e->getMessage()]);
}
