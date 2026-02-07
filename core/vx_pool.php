<?php
// core/vx_pool.php
// Community Pool (Season Bonus Pool) — Points-based hype engine.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/vx_points.php';

function vx_pool_table_exists($db): bool {
  try { if (function_exists('vx_table_exists')) return vx_table_exists($db, 'community_pool'); } catch (Throwable $e) {}
  return false;
}

function vx_pool_get_current($db, int $seasonId = 0): array {
  if ($db && vx_pool_table_exists($db)) {
    try {
      if ($seasonId > 0) {
        $q = $db->query("SELECT season_id, points_total, updated_at FROM community_pool WHERE season_id=? LIMIT 1", $seasonId);
        $r = $q ? ($q->fetchArray() ?: []) : [];
        if ($r) return ['ok'=>true,'season_id'=>(int)$r['season_id'],'points_total'=>(int)$r['points_total']];
      }
      // latest
      $q = $db->query("SELECT season_id, points_total FROM community_pool ORDER BY season_id DESC LIMIT 1");
      $r = $q ? ($q->fetchArray() ?: []) : [];
      if ($r) return ['ok'=>true,'season_id'=>(int)$r['season_id'],'points_total'=>(int)$r['points_total']];
    } catch (Throwable $e) {}
  }
  // fallback
  $sid = $seasonId > 0 ? $seasonId : (int)vx_meta_get($db, 0, 'pool_season_id', '0');
  $pts = (int)vx_meta_get($db, 0, 'pool_points_total', '0');
  return ['ok'=>true,'season_id'=>$sid,'points_total'=>$pts];
}

function vx_pool_add($db, int $seasonId, int $points, string $ctx, array $meta=[]): void {
  if ($points <= 0) return;
  if ($seasonId <= 0) $seasonId = 0;
  $now = time();
  $cur = 0;
  try { $cur = (int)(vx_pool_get_current($db, $seasonId)['points_total'] ?? 0); } catch (Throwable $e) { $cur = 0; }

  if ($db && vx_pool_table_exists($db) && function_exists('vx_table_exists') && vx_table_exists($db, 'pool_ledger')) {
    try {
      $db->query(
        "INSERT INTO pool_ledger (season_id, points, ctx, meta_json, created_at) VALUES (?, ?, ?, ?, ?)",
        $seasonId, $points, $ctx, json_encode($meta, JSON_UNESCAPED_SLASHES), $now
      );
    } catch (Throwable $e) {}

    try {
      $db->query(
        "INSERT INTO community_pool (season_id, points_total, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE points_total = points_total + VALUES(points_total), updated_at=CURRENT_TIMESTAMP",
        $seasonId, $points
      );
    } catch (Throwable $e) {}
    // milestones
    try { vx_pool_check_milestones($db, $seasonId, $cur + $points); } catch (Throwable $e) {}
    return;
  }

  // fallback to meta (global keys)
  try {
    $cur = (int)vx_meta_get($db, 0, 'pool_points_total', '0');
    vx_meta_set($db, 0, 'pool_points_total', (string)($cur + $points));
    vx_meta_set($db, 0, 'pool_season_id', (string)$seasonId);
  } catch (Throwable $e) {}
  try { vx_pool_check_milestones($db, $seasonId, $cur + $points); } catch (Throwable $e) {}
}


function vx_pool_check_milestones($db, int $seasonId, int $newTotal): void {
  // milestones are Points totals; award small airdrops to active users (7d) once per milestone.
  $milestones = [100000, 250000, 500000];
  foreach ($milestones as $m) {
    if ($newTotal < $m) continue;
    $mk = 'pool_milestone_'.$seasonId.'_'.$m;
    if ((string)vx_meta_get($db, 0, $mk, '0') === '1') continue;

    // Mark first to prevent double-run in concurrent calls
    vx_meta_set($db, 0, $mk, '1');

    // Airdrop: fixed per-user amount; capped user count to prevent heavy load.
    $perUser = ($m === 100000) ? 25 : (($m === 250000) ? 35 : 50);
    $capUsers = 2000;
    $since = time() - 7*86400;

    $uids = [];
    try {
      if (function_exists('vx_table_exists') && vx_table_exists($db, 'events_log')) {
        $q = $db->query("SELECT DISTINCT user_id AS uid FROM events_log WHERE created_at >= ? ORDER BY uid DESC LIMIT ".$capUsers, $since);
        while ($q && ($r = $q->fetchArray(SQLITE3_ASSOC))) {
          $u = (int)($r['uid'] ?? 0);
          if ($u > 0) $uids[] = $u;
        }
      }
    } catch (Throwable $e) {}

    if (!$uids) {
      // fallback: last active vault buyers
      try {
        $q = $db->query("SELECT DISTINCT uid FROM db_store WHERE add >= ? ORDER BY id DESC LIMIT ".$capUsers, $since);
        while ($q && ($r = $q->fetchArray(SQLITE3_ASSOC))) {
          $u = (int)($r['uid'] ?? 0);
          if ($u > 0) $uids[] = $u;
        }
      } catch (Throwable $e) {}
    }

    // award
    $awarded = 0;
    foreach ($uids as $u) {
      if ($u <= 0) continue;
      if (vx_points_add($db, $u, $perUser, 'Community Pool Milestone', ['season'=>$seasonId,'milestone'=>$m])) {
        $awarded++;
        // soft notify
        vx_notify_once($db, $u, 'pool_airdrop_'.$seasonId.'_'.$m, 'pool_airdrop', 'success',
          'Community Pool milestone reached! You received +'.$perUser.' Points.', ['season'=>$seasonId,'m'=>$m,'pts'=>$perUser]);
      }
    }

    // log in events_log if exists
    try {
      if (function_exists('vx_table_exists') && vx_table_exists($db, 'events_log')) {
        $db->query('INSERT INTO events_log (user_id, event_type, ctx, ip, ua, created_at) VALUES (?, ?, ?, ?, ?, ?)',
          0, 'pool_milestone', 'season_'.$seasonId.'_m'.$m,
          (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
          'system', time()
        );
      }
    } catch (Throwable $e) {}

    // Notify season-wide summary is handled via UI (pool status endpoint)
  }
}
