<?php
declare(strict_types=1);
if (!defined('FastCore')) { exit('Oops!'); }

// Deposit is handled by the existing insert flow.
// Keep query params (e.g. ?amount=10000) for future prefills.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$to = '/user/insert' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $to);
exit;
