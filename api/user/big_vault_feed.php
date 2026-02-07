<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/seasons.php';

if (!isset($_SESSION)) { session_start(); }
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

global $db;

// Active season window to keep feed relevant
$s = vx_current_season($db);
$start = (int)($s['starts_at'] ?? (time() - 86400));
$end   = (int)($s['ends_at'] ?? (time() + 86400));

$limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 10;

$rows = [];
try {
  $q = $db->query("SELECT id, event_type, ctx, created_at
                   FROM events_log
                   WHERE event_type IN ('founder_reserve_10000','founder_reserve_25000')
                     AND created_at BETWEEN ? AND ?
                   ORDER BY id DESC
                   LIMIT $limit", $start, $end);
  while ($r = $q->fetchArray()) {
    $amt = 0;
    if (!empty($r['ctx']) && preg_match('/amount_(\d+)/', (string)$r['ctx'], $m)) $amt = (int)$m[1];
    $rows[] = [
      'id' => (int)($r['id'] ?? 0),
      'event_type' => (string)($r['event_type'] ?? ''),
      'amount' => $amt,
      'created_at' => (int)($r['created_at'] ?? 0),
    ];
  }
} catch (Throwable $e) {
  echo json_encode(['ok'=>true,'items'=>[]]);
  exit;
}

echo json_encode(['ok'=>true,'items'=>$rows]);
