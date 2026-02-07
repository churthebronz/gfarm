<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rate_limit.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/PaykassaAPI.php';
require_once __DIR__ . '/../../core/wallets.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
global $db;

vx_schema_ensure($db);

$uid = (int)($_SESSION['uid'] ?? 0);
$login = (string)($_SESSION['login'] ?? '');

if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Unauthorized']);
  exit;
}

vx_csrf_validate_or_exit();
vx_rate_limit_or_429('withdraw_u'.$uid, 3, 600, true);

$amount = (float)($_POST['amount'] ?? 0);
$system = trim((string)($_POST['system'] ?? ''));

if ($amount <= 0 || $system === '') {
  echo json_encode(['ok'=>false,'msg'=>'Amount and payment system required']);
  exit;
}

$amount = round($amount, 6);
if ($amount < 0.01) {
  echo json_encode(['ok'=>false,'msg'=>'Minimum amount is 0.01']);
  exit;
}

try {
  $ps = $db->query('SELECT * FROM db_paysystem WHERE LOWER(name)=LOWER(?) LIMIT 1', $system)->fetchArray();
} catch (Throwable $e) {
  $ps = [];
}
if (!$ps) {
  echo json_encode(['ok'=>false,'msg'=>'Payment system not found']);
  exit;
}

$sysName  = (string)($ps['name'] ?? $system);
$min      = (float)($ps['min'] ?? 0);
$max      = (float)($ps['max'] ?? 0);
$com      = (float)($ps['com'] ?? 0);

if ($min > 0 && $amount < $min) { echo json_encode(['ok'=>false,'msg'=>'Min: '.$min]); exit; }
if ($max > 0 && $amount > $max) { echo json_encode(['ok'=>false,'msg'=>'Max: '.$max]); exit; }

$wallets = new wallets();

// wallet from db_purse
$purse = '';
try {
  $rowP = $db->query('SELECT purse FROM db_purse WHERE uid=? AND psys=? ORDER BY id DESC LIMIT 1', $uid, $sysName)->fetchArray();
  $purse = (string)($rowP['purse'] ?? '');
} catch (Throwable $e) {}
$purse = trim($purse);

if ($purse === '') {
  echo json_encode(['ok'=>false,'msg'=>'Set your withdrawal wallet first in Settings']);
  exit;
}

// Fail-soft validation for known systems
$okPurse = $purse;
try {
  $s = strtolower($sysName);
  if (strpos($s,'tron') !== false || $s === 'trx') {
    $okPurse = $wallets->tron_wallet($purse);
  } elseif (strpos($s,'payeer') !== false) {
    $okPurse = $wallets->payeer_wallet($purse);
  }
} catch (Throwable $e) {}
if ($okPurse === false) {
  echo json_encode(['ok'=>false,'msg'=>'Invalid wallet for '.$sysName]);
  exit;
}

$bucketMin = (int)floor(time()/60);
$idemKey = 'withdraw:'.$uid.':'.strtolower($sysName).':'.$amount.':'.$bucketMin;

$idem = vx_idempo_begin($db, $idemKey, 900);
if (($idem['status'] ?? '') === 'done') {
  echo json_encode($idem['response']);
  exit;
}

$balRow = $db->query('SELECT money_p FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
$bal = (float)($balRow['money_p'] ?? 0);
if ($bal < $amount) {
  $resp = ['ok'=>false,'msg'=>'Insufficient balance'];
  vx_idempo_store($db, $idemKey, $resp, 120);
  echo json_encode($resp);
  exit;
}

// atomic deduct
$db->query('UPDATE db_users SET money_p = money_p - ? WHERE id=? AND money_p >= ?', $amount, $uid, $amount);
$rc = (int)($db->query('SELECT ROW_COUNT() AS rc')->fetchArray()['rc'] ?? 0);
if ($rc <= 0) {
  $resp = ['ok'=>false,'msg'=>'Balance changed, try again'];
  vx_idempo_store($db, $idemKey, $resp, 120);
  echo json_encode($resp);
  exit;
}

$sum2 = $amount;
if ($com > 0) {
  $sum2 = max(0.0, $amount - ($amount * ($com/100.0)));
}

$now = time();
$requestId = $idemKey;

try {
  $db->query(
    'INSERT INTO db_payout (uid, login, purse, sum, sum2, status, sys, psys, add, del, request_id)
     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 0, ?)',
    $uid, $login, $okPurse, $amount, $sum2, $sysName, $sysName, $now, $requestId
  );
  $pid = (int)$db->lastInsert();
  $receipt = '';


  // Withdrawal proof receipt (copyable)
  try {
    $ts = time();
    $sec = (string)(getenv('WITHDRAW_RECEIPT_SECRET') ?: '');
    if ($sec === '') { try { $sec = (string)($GLOBALS['config']->tg_webhook_secret ?? ''); } catch (Throwable $e) {} }
    $payload = 'VXWD|'.$pid.'|'.$uid.'|'.number_format($amount, 2, '.', '').'|'.$sysName.'|'.$okPurse.'|'.$ts;
    $sig = substr(hash_hmac('sha256', $payload, $sec), 0, 18);
    $receipt = $payload.'|'.$sig;
    $db->query('UPDATE db_payout SET proof=?, paid_at=NULL WHERE id=?', $receipt, $pid);
  } catch (Throwable $e) {}

} catch (Throwable $e) {
  // rollback best-effort
  try { $db->query('UPDATE db_users SET money_p = money_p + ? WHERE id=?', $amount, $uid); } catch (Throwable $e2) {}
  $resp = ['ok'=>false,'msg'=>'Withdrawal request failed'];
  vx_idempo_store($db, $idemKey, $resp, 120);
  echo json_encode($resp);
  exit;
}

$resp = ['ok'=>true,'msg'=>'Withdrawal requested','payout_id'=>$pid,'amount'=>$amount,'system'=>$sysName,'proof'=>$receipt];
vx_idempo_store($db, $idemKey, $resp, 900);
echo json_encode($resp);
