<?php
declare(strict_types=1);
define('FastCore', true);

// Bootstrap config + DB
require __DIR__ . '/config.php';
if (!class_exists('db')) {
  $dbFile = __DIR__ . '/classes/db.php';
  if (is_file($dbFile)) require_once $dbFile;
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$host = $config->db_host ?? (defined('dbHost') ? dbHost : null);
$user = $config->db_user ?? (defined('dbUser') ? dbUser : null);
$pass = $config->db_pass ?? (defined('dbPass') ? dbPass : null);
$name = $config->db_name ?? (defined('dbName') ? dbName : null);
if (!isset($db) || !($db instanceof db)) {
  $db = new db($host, $user, $pass, $name, 'utf8mb4');
}

// Choose token: prefer $config->telegram_token, else $config->bot_token
$botToken = trim((string)($config->telegram_token ?? $config->bot_token ?? ''));
if ($botToken === '') {
  http_response_code(500);
  exit('Telegram token not configured.');
}

// Validate Telegram auth (GET params)
$data = $_GET;
$hash = $data['hash'] ?? '';
unset($data['hash']);

// optional: reject old auth data ( > 24h )
if (isset($data['auth_date']) && (time() - (int)$data['auth_date']) > 86400) {
  http_response_code(400);
  exit('Auth data expired.');
}

// Build check string
ksort($data);
$checkString = [];
foreach ($data as $k => $v) {
  $checkString[] = $k . '=' . $v;
}
$checkString = implode("\n", $checkString);

// Compute HMAC
$secretKey = hash('sha256', $botToken, true);
$calcHash  = hash_hmac('sha256', $checkString, $secretKey);

if (!hash_equals($calcHash, $hash)) {
  http_response_code(400);
  exit('Invalid signature.');
}

// Upsert user
$tgId     = (int)($data['id'] ?? 0);
$username = (string)($data['username'] ?? '');
$first    = (string)($data['first_name'] ?? '');
$last     = (string)($data['last_name'] ?? '');
$login    = $username !== '' ? $username : ('tg_' . $tgId);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$now = time();

// Try find by tg_id first
$u = $db->query('SELECT id, login FROM db_users WHERE telegram_id = ? LIMIT 1', $tgId)->fetchArray();
if (!$u) {
  // fallback by login
  $u = $db->query('SELECT id, login FROM db_users WHERE login = ? LIMIT 1', $login)->fetchArray();
}

if ($u) {
  $uid = (int)$u['id'];
  // keep username fresh
  $db->query('UPDATE db_users SET username = ? WHERE id = ?', $username, $uid);
} else {
  // minimal insert; adjust columns to your schema if needed
  $db->query(
    'INSERT INTO db_users (login, telegram_id, username, reg, auth, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
    $login, $tgId, $username, $now, $now, $now, $now
  );
  $uid = (int)$db->lastInsert();
}

$_SESSION['uid']   = $uid;
$_SESSION['login'] = $login;

// Persist session token (optional but handy)
$token = bin2hex(random_bytes(32));
setcookie('tg_sess', $token, time() + 86400*30, '/', '', true, true);

// Ensure table exists in your DB: db_tg_sessions(user_id int, token varchar(64) pk/unique, expires_at datetime)
$db->query(
  'INSERT INTO db_tg_sessions (user_id, token, expires_at)
   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
   ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)',
  $uid, $token
);

// Go to dashboard
header('Location: /user');
exit;
