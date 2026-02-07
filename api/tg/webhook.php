<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';

global $db;

$secret = (string)(getenv('TG_WEBHOOK_SECRET') ?: ($GLOBALS['config']->tg_webhook_secret ?? ''));
if ($secret !== '') {
  $hdr = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
  if (!hash_equals($secret, $hdr)) {
    http_response_code(401);
    echo json_encode(['ok'=>false]);
    exit;
  }
}

$raw = file_get_contents('php://input');
$upd = json_decode($raw ?: 'null', true);
if (!is_array($upd)) { echo json_encode(['ok'=>true]); exit; }

$sp = $upd['message']['successful_payment'] ?? null;
if (!$sp) { echo json_encode(['ok'=>true]); exit; }

$currency = (string)($sp['currency'] ?? '');
if ($currency !== 'XTR') { echo json_encode(['ok'=>true]); exit; }

$amt = (int)($sp['total_amount'] ?? 0);
$expected = 0;
try { $expected = (int)vx_season_pass_stars_xtr(); } catch (Throwable $e) { $expected = 2500; }
if ($amt !== $expected) { echo json_encode(['ok'=>true]); exit; }

$chargeId = (string)($sp['telegram_payment_charge_id'] ?? '');
if ($chargeId === '') { echo json_encode(['ok'=>true]); exit; }

$idemKey = 'tgstars:'.$chargeId;
$idem = vx_idempo_begin($db, $idemKey, 86400);
if (($idem['status'] ?? '') === 'done') { echo json_encode(['ok'=>true]); exit; }

$payload = (string)($sp['invoice_payload'] ?? '');
if (!preg_match('~^season_pass:(\d+):(\d+):[a-f0-9]+$~', $payload, $m)) {
  vx_idempo_store($db, $idemKey, ['ok'=>true], 86400);
  echo json_encode(['ok'=>true]);
  exit;
}

$uid = (int)$m[1];
$sid = (int)$m[2];

vx_schema_ensure($db);
vx_season_pass_grant($db, $uid, $sid, 'stars');

vx_idempo_store($db, $idemKey, ['ok'=>true], 86400);
echo json_encode(['ok'=>true]);
