<?php
// core/vx_cache.php
if (!defined('FastCore')) define('FastCore', true);

function vx_cache_get($db, string $scope, string $bucket): ?array {
  try {
    $q = $db->query("SELECT payload, expires_at FROM leaderboard_cache WHERE scope=? AND bucket=? LIMIT 1", $scope, $bucket);
    $r = $q ? ($q->fetchArray() ?: []) : [];
    if (empty($r['payload'])) return null;
    if (!empty($r['expires_at'])) {
      $exp = strtotime((string)$r['expires_at']);
      if ($exp > 0 && $exp < time()) return null;
    }
    $data = json_decode((string)$r['payload'], true);
    return is_array($data) ? $data : null;
  } catch (Throwable $e) { return null; }
}

function vx_cache_set($db, string $scope, string $bucket, array $payload, int $ttlSeconds=60): void {
  try {
    $expires = gmdate('Y-m-d H:i:s', time() + max(10, $ttlSeconds));
    $db->query("INSERT INTO leaderboard_cache (scope, bucket, payload, generated_at, expires_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)
                ON DUPLICATE KEY UPDATE payload=VALUES(payload), generated_at=CURRENT_TIMESTAMP, expires_at=VALUES(expires_at)",
                $scope, $bucket, json_encode($payload, JSON_UNESCAPED_SLASHES), $expires);
  } catch (Throwable $e) {}
}
