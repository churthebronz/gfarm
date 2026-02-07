<?php
if (!defined('FastCore')) { define('FastCore', true); }
if (!function_exists('vx_send_security_headers')) {
  function vx_send_security_headers(?string $nonce = null): void {
    if (headers_sent()) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
             (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
             (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');

    if ($https) {
      header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    $cdn = "https://cdnjs.cloudflare.com";
    $tgCdn = "https://*.t.me https://*.telegram.org https://*.cdn-telegram.org https://t.me https://telegram.org https://cdn.telegram.org";
    $nonceDir = $nonce ? (" 'nonce-" . $nonce . "'") : "";
    $csp = implode('; ', [
  "default-src 'self' $tgCdn $cdn https://telegram.org",
  "img-src 'self' data: $tgCdn https://telegram.org",
  "script-src 'self' https://telegram.org".$nonceDir,
  "style-src 'self' $cdn 'unsafe-inline' https://fonts.googleapis.com",
  "font-src 'self' $cdn data: https://fonts.gstatic.com",
  "connect-src 'self'",
  "media-src 'self' blob:",
  "frame-ancestors 'self'"
]);
    header("Content-Security-Policy: $csp");
  }
}