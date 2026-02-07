<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../require_tg_session.php';
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/rate_limiter.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../../core/vx_quests.php';

vx_rate_limit_or_429('api_share', 10, 60, true);

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_auth']); exit; }

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$channel = '';
$ctx = '';
if (is_array($body)) {
  $channel = isset($body['channel']) ? (string)$body['channel'] : '';
  $ctx = isset($body['ctx']) ? (string)$body['ctx'] : '';
}
$channel = preg_replace('~[^a-zA-Z0-9_\-]~', '', $channel);
$ctx = substr(preg_replace('~[^a-zA-Z0-9_\-]~', '', $ctx), 0, 48);

$now = time();
$today = gmdate('Y-m-d');
$wk = gmdate('o-\\WW');

// mark weekly quest (share)
vx_meta_set($db, $uid, 'qwk_'.$wk.'_share', '1');

// optional: store in events_log if present
try{
  $db->query('INSERT INTO events_log (user_id, event_type, ctx, ip, ua, created_at) VALUES (?, ?, ?, ?, ?, ?)',
    $uid, 'share', ($ctx !== '' ? $ctx : $channel),
    (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,190),
    $now
  );
}catch(Throwable $e){}

vx_notify_once($db, $uid, 'share_'.$today, 'share', 'info', 'Shared', 'Nice. More invites = higher rank.', ['date'=>$today,'ctx'=>$ctx]);

vx_daily_mark_task($db, $uid, 'share');
vx_weekly_mark($db, $uid, 'share');
vx_daily_try_award($db, $uid);
echo json_encode(['ok'=>true,'week'=>$wk,'today'=>$today]);
