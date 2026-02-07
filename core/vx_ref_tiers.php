<?php
// core/vx_ref_tiers.php
// Referral ladder tiers (Bronze->Diamond) based on qualified referrals.
// Drives better share copy + badges; rewards remain Points-only.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_virality.php';
require_once __DIR__ . '/vx_retention.php';

function vx_ref_tiers_def(): array {
  return [
    ['id'=>'bronze','name'=>'Bronze','min'=>1,'color'=>'rgba(255,154,46,.18)','ring'=>'#ff9a2e'],
    ['id'=>'silver','name'=>'Silver','min'=>5,'color'=>'rgba(180,200,255,.14)','ring'=>'#b8c8ff'],
    ['id'=>'gold','name'=>'Gold','min'=>25,'color'=>'rgba(255,210,90,.16)','ring'=>'#ffd25a'],
    ['id'=>'diamond','name'=>'Diamond','min'=>100,'color'=>'rgba(160,240,255,.14)','ring'=>'#a0f0ff'],
  ];
}

function vx_ref_tier_of(int $qualifiedRefs): array {
  $tiers = vx_ref_tiers_def();
  $cur = ['id'=>'starter','name'=>'Starter','min'=>0,'color'=>'rgba(255,255,255,.06)','ring'=>'rgba(255,255,255,.18)'];
  foreach ($tiers as $t) {
    if ($qualifiedRefs >= (int)$t['min']) $cur = $t;
  }
  $next = null;
  foreach ($tiers as $t) {
    if ($qualifiedRefs < (int)$t['min']) { $next = $t; break; }
  }
  return ['current'=>$cur,'next'=>$next,'qualified'=>$qualifiedRefs];
}

function vx_ref_tier_status($db, int $uid): array {
  $q = vx_qualified_ref_count($db, $uid);
  $s = vx_ref_tier_of($q);

  // persist + notify on upgrade
  $prev = (string)vx_meta_get($db, $uid, 'ref_tier', 'starter');
  if ($s['current']['id'] !== $prev) {
    vx_meta_set($db, $uid, 'ref_tier', (string)$s['current']['id']);
    // only notify on upward move (ignore downgrades if rules change)
    if ($prev === 'starter' || $s['current']['min'] > 0) {
      vx_notify_once($db, $uid, 'ref_tier_'.$s['current']['id'].'_'.gmdate('Y'), 'ref_tier', 'success',
        'Tier upgrade: '.$s['current']['name'].'!', ['tier'=>$s['current']['id'],'q'=>$q]);
    }
  }

  // share templates (kept clean, no dev text)
  $templates = [
    'starter' => "Join GreenFarm and start earning Points.\n\nUse my link:",
    'bronze'  => "I’m earning Points on GreenFarm.\nJoin with my link and start your first vault:",
    'silver'  => "GreenFarm is live — earn Points daily.\nJoin with my link:",
    'gold'    => "I’m climbing the ranks on GreenFarm.\nJoin with my link and let’s compete:",
    'diamond' => "GreenFarm is intense right now.\nJoin with my link — earn Points and push the leaderboard:",
  ];

  $tierId = $s['current']['id'];
  $msg = $templates[$tierId] ?? $templates['starter'];

  return [
    'ok'=>true,
    'qualified'=>$q,
    'tier'=>$s['current'],
    'next'=>$s['next'],
    'share_message'=>$msg,
  ];
}
