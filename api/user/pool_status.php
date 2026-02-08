<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/auth_mw.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/vx_pool.php';
require_once __DIR__ . '/../require_tg_session.php';

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
$season = vx_get_current_season($db);
$sid = (int)($season['id'] ?? 0);
$pool = vx_pool_get_current($db, $sid);
echo json_encode(['ok'=>true,'season_id'=>$sid,'pool_points'=>(int)($pool['points_total'] ?? 0)]);
