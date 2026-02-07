<?php
declare(strict_types=1);
if (!defined('FastCore')) define('FastCore', true);

require __DIR__ . '/config.php';

// DB bootstrap (same as before) ...
if (!class_exists('db')) {
    $dbFile = __DIR__ . '/classes/db.php';
    if (is_file($dbFile)) require_once $dbFile;
}
if (!isset($db) || !($db instanceof db)) {
    $host = $config->db_host ?? (defined('dbHost') ? dbHost : null);
    $user = $config->db_user ?? (defined('dbUser') ? dbUser : null);
    $pass = $config->db_pass ?? (defined('dbPass') ? dbPass : null);
    $name = $config->db_name ?? (defined('dbName') ? dbName : null);
    if ($host && $user && $name && class_exists('db')) {
        $db = new db($host, $user, $pass ?? '', $name, 'utf8mb4');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function is_telegram_webview(): bool {
  try {
    $h = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
    if ($h) return true;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (stripos($ua, 'Telegram') !== false);
  } catch(Throwable $e){ return false; }
}

$CURRENT_USER = null;

if (!empty($_SESSION['uid']) && isset($db) && $db instanceof db) {
    $uid = (int)$_SESSION['uid'];
    $row = $db->query('SELECT * FROM db_users WHERE id = ?', $uid)->fetchArray();
    if ($row) $CURRENT_USER = $row;
} else {
    $tok = $_COOKIE['tg_sess'] ?? '';
    if ($tok && preg_match('/^[a-f0-9]{64}$/', $tok) && isset($db) && $db instanceof db) {
        $row = $db->query(
            'SELECT u.* FROM db_tg_sessions s JOIN db_users u ON u.id = s.user_id
             WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1', $tok
        )->fetchArray();
        if ($row) {
            $CURRENT_USER = $row;
            $_SESSION['uid']   = (int)$row['id'];
            $_SESSION['login'] = $row['login'] ?? ('tg_' . ($row['telegram_id'] ?? ''));
        }
    }
}

function current_user() { global $CURRENT_USER; return $CURRENT_USER; }
function require_auth($u = null) { if (!$u) { http_response_code(401); exit('auth'); } }

// Only redirect non-Telegram users to /auth when hitting /user
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$firstSeg = explode('/', trim($path, '/'))[0] ?? '';

if ($firstSeg === 'user' && !current_user()) {
    if (is_telegram_webview()) {
        http_response_code(401);
        exit('auth'); // client JS should have already called /api/auth/telegram.php
    } else {
        header('Location: /auth');
        exit;
    }
}
