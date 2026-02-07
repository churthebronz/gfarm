<?php
declare(strict_types=1);
// File: /core/idempotency.php
// Purpose: DB-backed idempotency for critical endpoints (withdraw, payment callbacks, purchases).
//
// Contract:
// - vx_idempo_begin(): returns ['ok'=>true,'status'=>'inflight'|'done'|'bypass','response'=>array|null]
// - vx_idempo_store(): persists final JSON response for future replays until TTL expires.

if (!defined('FastCore')) { define('FastCore', true); }

/**
 * Start (or join) an idempotency key window.
 *
 * If a previous request already finished, returns status=done and decoded response.
 * If the key is new or inflight, returns status=inflight.
 * On DB errors, returns status=bypass (caller should proceed normally).
 */
function vx_idempo_begin($db, string $key, int $ttlSec = 900): array {
    if (!$db || $key === '') return ['ok'=>true, 'status'=>'bypass'];

    $now = time();
    $ttl = max(60, $ttlSec);
    $exp = $now + $ttl;

    try {
        // Create if not exists (idempotent) — relies on schema_ensure() elsewhere, but safe if missed.
        $db->query("CREATE TABLE IF NOT EXISTS vx_idempotency (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            idem_key VARCHAR(190) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'inflight',
            response_json MEDIUMTEXT NULL,
            created_at INT NOT NULL,
            expires_at INT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_idem_key (idem_key),
            KEY ix_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Insert inflight row (ignore duplicate keys).
        $db->query(
            "INSERT IGNORE INTO vx_idempotency (idem_key, status, response_json, created_at, expires_at)
             VALUES (?, 'inflight', NULL, ?, ?)",
            $key, $now, $exp
        );
    } catch (Throwable $e) {
        return ['ok'=>true, 'status'=>'bypass'];
    }

    try {
        $row = $db->query("SELECT status, response_json, expires_at FROM vx_idempotency WHERE idem_key = ? LIMIT 1", $key)->fetchArray();
        if (!$row) return ['ok'=>true, 'status'=>'inflight'];

        $expiresAt = (int)($row['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < $now) {
            // Expired window: reset to inflight
            try {
                $db->query("UPDATE vx_idempotency SET status='inflight', response_json=NULL, created_at=?, expires_at=? WHERE idem_key=? LIMIT 1", $now, $exp, $key);
            } catch (Throwable $e) {}
            return ['ok'=>true, 'status'=>'inflight'];
        }

        $status = (string)($row['status'] ?? 'inflight');
        $respJson = (string)($row['response_json'] ?? '');
        if ($status === 'done' && $respJson !== '') {
            $decoded = json_decode($respJson, true);
            if (is_array($decoded)) return ['ok'=>true, 'status'=>'done', 'response'=>$decoded];
        }
        return ['ok'=>true, 'status'=>'inflight'];
    } catch (Throwable $e) {
        return ['ok'=>true, 'status'=>'bypass'];
    }
}

/**
 * Store final response for an idempotency key.
 * Safe to call multiple times.
 */
function vx_idempo_store($db, string $key, array $response, int $ttlSec = 900): void {
    if (!$db || $key === '') return;

    $now = time();
    $ttl = max(60, $ttlSec);
    $exp = $now + $ttl;

    $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    try {
        $db->query(
            "INSERT INTO vx_idempotency (idem_key, status, response_json, created_at, expires_at)
             VALUES (?, 'done', ?, ?, ?)
             ON DUPLICATE KEY UPDATE status='done', response_json=VALUES(response_json), expires_at=VALUES(expires_at)",
            $key, $json, $now, $exp
        );
    } catch (Throwable $e) {
        // fail-soft
    }
}
