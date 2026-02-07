<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
$cands = [
  __DIR__ . '/../error_log',
  __DIR__ . '/../php_errors.log',
  $_SERVER['DOCUMENT_ROOT'] . '/error_log',
  $_SERVER['DOCUMENT_ROOT'] . '/php_errors.log',
  ini_get('error_log') ?: null,
];
$seen = [];
foreach ($cands as $f) {
  if (!$f) continue;
  $f = realpath($f) ?: $f;
  if (isset($seen[$f]) || !is_file($f)) continue;
  $seen[$f] = true;
  echo "==> $f\n";
  $lines = @file($f);
  if (!$lines) { echo "(no read)\n\n"; continue; }
  $tail = array_slice($lines, -200);
  echo implode('', $tail), "\n";
}
if (!$seen) echo "No error logs found next to docroot.\n";
