<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../require_tg_session.php';
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_ref_tiers.php';

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_auth']); exit; }

echo json_encode(vx_ref_tier_status($db, $uid));
