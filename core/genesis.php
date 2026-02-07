<?php
declare(strict_types=1);

/**
 * Season Pass / Snapshot helpers
 * - Uses current season window (vx_seasons starts_at/ends_at)
 * - Snapshot is treated as season end (ends_at)
 * - Season Pass badge stored in user_meta: meta_key = 'genesis_badge'
 */

require_once __DIR__ . '/seasons.php';

function vx_snapshot_at($db): int {
  $s = vx_current_season($db);
  if (!is_array($s) || empty($s['ok'])) return 0;
  return (int)($s['ends_at'] ?? 0);
}

function vx_snapshot_seconds_left($db): int {
  $s = vx_current_season($db);
  if (!is_array($s) || empty($s['ok'])) return 0;
  return (int)($s['ends_in'] ?? 0);
}

function vx_genesis_is_live($db): bool {
  $snap = vx_snapshot_at($db);
  if ($snap <= 0) return false;
  return time() < $snap;
}

function vx_user_has_genesis_badge($db, int $uid): bool {
  try {
    $row = $db->query("SELECT meta_value FROM user_meta WHERE user_id=? AND meta_key='genesis_badge' LIMIT 1", $uid)->fetchArray();
    return is_array($row) && !empty($row['meta_value']);
  } catch (Throwable $e) {
    return false;
  }
}

function vx_grant_genesis_badge($db, int $uid): bool {
  // Idempotent: unique(user_id, meta_key) should exist; if not, fall back gracefully
  $val = json_encode(['earned_at' => time()], JSON_UNESCAPED_UNICODE);
  try {
    // Try insert ignore style
    $db->query("INSERT INTO user_meta (user_id, meta_key, meta_value, created_at, updated_at)
                VALUES (?, 'genesis_badge', ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE meta_value=meta_value", $uid, $val);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function vx_grant_genesis_badge_if_eligible($db, int $uid): bool {
  if ($uid <= 0) return false;
  if (!vx_genesis_is_live($db)) return false;
  if (vx_user_has_genesis_badge($db, $uid)) return false;
  return vx_grant_genesis_badge($db, $uid);
}
