<?php
// core/vx_ref_boost.php
// Referral boost levels: increases POINTS earned from referred users' purchases.
// Base referral points remain 10% of buyer points; boost multiplies the ref reward.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_virality.php';
require_once __DIR__ . '/vx_app_settings.php';

/**
 * Default boost ladder.
 * - qualified referrals are counted using vx_qualified_ref_count().
 * - mult applies on top of the base 10% referral points.
 *
 * Example: base=10%, level mult=1.20 => effective 12%.
 */
function vx_ref_boost_levels_default(): array {
  return [
    ['id'=>'lvl0','name'=>'Starter','min'=>0,'pct'=>0,'mult'=>1.00],
    ['id'=>'lvl1','name'=>'Bronze Boost','min'=>3,'pct'=>10,'mult'=>1.10],
    ['id'=>'lvl2','name'=>'Silver Boost','min'=>10,'pct'=>20,'mult'=>1.20],
    ['id'=>'lvl3','name'=>'Gold Boost','min'=>25,'pct'=>30,'mult'=>1.30],
    ['id'=>'lvl4','name'=>'Platinum Boost','min'=>50,'pct'=>40,'mult'=>1.40],
    ['id'=>'lvl5','name'=>'Diamond Boost','min'=>100,'pct'=>50,'mult'=>1.50],
  ];
}

/**
 * Base referral percent, configurable.
 */
function vx_ref_base_points_pct(): float {
  $pct = (float)vx_app_setting('ref_points_base_pct', 0.10);
  if ($pct < 0.00) $pct = 0.00;
  if ($pct > 0.50) $pct = 0.50; // hard cap safety
  return $pct;
}

/**
 * Effective boost level for a user.
 */
function vx_ref_boost_status($db, int $uid): array {
  $qualified = 0;
  try { $qualified = (int)vx_qualified_ref_count($db, $uid); } catch (Throwable $e) { $qualified = 0; }

  $levels = vx_ref_boost_levels_default();
  // optional override: comma list like "3:1.1,10:1.2" (min:mult)
  $override = (string)vx_app_setting('ref_points_boost_levels', '');
  if ($override !== '') {
    $parsed = [];
    foreach (explode(',', $override) as $pair) {
      $pair = trim($pair);
      if ($pair === '' || strpos($pair, ':') === false) continue;
      [$m,$mult] = array_map('trim', explode(':', $pair, 2));
      $min = (int)$m;
      $mu = (float)$mult;
      if ($min < 0) $min = 0;
      if ($mu < 1.0) $mu = 1.0;
      if ($mu > 3.0) $mu = 3.0;
      $parsed[] = ['min'=>$min,'mult'=>$mu];
    }
    if (!empty($parsed)) {
      usort($parsed, fn($a,$b)=>($a['min']<=>$b['min']));
      $levels = [];
      $i = 0;
      foreach ($parsed as $p) {
        $pct = (int)round(($p['mult'] - 1.0) * 100);
        $levels[] = ['id'=>'lvl'.$i,'name'=>'Level '.($i),'min'=>(int)$p['min'],'pct'=>$pct,'mult'=>(float)$p['mult']];
        $i++;
      }
      // ensure a 0 baseline exists
      if ((int)($levels[0]['min'] ?? 0) !== 0) {
        array_unshift($levels, ['id'=>'lvl0','name'=>'Starter','min'=>0,'pct'=>0,'mult'=>1.00]);
      }
    }
  }

  $cur = $levels[0];
  $next = null;
  foreach ($levels as $lvl) {
    if ($qualified >= (int)$lvl['min']) $cur = $lvl;
  }
  foreach ($levels as $lvl) {
    if ($qualified < (int)$lvl['min']) { $next = $lvl; break; }
  }

  return [
    'ok'=>true,
    'qualified'=>$qualified,
    'current'=>$cur,
    'next'=>$next,
    'base_pct'=>vx_ref_base_points_pct(),
    'effective_pct'=>vx_ref_base_points_pct() * (float)$cur['mult'],
  ];
}

/**
 * Multiplier for referral points.
 */
function vx_ref_points_boost_mult($db, int $uid): float {
  $s = vx_ref_boost_status($db, $uid);
  $m = (float)($s['current']['mult'] ?? 1.0);
  if ($m < 1.0) $m = 1.0;
  if ($m > 3.0) $m = 3.0;
  return $m;
}
