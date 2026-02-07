<?php
// File: /core/vx_nicknames.php
// Per-user guardian nicknames (cosmetic)

if (!defined('FastCore')) define('FastCore', true);

function vx_nicknames_schema_ensure($db): void {
  try {
    $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_nicknames (
      uid INT NOT NULL,
      tarif_id INT NOT NULL,
      nickname VARCHAR(64) NOT NULL,
      updated_at INT NOT NULL,
      PRIMARY KEY (uid, tarif_id),
      KEY updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
}

function vx_nickname_get($db, int $uid, int $tarif): string {
  if ($uid<=0 || $tarif<=0) return '';
  try {
    vx_nicknames_schema_ensure($db);
    $r = $db->query("SELECT nickname FROM vx_guardian_nicknames WHERE uid=? AND tarif_id=? LIMIT 1", $uid, $tarif)->fetchArray();
    return is_array($r) ? trim((string)($r['nickname'] ?? '')) : '';
  } catch (Throwable $e) { return ''; }
}

function vx_nickname_set($db, int $uid, int $tarif, string $nickname): bool {
  if ($uid<=0 || $tarif<=0) return false;
  $nickname = trim($nickname);
  // allow clearing
  if ($nickname !== '') {
    // basic sanitize: keep letters numbers spaces # - _ .
    $nickname = preg_replace('~[^a-zA-Z0-9 \#\-\_\.\'\!\?]~u', '', $nickname);
    $nickname = trim($nickname);
    if (mb_strlen($nickname) > 64) $nickname = mb_substr($nickname, 0, 64);
  }
  $now = time();
  try {
    vx_nicknames_schema_ensure($db);
    if ($nickname === '') {
      $db->query("DELETE FROM vx_guardian_nicknames WHERE uid=? AND tarif_id=? LIMIT 1", $uid, $tarif);
      return true;
    }
    $db->query("INSERT INTO vx_guardian_nicknames (uid, tarif_id, nickname, updated_at)
      VALUES (?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE nickname=VALUES(nickname), updated_at=VALUES(updated_at)",
      $uid, $tarif, $nickname, $now
    );
    return true;
  } catch (Throwable $e) { return false; }
}
