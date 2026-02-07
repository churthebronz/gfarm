<?php
declare(strict_types=1);
// File: /api/user/founders_claim.php
// Purpose: Manual claim endpoint for the Founders Guardian popup.
// - Idempotent: safe to call multiple times.
// - Only grants during the active founders window.

if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../../core/vx_founders.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  echo json_encode(['ok' => false, 'msg' => 'Not signed in']);
  exit;
}

try {
  if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
    echo json_encode(['ok' => false, 'msg' => 'DB not ready']);
    exit;
  }
  $db = $GLOBALS['db'];
  if (function_exists('vx_schema_ensure')) { vx_schema_ensure($db); }

  $now = time();
  $window = function_exists('vx_founders_window_active') ? (bool)vx_founders_window_active($db, $now) : false;
  $already = (function_exists('vx_user_has_founders_badge')) ? (bool)vx_user_has_founders_badge($db, $uid) : ((string)vx_meta_get($db, $uid, 'founders_badge', '0') === '1');

  if (!$window && !$already) {
    echo json_encode(['ok' => false, 'claimed' => false, 'window' => false, 'msg' => 'Founders launch window ended.']);
    exit;
  }

  // If within window and not yet claimed, grant now.
  if ($window && !$already) {
    try { vx_founders_on_login($db, $uid, $now); } catch (Throwable $e) {}
  }

  $claimed = (function_exists('vx_user_has_founders_badge')) ? (bool)vx_user_has_founders_badge($db, $uid) : ((string)vx_meta_get($db, $uid, 'founders_badge', '0') === '1');
  $vpDay = (int)vx_app_setting('founders_vp_per_day', 5);
  if ($vpDay < 1) $vpDay = 5;
  if ($vpDay > 100) $vpDay = 100;

  // Conversion attribution (fail-soft): if user has a referral cookie, record conversion once.
  try {
    if ($claimed) {
      $ref = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_COOKIE['vx_ref'] ?? ''));
      if (stripos($ref, 'ref_') === 0 && strlen($ref) > 4) { $ref = substr($ref, 4); }
      if ($ref !== '') {
        // Ensure table exists (same schema as /api/share/track.php)
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

        // Don’t count self-ref conversions.
        $rid = 0;
        try { $r = $db->query('SELECT id FROM db_users WHERE ref_code=? LIMIT 1', [$ref])->fetchArray(); $rid = (int)($r['id'] ?? 0); } catch (Throwable $e) {}
        if ($rid > 0 && $rid !== $uid) {
          $exists = false;
          try {
            $x = $db->query("SELECT id FROM vx_share_events WHERE uid=? AND event='conversion' AND ctx='founders_claim' LIMIT 1", [$uid])->fetchArray();
            $exists = (bool)$x;
          } catch (Throwable $e) {}
          if (!$exists) {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
            $ipHash = $ip !== '' ? hash('sha256', $ip) : '';
            $uaHash = $ua !== '' ? hash('sha256', $ua) : '';
            try {
              $db->query(
                "INSERT INTO vx_share_events (uid, ref_code, event, ctx, ip_hash, ua_hash, created_at) VALUES (?, ?, 'conversion', 'founders_claim', ?, ?, ?)",
                [$uid, $ref, $ipHash, $uaHash, time()]
              );
            } catch (Throwable $e) {}
          }
        }
      }
    }
  } catch (Throwable $e) {}

  echo json_encode([
    'ok' => true,
    'claimed' => $claimed,
    'window' => $window,
    'vp_day' => $vpDay,
  ]);
  exit;
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'msg' => 'Server error']);
  exit;
}
