<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../require_tg_session.php';
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/rate_limiter.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../../core/vx_points.php';
require_once __DIR__ . '/../../core/vx_quests.php';

vx_rate_limit_or_429('api_checkin', 6, 60, true);

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  http_response_code(401);
  $resp=['ok'=>false,'error'=>'not_auth']; vx_idempo_store($db, $idemKey, $resp, 86400); echo json_encode($resp); exit;
}


vx_csrf_validate_or_exit();

// keep existing rate limit (if any); add stable key too
vx_rate_limit_or_429('checkin_u'.$uid, 4, 600, true);

// Idempotency (once per day)
$dayKey = gmdate('Ymd');
$idemKey = 'checkin:'.$uid.':'.$dayKey;
$idem = vx_idempo_begin($db, $idemKey, 86400);
if (($idem['status'] ?? '') === 'done') { echo json_encode($idem['response']); exit; }
$today = gmdate('Y-m-d');
$last = (string)vx_meta_get($db, $uid, 'checkin_date', '');
if ($last === $today) {
  $resp=['ok'=>false,'error'=>'already_checked_in','today'=>$today]; vx_idempo_store($db, $idemKey, $resp, 86400); echo json_encode($resp); exit;
}

// Base reward + streak bonus (cap)
$streak = (int)vx_meta_get($db, $uid, 'streak_days', '1');
$base = 40;
$bonus = min(120, max(0, ($streak - 1) * 6));
$award = $base + $bonus;

$ok = vx_points_add($db, $uid, $award, 'Daily check-in', ['date'=>$today, 'streak'=>$streak]);
if ($ok) {
  vx_meta_set($db, $uid, 'checkin_date', $today);
  // Daily/Weekly quests
  vx_daily_mark_task($db, $uid, 'checkin');
  vx_weekly_mark($db, $uid, 'checkin');
  vx_daily_try_award($db, $uid);
  // events log (optional)
  try{ if(function_exists('vx_table_exists') && vx_table_exists($db,'events_log')){
    $db->query('INSERT INTO events_log (user_id,event_type,ctx,ip,ua,created_at) VALUES (?,?,?,?,?,?)',
      $uid,'checkin',$today,(string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,190),time());
  }}catch(Throwable $e){}
  // Weekly quest: check-in
  $wk = gmdate('o-\\WW');
  vx_meta_set($db, $uid, 'qwk_'.$wk.'_checkin', '1');
  vx_notify_once(
    $db,
    $uid,
    'checkin_'.$today,
    'checkin',
    'success',
    'Daily check-in complete',
    'You earned +'.$award.' Points. Come back tomorrow to keep your streak going.',
    ['pts'=>$award,'date'=>$today]
  );
}

echo json_encode(['ok'=>$ok,'pts'=>$award,'streak'=>$streak,'today'=>$today]);
