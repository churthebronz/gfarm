<?php
require_once __DIR__ . '/_bootstrap.php';
try {
  // total guardians in catalog (db_tarif is source of truth)
  $rowT = $db->query("SELECT COUNT(*) AS n FROM db_tarif")->fetchArray();
  $total = (int)($rowT['n'] ?? 0);

  // owned guardians (any chain)
  $rowO = $db->query("SELECT COUNT(DISTINCT tarif) AS n FROM vx_guardian_chains WHERE uid=?", [$uid])->fetchArray();
  $owned = (int)($rowO['n'] ?? 0);

  // archived/codex guardians
  $rowA = $db->query("SELECT COUNT(*) AS n FROM vx_guardian_chains WHERE uid=? AND status='codex'", [$uid])->fetchArray();
  $archived = (int)($rowA['n'] ?? 0);

  // evolve actions proxy: sum crossbreed levels across chains (cap safety)
  $rowE = $db->query("SELECT COALESCE(SUM(crossbreed_level),0) AS n FROM vx_guardian_chains WHERE uid=?", [$uid])->fetchArray();
  $evolves = (int)($rowE['n'] ?? 0);

  $codexPct = ($total > 0) ? (int)floor(($owned / $total) * 100.0) : 0;

  echo json_encode([
    'ok'=>true,
    'total'=>$total,
    'owned'=>$owned,
    'archived'=>$archived,
    'evolves'=>$evolves,
    'codexPct'=>$codexPct
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'ERR','msg'=>$e->getMessage()]);
}
