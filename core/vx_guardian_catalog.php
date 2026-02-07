<?php
// File: /core/vx_guardian_catalog.php
// GreenFarm — Guardian Catalog (Pokedex)

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/schema_helpers.php';

function vx_guardian_catalog_schema_ensure($db): void {
  if (!$db) return;

  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_catalog (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      kind VARCHAR(24) NOT NULL DEFAULT 'plan',
      guardian_no INT NOT NULL DEFAULT 0,
      guardian_code VARCHAR(32) NOT NULL,
      tarif_id INT NULL,
      base_name VARCHAR(80) NOT NULL,
      display_name VARCHAR(120) NOT NULL,
      type_primary VARCHAR(32) NOT NULL DEFAULT 'Mystic',
      type_secondary VARCHAR(32) NULL,
      rarity VARCHAR(24) NOT NULL DEFAULT 'common',
      max_evolve TINYINT NOT NULL DEFAULT 5,
      forms_total TINYINT NOT NULL DEFAULT 6,
      blur_in_codex TINYINT NOT NULL DEFAULT 1,
      unlock_method VARCHAR(32) NOT NULL DEFAULT 'purchase',
      created_at INT NOT NULL DEFAULT 0,
      updated_at INT NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY ux_code (guardian_code),
      UNIQUE KEY ux_kind_no (kind, guardian_no),
      KEY ix_tarif (tarif_id),
      KEY ix_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {}

  // Backfill missing columns if an older version exists.
  try {
    if (function_exists('vx_column_exists')) {
      $cols = [
        'kind' => "ALTER TABLE vx_guardian_catalog ADD COLUMN kind VARCHAR(24) NOT NULL DEFAULT 'plan'",
        'guardian_no' => "ALTER TABLE vx_guardian_catalog ADD COLUMN guardian_no INT NOT NULL DEFAULT 0",
        'guardian_code' => "ALTER TABLE vx_guardian_catalog ADD COLUMN guardian_code VARCHAR(32) NOT NULL",
        'tarif_id' => "ALTER TABLE vx_guardian_catalog ADD COLUMN tarif_id INT NULL",
        'base_name' => "ALTER TABLE vx_guardian_catalog ADD COLUMN base_name VARCHAR(80) NOT NULL DEFAULT ''",
        'display_name' => "ALTER TABLE vx_guardian_catalog ADD COLUMN display_name VARCHAR(120) NOT NULL DEFAULT ''",
        'type_primary' => "ALTER TABLE vx_guardian_catalog ADD COLUMN type_primary VARCHAR(32) NOT NULL DEFAULT 'Mystic'",
        'type_secondary' => "ALTER TABLE vx_guardian_catalog ADD COLUMN type_secondary VARCHAR(32) NULL",
        'rarity' => "ALTER TABLE vx_guardian_catalog ADD COLUMN rarity VARCHAR(24) NOT NULL DEFAULT 'common'",
        'max_evolve' => "ALTER TABLE vx_guardian_catalog ADD COLUMN max_evolve TINYINT NOT NULL DEFAULT 5",
        'forms_total' => "ALTER TABLE vx_guardian_catalog ADD COLUMN forms_total TINYINT NOT NULL DEFAULT 6",
        'blur_in_codex' => "ALTER TABLE vx_guardian_catalog ADD COLUMN blur_in_codex TINYINT NOT NULL DEFAULT 1",
        'unlock_method' => "ALTER TABLE vx_guardian_catalog ADD COLUMN unlock_method VARCHAR(32) NOT NULL DEFAULT 'purchase'",
        'created_at' => "ALTER TABLE vx_guardian_catalog ADD COLUMN created_at INT NOT NULL DEFAULT 0",
        'updated_at' => "ALTER TABLE vx_guardian_catalog ADD COLUMN updated_at INT NOT NULL DEFAULT 0"
      ];
      foreach ($cols as $col => $sql) {
        if (!vx_column_exists($db, 'vx_guardian_catalog', $col)) {
          try { $db->query($sql); } catch (Throwable $e) {}
        }
      }
    }
  } catch (Throwable $e) {}
}

function vx_guardian_catalog_list($db, int $limit = 200, int $offset = 0): array {
  if (!$db) return [];
  vx_guardian_catalog_schema_ensure($db);
  $limit = max(1, min(500, $limit));
  $offset = max(0, $offset);
  try {
    $q = $db->query("SELECT * FROM vx_guardian_catalog WHERE kind='plan' ORDER BY guardian_no ASC LIMIT ? OFFSET ?", $limit, $offset);
    $out = [];
    if ($q) while ($r = $q->fetchArray()) $out[] = $r;
    return $out;
  } catch (Throwable $e) { return []; }
}

function vx_guardian_catalog_list_special($db, int $limit = 50): array {
  if (!$db) return [];
  vx_guardian_catalog_schema_ensure($db);
  $limit = max(1, min(200, $limit));
  try {
    $q = $db->query("SELECT * FROM vx_guardian_catalog WHERE kind='star' ORDER BY guardian_no ASC LIMIT ?", $limit);
    $out = [];
    if ($q) while ($r = $q->fetchArray()) $out[] = $r;
    return $out;
  } catch (Throwable $e) { return []; }
}

function vx_guardian_catalog_row_by_tarif($db, int $tarif): array {
  if (!$db || $tarif <= 0) return [];
  vx_guardian_catalog_schema_ensure($db);
  try {
    $r = $db->query('SELECT * FROM vx_guardian_catalog WHERE kind=\'plan\' AND tarif_id=? LIMIT 1', $tarif)->fetchArray();
    return $r ?: [];
  } catch (Throwable $e) { return []; }
}

function vx_guardian_catalog_max_evolve_for_tarif($db, int $tarif, int $fallback = 5): int {
  $fallback = max(1, min(10, $fallback));
  $r = vx_guardian_catalog_row_by_tarif($db, $tarif);
  $m = (int)($r['max_evolve'] ?? 0);
  if ($m <= 0) return $fallback;
  return max(1, min(10, $m));
}

function vx_guardian_catalog_display_name_for_tarif($db, int $tarif, string $fallbackTitle = ''): string {
  $r = vx_guardian_catalog_row_by_tarif($db, $tarif);
  $d = (string)($r['display_name'] ?? '');
  if ($d !== '') return $d;
  return $fallbackTitle !== '' ? $fallbackTitle : 'Mutant Crop';
}
