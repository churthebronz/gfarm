<?php
require_once __DIR__ . '/_bootstrap.php';

// $uid and $db are available from bootstrap

$now = time();

// Yield state
$st = ['pending'=>0.0,'per_second'=>0.0];
try { $st = vx_earnings_touch($db, $uid); } catch (Throwable $e) {}
$perSecond = (float)($st['per_second'] ?? 0.0);
$yieldPerDay = $perSecond * 86400.0;

// Next maturity (soonest active vault end)
$nextMaturity = 0;
try {
  $row = $db->query(
    "SELECT MIN(`end`) AS n FROM db_store WHERE uid=? AND status=1 AND `end` > ?",
    [$uid, $now]
  )->fetchArray();
  $nextMaturity = (int)($row['n'] ?? 0);
} catch (Throwable $e) {}

// VP/LP per day estimate from active guardian chains
$vpDay = 0.0;
$lpDay = 0.0;
try {
  if (function_exists('vx_guardians_settings')) {
    $s = vx_guardians_settings();
    $rarMults = (array)($s['rarity_mult'] ?? []);
    $vpMults = (array)($s['vp_crossbreed_mult'] ?? []);
    $lpMults = (array)($s['lp_crossbreed_mult'] ?? []);

    $q = $db->query(
      "SELECT id, tarif, kind, title_override, vp_per_day_override, lp_per_day_override, rarity, crossbreed_level, status\n"
      ."FROM vx_guardian_chains WHERE uid=? AND status='active'",
      [$uid]
    );
    if ($q) {
      while ($c = $q->fetchArray()) {
        $tarif = (int)($c['tarif'] ?? 0);
        $kind  = (string)($c['kind'] ?? '');
        $rarity = (string)($c['rarity'] ?? 'common');
        $lvl = (int)($c['crossbreed_level'] ?? 0);
        if ($lvl < 0) $lvl = 0;
        if ($lvl > 5) $lvl = 5;

        // Base per-day points from vault definition (or fallback)
        $def = function_exists('vx_vault_definition') ? vx_vault_definition($db, $tarif) : ['vp_per_day'=>0,'lp_per_day'=>0,'rarity'=>$rarity];
        $vpBase = (int)($def['vp_per_day'] ?? 0);
        $lpBase = (int)($def['lp_per_day'] ?? 0);

        // Founder guardians: LP is gated until crossbreed >=1 (matches your design notes)
        $vpOv = (int)($c['vp_per_day_override'] ?? 0);
        $lpOv = (int)($c['lp_per_day_override'] ?? 0);
        if ($vpOv > 0) $vpBase = $vpOv;
        if ($lpOv > 0) $lpBase = $lpOv;

        $rarMult = (float)($rarMults[$rarity] ?? 1.0);
        $vpMult = (float)($vpMults[$lvl] ?? end($vpMults));
        $lpMult = (float)($lpMults[$lvl] ?? end($lpMults));
        if ($vpMult <= 0) $vpMult = 1.0;
        if ($lpMult <= 0) $lpMult = 1.0;

        $vpDay += max(0.0, (float)$vpBase) * $rarMult * $vpMult;

        // LP unlock at crossbreed >= 1
        if ($lvl >= 1) {
          $lpDay += max(0.0, (float)$lpBase) * $rarMult * $lpMult;
        }
      }
    }
  }
} catch (Throwable $e) {}

vx_json_out([
  'ok' => true,
  'yield' => [
    'pending' => (float)($st['pending'] ?? 0.0),
    'per_second' => $perSecond,
    'per_day_est' => $yieldPerDay,
  ],
  'points' => [
    'vp_per_day_est' => $vpDay,
    'lp_per_day_est' => $lpDay,
  ],
  'next_maturity_at' => $nextMaturity,
  'now' => $now,
]);