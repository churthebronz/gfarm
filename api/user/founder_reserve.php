<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season pass.php';
require_once __DIR__ . '/../require_tg_session.php';

if (!isset($_SESSION)) { session_start(); }
$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

global $db;

$amount = isset($_GET['amount']) ? (int)$_GET['amount'] : (int)($_POST['amount'] ?? 0);
if (!in_array($amount, [10000, 25000], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_amount']);
  exit;
}

// Caps
$cap = ($amount === 25000) ? 2 : 5;
$eventType = ($amount === 25000) ? 'founder_reserve_25000' : 'founder_reserve_10000';

// Active season window
$s = vx_current_season($db);
if (!is_array($s) || empty($s['ok'])) { echo json_encode(['ok'=>false,'error'=>'no_active_season']); exit; }
$start = (int)($s['starts_at'] ?? 0);
$end   = (int)($s['ends_at'] ?? 0);
$now = time();
if ($end > 0 && $now >= $end) { echo json_encode(['ok'=>false,'error'=>'snapshot_closed']); exit; }

// Per-user once per season
$mine = $db->query("SELECT id FROM events_log WHERE user_id=? AND event_type=? AND created_at BETWEEN ? AND ? LIMIT 1",
  $uid, $eventType, $start, $end)->fetchArray();
if (is_array($mine) && !empty($mine['id'])) {
  echo json_encode(['ok'=>false,'error'=>'already_reserved']);
  exit;
}

// Global cap
$row = $db->query("SELECT COUNT(*) AS c FROM events_log WHERE event_type=? AND created_at BETWEEN ? AND ?",
  $eventType, $start, $end)->fetchArray();
$used = (int)($row['c'] ?? 0);
if ($used >= $cap) {
  echo json_encode(['ok'=>false,'error'=>'sold_out','cap'=>$cap,'used'=>$used]);
  exit;
}

// Reserve slot (event log)
$ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$ctx = 'amount_'.$amount;

try {
  $db->query("INSERT INTO events_log (user_id, event_type, ctx, ip, ua, created_at) VALUES (?,?,?,?,?,?)",
    $uid, $eventType, $ctx, $ip, $ua, $now);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_insert_failed']);
  exit;
}

// Grant Season Pass badge (idempotent)
vx_grant_genesis_badge_if_eligible($db, $uid);

echo json_encode([
  'ok' => true,
  'event' => $eventType,
  'cap' => $cap,
  'used' => $used + 1,
  'redirect' => '/user/deposit?amount=' . $amount . '&founder=1'
]);
