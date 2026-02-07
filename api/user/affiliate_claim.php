<?php
declare(strict_types=1);
// /api/user/affiliate_claim.php
// Claims the Affiliate Guardian once qualified.
// Idempotent.

if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/vx_affiliate.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Not signed in']); exit; }

try {
  if (!isset($GLOBALS['db']) || !$GLOBALS['db']) { echo json_encode(['ok'=>false,'msg'=>'DB not ready']); exit; }
  $db = $GLOBALS['db'];
  if (function_exists('vx_schema_ensure')) vx_schema_ensure($db);
  vx_guardians_schema_ensure($db);

  $required = vx_affiliate_required_refs();
  $qualified = vx_affiliate_qualified_paid_refs($db, $uid);
  $claimed = vx_user_has_affiliate_badge($db, $uid);

  if (!$claimed && $qualified < $required) {
    echo json_encode(['ok'=>false,'claimed'=>false,'eligible'=>false,'qualified'=>$qualified,'required'=>$required,'msg'=>'Not eligible yet']);
    exit;
  }

  // If already claimed, return ok.
  if ($claimed) {
    echo json_encode(['ok'=>true,'claimed'=>true,'eligible'=>false,'qualified'=>$qualified,'required'=>$required]);
    exit;
  }

  $now = time();
  $vpDay = vx_affiliate_vp_per_day();
  $lpDay = vx_affiliate_lp_per_day();

  // Ensure only one Affiliate Guardian chain exists.
  $exists = false;
  try {
    $x = $db->query("SELECT id FROM vx_guardian_chains WHERE uid=? AND kind IN ('affiliate','partner') LIMIT 1", [$uid])->fetchArray();
    $exists = (bool)$x;
  } catch (Throwable $e) {}

  if (!$exists) {
    // Create a special always-on chain. It does not mature/crossbreed and does not produce yield.
    try {
      $db->query(
        "INSERT INTO vx_guardian_chains (uid, tarif, root_store_id, current_store_id, season_id, rarity, crossbreed_level, status, term_end, matured_at, crossbreed_deadline, vp_total, lp_total, vp_frac, lp_frac, points_last_at, created_at, updated_at, kind, title_override, vp_per_day_override, lp_per_day_override)\n"
        ."VALUES (?, 10002, 0, 0, 0, 'epic', 0, 'active', 0, 0, 0, 0, 0, 0, 0, ?, ?, ?, 'affiliate', 'Affiliate Guardian', ?, ?)",
        [$uid, $now, $now, $now, $vpDay, $lpDay]
      );
    } catch (Throwable $e) {
      echo json_encode(['ok'=>false,'msg'=>'Could not create guardian']);
      exit;
    }
  }

  // Set badge keys (new + back-compat)
  try { vx_meta_set($db, $uid, 'affiliate_badge', '1'); } catch (Throwable $e) {}
  try { vx_meta_set($db, $uid, 'partner_badge', '1'); } catch (Throwable $e) {}

  // Celebrate once
  try { vx_notify_once($db, $uid, 'affiliate_unlocked', 'affiliate', 'success', 'Affiliate Guardian unlocked', 'You hit '.$qualified.' qualified paid referral(s). Claim complete.', ['qualified'=>$qualified]); } catch (Throwable $e) {}

  echo json_encode([
    'ok'=>true,
    'claimed'=>true,
    'eligible'=>false,
    'qualified'=>$qualified,
    'required'=>$required,
    'vp_day'=>$vpDay,
    'lp_day'=>$lpDay,
  ]);
  exit;
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'msg'=>'Server error']);
  exit;
}
