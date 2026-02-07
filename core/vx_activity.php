<?php
// core/vx_activity.php
// GreenFarm — lightweight activity log (for Harvest Rush + UI counters)

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/schema_helpers.php';

function vx_activity_schema_ensure($db): void {
  if (!$db) return;
  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_activity_log (\n"
      ."  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
      ."  uid INT NOT NULL,\n"
      ."  type VARCHAR(32) NOT NULL,\n"
      ."  amount DECIMAL(18,8) NOT NULL DEFAULT 0,\n"
      ."  meta_json MEDIUMTEXT NULL,\n"
      ."  created_at INT NOT NULL,\n"
      ."  PRIMARY KEY (id),\n"
      ."  KEY ix_type_time (type, created_at),\n"
      ."  KEY ix_uid_time (uid, created_at)\n"
      .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // fail-soft
  }
}

function vx_activity_log($db, int $uid, string $type, float $amount = 0.0, array $meta = []): void {
  if ($uid <= 0 || !$db) return;
  $type = trim($type);
  if ($type === '') $type = 'event';
  $now = time();
  try { vx_activity_schema_ensure($db); } catch (Throwable $e) {}
  try {
    $db->query(
      "INSERT INTO vx_activity_log (uid, type, amount, meta_json, created_at) VALUES (?, ?, ?, ?, ?)",
      $uid,
      $type,
      $amount,
      json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
      $now
    );
  } catch (Throwable $e) {
    // ignore
  }
}

function vx_activity_count_since($db, string $type, int $sinceTs): int {
  if (!$db) return 0;
  $type = trim($type);
  if ($type === '') return 0;
  try {
    vx_activity_schema_ensure($db);
    $r = $db->query(
      "SELECT COUNT(*) AS c FROM vx_activity_log WHERE type=? AND created_at >= ?",
      $type,
      $sinceTs
    )->fetchArray();
    return (int)($r['c'] ?? 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function vx_activity_sum_since($db, string $type, int $sinceTs): float {
  if (!$db) return 0.0;
  $type = trim($type);
  if ($type === '') return 0.0;
  try {
    vx_activity_schema_ensure($db);
    $r = $db->query(
      "SELECT COALESCE(SUM(amount),0) AS s FROM vx_activity_log WHERE type=? AND created_at >= ?",
      $type,
      $sinceTs
    )->fetchArray();
    return (float)($r['s'] ?? 0);
  } catch (Throwable $e) {
    return 0.0;
  }
}
