<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
try { require_once __DIR__ . '/../../core/config.php'; require_once __DIR__ . '/../../core/auth_mw.php'; require_once __DIR__ . '/../require_tg_session.php'; }
catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>'Bootstrap failed']); exit; }

global $db; $uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

function safeFetchArray($stmt){ try{ return $stmt ? ($stmt->fetchArray() ?: []) : []; }catch(Throwable $e){ return []; } }

try {
  $total = safeFetchArray($db->query('SELECT COUNT(*) c FROM db_users WHERE rid=?', $uid));
  $active = safeFetchArray($db->query(
    'SELECT COUNT(*) c FROM db_store s JOIN db_users u ON u.id = s.uid WHERE u.rid=? AND s.status=1',
     $uid
  ));
  echo json_encode([
    'ok'=>true,
    'refs'=>[ 'total'=>(int)($total['c'] ?? 0), 'active'=>(int)($active['c'] ?? 0) ]
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'msg'=>'Referrals error']);
}
