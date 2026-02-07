<?php
// core/vx_events.php
// Events engine (simple + production-safe)
// Option A: Daily Harvest Rush (12:00–15:00 UTC)
// - Multiplies Points awarded for vault activations.
// - Supports per-user extension boost (+30m) via vx_boosts.php.

if (!defined('FastCore')) define('FastCore', true);

/**
 * Returns today's Harvest Rush window in UTC.
 * @return array{start:int,end:int,ext_end:int,date:string}
 */
function vx_event_vault_rush_window(int $ts = 0): array {
  if ($ts <= 0) $ts = time();
  $date = gmdate('Y-m-d', $ts);
  $start = strtotime($date . ' 12:00:00 UTC');
  $end   = strtotime($date . ' 15:00:00 UTC');
  // global extension window reserved for per-user boost (+30m)
  $ext_end = $end + 30 * 60;
  return ['start'=>$start, 'end'=>$end, 'ext_end'=>$ext_end, 'date'=>$date];
}

/**
 * Computes event status.
 */
function vx_event_status_vault_rush(int $uid = 0): array {
  $now = time();
  $w = vx_event_vault_rush_window($now);
  $active = ($now >= $w['start'] && $now < $w['end']);
  $extended = false;

  // If user has Rush Token boost active, allow extension until ext_end
  if (!$active && $uid > 0) {
    try {
      require_once __DIR__ . '/vx_boosts.php';
      if (vx_boost_is_active(null, $uid, 'rush_token')) {
        $active = ($now >= $w['start'] && $now < $w['ext_end']);
        if ($active) $extended = ($now >= $w['end']);
      }
    } catch (Throwable $e) {}
  }

  $next_start = $w['start'];
  if ($now >= $w['ext_end']) {
    // tomorrow
    $tomorrow = $now + 86400;
    $nw = vx_event_vault_rush_window($tomorrow);
    $next_start = $nw['start'];
    $w = $nw;
  }

  $ends_at = $active ? ($extended ? $w['ext_end'] : $w['end']) : $w['end'];
  return [
    'ok' => true,
    'key' => 'vault_rush',
    'title' => 'Harvest Rush',
    'multiplier' => 2,
    'active' => $active,
    'extended' => $extended,
    'now' => $now,
    'start_at' => (int)$w['start'],
    'end_at' => (int)$w['end'],
    'end_at_effective' => (int)$ends_at,
    'next_start_at' => (int)$next_start,
    'seconds_left' => $active ? max(0, $ends_at - $now) : 0,
  ];
}

function vx_event_vault_rush_multiplier($db, int $uid): int {
  $st = vx_event_status_vault_rush($uid);
  return !empty($st['active']) ? 2 : 1;
}
