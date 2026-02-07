<?php
declare(strict_types=1);
// File: /core/vx_partners.php
// GreenFarm — Partner/Ambassador guardians (manual grants)
//
// Purpose: give select partners/influencers a special guardian that earns VP + LP.
// Guardrails built in:
// - Not purchasable via public flows
// - Hard caps via app settings (optional)
// - LP does not depend on deposits/plans

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_app_settings.php';
require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/vx_guardians.php';

// Reserved tariff ID for Partner/Ambassador plan card.
const VX_PARTNER_TARIF_ID_DEFAULT = 10002;

function vx_partner_tarif_id(): int {
  $v = (int)vx_app_setting('partner_tarif_id', VX_PARTNER_TARIF_ID_DEFAULT);
  return ($v > 0) ? $v : VX_PARTNER_TARIF_ID_DEFAULT;
}

/** Ensure the special tariff row exists so it renders like a normal Seed plan. */
function vx_partner_ensure_tarif_row($db, int $now): void {
  if (!$db) return;
  $id = vx_partner_tarif_id();
  try {
    $db->query(
      "INSERT INTO db_tarif (id, title, img, speed, profit_speed, price, period, created_at, updated_at, sort_order, is_active)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE title=VALUES(title), price=VALUES(price), period=VALUES(period), speed=VALUES(speed), profit_speed=VALUES(profit_speed), is_active=VALUES(is_active), updated_at=VALUES(updated_at)",
      $id,
      'Partner Seed',
      0,
      0.000000,
      0.000000,
      0,
      0,
      date('Y-m-d H:i:s', $now),
      date('Y-m-d H:i:s', $now),
      -99,
      1
    );
  } catch (Throwable $e) {}
}

function vx_partner_total_granted($db): int {
  if (!$db) return 0;
  try {
    $r = $db->query("SELECT COUNT(*) AS c FROM vx_guardian_chains WHERE kind='partner'")->fetchArray();
    return (int)($r['c'] ?? 0);
  } catch (Throwable $e) { return 0; }
}

function vx_partner_grant($db, int $uid, int $now, string $title = 'Ambassador Guardian', int $vpPerDay = 25, int $lpPerDay = 3): array {
  if (!$db || $uid <= 0) return ['ok'=>false,'err'=>'bad_uid'];
  vx_guardians_schema_ensure($db);

  // One per user.
  try {
    $ex = $db->query("SELECT id FROM vx_guardian_chains WHERE uid=? AND kind='partner' LIMIT 1", $uid)->fetchArray();
    if ($ex && (int)($ex['id'] ?? 0) > 0) return ['ok'=>true,'already'=>true];
  } catch (Throwable $e) {}

  // Optional global cap.
  $cap = (int)vx_app_setting('partner_guardian_cap', 50);
  if ($cap < 1) $cap = 50;
  if (vx_partner_total_granted($db) >= $cap) return ['ok'=>false,'err'=>'cap_reached'];

  // Clamp rates.
  $vpPerDay = max(1, min(500, (int)$vpPerDay));
  $lpPerDay = max(0, min(100, (int)$lpPerDay));
  $title = trim($title);
  if ($title === '') $title = 'Ambassador Guardian';

  // Badge.
  vx_meta_set($db, $uid, 'partner_badge', '1');
  vx_meta_set($db, $uid, 'partner_badge_at', (string)$now);

  // Ensure UI tariff exists.
  vx_partner_ensure_tarif_row($db, $now);

  $sid = 0;
  try { $sid = function_exists('vx_get_current_season') ? (int)((vx_get_current_season($db)['id'] ?? 0)) : 0; } catch (Throwable $e) { $sid = 0; }

  // Create guardian.
  try {
    $db->query(
      "INSERT INTO vx_guardian_chains (uid, tarif, root_store_id, current_store_id, kind, title_override, vp_per_day_override, lp_per_day_override, season_id, rarity, crossbreed_level, status, term_end, matured_at, crossbreed_deadline, points_last_at, codex_reason, codex_at, created_at, updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      $uid,
      vx_partner_tarif_id(),
      0,
      0,
      'partner',
      $title,
      $vpPerDay,
      $lpPerDay,
      $sid,
      'mythic',
      0,
      'active',
      0,
      0,
      0,
      $now,
      'partner',
      $now,
      $now,
      $now
    );
    return ['ok'=>true,'granted'=>true];
  } catch (Throwable $e) {
    return ['ok'=>false,'err'=>'db_error'];
  }
}
