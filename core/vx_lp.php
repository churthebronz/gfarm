<?php
// File: /core/vx_lp.php
// GreenFarm — LP (Legacy Points)
//
// LP rules (as per spec):
// - LP does NOT exist at activation.
// - LP only accrues once a Guardian has crossbreeded at least once (crossbreed_level >= 1).
// - LP is intended to be rarer than VP and scales more aggressively.
//
// Storage strategy (fail-soft):
// - Prefer db_users.lp_total + db_users.lp_spendable if present.
// - Otherwise fall back to user_meta keys.
//
// History strategy (optional):
// - If vx_lp_ledger exists we log accrual & adjustments.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/schema_helpers.php';

function vx_lp_columns_exist($db): bool {
  try {
    if (!function_exists('vx_column_exists')) return false;
    return vx_column_exists($db, 'db_users', 'lp_total') && vx_column_exists($db, 'db_users', 'lp_spendable');
  } catch (Throwable $e) {
    return false;
  }
}

function vx_lp_get($db, int $uid): array {
  if ($uid <= 0) return ['total'=>0,'spendable'=>0];

  if (vx_lp_columns_exist($db)) {
    try {
      $r = $db->query('SELECT lp_total, lp_spendable FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
      return [
        'total' => (int)($r['lp_total'] ?? 0),
        'spendable' => (int)($r['lp_spendable'] ?? 0),
      ];
    } catch (Throwable $e) {
      return ['total'=>0,'spendable'=>0];
    }
  }

  // meta fallback
  try {
    $t = (int)vx_meta_get($db, $uid, 'lp_total', '0');
    $s = (int)vx_meta_get($db, $uid, 'lp_spendable', '0');
    return ['total'=>$t,'spendable'=>$s];
  } catch (Throwable $e) {
    return ['total'=>0,'spendable'=>0];
  }
}

/**
 * Add (or subtract) LP.
 * - delta can be positive or negative.
 * - spendable never goes below 0.
 * - total never goes below 0.
 */
function vx_lp_add($db, int $uid, int $delta, string $ctx='lp', array $meta=[]): bool {
  if ($uid <= 0 || !$db || $delta === 0) return false;
  $now = time();
  $ctx = trim($ctx) !== '' ? trim($ctx) : 'lp';

  $ok = false;
  if (vx_lp_columns_exist($db)) {
    try {
      if ($delta > 0) {
        $db->query('UPDATE db_users SET lp_total = lp_total + ?, lp_spendable = lp_spendable + ? WHERE id=? LIMIT 1', $delta, $delta, $uid);
      } else {
        $db->query(
          'UPDATE db_users SET lp_total = GREATEST(0, lp_total + ?), lp_spendable = GREATEST(0, lp_spendable + ?) WHERE id=? LIMIT 1',
          $delta, $delta, $uid
        );
      }
      $ok = true;
    } catch (Throwable $e) {
      $ok = false;
    }
  } else {
    // meta fallback
    try {
      $cur = vx_lp_get($db, $uid);
      $t = max(0, (int)$cur['total'] + $delta);
      $s = max(0, (int)$cur['spendable'] + $delta);
      $ok = vx_meta_set($db, $uid, 'lp_total', (string)$t) && vx_meta_set($db, $uid, 'lp_spendable', (string)$s);
    } catch (Throwable $e) {
      $ok = false;
    }
  }

  // Optional ledger
  try {
    if ($ok && function_exists('vx_table_exists') && vx_table_exists($db, 'vx_lp_ledger')) {
      $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;
      $db->query('INSERT INTO vx_lp_ledger (uid, delta, ctx, meta_json, created_at) VALUES (?, ?, ?, ?, ?)', $uid, $delta, $ctx, $metaJson, $now);
    }
  } catch (Throwable $e) {}

  // Keep per-season LP totals (for seasonal ranking)
  try {
    $seasonId = (int)($meta['season_id'] ?? 0);
    if ($seasonId <= 0) {
      require_once __DIR__ . '/seasons.php';
      $sx = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
      $seasonId = (int)($sx['id'] ?? 0);
    }
    if ($ok && $seasonId > 0 && $delta !== 0) {
      require_once __DIR__ . '/vx_season_points.php';
      vx_season_points_add($db, $uid, $seasonId, 0, (int)$delta);
    }
  } catch (Throwable $e) {}

  return $ok;
}
