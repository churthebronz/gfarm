<?php
declare(strict_types=1);

// /api/user/onboarding_done.php
// Marks onboarding as completed for the current user.

define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
  try { session_start(); } catch (Throwable $e) {}
}

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'not_logged_in']);
  exit;
}

try {
  require_once __DIR__ . '/../../core/config.php';
  require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../require_tg_session.php';
  global $db;
  vx_meta_set($db, $uid, 'onboarding_v1_done', '1');
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
