<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/require_tg_session.php';
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/seasons.php';

/** @var db $db */
if (!isset($db) || !($db instanceof db)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_not_initialized']);
    exit;
}

$uid = isset($GLOBALS['UID']) ? (int)$GLOBALS['UID'] : 0;
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$stats = [
    'inserts' => 0.0,
    'payouts' => 0.0,
    'farms'   => 0,
    'points'  => 0,
];

// Season meta (for UI counters)
$season = vx_get_current_season($db);
$seasonId = (int)($season['id'] ?? 0);
$seasonCaps = $seasonId > 0 ? vx_get_caps_for_season($db, $seasonId) : [];
$seasonUsed = $seasonId > 0 ? vx_get_usage_for_season($db, $seasonId) : [];

$r = $db->query(
    "SELECT COALESCE(SUM(sum),0) AS s 
     FROM db_insert 
     WHERE uid = ? AND status IN (0,1)",
    $uid
)->fetchArray();
$stats['inserts'] = (float)($r['s'] ?? 0);

$r = $db->query(
    "SELECT COALESCE(SUM(sum2),0) AS s 
     FROM db_payout 
     WHERE uid = ? AND status IN (0,1)",
    $uid
)->fetchArray();
$stats['payouts'] = (float)($r['s'] ?? 0);

$r = $db->query(
    "SELECT COUNT(*) AS c 
     FROM db_store 
     WHERE uid = ? AND status = 1",
    $uid
)->fetchArray();
$stats['farms'] = (int)($r['c'] ?? 0);

$r = $db->query(
    "SELECT COALESCE(SUM(delta),0) AS s 
     FROM db_points_log 
     WHERE uid = ?",
    $uid
)->fetchArray();
$stats['points'] = (int)($r['s'] ?? 0);

echo json_encode([
  'ok' => true,
  'stats' => $stats,
  'season' => [
    'id' => $seasonId,
    'no' => (int)($season['season_no'] ?? 0),
    'starts_at' => (int)($season['starts_at'] ?? 0),
    'ends_at' => (int)($season['ends_at'] ?? 0),
    'ends_in' => (int)(($season['ends_at'] ?? 0) > 0 ? max(0, (int)$season['ends_at'] - time()) : 0),
    'caps' => $seasonCaps,
    'used' => $seasonUsed,
  ],
]);
