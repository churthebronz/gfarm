<?php
declare(strict_types=1);

// core/vx_metrics.php
// Minimal user metrics storage (no cron).

function vx_metrics_schema_ensure($db): void {
  if (!$db) return;
  $db->query("CREATE TABLE IF NOT EXISTS vx_user_metrics (\n"
    ."  uid INT NOT NULL,\n"
    ."  season_id INT NOT NULL DEFAULT 0,\n"
    ."  last_rank INT NOT NULL DEFAULT 0,\n"
    ."  last_rank_at INT NOT NULL DEFAULT 0,\n"
    ."  updated_at INT NOT NULL DEFAULT 0,\n"
    ."  PRIMARY KEY(uid, season_id)\n"
    .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function vx_metrics_get_rank($db, int $uid, int $seasonId): array {
  if (!$db || $uid<=0) return ['last_rank'=>0,'last_rank_at'=>0];
  try {
    vx_metrics_schema_ensure($db);
    $r = $db->query('SELECT last_rank, last_rank_at FROM vx_user_metrics WHERE uid=? AND season_id=? LIMIT 1', $uid, $seasonId);
    $m = $r ? ($r->fetchArray() ?: []) : [];
    return ['last_rank'=>(int)($m['last_rank'] ?? 0), 'last_rank_at'=>(int)($m['last_rank_at'] ?? 0)];
  } catch (Throwable $e) {
    return ['last_rank'=>0,'last_rank_at'=>0];
  }
}

function vx_metrics_set_rank($db, int $uid, int $seasonId, int $rank, int $now): void {
  if (!$db || $uid<=0) return;
  try {
    vx_metrics_schema_ensure($db);
    $db->query(
      'INSERT INTO vx_user_metrics (uid, season_id, last_rank, last_rank_at, updated_at) VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE last_rank=VALUES(last_rank), last_rank_at=VALUES(last_rank_at), updated_at=VALUES(updated_at)',
      $uid, $seasonId, $rank, $now, $now
    );
  } catch (Throwable $e) {}
}
