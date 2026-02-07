<?php
require_once __DIR__ . '/_bootstrap.php';
try {
  vx_activity_schema_ensure($db);

  $limit = max(10, min(60, (int)($_GET['limit'] ?? 20)));
  $since = (int)($_GET['since'] ?? 0);

  $q = $db->query(
    "SELECT id, uid, type, amount, meta_json, created_at FROM vx_activity_log WHERE uid=? AND created_at > ? ORDER BY created_at DESC, id DESC LIMIT ?",
    $uid,
    $since,
    $limit
  );

  $items = [];
  if ($q) {
    while ($r = $q->fetchArray()) {
      $meta = [];
      try {
        $mj = (string)($r['meta_json'] ?? '');
        if ($mj !== '') {
          $meta = json_decode($mj, true);
          if (!is_array($meta)) $meta = [];
        }
      } catch (Throwable $e) { $meta = []; }

      $items[] = [
        'ts'    => (int)($r['created_at'] ?? 0),
        'type'  => (string)($r['type'] ?? ''),
        'amount'=> (float)($r['amount'] ?? 0),
        'meta'  => $meta,
      ];
    }
  }

  echo json_encode(['ok'=>true,'items'=>$items,'now'=>time()]);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server error']);
  exit;
}
