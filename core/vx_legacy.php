<?php
// core/vx_legacy.php
// Legacy VP (Harvest Points) system.
//
// Design:
// - Legacy VP are ONLY earned by reactivating a Mutant Crop AFTER a full term ends.
// - If the schema has db_users.legacy_vp_total + legacy_vp_spendable, we use them.
// - Otherwise we fall back to user_meta keys.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/schema_helpers.php';
require_once __DIR__ . '/vx_retention.php';

function vx_legacy_columns_exist($db): bool {
  try {
    if (!function_exists('vx_column_exists')) return false;
    return vx_column_exists($db, 'db_users', 'legacy_vp_total') && vx_column_exists($db, 'db_users', 'legacy_vp_spendable');
  } catch (Throwable $e) { return false; }
}

function vx_legacy_get($db, int $uid): array {
  if ($uid <= 0) return ['total'=>0,'spendable'=>0];

  if (vx_legacy_columns_exist($db)) {
    try {
      $r = $db->query('SELECT legacy_vp_total, legacy_vp_spendable FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
      return [
        'total' => (int)($r['legacy_vp_total'] ?? 0),
        'spendable' => (int)($r['legacy_vp_spendable'] ?? 0),
      ];
    } catch (Throwable $e) {
      return ['total'=>0,'spendable'=>0];
    }
  }

  // meta fallback
  $t = (int)vx_meta_get($db, $uid, 'legacy_vp_total', '0');
  $s = (int)vx_meta_get($db, $uid, 'legacy_vp_spendable', '0');
  return ['total'=>$t,'spendable'=>$s];
}

function vx_legacy_add($db, int $uid, int $delta, string $ctx='legacy', array $meta=[]): bool {
  if ($uid <= 0 || $delta <= 0) return false;

  $ok = false;
  if (vx_legacy_columns_exist($db)) {
    try {
      $db->query('UPDATE db_users SET legacy_vp_total = legacy_vp_total + ?, legacy_vp_spendable = legacy_vp_spendable + ? WHERE id=? LIMIT 1', $delta, $delta, $uid);
      $ok = true;
    } catch (Throwable $e) { $ok = false; }
  } else {
    // meta fallback
    $cur = vx_legacy_get($db, $uid);
    $t = (int)$cur['total'] + $delta;
    $s = (int)$cur['spendable'] + $delta;
    $ok = vx_meta_set($db, $uid, 'legacy_vp_total', (string)$t) && vx_meta_set($db, $uid, 'legacy_vp_spendable', (string)$s);
  }

  // Optional: log to points ledger if it exists.
  try {
    if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_points_ledger')) {
      $metaJson = '';
      if (!empty($meta)) {
        $meta['ctx'] = $ctx;
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
      }
      if ($metaJson !== '' && function_exists('vx_column_exists') && vx_column_exists($db, 'db_points_ledger', 'meta_json')) {
        $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, meta_json, created_at) VALUES (?, ?, ?, ?, ?)', $uid, $delta, 'legacy_vp', $metaJson, time());
      } else {
        $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, created_at) VALUES (?, ?, ?, ?)', $uid, $delta, 'legacy_vp', time());
      }
    }
  } catch (Throwable $e) {}

  return $ok;
}

/**
 * Detect a completed guardian eligible for Legacy VP on reactivation.
 * Returns a row from db_store or null.
 */
function vx_legacy_find_eligible_completed($db, int $uid, int $tarifId): ?array {
  if ($uid <= 0 || $tarifId <= 0) return null;
  $now = time();
  try {
    // Find the most recent completed guardian for this plan.
    $q = $db->query(
      'SELECT id, uid, tarif, `add`, `end`, season_id, title, hashpower, speed, status
         FROM db_store
        WHERE uid=? AND tarif=? AND status=2 AND `end`>0 AND `end`<=?
        ORDER BY `end` DESC
        LIMIT 5',
      $uid, $tarifId, $now
    );
    if (!$q) return null;
    while ($r = $q->fetchArray()) {
      $sid = (int)($r['id'] ?? 0);
      if ($sid <= 0) continue;
      // Skip if already awarded
      $aw = (string)vx_meta_get($db, $uid, 'legacy_awarded_store_'.$sid, '0');
      if ($aw === '1') continue;
      return $r;
    }
  } catch (Throwable $e) {}
  return null;
}

function vx_legacy_mark_awarded($db, int $uid, int $storeId): void {
  if ($uid <= 0 || $storeId <= 0) return;
  try { vx_meta_set($db, $uid, 'legacy_awarded_store_'.$storeId, '1'); } catch (Throwable $e) {}
}

function vx_legacy_mark_collector($db, int $uid, int $storeId): void {
  if ($uid <= 0 || $storeId <= 0) return;
  try { vx_meta_set($db, $uid, 'collector_store_'.$storeId, '1'); } catch (Throwable $e) {}
}

function vx_legacy_is_collector($db, int $uid, int $storeId): bool {
  if ($uid <= 0 || $storeId <= 0) return false;
  return (string)vx_meta_get($db, $uid, 'collector_store_'.$storeId, '0') === '1';
}
