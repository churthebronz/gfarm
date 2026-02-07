<?php
declare(strict_types=1);
if (!defined('FastCore')) define('FastCore', true);

/**
 * Boost catalog (edit titles/costs freely)
 */
function vx_boost_catalog(): array {
  return [
    'rush_2x' => [
      'key' => 'rush_2x',
      'title' => 'Rush 2×',
      'desc' => 'Doubles Points from vault activations for 2 hours.',
      'cost' => 250,
      'duration' => 7200,
      'type' => 'timed',
    ],
    'ref_plus' => [
      'key' => 'ref_plus',
      'title' => 'Referral +25%',
      'desc' => 'Boosts your referral rewards for 24 hours.',
      'cost' => 400,
      'duration' => 86400,
      'type' => 'timed',
    ],
    'streak_shield' => [
      'key' => 'streak_shield',
      'title' => 'Streak Shield',
      'desc' => 'Prevents losing your streak once (expires in 7 days).',
      'cost' => 300,
      'duration' => 604800,
      'type' => 'consumable',
    ],
    'cooldown_skip' => [
      'key' => 'cooldown_skip',
      'title' => 'Cooldown Skip',
      'desc' => 'Skip one cooldown (consumable effect; expires in 6 hours).',
      'cost' => 200,
      'duration' => 21600,
      'type' => 'consumable',
    ],
  ];
}

/**
 * Points getter (supports db_users columns OR db_user_points table)
 */
function vx_points_get($db, int $uid): array {
  $spendable = 0;
  $lifetime = 0;

  try {
    $hasPtsUsers = function_exists('vx_column_exists')
      && vx_column_exists($db, 'db_users', 'points_spendable')
      && vx_column_exists($db, 'db_users', 'points_total');

    if ($hasPtsUsers) {
      $q = $db->query("SELECT points_spendable, points_total FROM db_users WHERE id=? LIMIT 1", $uid);
      $r = $q ? ($q->fetchArray() ?: []) : [];
      $spendable = (int)($r['points_spendable'] ?? 0);
      $lifetime  = (int)($r['points_total'] ?? 0);
      return [$spendable, $lifetime];
    }
  } catch (Throwable $e) { /* ignore */ }

  try {
    // fallback table
    if (function_exists('vx_table_exists') && !vx_table_exists($db, 'db_user_points')) {
      return [0, 0];
    }
    $q = $db->query("SELECT points, lifetime_points FROM db_user_points WHERE uid=? LIMIT 1", $uid);
    $r = $q ? ($q->fetchArray() ?: []) : [];
    $spendable = (int)($r['points'] ?? 0);
    $lifetime  = (int)($r['lifetime_points'] ?? 0);
  } catch (Throwable $e) { /* ignore */ }

  return [$spendable, $lifetime];
}

/**
 * Points spender (deduct only; does not touch lifetime)
 */
function vx_points_deduct($db, int $uid, int $cost): bool {
  if ($cost <= 0) return true;

  // db_users columns version
  try {
    $hasPtsUsers = function_exists('vx_column_exists')
      && vx_column_exists($db, 'db_users', 'points_spendable');

    if ($hasPtsUsers) {
      $q = $db->query("SELECT points_spendable FROM db_users WHERE id=? LIMIT 1", $uid);
      $r = $q ? ($q->fetchArray() ?: []) : [];
      $cur = (int)($r['points_spendable'] ?? 0);
      if ($cur < $cost) return false;
      $db->query("UPDATE db_users SET points_spendable=? WHERE id=? LIMIT 1", $cur - $cost, $uid);
      return true;
    }
  } catch (Throwable $e) { /* ignore */ }

  // db_user_points version
  try {
    if (function_exists('vx_table_exists') && !vx_table_exists($db, 'db_user_points')) return false;

    $q = $db->query("SELECT points FROM db_user_points WHERE uid=? LIMIT 1", $uid);
    $r = $q ? ($q->fetchArray() ?: []) : [];
    $cur = (int)($r['points'] ?? 0);
    if ($cur < $cost) return false;

    $db->query("UPDATE db_user_points SET points=? WHERE uid=? LIMIT 1", $cur - $cost, $uid);
    return true;
  } catch (Throwable $e) { /* ignore */ }

  return false;
}

/**
 * Optional: ledger insert (safe if table missing)
 */
