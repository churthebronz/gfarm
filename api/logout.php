<?php
// /api/logout.php
declare(strict_types=1);
define('FastCore', true);

require_once __DIR__ . '/../core/config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

// Delete TG session token from DB
$tok = (string)($_COOKIE['tg_sess'] ?? ($_COOKIE['tg_session'] ?? ''));
if ($tok !== '') {
  try {
    global $db;
    if (isset($db) && ($db instanceof db)) {
      $db->query('DELETE FROM db_tg_sessions WHERE token = ? LIMIT 1', $tok);
    }
  } catch (Throwable $e) {}
}

// Clear cookies (match secure/httponly/samesite used on login)
$cookieOpts = [
  'expires'  => time() - 3600,
  'path'     => '/',
  'secure'   => true,
  'httponly' => true,
  'samesite' => 'None',
];
setcookie('tg_sess', '', $cookieOpts);
setcookie('tg_session', '', $cookieOpts);

// Clear PHP session
$_SESSION = [];
try {
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
  }
} catch (Throwable $e) {}
@session_destroy();

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
