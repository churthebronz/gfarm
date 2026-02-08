<?php
declare(strict_types=1);

define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rate_limit.php';
require_once __DIR__ . '/../../core/wallets.php';
require_once __DIR__ . '/../require_tg_session.php';

function vx_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

global $db;
$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) {
    vx_json(['ok' => false, 'msg' => 'Unauthorized'], 401);
}

// Tight rate-limit: wallet writes should be rare.
vx_rate_limit_or_429('wallet_save', 20, 60, true);

if (!vx_csrf_check()) {
    vx_json(['ok' => false, 'msg' => 'Invalid CSRF'], 403);
}

$name = trim((string)($_POST['name'] ?? $_GET['name'] ?? ''));
$purse = trim((string)($_POST['purse'] ?? $_GET['purse'] ?? ''));
if ($name === '' || $purse === '') {
    vx_json(['ok' => false, 'msg' => 'Wallet type or address is missing'], 400);
}

// Load pay system by name (as stored in db_paysystem.name)
try {
    $ps = $db->query(
        "SELECT * FROM db_paysystem WHERE LOWER(name) = LOWER(?) LIMIT 1",
        $name
    )->fetchArray();
} catch (Throwable $e) {
    $ps = null;
}
if (!$ps) {
    vx_json(['ok' => false, 'msg' => 'Unknown payment system'], 400);
}

// Optional format validation based on wallet method.
$w = new wallets();
$method = strtolower(preg_replace('~[^a-z0-9_]~i', '', (string)($ps['name'] ?? ''))) . '_wallet';
if (method_exists($w, $method)) {
    $validated = $w->{$method}($purse);
    if ($validated === false) {
        vx_json(['ok' => false, 'msg' => 'Invalid wallet format'], 400);
    }
    $purse = (string)$validated;
}

try {
    $exists = $db->query(
        'SELECT id FROM db_purse WHERE uid=? AND name=? LIMIT 1',
        $uid,
        (string)$ps['name']
    )->fetchArray();

    if ($exists && isset($exists['id'])) {
        $db->query('UPDATE db_purse SET purse=? WHERE id=?', $purse, (int)$exists['id']);
    } else {
        $db->query('INSERT INTO db_purse (uid, name, purse) VALUES (?, ?, ?)', $uid, (string)$ps['name'], $purse);
    }

    vx_json(['ok' => true, 'msg' => 'Wallet saved']);
} catch (Throwable $e) {
    vx_json(['ok' => false, 'msg' => 'Failed to save wallet'], 500);
}
