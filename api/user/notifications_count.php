<?php
require_once __DIR__ . '/_bootstrap.php';
try {
  $r = $db->query('SELECT COUNT(*) AS c FROM notifications_log WHERE user_id=? AND is_read=0', $uid)->fetchArray();
  $c = (int)($r['c'] ?? 0);
  if ($c < 0) $c = 0;
  echo json_encode(['ok'=>true,'unread'=>$c]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'unread'=>0]);
}
