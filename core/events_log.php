<?php
declare(strict_types=1);

// File: /core/events_log.php
// Purpose: Lightweight event logging with schema auto-detection (compat with older builds).

if (!defined('FastCore')) { define('FastCore', true); }

/**
 * Ensure a usable events_log table exists.
 * Preferred schema (current): user_id, event_type, ctx, ip, ua, created_at
 * Legacy schema (older): uid, event, meta, created_at
 */
function vx_events_log_ensure($db): void {
  if (!$db) return;

  // Create preferred schema if table is missing. If it already exists with a different schema,
  // we do NOT try to migrate automatically (safe in prod). Inserts will adapt at runtime.
  try {
    $db->query(
      "CREATE TABLE IF NOT EXISTS `events_log` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `event_type` VARCHAR(32) NOT NULL,
        `ctx` VARCHAR(64) NULL,
        `ip` VARCHAR(64) NULL,
        `ua` VARCHAR(191) NULL,
        `created_at` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_user_time` (`user_id`, `created_at`),
        KEY `idx_type_time` (`event_type`, `created_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function vx_events_log_columns($db): array {
  static $cache = null;
  if ($cache !== null) return $cache;

  $cache = ['schema' => 'unknown', 'cols' => []];

  if (!$db) return $cache;

  try {
    $rows = $db->query(
      "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events_log'"
    );
    $cols = [];
    while ($r = $rows->fetchArray()) {
      if (!empty($r['COLUMN_NAME'])) $cols[strtolower((string)$r['COLUMN_NAME'])] = true;
    }

    $cache['cols'] = $cols;

    // Preferred schema
    if (isset($cols['user_id']) && isset($cols['event_type'])) {
      $cache['schema'] = 'preferred';
      return $cache;
    }
    // Legacy schema
    if (isset($cols['uid']) && isset($cols['event'])) {
      $cache['schema'] = 'legacy';
      return $cache;
    }
  } catch (Throwable $e) {}

  // If table exists but we can't inspect, assume preferred (most common in this repo)
  $cache['schema'] = 'preferred';
  return $cache;
}

/**
 * Log an event, fail-soft.
 *
 * @param string $event event name/type
 * @param int $uid user id (0 allowed)
 * @param mixed $meta string/array/object context
 */
function vx_events_log($db, string $event, int $uid = 0, $meta = null): void {
  if (!$db) return;

  try {
    vx_events_log_ensure($db);
    $info = vx_events_log_columns($db);

    $m = null;
    if ($meta !== null) {
      if (is_string($meta)) $m = $meta;
      else $m = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $now = time();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    if ($info['schema'] === 'legacy') {
      // uid,event,meta,created_at
      try {
        $db->query(
          "INSERT INTO events_log (uid, event, meta, created_at) VALUES (?, ?, ?, ?)",
          (int)$uid, (string)$event, (string)($m ?? ''), (int)$now
        );
      } catch (Throwable $e) {}
      return;
    }

    // preferred: user_id,event_type,ctx,ip,ua,created_at
    $ctx = $m;
    if ($ctx !== null && strlen($ctx) > 64) {
      $ctx = substr($ctx, 0, 64);
    }

    try {
      $db->query(
        "INSERT INTO events_log (user_id, event_type, ctx, ip, ua, created_at) VALUES (?, ?, ?, ?, ?, ?)",
        (int)$uid, (string)$event, $ctx, $ip, $ua, (int)$now
      );
    } catch (Throwable $e) {}
  } catch (Throwable $e) {}
}
