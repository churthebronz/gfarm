<?php
declare(strict_types=1);

// File: /api/user/guardian_collect.php
// Purpose: User action to move a matured guardian into Mutant Index (Collect), ending the crossbreed window.

define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rate_limit.php';
require_once __DIR__ . '/../../core/vx_guardians.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
global $db;

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

vx_csrf_validate_or_exit();
vx_rate_limit_or_429('guardian_collect_u'.$uid, 12, 300, true);

$chainId = (int)($_POST['chain_id'] ?? 0);
if ($chainId <= 0) { echo json_encode(['ok'=>false,'msg'=>'Missing chain id']); exit; }

$now = time();

try {
  vx_guardians_schema_ensure($db);

  $row = $db->query(
    "SELECT id, uid, status, crossbreed_level, term_end, crossbreed_deadline
       FROM vx_guardian_chains
      WHERE id=? AND uid=?
      LIMIT 1",
    $chainId, $uid
  )->fetchArray();

  if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }
  $status = (string)($row['status'] ?? '');

  if ($status !== 'matured' && $status !== 'active') {
    echo json_encode(['ok'=>false,'msg'=>'This card is already in Mutant Index']);
    exit;
  }

  // Ensure status is matured when collecting.
  if ($status === 'active') {
    // If term already ended, mark matured first; otherwise user cannot collect early.
    $termEnd = (int)($row['term_end'] ?? 0);
    if ($termEnd > 0 && $termEnd <= $now) {
      $db->query("UPDATE vx_guardian_chains SET status='matured', matured_at=?, updated_at=? WHERE id=? AND uid=? LIMIT 1", $now, $now, $chainId, $uid);
    } else {
      echo json_encode(['ok'=>false,'msg'=>'Not matured yet']);
      exit;
    }
  }

  // Move to codex with explicit reason.
  $db->query(
    "UPDATE vx_guardian_chains
        SET status='codex', codex_reason='collect', codex_at=?, updated_at=?
      WHERE id=? AND uid=? AND status='matured'
      LIMIT 1",
    $now, $now, $chainId, $uid
  );
  $rc = (int)($db->query('SELECT ROW_COUNT() AS rc')->fetchArray()['rc'] ?? 0);
  if ($rc <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'Unable to collect right now']);
    exit;
  }

  echo json_encode(['ok'=>true,'msg'=>'Collected into Mutant Index','chain_id'=>$chainId]);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Server error']);
  exit;
}
