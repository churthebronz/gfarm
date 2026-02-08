<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { echo json_encode(['ok'=>false]); exit; }

$id = (int)($_POST['id'] ?? 0);
$all = (int)($_POST['all'] ?? 0);

try {
  if ($all === 1) {
    $db->query("UPDATE notifications_log SET is_read=1, read_at=CURRENT_TIMESTAMP WHERE user_id=? AND is_read=0", $uid);
    echo json_encode(['ok'=>true,'mode'=>'all']);
    exit;
  }
  if ($id > 0) {
    $db->query("UPDATE notifications_log SET is_read=1, read_at=CURRENT_TIMESTAMP WHERE user_id=? AND id=? LIMIT 1", $uid, $id);
    echo json_encode(['ok'=>true,'mode'=>'one']);
    exit;
  }
  echo json_encode(['ok'=>false,'msg'=>'no-op']);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false]);
}
