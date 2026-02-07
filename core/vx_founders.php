<?php
declare(strict_types=1);
// File: /core/vx_founders.php
// GreenFarm — Founders onboarding (30-day Genesis window)
//
// What this does:
// - During the first 30 days after launch, every NEW user who logs in gets:
//   1) A Founders badge (user_meta: founders_badge=1)
//   2) A special "Founders Guardian" that earns VP daily (VP only; no balance; no LP)
//
// Notes:
// - Launch timestamp is taken from app setting: founders_launch_ts
// - If not set, the first time we grant a Founders reward we automatically set it to "now".
// - The Founders guardian is implemented as a special guardian chain (kind='founder')
//   with override VP/day, decoupled from plans/deposits.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_app_settings.php';
require_once __DIR__ . '/schema_helpers.php';
require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/vx_guardians.php';

// Reserved tariff IDs for special guardians (kept outside normal tariff range)
// Override via app settings if you ever need to migrate IDs.
const VX_FOUNDERS_TARIF_ID_DEFAULT = 10001;

function vx_founders_tarif_id(): int {
  $v = (int)vx_app_setting('founders_tarif_id', VX_FOUNDERS_TARIF_ID_DEFAULT);
  return ($v > 0) ? $v : VX_FOUNDERS_TARIF_ID_DEFAULT;
}

/** Ensure the special tariff row exists so the UI can render it like other Seed plans. */
function vx_founders_ensure_tarif_row($db, int $now): void {
  if (!$db) return;
  $id = vx_founders_tarif_id();
  try {
    // price=0, period=0, speed/profit_speed=0 (this plan never pays balance)
    $db->query(
      "INSERT INTO db_tarif (id, title, img, speed, profit_speed, price, period, created_at, updated_at, sort_order, is_active)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE title=VALUES(title), price=VALUES(price), period=VALUES(period), speed=VALUES(speed), profit_speed=VALUES(profit_speed), is_active=VALUES(is_active), updated_at=VALUES(updated_at)",
      $id,
      'Founders Seed',
      0,
      0.000000,
      0.000000,
      0,
      0,
      date('Y-m-d H:i:s', $now),
      date('Y-m-d H:i:s', $now),
      -100,
      1
    );
  } catch (Throwable $e) {}
}

/** Ensure user_meta exists (some older installs may not have it). */
function vx_founders_schema_ensure($db): void {
  if (!$db) return;
  try {
    $db->query("CREATE TABLE IF NOT EXISTS user_meta (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT NOT NULL,
      meta_key VARCHAR(64) NOT NULL,
      meta_value MEDIUMTEXT NULL,
      created_at INT NOT NULL DEFAULT 0,
      updated_at INT NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY ux_user_key (user_id, meta_key),
      KEY ix_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}
}

// NOTE: Meta helpers are provided by core/vx_retention.php (vx_meta_get / vx_meta_set).

function vx_founders_launch_ts($db, int $now): int {
  // Admin can set this in DB settings; we fail-soft.
  $ts = (int)vx_app_setting('founders_launch_ts', 0);
  if ($ts > 0) return $ts;

  // Auto-seed on first run to make "30 days from launch" real without manual steps.
  // If you want strict control, set founders_launch_ts explicitly in admin before launch.
  try {
    if (function_exists('vx_app_setting_set')) {
      vx_app_setting_set($db, 'founders_launch_ts', (string)$now);
      return $now;
    }
  } catch (Throwable $e) {}

  return $now;
}

function vx_founders_window_active($db, int $now): bool {
  $launch = vx_founders_launch_ts($db, $now);
  $days = (int)vx_app_setting('founders_window_days', 30);
  if ($days < 1) $days = 30;
  $end = $launch + ($days * 86400);
  return ($now >= $launch && $now < $end);
}

function vx_user_has_founders_badge($db, int $uid): bool {
  return ((string)vx_meta_get($db, $uid, 'founders_badge', '0') === '1');
}

/**
 * Grant founders badge + founders guardian to eligible users.
 * Safe to call on every login.
 */
function vx_founders_on_login($db, int $uid, int $now): void {
  if (!$db || $uid <= 0) return;
  vx_founders_schema_ensure($db);
  vx_guardians_schema_ensure($db);

  // Only during launch window.
  if (!vx_founders_window_active($db, $now)) return;

  // If already granted, stop.
  if (vx_user_has_founders_badge($db, $uid)) return;

  // Mark badge first (idempotent).
  vx_meta_set($db, $uid, 'founders_badge', '1');
  vx_meta_set($db, $uid, 'founders_badge_at', (string)$now);

  // Ensure the special tariff exists for consistent UI rendering.
  vx_founders_ensure_tarif_row($db, $now);

  // Create Founders Guardian if not exists.
  try {
    $exists = $db->query("SELECT id FROM vx_guardian_chains WHERE uid=? AND kind='founder' LIMIT 1", $uid)->fetchArray();
    if ($exists && (int)($exists['id'] ?? 0) > 0) return;
  } catch (Throwable $e) {}

  $vpDay = (int)vx_app_setting('founders_vp_per_day', 5);
  if ($vpDay < 1) $vpDay = 5;
  if ($vpDay > 100) $vpDay = 100; // safety

  $sid = 0;
  try { $sid = function_exists('vx_get_current_season') ? (int)((vx_get_current_season($db)['id'] ?? 0)) : 0; } catch (Throwable $e) { $sid = 0; }

  try {
    $db->query(
      "INSERT INTO vx_guardian_chains (uid, tarif, root_store_id, current_store_id, kind, title_override, vp_per_day_override, lp_per_day_override, season_id, rarity, crossbreed_level, status, term_end, matured_at, crossbreed_deadline, points_last_at, codex_reason, codex_at, created_at, updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      $uid,
      vx_founders_tarif_id(),
      0,
      0,
      'founder',
      'Founders Guardian',
      $vpDay,
      0,
      $sid,
      'legendary',
      0,
      'active',
      0,
      0,
      0,
      $now,
      'founders',
      $now,
      $now,
      $now
    );
  } catch (Throwable $e) {}
}
