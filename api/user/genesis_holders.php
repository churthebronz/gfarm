<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

try {
  $row = $db->query("SELECT COUNT(*) AS c FROM user_meta WHERE meta_key='genesis_badge'")->fetchArray();
  $c = (int)($row['c'] ?? 0);
  echo json_encode(['ok'=>true,'count'=>$c]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'db']);
}
