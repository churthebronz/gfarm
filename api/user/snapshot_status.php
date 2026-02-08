<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

// Fail-soft loader: this endpoint MUST never 500 because vx_shell depends on it.
// Any fatal error here breaks dashboard UI.
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
// Back-compat: some UI scripts still expect "genesis_*" fields.
// If genesis.php exists, load it; otherwise we fall back to Season Pass state.
if (is_file(__DIR__ . '/../../core/genesis.php')) {
  require_once __DIR__ . '/../../core/genesis.php';
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));

// Telegram Mini App pages may have a valid tg_sess cookie before PHP session is hydrated.
// This endpoint is consumed by vx_shell.js and must FAIL-SOFT (never 401).
if ($uid <= 0) {
  try {
    define('VX_SOFT_AUTH', true);
    require_once __DIR__ . '/../require_tg_session.php';
    $uid = isset($GLOBALS['UID']) ? (int)$GLOBALS['UID'] : 0;
  } catch (Throwable $e) {
    $uid = 0;
  }
}

if ($uid <= 0) {
  http_response_code(200);
  echo json_encode(['ok'=>true,'logged_in'=>false], JSON_UNESCAPED_SLASHES);
  exit;
}

global $db;
if (!isset($db) || !($db instanceof db)) {
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>'db_unavailable']);
  exit;
}

$s = vx_current_season($db);
if (!is_array($s) || empty($s['ok'])) {
  echo json_encode(['ok'=>false,'error'=>'no_active_season']);
  exit;
}

$snapshot_at = (int)($s['ends_at'] ?? 0);
$seconds_left = max(0, $snapshot_at - time());

// Crossbreed (for dashboard nudges)
$now = time();
$crossbreed = ['ok'=>true,'count'=>0,'items'=>[]];
try {
  $items = vx_guardians_pending_crossbreed($db, $uid, $now, 5);
  if (is_array($items) && !empty($items)) {
    $crossbreed['count'] = count($items);
    $crossbreed['items'] = array_values($items);
  }
} catch (Throwable $e) {}

echo json_encode([
  'ok' => true,
  'season_no' => (int)($s['season_no'] ?? 1),
  'starts_at' => (int)($s['starts_at'] ?? 0),
  'ends_at' => (int)($s['ends_at'] ?? 0),
  'snapshot_at' => $snapshot_at,
  'seconds_left' => $seconds_left,
  'progress_pct' => (int)($s['progress_pct'] ?? 0),
  // These are used by vx_shell.js for the "season locked-in" pill.
  // Prefer real genesis functions if present; otherwise treat Season Pass as the badge.
  'has_genesis_badge' => (function_exists('vx_user_has_genesis_badge')
      ? (bool)vx_user_has_genesis_badge($db, $uid)
      : (bool)vx_season_pass_active($db, (int)$uid, (int)($s['id'] ?? 0))),
  'genesis_live' => (function_exists('vx_genesis_is_live')
      ? (bool)vx_genesis_is_live($db)
      : true),
  'crossbreed_pending' => $crossbreed,
]);
