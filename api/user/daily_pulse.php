<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/vx_lp.php';

if (session_status() !== PHP_SESSION_ACTIVE) { try { session_start(); } catch (Throwable $e) {} }
global $db;

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'AUTH']); exit; }

$now = time();
$ymd = gmdate('Y-m-d', $now); // use stable day boundary (UTC) for consistency across regions
$key = 'daily_pulse_ymd';

$last = '';
try { $last = (string)vx_meta_get($db, $uid, $key, ''); } catch (Throwable $e) { $last=''; }

if ($last === $ymd) {
  echo json_encode(['ok'=>true,'already'=>true,'lp_delta'=>0,'ymd'=>$ymd], JSON_UNESCAPED_SLASHES);
  exit;
}

// Award small LP pulse (cosmetic retention). Keep tiny.
$lpDelta = 3;
try { vx_lp_add($db, $uid, $lpDelta, 'daily_pulse', ['ymd'=>$ymd]); } catch (Throwable $e) { $lpDelta = 0; }

try { vx_meta_set($db, $uid, $key, $ymd); } catch (Throwable $e) {}

$lp = ['total'=>0,'spendable'=>0];
try { $lp = vx_lp_get($db, $uid); } catch (Throwable $e) {}

echo json_encode([
  'ok'=>true,
  'already'=>false,
  'ymd'=>$ymd,
  'lp_delta'=>$lpDelta,
  'lp_total'=>(int)($lp['total']??0),
  'lp_spendable'=>(int)($lp['spendable']??0),
], JSON_UNESCAPED_SLASHES);
