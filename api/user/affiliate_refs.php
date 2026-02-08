<?php
declare(strict_types=1);

// /api/user/affiliate_refs.php
// Returns a masked list of referred users + whether they have qualified (paid guardian activated).

define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_affiliate.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/vx_refqual.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'auth']);
  exit;
}

global $db;
vx_guardians_schema_ensure($db);

function vx_mask_login(string $s): string {
  $s = trim($s);
  if ($s === '') return 'User';
  if (strlen($s) <= 4) return $s;
  return substr($s, 0, 2) . str_repeat('•', max(2, strlen($s) - 4)) . substr($s, -2);
}

try {
  $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
  $rows = [];
  // Prefer trust-layer statuses when present.
  $q = $db->query(
    "SELECT u.id, u.login, u.tg_username, u.created_at,
            COALESCE(q.status,'') AS q_status,
            COALESCE(q.qualify_after,0) AS q_after,
            COALESCE(q.qualified_at,0) AS q_at,
            COALESCE(q.reason,'') AS q_reason,
            CASE WHEN COALESCE(q.status,'')='qualified' THEN 1
                 WHEN EXISTS(
                   SELECT 1 FROM vx_guardian_chains c
                   JOIN db_tarif t ON t.id=c.tarif
                   WHERE c.uid=u.id AND c.kind='plan' AND t.price>0 AND c.status IN ('active','matured','codex')
                 ) THEN 1 ELSE 0 END AS qualified
     FROM db_users u
     LEFT JOIN vx_ref_qualifications q ON q.buyer_uid = u.id
     WHERE u.rid = ?
     ORDER BY u.id DESC
     LIMIT ?",
    $uid, $limit
  );
  if ($q) {
    while ($r = $q->fetchArray()) { $rows[] = $r; }
  }

  $out = [];
  foreach ($rows as $r) {
    $label = '';
    $tg = trim((string)($r['tg_username'] ?? ''));
    if ($tg !== '') $label = '@'.ltrim($tg,'@');
    else $label = vx_mask_login((string)($r['login'] ?? 'User'));
    $out[] = [
      'uid' => (int)($r['id'] ?? 0),
      'user' => $label,
      'qualified' => ((int)($r['qualified'] ?? 0) === 1),
      'status' => (string)($r['q_status'] ?? ''),
      'qualify_after' => (int)($r['q_after'] ?? 0),
      'qualified_at' => (int)($r['q_at'] ?? 0),
      'reason' => (string)($r['q_reason'] ?? ''),
      'created_at' => (string)($r['created_at'] ?? ''),
    ];
  }

  echo json_encode(['ok'=>true,'items'=>$out]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server']);
}
