<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_retention.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { echo json_encode(['ok'=>false,'items'=>[]]); exit; }

$limit = (int)($_GET['limit'] ?? 15);
if ($limit < 5) $limit = 5;
if ($limit > 50) $limit = 50;

try {
  $items = [];
  $q = $db->query("SELECT id, type, level, title, message, data_json, is_read, UNIX_TIMESTAMP(created_at) AS ts
                   FROM notifications_log
                   WHERE user_id=?
                   ORDER BY id DESC
                   LIMIT ".$limit, $uid);
  if ($q) { while($r = $q->fetchArray()) {
    $r['id'] = (int)($r['id'] ?? 0);
    $r['is_read'] = (int)($r['is_read'] ?? 0);
    $r['ts'] = (int)($r['ts'] ?? 0);
    $items[] = $r;
  } }
  echo json_encode(['ok'=>true,'items'=>$items]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'items'=>[]]);
}
