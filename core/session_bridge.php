<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
global $db;

// Check if we already have a PHP session user
if (!isset($_SESSION['uid']) || (int)$_SESSION['uid'] <= 0) {
    $token = $_COOKIE['tg_sess'] ?? ($_COOKIE['tg_session'] ?? '');
    if ($token) {
        $row = $db->query("SELECT user_id FROM db_tg_sessions WHERE token = ? AND expires_at > NOW()", $token)->fetchArray();
        if ($row && (int)$row['user_id'] > 0) {
            $_SESSION['uid'] = (int)$row['user_id'];
        }
    }
}
