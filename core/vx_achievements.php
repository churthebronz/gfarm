<?php
// core/vx_achievements.php
// GreenFarm — Achievements (one-time) with small VP/LP rewards.
//
// Fail-soft + idempotent.

if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/vx_app_settings.php';
require_once __DIR__ . '/vx_user_meta.php';
require_once __DIR__ . '/vx_points.php';
require_once __DIR__ . '/vx_lp.php';
require_once __DIR__ . '/vx_activity.php';

/**
 * Get an integer app setting with a default.
 */
function vx_achv_int(string $key, int $def): int {
  try {
    $v = (int)vx_app_setting($key, $def);
    return $v;
  } catch (Throwable $e) {
    return $def;
  }
}

/**
 * Award an achievement once.
 * Returns ['awarded'=>bool,'vp'=>int,'lp'=>int]
 */
function vx_achievement_award_once($db, int $uid, string $achKey, string $title, string $desc, int $vp, int $lp, array $meta = []): array {
  $out = ['awarded'=>false,'vp'=>0,'lp'=>0];
  if ($uid <= 0 || !$db) return $out;
  $achKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $achKey);
  if ($achKey === '') return $out;

  try {
    $flagKey = 'achv_' . $achKey;
    $done = (string)vx_meta_get($db, $uid, $flagKey, '0');
    if ($done === '1') return $out;

    // Mark first (best effort) to avoid double-awards in race conditions.
    vx_meta_set($db, $uid, $flagKey, '1');

    // Award VP/LP.
    $vp = (int)$vp;
    $lp = (int)$lp;
    if ($vp !== 0) {
      try { vx_points_add($db, $uid, $vp, 'Achievement', ['ach'=>$achKey] + $meta); } catch (Throwable $e) {}
    }
    if ($lp !== 0) {
      try { vx_lp_add($db, $uid, $lp, 'achievement', ['ach'=>$achKey] + $meta); } catch (Throwable $e) {}
    }

    // Activity log (for Recent Activity panel)
    try {
      vx_activity_log($db, $uid, 'achievement', (float)$vp, [
        'key' => $achKey,
        'title' => $title,
        'desc' => $desc,
        'vp' => $vp,
        'lp' => $lp,
      ] + $meta);
    } catch (Throwable $e) {}

    // Optional: lightweight in-app notification (if notify system exists)
    try {
      if (function_exists('vx_notify_once')) {
        $txt = $desc;
        if ($vp > 0 || $lp > 0) {
          $txt .= "\n\nReward: +".max(0,$vp)." VP" . ($lp>0 ? (" +".$lp." LP") : '');
        }
        vx_notify_once($db, $uid, 'achv_'.$achKey, 'achievement', 'success', 'Achievement unlocked', $txt, [
          'ach' => $achKey,
          'vp' => $vp,
          'lp' => $lp,
        ]);
      }
    } catch (Throwable $e) {}

    return ['awarded'=>true,'vp'=>$vp,'lp'=>$lp];
  } catch (Throwable $e) {
    return $out;
  }
}

/**
 * Event hooks
 */
function vx_achievements_on_claim($db, int $uid, float $claimedAmount, int $streakDays): void {
  if ($uid <= 0 || !$db) return;
  try {
    // First ever claim
    $vp = vx_achv_int('achv_first_claim_vp', 5);
    vx_achievement_award_once($db, $uid, 'first_claim', 'First Claim', 'You collected yield for the first time.', $vp, 0, ['claimed'=>$claimedAmount]);

    // 7-day streak
    if ($streakDays >= 7) {
      $vp7 = vx_achv_int('achv_streak7_vp', 10);
      vx_achievement_award_once($db, $uid, 'streak_7', 'Streak • 7 Days', 'Claimed 7 days in a row.', $vp7, 0, ['streak'=>$streakDays]);
    }

    // 30-day streak
    if ($streakDays >= 30) {
      $vp30 = vx_achv_int('achv_streak30_vp', 50);
      $lp30 = vx_achv_int('achv_streak30_lp', 3);
      vx_achievement_award_once($db, $uid, 'streak_30', 'Streak • 30 Days', 'Claimed 30 days in a row.', $vp30, $lp30, ['streak'=>$streakDays]);
    }
  } catch (Throwable $e) {}
}

function vx_achievements_on_first_paid($db, int $uid, int $planId, float $amount): void {
  if ($uid <= 0 || !$db) return;
  try {
    $vp = vx_achv_int('achv_first_paid_vp', 25);
    $lp = vx_achv_int('achv_first_paid_lp', 1);
    vx_achievement_award_once($db, $uid, 'first_paid', 'First Paid Guardian', 'Activated your first paid Guardian.', $vp, $lp, ['plan'=>$planId,'amount'=>$amount]);
  } catch (Throwable $e) {}
}

function vx_achievements_on_crossbreed($db, int $uid, int $level): void {
  if ($uid <= 0 || !$db) return;
  try {
    if ($level >= 1) {
      $vp = vx_achv_int('achv_first_crossbreed_vp', 20);
      $lp = vx_achv_int('achv_first_crossbreed_lp', 2);
      vx_achievement_award_once($db, $uid, 'first_crossbreed', 'First Crossbreed', 'You crossbreeded a Guardian and increased scaling.', $vp, $lp, ['crossbreed_level'=>$level]);
    }
  } catch (Throwable $e) {}
}

function vx_achievements_on_affiliate_tier($db, int $uid, int $tier, int $qualifiedRefs): void {
  if ($uid <= 0 || !$db) return;
  try {
    $key = 'affiliate_tier_'.$tier;
    $vp = vx_achv_int('achv_affiliate_tier_vp', 15);
    $lp = vx_achv_int('achv_affiliate_tier_lp', 1);
    // Reward scales slightly by tier, but stays small.
    $vp = (int)max(0, $vp * max(1, $tier));
    $lp = (int)max(0, $lp * max(1, min(3, $tier)));
    vx_achievement_award_once($db, $uid, $key, 'Affiliate Tier '.$tier, 'Reached Affiliate Tier '.$tier.' via qualified referrals.', $vp, $lp, ['tier'=>$tier,'qualified'=>$qualifiedRefs]);
  } catch (Throwable $e) {}
}
