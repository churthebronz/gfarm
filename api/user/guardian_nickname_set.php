<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_nicknames.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { try { session_start(); } catch (Throwable $e) {} }
global $db;

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'AUTH']); exit; }

$tarif = (int)($_POST['tarif'] ?? 0);
$nickname = (string)($_POST['nickname'] ?? '');

if ($tarif <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'BAD_TARIF']); exit; }

$ok = vx_nickname_set($db, $uid, $tarif, $nickname);
echo json_encode(['ok'=>$ok, 'nickname'=>vx_nickname_get($db,$uid,$tarif)], JSON_UNESCAPED_SLASHES);
