<?php
declare(strict_types=1);

// /api/user/boost_fragment.php
// Returns the Mutant Crops (boost.php) HTML for lazy-loading on dashboard.

define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  try { session_start(); } catch (Throwable $e) {}
}

$uid = (int)($_SESSION['uid'] ?? 0);

// TG WebApp fallback: if PHP session is missing uid, try tg_sess (same logic as dashboard)
if ($uid <= 0) {
  try { require_once __DIR__ . '/../require_tg_session.php'; } catch (Throwable $e) {}
  if (isset($GLOBALS['UID'])) {
    $uid = (int)$GLOBALS['UID'];
    if ($uid > 0) { $_SESSION['uid'] = $uid; }
  }
}

if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'not_logged_in']);
  exit;
}

try {
  // Render boost.php in dashboard-embed mode (no external CSS links/bg div)
  $candidates = [
    __DIR__ . '/../../pages/user/boost.php',
    __DIR__ . '/../../user/boost.php',
    __DIR__ . '/../../pages/user/boost.php',
    __DIR__ . '/../../pages/user/boost.php',
  ];

  $boost = '';
  foreach ($candidates as $p) {
    if (is_file($p)) { $boost = $p; break; }
  }

  if ($boost === '') {
    echo json_encode(['ok'=>false,'error'=>'boost_missing']);
    exit;
  }

  // Provide a flag so boost.php can skip global layout wrappers if it supports it.
  $GLOBALS['VX_DASHBOARD_EMBED'] = true;

  ob_start();
  include $boost;
  $html = (string)ob_get_clean();

  echo json_encode(['ok'=>true,'html'=>$html]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
