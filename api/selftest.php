<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth_mw.php';
require_once __DIR__ . '/../core/schema_helpers.php';

// Production hardening: do not expose diagnostics publicly.
// Allow only a single admin UID (config->diag_allow_uid), defaults to 1.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
global $config;
$allowUid = isset($config->diag_allow_uid) ? (int)$config->diag_allow_uid : 1;
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0 || ($allowUid > 0 && $uid !== $allowUid)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

global $db;
$out = ['ok' => true, 'checks' => []];

$bot = (string)($config->bot_token ?? '');
$out['checks']['bot_token'] = $bot ? 'set' : 'missing';
if (!$bot) $out['ok'] = false;

try {
    $row = $db->query('SELECT id,title,price,speed,period FROM db_tarif ORDER BY id ASC LIMIT 1')->fetchArray();
    $out['checks']['plan'] = $row ? 'ok' : 'missing';
    if (!$row) $out['ok'] = false;
} catch (Throwable $e) {
    $out['checks']['plan'] = 'error';
    $out['ok'] = false;
}

try {
    if (function_exists('vx_earnings_touch')) {
        $st = vx_earnings_touch($db, $uid);
        $out['checks']['earnings_touch'] = is_array($st) ? 'ok' : 'error';
    } else {
        $out['checks']['earnings_touch'] = 'skipped';
    }
} catch (Throwable $e) {
    $out['checks']['earnings_touch'] = 'error';
    $out['ok'] = false;
}

echo json_encode($out);