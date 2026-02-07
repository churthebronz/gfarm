<?php
declare(strict_types=1);
/**
 * Resolve current authenticated UID from Telegram session cookie or legacy PHP session.
 *
 * Usage (API scripts):
 *   require_once __DIR__ . '/require_tg_session.php';
 *   $uid = (int)($GLOBALS['UID'] ?? 0);
 */

if (!defined('FastCore')) {
    define('FastCore', true);
}

require_once __DIR__ . '/../core/config.php';

$tok = '';
if (isset($_COOKIE['tg_sess']) && is_string($_COOKIE['tg_sess'])) {
    $tok = $_COOKIE['tg_sess'];
} elseif (isset($_COOKIE['tg_session']) && is_string($_COOKIE['tg_session'])) {
    $tok = $_COOKIE['tg_session'];
}

// Soft mode is used by page routes (e.g., /user) and fail-soft endpoints.
// In soft mode we NEVER echo/exit; we just leave UID=0 and return.
$VX_SOFT = defined('VX_SOFT_AUTH') && VX_SOFT_AUTH;

if ($tok === '' || $tok === null) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (isset($_SESSION['uid']) && (int)$_SESSION['uid'] > 0) {
        $GLOBALS['UID'] = (int)$_SESSION['uid'];
        return;
    }

    if ($VX_SOFT) {
        $GLOBALS['UID'] = 0;
        return;
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

/** @var db $db */
global $db;
if (!isset($db) || !($db instanceof db)) {
    if ($VX_SOFT) {
        $GLOBALS['UID'] = 0;
        return;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_not_initialized']);
    exit;
}

$row = $db->query(
    "SELECT user_id 
     FROM db_tg_sessions 
     WHERE token = ? AND expires_at > NOW()
     LIMIT 1",
    $tok
)->fetchArray();

if (!$row || !isset($row['user_id'])) {
    if ($VX_SOFT) {
        $GLOBALS['UID'] = 0;
        return;
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$GLOBALS['UID'] = (int)$row['user_id'];

// Mirror into PHP session for compatibility with existing endpoints.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['uid'] = $GLOBALS['UID'];
