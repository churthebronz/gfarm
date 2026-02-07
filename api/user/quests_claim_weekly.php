<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../require_tg_session.php';
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/rate_limiter.php';
require_once __DIR__ . '/../../core/vx_quests.php';

vx_rate_limit_or_429('api_weekly_pack', 4, 60, true);

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_auth']); exit; }

$ok = vx_weekly_pack_try_claim($db, $uid);
$st = vx_weekly_pack_status($db, $uid);

echo json_encode(['ok'=>$ok, 'weekly'=>$st]);
