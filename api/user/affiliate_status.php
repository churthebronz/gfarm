<?php
declare(strict_types=1);
// /api/user/affiliate_status.php
// Returns Affiliate Guardian unlock state.

if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../../core/vx_affiliate.php';
require_once __DIR__ . '/../require_tg_session.php';

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Not signed in']); exit; }

try {
  if (!isset($GLOBALS['db']) || !$GLOBALS['db']) { echo json_encode(['ok'=>false,'msg'=>'DB not ready']); exit; }
  $db = $GLOBALS['db'];
  if (function_exists('vx_schema_ensure')) vx_schema_ensure($db);
  $st = vx_affiliate_status($db, $uid);
  echo json_encode(['ok'=>true] + $st);
  exit;
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'msg'=>'Server error']);
  exit;
}
