<?php
// core/vx_virality.php
// Virality & anti-abuse utilities (Points rewards only)
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/vx_points.php';

function vx_qualified_ref_count($db, int $uid): int {
  if ($uid <= 0) return 0;
  try {
    // Prefer the new trust layer (qualified paid referrals) when available.
    try {
      require_once __DIR__ . '/vx_refqual.php';
      vx_refqual_schema_ensure($db);
      $r2 = $db->query("SELECT COUNT(*) AS c FROM vx_ref_qualifications WHERE referrer_uid=? AND status='qualified'", $uid)->fetchArray();
      if (is_array($r2) && array_key_exists('c', $r2)) {
        return (int)($r2['c'] ?? 0);
      }
    } catch (Throwable $e) {
      // fall back to legacy below
    }

    // Qualified referral = referred user (rid=uid) who has either deposited or started at least 1 vault.
    // (This blocks spam-bot signups from counting.)
    $q = $db->query(
      "SELECT COUNT(DISTINCT u.id) AS c
       FROM db_users u
       LEFT JOIN db_insert i ON i.uid = u.id
       WHERE u.rid = ?
         AND (u.sum_in > 0 OR i.id IS NOT NULL)",
      $uid
    );
    $r = $q ? ($q->fetchArray() ?: []) : [];
    return (int)($r['c'] ?? 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function vx_virality_tick($db, int $uid): void {
  if ($uid <= 0) return;

  // Invite quest ladder (award once per milestone)
  $milestones = [1, 3, 5, 10, 25, 50, 100];
  $rewards = [
    1 => 50,
    3 => 100,
    5 => 200,
    10 => 400,
    25 => 1000,
    50 => 2500,
    100 => 6000,
  ];

  $qualified = vx_qualified_ref_count($db, $uid);
  foreach ($milestones as $m) {
    if ($qualified < $m) continue;
    $k = 'invite_milestone_' . $m;
    $got = (string)vx_meta_get($db, $uid, $k, '0');
    if ($got === '1') continue;

    $amt = (int)($rewards[$m] ?? 0);
    if ($amt <= 0) {
      vx_meta_set($db, $uid, $k, '1');
      continue;
    }

    // Award points and mark as claimed.
    $ok = vx_points_add($db, $uid, $amt, 'Invite milestone', [
      'milestone' => $m,
      'qualified_refs' => $qualified,
    ]);

    if ($ok) {
      vx_meta_set($db, $uid, $k, '1');
      vx_notify_once($db, $uid, 'invite_'.$m, 'invite_reward', 'success', 'Invite milestone unlocked', 'You hit '.$m.' qualified referral(s) and earned +'.$amt.' Points.', [
        'm' => $m,
        'pts' => $amt,
      ]);
    }
  }
}
