<?php
declare(strict_types=1); define('FastCore', true); header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rate_limit.php'; require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../require_tg_session.php';
global $db; $uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0)); $login=(string)($_SESSION['login']??''); if($uid<=0){ http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

vx_csrf_validate_or_exit();
vx_rate_limit_or_429('bonus_claim_u'.$uid, 6, 600, true);

// Idempotency (once per day) to prevent double-tap / replay
$dayKey = gmdate('Ymd'); // stable across timezones
$idemKey = 'bonus_claim:'.$uid.':'.$dayKey;
$idem = vx_idempo_begin($db, $idemKey, 86400);
if (($idem['status'] ?? '') === 'done') { echo json_encode($idem['response']); exit; }
$action=strtolower((string)($_GET['action']??$_POST['action']??'')); $now=time();
try{
  if($action==='tg'){ $amount=0.10; try{$r=$db->query('SELECT val FROM db_aff_conf WHERE name=? LIMIT 1','tg_bonus_amount')->fetchArray(); if($r)$amount=(float)$r['val'];}catch(Throwable $e){} $claimed=false; try{$c=$db->query('SELECT COUNT(*) AS c FROM db_bonus_tg WHERE uid=? AND status=1',$uid)->fetchArray(); $claimed=(int)($c['c']??0)>0;}catch(Throwable $e){} if($claimed){ $resp=['ok'=>false,'msg'=>'Telegram bonus already claimed']; vx_idempo_store($db, $idemKey, $resp, 86400); echo json_encode($resp); exit; } $db->query('UPDATE db_users SET money_p = money_p + ? WHERE id = ?', $amount, $uid); try{ $db->query('INSERT INTO db_bonus_tg (uid, login, status, date_add, amount) VALUES (?, ?, 1, ?, ?)', $uid, $login, $now, $amount);}catch(Throwable $e){} $resp=['ok'=>true,'msg'=>'+$'.number_format($amount,2).' credited']; vx_idempo_store($db, $idemKey, $resp, 86400); echo json_encode($resp); exit; }
  if($action==='daily'){ $amount=0.05; try{$r=$db->query('SELECT val FROM db_aff_conf WHERE name=? LIMIT 1','daily_bonus_min')->fetchArray(); if($r)$amount=(float)$r['val'];}catch(Throwable $e){} $lock=$db->query('SELECT MAX(`add`) AS last FROM db_bonus WHERE uid = ?',$uid)->fetchArray(); if((int)($lock['last']??0)>(time()-86400)){ $resp=['ok'=>false,'msg'=>'Bonus already claimed today']; vx_idempo_store($db, $idemKey, $resp, 86400); echo json_encode($resp); exit; } $db->query('UPDATE db_users SET money_p = money_p + ? WHERE id = ?', $amount, $uid); try{$db->query('INSERT INTO db_bonus (uid, login, sum, `add`, bonus_id) VALUES (?, ?, ?, ?, ?)', $uid, $login, $amount, $now, 1);}catch(Throwable $e){} $resp=['ok'=>true,'msg'=>'+$'.number_format($amount,2).' daily bonus']; vx_idempo_store($db, $idemKey, $resp, 86400); echo json_encode($resp); exit; }
  echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
}catch(Throwable $e){ echo json_encode(['ok'=>false,'msg'=>'Unexpected error']); }
