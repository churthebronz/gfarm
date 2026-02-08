<?php
declare(strict_types=1);
// File: /api/user/onboarding_complete.php
// Marks the dashboard onboarding overlay as completed.

if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../require_tg_session.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'Not signed in']);
    exit;
}

try {
    if (isset($GLOBALS['db']) && $GLOBALS['db']) {
        $db = $GLOBALS['db'];
        if (function_exists('vx_schema_ensure')) { vx_schema_ensure($db); }
        $db->query('UPDATE db_users SET vx_onboarded = 1, vx_onboarded_at = ? WHERE id = ? LIMIT 1', time(), $uid);

        // Mark the first-login Founders overlay as seen (even if the window is over).
        // This prevents repeat popups on every dashboard visit.
        try { if (function_exists('vx_meta_set')) vx_meta_set($db, $uid, 'founders_popup_seen', '1'); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

echo json_encode(['ok'=>true]);