function vx_points_ledger_add($db, int $uid, int $delta, string $ctx): void {
  try {
    if (function_exists('vx_table_exists') && !vx_table_exists($db, 'db_points_ledger')) return;
    $ctx = substr($ctx, 0, 32);
    $db->query(
      "INSERT INTO db_points_ledger (uid, delta, ctx, created_at) VALUES (?,?,?,?)",
      $uid, $delta, $ctx, time()
    );
  } catch (Throwable $e) { /* ignore */ }
}

/**
 * Return boosts + points snapshot for UI
 */
function vx_boosts_status($db, int $uid): array {
  [$spendable, $lifetime] = vx_points_get($db, $uid);

  $cat = vx_boost_catalog();
  $now = time();

  // Load active_until per boost
  $active = [];
  try {
    if (function_exists('vx_table_exists') && !vx_table_exists($db, 'vx_user_boosts')) {
      // no table -> treat all as inactive
      $active = [];
    } else {
      $q = $db->query("SELECT boost_key, active_until FROM vx_user_boosts WHERE uid=?", $uid);
      if ($q) {
        while ($r = $q->fetchArray()) {
          $k = (string)($r['boost_key'] ?? '');
          if ($k !== '') $active[$k] = (int)($r['active_until'] ?? 0);
        }
      }
    }
  } catch (Throwable $e) { /* ignore */ }

  $out = [];
  foreach ($cat as $k => $b) {
    $until = (int)($active[$k] ?? 0);
    $isActive = $until > $now;

    $type = (string)($b['type'] ?? 'timed');
    $usesLeft = 0;
    if ($type === 'consumable') {
      $usesLeft = $isActive ? 1 : 0;
    }

    $out[] = [
      'key' => $k,
      'title' => (string)($b['title'] ?? $k),
      'desc' => (string)($b['desc'] ?? ''),
      'cost' => (int)($b['cost'] ?? 0),
      'duration' => (int)($b['duration'] ?? 0),
      'type' => $type,
      'active' => $isActive,
      'until' => $isActive ? $until : 0,
      'uses_left' => $usesLeft,
    ];
  }

  return [
    'ok' => true,
    'spendable' => (int)$spendable,
    'lifetime' => (int)$lifetime,
    'boosts' => $out,
    'server_time' => $now,
  ];
}

/**
 * Buy a boost (deduct points, then activate)
 */
function vx_boost_buy($db, int $uid, string $key): array {
  $key = trim($key);
  if ($key === '') return ['ok' => false, 'msg' => 'Missing boost key'];

  $cat = vx_boost_catalog();
  if (!isset($cat[$key])) return ['ok' => false, 'msg' => 'Unknown boost'];

  $b = $cat[$key];
  $cost = (int)($b['cost'] ?? 0);
  $duration = (int)($b['duration'] ?? 0);
  $now = time();
  $until = $duration > 0 ? ($now + $duration) : 0;

  // Already active? don't charge again
  try {
    if (!function_exists('vx_table_exists') || vx_table_exists($db, 'vx_user_boosts')) {
      $q = $db->query("SELECT active_until FROM vx_user_boosts WHERE uid=? AND boost_key=? LIMIT 1", $uid, $key);
      $r = $q ? ($q->fetchArray() ?: []) : [];
      $curUntil = (int)($r['active_until'] ?? 0);
      if ($curUntil > $now) {
        return ['ok' => true, 'msg' => 'Already active', 'until' => $curUntil];
      }
    }
  } catch (Throwable $e) { /* ignore */ }

  // Deduct points
  if ($cost > 0) {
    $ok = vx_points_deduct($db, $uid, $cost);
    if (!$ok) return ['ok' => false, 'msg' => 'Not enough points'];
    vx_points_ledger_add($db, $uid, -$cost, 'boost_' . $key);
  }

  // Activate boost row
  try {
    // Update if exists
    $q = $db->query("SELECT id FROM vx_user_boosts WHERE uid=? AND boost_key=? LIMIT 1", $uid, $key);
    $r = $q ? ($q->fetchArray() ?: []) : [];
    $id = (int)($r['id'] ?? 0);

    if ($id > 0) {
      $db->query("UPDATE vx_user_boosts SET active_until=?, updated_at=? WHERE id=? LIMIT 1", $until, $now, $id);
    } else {
      $db->query(
        "INSERT INTO vx_user_boosts (uid, boost_key, active_until, created_at, updated_at) VALUES (?,?,?,?,?)",
        $uid, $key, $until, $now, $now
      );
    }
  } catch (Throwable $e) {
    return ['ok' => false, 'msg' => 'Server error', 'error' => $e->getMessage()];
  }

  return ['ok' => true, 'msg' => 'Boost activated', 'until' => $until];
}
