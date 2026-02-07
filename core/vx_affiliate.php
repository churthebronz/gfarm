<?php
// core/vx_affiliate.php
// Affiliate Guardian unlock rules.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/vx_guardians.php';

function vx_affiliate_required_refs(): int {
  $n = (int)vx_app_setting('affiliate_required_refs', 10);
  if ($n < 1) $n = 10;
  if ($n > 1000) $n = 1000;
  return $n;
}

function vx_affiliate_vp_per_day(): int {
  $n = (int)vx_app_setting('affiliate_vp_per_day', 5);
  if ($n < 0) $n = 0;
  if ($n > 500) $n = 500;
  return $n;
}

function vx_affiliate_lp_per_day(): int {
  $n = (int)vx_app_setting('affiliate_lp_per_day', 5);
  if ($n < 0) $n = 0;
  if ($n > 500) $n = 500;
  return $n;
}

/**
 * Tiered affiliate rewards (beyond unlock).
 * Defaults:
 *  - unlock at affiliate_required_refs (default 10)
 *  - tier2 at 25
 *  - tier3 at 50
 *  - tier4 at 100
 * Each tier adds +affiliate_tier_bonus_step VP/day and LP/day (default 1).
 */
function vx_affiliate_tiers(): array {
  $unlock = vx_affiliate_required_refs();
  $t2 = (int)vx_app_setting('affiliate_tier2_refs', 25);
  $t3 = (int)vx_app_setting('affiliate_tier3_refs', 50);
  $t4 = (int)vx_app_setting('affiliate_tier4_refs', 100);
  // normalize
  if ($t2 < $unlock) $t2 = $unlock;
  if ($t3 < $t2) $t3 = $t2;
  if ($t4 < $t3) $t4 = $t3;
  return [
    ['tier'=>1,'refs'=>$unlock,'label'=>'Unlock'],
    ['tier'=>2,'refs'=>$t2,'label'=>'Tier II'],
    ['tier'=>3,'refs'=>$t3,'label'=>'Tier III'],
    ['tier'=>4,'refs'=>$t4,'label'=>'Tier IV'],
  ];
}

function vx_affiliate_tier_bonus_step(): int {
  $n = (int)vx_app_setting('affiliate_tier_bonus_step', 1);
  if ($n < 0) $n = 0;
  if ($n > 50) $n = 50;
  return $n;
}

function vx_affiliate_tier_from_qualified(int $qualified): int {
  $tier = 0;
  foreach (vx_affiliate_tiers() as $t) {
    if ($qualified >= (int)$t['refs']) $tier = (int)$t['tier'];
  }
  return $tier;
}

function vx_affiliate_daily_bonus_for_tier(int $tier): array {
  // Tier 1 is the base Affiliate Guardian payout.
  // Tier 2+ add incremental bonuses.
  $step = vx_affiliate_tier_bonus_step();
  $extraTiers = max(0, $tier - 1);
  return ['vp'=>($extraTiers * $step), 'lp'=>($extraTiers * $step)];
}

function vx_user_has_affiliate_badge($db, int $uid): bool {
  if ($uid <= 0) return false;
  try {
    // New key
    if ((string)vx_meta_get($db, $uid, 'affiliate_badge', '0') === '1') return true;
    // Back-compat: prior Partner badge
    if ((string)vx_meta_get($db, $uid, 'partner_badge', '0') === '1') return true;
  } catch (Throwable $e) {}
  return false;
}

/**
 * Qualified paid referrals:
 * - user.rid = $uid
 * - referred user has at least 1 active/matured/codex plan guardian chain (kind='plan')
 * - excludes founders/affiliate special guardians automatically by using kind='plan'
 */
function vx_affiliate_qualified_paid_refs($db, int $uid): int {
  if (!$db || $uid <= 0) return 0;
  vx_guardians_schema_ensure($db);
  try {
    // New trust layer: if the qualification table exists, use it.
    // This enforces min paid amount + cooldown + admin review (flagged/blocked do not count).
    try {
      require_once __DIR__ . '/vx_refqual.php';
      vx_refqual_schema_ensure($db);
      $r2 = $db->query("SELECT COUNT(*) AS c FROM vx_ref_qualifications WHERE referrer_uid=? AND status='qualified'", $uid)->fetchArray();
      if (is_array($r2) && array_key_exists('c', $r2)) {
        return (int)($r2['c'] ?? 0);
      }
    } catch (Throwable $e) {
      // Fall back to legacy behavior below.
    }

    $q = $db->query(
      "SELECT COUNT(DISTINCT u.id) AS c\n"
      ."FROM db_users u\n"
      ."JOIN vx_guardian_chains c ON c.uid = u.id\n"
      ."JOIN db_tarif t ON t.id = c.tarif\n"
      ."WHERE u.rid = ?\n"
      ."  AND c.kind = 'plan'\n"
      ."  AND t.price > 0\n"
      ."  AND c.status IN ('active','matured','codex')",
      $uid
    );
    $r = $q ? ($q->fetchArray() ?: []) : [];
    return (int)($r['c'] ?? 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function vx_affiliate_status($db, int $uid): array {
  $qualified = vx_affiliate_qualified_paid_refs($db, $uid);
  $need = vx_affiliate_required_refs();
  $claimed = vx_user_has_affiliate_badge($db, $uid);
  $tier = vx_affiliate_tier_from_qualified($qualified);
  $bonus = vx_affiliate_daily_bonus_for_tier($tier);

  // Milestone drops (pure motivation / badges).
  // Idempotent via user_meta flags so it is safe to call often.
  $milestones = [5, 10, 25, 50, 100];
  $newMilestones = [];
  try {
    foreach ($milestones as $m) {
      if ($qualified < $m) continue;
      $k = 'aff_milestone_'.$m;
      $seen = (string)vx_meta_get($db, $uid, $k, '0');
      if ($seen !== '1') {
        vx_meta_set($db, $uid, $k, '1');
        $newMilestones[] = $m;
        // Activity + notification (best-effort)
        try {
          require_once __DIR__ . '/vx_activity.php';
          vx_activity_log($db, $uid, 'aff_milestone', (float)$m, ['milestone'=>$m]);
        } catch (Throwable $e) {}
        try {
          vx_notify_once($db, $uid, 'aff_ms_'.$m, 'affiliate_milestone', 'success', 'Referral milestone unlocked', 'You hit '.$m.' qualified referrals. Keep going to unlock more perks.', ['milestone'=>$m]);
        } catch (Throwable $e) {}
      }
    }
  } catch (Throwable $e) {}

  return [
    'qualified' => $qualified,
    'required' => $need,
    'eligible' => (!$claimed && $qualified >= $need),
    'claimed' => $claimed,
    'tier' => $tier,
    'tiers' => vx_affiliate_tiers(),
    'bonus_step' => vx_affiliate_tier_bonus_step(),
    'bonus_vp' => (int)($bonus['vp'] ?? 0),
    'bonus_lp' => (int)($bonus['lp'] ?? 0),
    'vp_day' => vx_affiliate_vp_per_day() + (int)($bonus['vp'] ?? 0),
    'lp_day' => vx_affiliate_lp_per_day() + (int)($bonus['lp'] ?? 0),

    'milestones' => $milestones,
    'new_milestones' => $newMilestones,
  ];
}
