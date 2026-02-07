<?php
declare(strict_types=1);

/**
 * File: /api/user/_bootstrap.php
 * Shared bootstrap for authenticated user API endpoints.
 *
 * Goals:
 * - Works reliably inside Telegram WebView (cookie/session timing can be delayed)
 * - Never hard-crashes the dashboard UI (fail-soft JSON)
 * - Centralizes JSON helpers + common includes
 */

define('FastCore', true);

// IMPORTANT:
// Telegram WebView / cookie timing can be delayed on first paint.
// require_tg_session.php normally hard-exits with 401 when no cookie is present.
// For dashboard APIs we must run it in SOFT mode and fail-soft with JSON instead.
if (!defined('VX_SOFT_AUTH')) {
  define('VX_SOFT_AUTH', true);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* -------- JSON helpers (fail-soft) -------- */
if (!function_exists('vx_json_out')) {
  function vx_json_out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }
}
// Back-compat helper used by a few endpoints
if (!function_exists('vx_json')) {
  function vx_json(array $payload, int $code = 200): void {
    vx_json_out($payload, $code);
  }
}

/* -------- Auth -------- */
// Cookie/session resolver (sets $GLOBALS['UID'])
require_once __DIR__ . '/../require_tg_session.php';
$uid = (int)($GLOBALS['UID'] ?? 0);

// Mirror into PHP session for legacy callers
if ($uid > 0) {
  $_SESSION['uid'] = $uid;
}

// IMPORTANT: fail-soft (Telegram sometimes delays cookie/session availability on first paint)
if ($uid <= 0) {
  vx_json_out(['ok' => false, 'auth' => false], 200);
}

/* -------- DB + common includes -------- */
global $db;
if (!isset($db) || !is_object($db)) {
  vx_json_out(['ok' => false, 'error' => 'DB'], 500);
}

// Common core helpers used by multiple endpoints
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/earnings_helpers.php';
require_once __DIR__ . '/../../core/vx_activity.php';
require_once __DIR__ . '/../../core/events_log.php';

// Launch-safe schema ensure (idempotent)
try { vx_schema_ensure($db); } catch (Throwable $e) {}
