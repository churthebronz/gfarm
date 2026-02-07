<?php
// pages/adminka/inc/admin_ops.php
// Small admin helpers: CSRF + audit log (best-effort).

declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

function vx_admin_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION['_vx_admin_csrf'])) {
        $_SESSION['_vx_admin_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['_vx_admin_csrf'];
}

function vx_admin_csrf_ok(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $t = (string)($_SESSION['_vx_admin_csrf'] ?? '');
    if ($t === '' || !$token) return false;
    return hash_equals($t, (string)$token);
}

function vx_admin_uid(): int {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    return (int)($_SESSION['uid'] ?? 0);
}

/**
 * Best-effort audit log into events_log.
 * Schema in this app: events_log(id, uid, event, meta, created_at)
 */
function vx_admin_audit($db, string $event, array $meta = []): void {
    try {
        $uid = vx_admin_uid();
        $meta['ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $meta['ua'] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
        // Try the expected schema
        $db->query(
            'INSERT INTO events_log (uid, event, meta, created_at) VALUES (?, ?, ?, ?)',
            $uid, $event, $metaJson, time()
        );
    } catch (Throwable $e) {
        // Swallow – audit should never break admin.
    }
}

function vx_admin_status_badge(int $status, string $kind): array {
    // Returns [label, class]
    if ($kind === 'deposit') {
        if ($status === 1) return ['confirmed', 'ok'];
        if ($status === 0) return ['pending', 'wait'];
        return ['status '.$status, 'bad'];
    }
    // payout
    if ($status === 3) return ['paid', 'ok'];
    if ($status === 1) return ['pending', 'wait'];
    if ($status === 2) return ['canceled', 'bad'];
    return ['status '.$status, 'bad'];
}
