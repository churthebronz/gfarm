<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Records lightweight virality events (share clicks & conversions).
// This endpoint is intentionally fail-soft.

try {
  require_once __DIR__ . '/../../core/config.php';
  require_once __DIR__ . '/../../core/schema_helpers.php';
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'msg'=>'bootstrap_failed']);
  exit;
}

function vx_out(array $a, int $code = 200): void {
  http_response_code($code);
  if (function_exists('ob_get_level')) { while (ob_get_level()>0) { @ob_end_clean(); } }
  echo json_encode($a);
  exit;
}

global $db;
if (!isset($db) || !$db) vx_out(['ok'=>false,'msg'=>'db_missing'], 500);

$event = strtolower((string)($_GET['event'] ?? $_POST['event'] ?? ''));
$ctx   = (string)($_GET['ctx'] ?? $_POST['ctx'] ?? '');
$ref   = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['ref'] ?? $_POST['ref'] ?? ''));
if (stripos($ref, 'ref_') === 0 && strlen($ref) > 4) { $ref = substr($ref, 4); }

if ($event === '') vx_out(['ok'=>false,'msg'=>'missing_event'], 400);
if ($ctx === '') $ctx = 'generic';

// Best-effort user id (may be 0 for anonymous clicks)
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

// Ensure table exists (fail-soft)
try {
  if (!function_exists('vx_table_exists') || !vx_table_exists($db, 'vx_share_events')) {
    $db->query(
      "CREATE TABLE IF NOT EXISTS vx_share_events (\n"
      ."  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
      ."  uid BIGINT UNSIGNED NOT NULL DEFAULT 0,\n"
      ."  ref_code VARCHAR(32) NOT NULL DEFAULT '',\n"
      ."  event VARCHAR(24) NOT NULL,\n"
      ."  ctx VARCHAR(64) NOT NULL DEFAULT 'generic',\n"
      ."  ip_hash CHAR(64) NOT NULL DEFAULT '',\n"
      ."  ua_hash CHAR(64) NOT NULL DEFAULT '',\n"
      ."  created_at INT NOT NULL,\n"
      ."  PRIMARY KEY (id),\n"
      ."  KEY ix_event (event, ctx, created_at),\n"
      ."  KEY ix_ref (ref_code, created_at),\n"
      ."  KEY ix_uid (uid, created_at)\n"
      .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  }
} catch (Throwable $e) {}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
$ipHash = $ip !== '' ? hash('sha256', $ip) : '';
$uaHash = $ua !== '' ? hash('sha256', $ua) : '';

try {
  $db->query(
    "INSERT INTO vx_share_events (uid, ref_code, event, ctx, ip_hash, ua_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
    [$uid, $ref, $event, $ctx, $ipHash, $uaHash, time()]
  );
} catch (Throwable $e) {
  // fail-soft
}

vx_out(['ok'=>true]);
