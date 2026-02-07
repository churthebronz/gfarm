<?php
// core/vx_season_points.php
// GreenFarm — per-season VP/LP totals (for seasonal ranking + dashboard pressure)

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/schema_helpers.php';

function vx_season_points_schema_ensure($db): void {
  if (!$db) return;
  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_season_points (
      uid INT NOT NULL,
      season_id INT NOT NULL,
      vp_total BIGINT NOT NULL DEFAULT 0,
      lp_total BIGINT NOT NULL DEFAULT 0,
      updated_at INT NOT NULL DEFAULT 0,
      PRIMARY KEY (uid, season_id),
      KEY ix_season_vp (season_id, vp_total),
      KEY ix_season_lp (season_id, lp_total)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}
}

function vx_season_points_add($db, int $uid, int $seasonId, int $vpDelta, int $lpDelta): void {
  if ($uid <= 0 || $seasonId <= 0 || !$db) return;
  if ($vpDelta === 0 && $lpDelta === 0) return;
  $now = time();
  try { vx_season_points_schema_ensure($db); } catch (Throwable $e) {}
  try {
    $db->query(
      'INSERT INTO vx_season_points (uid, season_id, vp_total, lp_total, updated_at) VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE vp_total = vp_total + VALUES(vp_total), lp_total = lp_total + VALUES(lp_total), updated_at = VALUES(updated_at)',
      $uid, $seasonId, $vpDelta, $lpDelta, $now
    );
  } catch (Throwable $e) {}
}

function vx_season_points_get($db, int $uid, int $seasonId): array {
  if ($uid<=0||$seasonId<=0||!$db) return ['vp'=>0,'lp'=>0];
  try { vx_season_points_schema_ensure($db); } catch (Throwable $e) {}
  try {
    $r = $db->query('SELECT vp_total, lp_total FROM vx_season_points WHERE uid=? AND season_id=? LIMIT 1', $uid, $seasonId)->fetchArray();
    return ['vp'=>(int)($r['vp_total'] ?? 0), 'lp'=>(int)($r['lp_total'] ?? 0)];
  } catch (Throwable $e) {
    return ['vp'=>0,'lp'=>0];
  }
}

function vx_season_points_score(int $vp, int $lp, float $lpWeight = 5.0): float {
  // LP is rarer / prestige, so it weighs more in seasonal ranking.
  if ($lpWeight < 0) $lpWeight = 0;
  return (float)$vp + ((float)$lp * $lpWeight);
}

function vx_season_points_top($db, int $seasonId, int $limit = 50, float $lpWeight = 5.0): array {
  if (!$db || $seasonId <= 0) return [];
  vx_season_points_schema_ensure($db);
  $limit = max(1, min(200, $limit));
  $out = [];
  try {
    $q = $db->query(
      "SELECT sp.uid, sp.vp_total, sp.lp_total, u.login, u.tg_username, u.tg_name
       FROM vx_season_points sp
       JOIN db_users u ON u.id = sp.uid
       WHERE sp.season_id = ?
       ORDER BY (sp.vp_total + sp.lp_total * ?) DESC, sp.vp_total DESC
       LIMIT {$limit}",
      $seasonId, $lpWeight
    );
    if ($q) {
      while ($r = $q->fetchArray()) {
        $vp = (int)($r['vp_total'] ?? 0);
        $lp = (int)($r['lp_total'] ?? 0);
        $r['score'] = vx_season_points_score($vp, $lp, $lpWeight);
        $out[] = $r;
      }
    }
  } catch (Throwable $e) {}
  return $out;
}

function vx_season_points_rank($db, int $seasonId, int $uid, float $lpWeight = 5.0): array {
  if (!$db || $seasonId <= 0 || $uid <= 0) return ['rank'=>null,'vp'=>0,'lp'=>0,'score'=>0];
  vx_season_points_schema_ensure($db);
  $vp = 0; $lp = 0;
  try {
    $me = $db->query('SELECT vp_total, lp_total FROM vx_season_points WHERE season_id=? AND uid=? LIMIT 1', $seasonId, $uid)->fetchArray();
    $vp = (int)($me['vp_total'] ?? 0);
    $lp = (int)($me['lp_total'] ?? 0);
  } catch (Throwable $e) {}
  $score = vx_season_points_score($vp, $lp, $lpWeight);
  $rank = null;
  try {
    $r = $db->query(
      'SELECT 1 + COUNT(*) AS r
         FROM vx_season_points
        WHERE season_id=? AND (vp_total + lp_total * ?) > ?',
      $seasonId, $lpWeight, $score
    )->fetchArray();
    $rank = isset($r['r']) ? (int)$r['r'] : null;
  } catch (Throwable $e) {}
  return ['rank'=>$rank,'vp'=>$vp,'lp'=>$lp,'score'=>$score];
}
