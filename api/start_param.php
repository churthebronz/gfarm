<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$raw = (string)($_POST['start'] ?? $_GET['start'] ?? $_POST['startapp'] ?? $_GET['startapp'] ?? $_GET['ref'] ?? '');
$raw = trim($raw);

// Normalize referral payloads (accept ref_CODE but store CODE)
if (stripos($raw, 'ref_') === 0 && strlen($raw) > 4) { $raw = substr($raw, 4); }

if ($raw !== '') {
  $_SESSION['vx_start_param'] = $raw;
  setcookie('vx_start_param', $raw, time()+86400*30, '/', '', isset($_SERVER['HTTPS']), true);
} else {
  // Allow clearing during debug / testing
  unset($_SESSION['vx_start_param']);
  setcookie('vx_start_param', '', time()-3600, '/', '', isset($_SERVER['HTTPS']), true);
}
echo json_encode(['ok'=>true,'start'=>$raw,'cleared'=>($raw==='')]);
