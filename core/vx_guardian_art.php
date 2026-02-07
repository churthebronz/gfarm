<?php
// File: /core/vx_guardian_art.php
// GreenFarm — Guardian evolution art (E0..E5)
// Stores per-tariff image keys for each evolution level.
// Image keys map to files in /img/items (png/webp/jpg).

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/schema_helpers.php';

/**
 * Ensure schema exists.
 * Table: vx_guardian_art (tarif_id, evolve_level, img_key)
 */
function vx_guardian_art_schema_ensure($db): void {
  if (!$db) return;
  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_art (
      tarif_id INT NOT NULL,
      evolve_level TINYINT NOT NULL,
      img_key VARCHAR(190) NOT NULL DEFAULT '',
      updated_at INT NOT NULL DEFAULT 0,
      PRIMARY KEY (tarif_id, evolve_level),
      KEY ix_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}
}

/**
 * Return map: level(int) => img_key(string)
 */
function vx_guardian_art_get_all($db, int $tarif_id): array {
  $tarif_id = (int)$tarif_id;
  if (!$db || $tarif_id <= 0) return [];
  vx_guardian_art_schema_ensure($db);
  $out = [];
  try {
    $q = $db->query('SELECT evolve_level, img_key FROM vx_guardian_art WHERE tarif_id=?', $tarif_id);
    if ($q) {
      while ($r = $q->fetchArray()) {
        $lvl = (int)($r['evolve_level'] ?? 0);
        $k = trim((string)($r['img_key'] ?? ''));
        if ($k !== '') $out[$lvl] = $k;
      }
    }
  } catch (Throwable $e) {}
  return $out;
}

/**
 * Upsert a single level.
 */
function vx_guardian_art_set($db, int $tarif_id, int $level, string $img_key): bool {
  $tarif_id = (int)$tarif_id;
  $level = (int)$level;
  $img_key = trim($img_key);
  if (!$db || $tarif_id <= 0 || $level < 0 || $level > 10) return false;
  vx_guardian_art_schema_ensure($db);
  $now = time();
  try {
    $db->query(
      "INSERT INTO vx_guardian_art (tarif_id, evolve_level, img_key, updated_at)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE img_key=VALUES(img_key), updated_at=VALUES(updated_at)",
      $tarif_id, $level, $img_key, $now
    );
    return true;
  } catch (Throwable $e) {
    // Fallback (older engines)
    try {
      $db->query('DELETE FROM vx_guardian_art WHERE tarif_id=? AND evolve_level=?', $tarif_id, $level);
      $db->query('INSERT INTO vx_guardian_art (tarif_id, evolve_level, img_key, updated_at) VALUES (?,?,?,?)', $tarif_id, $level, $img_key, $now);
      return true;
    } catch (Throwable $e2) {}
  }
  return false;
}

/**
 * Pick best art for a given evolve level.
 * Falls back to nearest lower level (so if you only upload E0 & E3, level 2 will show E0).
 */
function vx_guardian_art_key_for_level($db, int $tarif_id, int $level, string $fallback_key = ''): string {
  $tarif_id = (int)$tarif_id;
  $level = (int)$level;
  if ($level < 0) $level = 0;
  if ($level > 10) $level = 10;
  $fallback_key = trim($fallback_key);
  if (!$db || $tarif_id <= 0) return $fallback_key;

  $map = vx_guardian_art_get_all($db, $tarif_id);
  if (isset($map[$level]) && trim((string)$map[$level]) !== '') return (string)$map[$level];
  // Search downward
  for ($i = $level; $i >= 0; $i--) {
    if (isset($map[$i]) && trim((string)$map[$i]) !== '') return (string)$map[$i];
  }
  // Search upward
  for ($i = $level + 1; $i <= 10; $i++) {
    if (isset($map[$i]) && trim((string)$map[$i]) !== '') return (string)$map[$i];
  }
  return $fallback_key;
}

/**
 * Resolve /img/items URL from a key, checking common extensions.
 */
function vx_img_items_url_from_key(string $key): string {
  $key = trim($key);
  if ($key === '') return '/assets/img/guardians/placeholder.png';
  $key = preg_replace('~\.(png|webp|jpe?g)$~i', '', $key);
  $key = preg_replace('~[^a-zA-Z0-9_\-]~', '', $key);
  $base = __DIR__ . '/../img/items/' . $key;
  foreach (['webp','png','jpg','jpeg'] as $ext) {
    $p = $base . '.' . $ext;
    if (is_file($p)) return '/img/items/' . $key . '.' . $ext;
  }
  // If caller already stored a full filename, try direct
  foreach (['webp','png','jpg','jpeg'] as $ext) {
    $p = __DIR__ . '/../img/items/' . $key . '.' . $ext;
    if (is_file($p)) return '/img/items/' . $key . '.' . $ext;
  }
  return '/assets/img/guardians/placeholder.png';
}
