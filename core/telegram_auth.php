<?php
// Minimal bootstrap
define('FastCore', true);
require_once __DIR__ . '/config.php';   // ensures $db and $config are available
header('Content-Type: text/plain; charset=utf-8');

session_start();

/**
 * Verify Telegram auth data (Login Widget or WebApp)
 * For WebApp: receive initData (string)
 * For Widget: receive query string with id, first_name, auth_date, hash, etc.
 */
$botToken = $config->bot_token ?? '';
if (!$botToken) { http_response_code(500); echo 'Missing bot token'; exit; }

function tg_check_hash(array $data, string $botToken): bool {
    $check_hash = $data['hash'] ?? '';
    unset($data['hash']);

    ksort($data);
    $data_check_string = [];
    foreach ($data as $k => $v) {
        $data_check_string[] = $k . '=' . $v;
    }
    $data_check_string = implode("\n", $data_check_string);

    $secret_key = hash('sha256', $botToken, true);
    $hmac = hash_hmac('sha256', $data_check_string, $secret_key);
    return hash_equals($hmac, $check_hash);
}

function tg_parse_init_data(string $initData): array {
    // initData is URL-encoded query string
    parse_str($initData, $pairs);
    if (isset($pairs['user'])) {
        $pairs['user'] = json_decode($pairs['user'], true) ?: [];
    }
    return $pairs;
}

// Source: WebApp or Widget
if (isset($_POST['initData'])) {
    // WebApp path
    $pairs = tg_parse_init_data($_POST['initData']);
    if (empty($pairs['hash'])) { http_response_code(400); echo 'Missing hash'; exit; }

    // Flatten for signature check
    $flat = [];
    foreach ($pairs as $k => $v) {
        if (is_array($v)) continue;
        $flat[$k] = $v;
    }
    // Include user.* keys in canonical form
    if (isset($pairs['user']) && is_array($pairs['user'])) {
        foreach ($pairs['user'] as $k => $v) {
            $flat['user.' . $k] = (string)$v;
        }
    }
    if (!tg_check_hash($flat, $botToken)) { http_response_code(401); echo 'Bad hash'; exit; }

    $tg = $pairs['user'] ?? [];
    $tgId = (int)($tg['id'] ?? 0);
    $tgName = trim(($tg['username'] ?? '') ?: (($tg['first_name'] ?? '') . ' ' . ($tg['last_name'] ?? '')));

} else {
    // Widget path — params come via GET
    $data = $_GET;
    if (empty($data['hash'])) { http_response_code(400); echo 'Missing hash'; exit; }
    if (!tg_check_hash($data, $botToken)) { http_response_code(401); echo 'Bad hash'; exit; }

    $tgId   = (int)($data['id'] ?? 0);
    $tgName = trim(($data['username'] ?? '') ?: (($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')));
}

if ($tgId <= 0) { http_response_code(400); echo 'Bad user id'; exit; }

// Upsert user and set session
// Table/fields: adjust to your schema
$user = $db->query('SELECT * FROM db_users WHERE telegram_id = ?', $tgId)->fetchArray();

if (!$user) {
    // Create minimal account
    $db->query('INSERT INTO db_users (telegram_id, login, reg, auth, created_at, updated_at) VALUES (?,?,?,?,?,?)',
        $tgId, $tgName ?: ('tg_' . $tgId), time());
    $uid = $db->lastInsert();
} else {
    $uid = (int)$user['id'];
}

$_SESSION['uid']   = $uid;
$_SESSION['login'] = $tgName ?: ('tg_' . $tgId);

// Redirect accordingly
if (isset($_POST['initData'])) {
    // WebApp → simple OK (JS will redirect)
    echo 'OK';
    exit;
} else {
    // Widget → redirect server-side
    header('Location: /user/home.php');
    exit;
}
