<?php
if (!defined('FastCore')) { define('FastCore', true); }
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }

function vx_csrf_token(): string { return (string)($_SESSION['csrf'] ?? ''); }
function vx_csrf_check(): bool {
  $tok = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  $tok = is_string($tok) ? trim($tok) : '';
  $sess = (string)($_SESSION['csrf'] ?? '');
  return ($tok !== '' && hash_equals($sess, $tok));
}

/**
 * Validate CSRF and exit with a JSON error.
 * Used by API endpoints to fail closed.
 */
function vx_csrf_validate_or_exit(string $msg = 'Invalid request'): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') return;
  if (vx_csrf_check()) return;
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'msg'=>$msg], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}