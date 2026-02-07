<?php
require_once __DIR__ . '/_bootstrap.php';
// Rank is based on today's positive ledger delta (UTC today)
$todayStart = strtotime('today');
$todayEnd = $todayStart + 86399;

if (!function_exists('vx_table_exists')) {
  require_once __DIR__ . '/../../core/schema_helpers.php';
}

try {
  if (!vx_table_exists($db, 'db_points_ledger')) {
    echo json_encode(['ok'=>true,'rank'=>null,'pts'=>0]);
    exit;
  }

  $qMe = $db->query(
    'SELECT COALESCE(SUM(delta),0) AS pts FROM db_points_ledger WHERE uid=? AND created_at>=? AND created_at<=? AND delta>0',
    $uid, $todayStart, $todayEnd
  );
  $rowMe = $qMe ? ($qMe->fetchArray() ?: []) : [];
  $mePts = (int)($rowMe['pts'] ?? 0);

  // Dense rank: 1 + count(users with strictly more points)
  $qRank = $db->query(
    'SELECT 1 + COUNT(*) AS r FROM (
       SELECT uid, COALESCE(SUM(delta),0) AS pts
       FROM db_points_ledger
       WHERE created_at>=? AND created_at<=? AND delta>0
       GROUP BY uid
     ) t WHERE t.pts > ?',
    $todayStart, $todayEnd, $mePts
  );
  $rowR = $qRank ? ($qRank->fetchArray() ?: []) : [];
  $rank = isset($rowR['r']) ? (int)$rowR['r'] : null;

  echo json_encode(['ok'=>true,'rank'=>$rank,'pts'=>$mePts]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'db']);
}
