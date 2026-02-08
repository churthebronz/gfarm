<?php
// /api/user/ref_debug.php
// Lightweight referral diagnostics (safe to call from TG WebView)

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../require_tg_session.php';

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) {
  echo json_encode(['ok'=>false,'error'=>'not_authenticated']);
  exit;
}

function vx_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Read user + ref fields
$u = [];
try {
  $q = $db->query('SELECT id, login, rid, ref_code, ref_start_param, rid_set_at, rid_lock FROM db_users WHERE id=? LIMIT 1', $uid);
  $u = $q ? ($q->fetchArray() ?: []) : [];
} catch (Throwable $e) {}

$cookie_sp = (string)($_COOKIE['vx_start_param'] ?? '');
$sess_sp   = (string)($_SESSION['vx_start_param'] ?? '');

$ref_code = (string)($u['ref_code'] ?? '');
$bot_user = 'GreenFarmAppBot';
try {
  // Prefer app setting if available
  if (function_exists('vx_app_setting')) {
    $tmp = (string)vx_app_setting($db, 'tg_bot_username', 'GreenFarmAppBot');
    if ($tmp !== '') { $bot_user = ltrim($tmp, '@'); }
  }
} catch (Throwable $e) {}

$bot_link = $ref_code !== ''
  ? ('https://t.me/' . $bot_user . '?start=ref_' . rawurlencode($ref_code))
  : ('https://t.me/' . $bot_user);

echo json_encode([
  'ok' => true,
  'uid' => (int)($u['id'] ?? $uid),
  'login' => (string)($u['login'] ?? ''),
  'rid' => (int)($u['rid'] ?? 0),
  'rid_lock' => (int)($u['rid_lock'] ?? 0),
  'rid_set_at' => (int)($u['rid_set_at'] ?? 0),
  'ref_code' => $ref_code,
  'ref_start_param_db' => (string)($u['ref_start_param'] ?? ''),
  'vx_start_param_cookie' => $cookie_sp,
  'vx_start_param_session' => $sess_sp,
  'bot_username' => $bot_user,
  'bot_ref_test_link' => $bot_link,
]);
