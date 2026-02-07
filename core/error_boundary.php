<?php
// File: /core/error_boundary.php
// Purpose: Consistent, production-safe error boundary for page rendering.

if (!defined('FastCore')) { define('FastCore', true); }

function vx_is_api_request(): bool {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, '/api/') === 0) return true;
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return (stripos($accept, 'application/json') !== false);
}

function vx_error_response(string $publicMessage = 'Something went wrong', int $status = 500): void {
    http_response_code($status);
    if (vx_is_api_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $publicMessage], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Minimal safe HTML (no template dependencies)
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>GreenFarm</title>'
       . '<style>body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#060816;color:#eef2ff;}
       .c{max-width:720px;margin:40px auto;padding:0 14px}
       .card{border:1px solid rgba(255,255,255,.12);border-radius:18px;background:rgba(15,23,42,.7);padding:16px;}
       .t{font-weight:900;font-size:18px}
       .m{color:rgba(168,178,209,.9);margin-top:8px;line-height:1.5}
       .a{margin-top:12px;display:inline-block;text-decoration:none;color:#001014;background:linear-gradient(135deg,#00ffe0,#58a6ff);padding:10px 12px;border-radius:12px;font-weight:900}
       </style></head><body>'
       . '<div class="c"><div class="card"><div class="t">We hit a problem</div><div class="m">'
       . htmlspecialchars($publicMessage, ENT_QUOTES, 'UTF-8')
       . '</div><a class="a" href="/user/dashboard">Back to dashboard</a></div></div></body></html>';
    exit;
}

/**
 * Run a callable inside an error boundary.
 */
/**
 * Error boundary wrapper.
 *
 * Supports both call styles:
 *   vx_with_error_boundary(function(){ ... }, 'Page error');
 *   vx_with_error_boundary('Page error', function(){ ... });
 */
function vx_with_error_boundary($a, $b = null): void {
    $fn = null;
    $publicMessage = 'Something went wrong';

    if (is_callable($a)) {
        $fn = $a;
        if (is_string($b) && $b !== '') $publicMessage = $b;
    } else {
        if (is_string($a) && $a !== '') $publicMessage = $a;
        if (is_callable($b)) $fn = $b;
    }

    if (!is_callable($fn)) {
        // Developer misuse: still fail safely in prod.
        error_log('[VX_BOUNDARY] vx_with_error_boundary called without a callable');
        vx_error_response($publicMessage, 500);
        return;
    }

    try {
        $fn();
    } catch (Throwable $e) {
        // Log full details; show generic message in prod
        error_log('[VX_BOUNDARY] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        vx_error_response($publicMessage, 500);
    }
}
