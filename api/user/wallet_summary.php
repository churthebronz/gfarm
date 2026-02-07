<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/vx_legacy.php';

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_auth']); exit; }

function safe_num($v): float { return (float)(is_numeric($v) ? $v : 0); }

try{
  $u = $db->query("SELECT id, login, username, tg_username, tg_name, bank, income, points_spendable, points_total FROM db_users WHERE id=? LIMIT 1", $uid);
  $user = $u ? ($u->fetchArray() ?: []) : [];
}catch(Throwable $e){
  $user = [];
}

$walletBalance = safe_num(($user['bank'] ?? 0)) + safe_num(($user['income'] ?? 0));
$currency = (string)($config->valuta ?? 'USD');

$recent = [];
// Recent points
try{
  $q = $db->query("SELECT delta, ctx, created_at FROM db_points_ledger WHERE uid=? ORDER BY created_at DESC LIMIT 3", $uid);
  if ($q){
    while($r = $q->fetchArray()){
      $d = (int)($r['delta'] ?? 0);
      $label = !empty($r['ctx']) ? (string)$r['ctx'] : 'Points';
      $recent[] = ['label'=>$label, 'value'=>($d>=0?'+':'').$d.' pts'];
    }
  }
}catch(Throwable $e){}

// Recent deposits / withdrawals (if tables exist)
try{
  $q2 = $db->query("SELECT SUM(sum) AS s FROM db_insert WHERE uid=? AND status=1", $uid);
  $r2 = $q2 ? ($q2->fetchArray() ?: []) : [];
  $sumIn = safe_num($r2['s'] ?? 0);

  $q3 = $db->query("SELECT SUM(sum) AS s FROM db_payout WHERE uid=? AND status=1", $uid);
  $r3 = $q3 ? ($q3->fetchArray() ?: []) : [];
  $sumOut = safe_num($r3['s'] ?? 0);

  array_unshift($recent, ['label'=>'Total in', 'value'=>number_format($sumIn, 2, '.', '') . ' ' . $currency]);
  array_unshift($recent, ['label'=>'Total out', 'value'=>number_format($sumOut, 2, '.', '') . ' ' . $currency]);
}catch(Throwable $e){}

$display = '';
if (!empty($user['tg_username'])) $display = '@'.ltrim((string)$user['tg_username'], '@');
elseif (!empty($user['tg_name'])) $display = (string)$user['tg_name'];
elseif (!empty($user['username'])) $display = (string)$user['username'];
else $display = (string)($user['login'] ?? '');

$legacy = ['total'=>0,'spendable'=>0];
try { $legacy = vx_legacy_get($db, (int)$uid); } catch (Throwable $e) {}

echo json_encode([
  'ok'=>true,
  'user'=>[
    'id'=>(int)($user['id'] ?? 0),
    'login'=>(string)($user['login'] ?? ''),
    'display'=>$display
  ],
  'wallet'=>[
    'balance'=>$walletBalance,
    'currency'=>$currency
  ],
  'points'=>[
    'spendable'=>(int)($user['points_spendable'] ?? 0),
    'lifetime'=>(int)($user['points_total'] ?? 0),
  ],
  'legacy_vp'=>[
    'spendable'=>(int)($legacy['spendable'] ?? 0),
    'lifetime'=>(int)($legacy['total'] ?? 0),
  ],
  'recent'=>$recent
]);
