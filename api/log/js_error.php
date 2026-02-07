<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Endpoint: /api/log/js_error.php
// Receives client JS errors and stores into events_log (fail-soft).

function out(array $a, int $code = 200): void {
  http_response_code($code);
  if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
  echo json_encode($a);
  exit;
}

try {
  require_once __DIR__ . '/../../core/config.php';
  require_once __DIR__ . '/../../core/events_log.php';
} catch (Throwable $e) {
  out(['ok'=>false,'msg'=>'bootstrap_failed']);
}

global $db;
if (!isset($db) || !$db) out(['ok'=>false,'msg'=>'db_missing'], 500);

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

// Rate-limit per session to avoid spam
$now = time();
$bucket = (int)($_SESSION['_vx_js_err_bucket_t'] ?? 0);
$cnt = (int)($_SESSION['_vx_js_err_cnt'] ?? 0);
if ($bucket < ($now - 60)) { $bucket = $now; $cnt = 0; }
if ($cnt >= 6) out(['ok'=>true,'msg'=>'rate_limited']);
$_SESSION['_vx_js_err_bucket_t'] = $bucket;
$_SESSION['_vx_js_err_cnt'] = $cnt + 1;

$payload = [
  'page' => (string)($_POST['page'] ?? ''),
  'msg'  => (string)($_POST['msg'] ?? ''),
  'src'  => (string)($_POST['src'] ?? ''),
  'line' => (int)($_POST['line'] ?? 0),
  'col'  => (int)($_POST['col'] ?? 0),
  'stack'=> (string)($_POST['stack'] ?? ''),
  'ua'   => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
];

// keep small
foreach ($payload as $k => $v) {
  if (is_string($v) && strlen($v) > 1200) $payload[$k] = substr($v, 0, 1200) . '…';
}

try {
  vx_events_log($db, 'js_error', $uid, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
  // fail-soft
}

out(['ok'=>true]);
