<?php
// File: /core/rate_limiter.php
// Purpose: Lightweight per-IP rate limiter (no extensions required).
// Storage: file in sys_get_temp_dir() so it works across PHP-FPM workers.

if (!defined('FastCore')) { define('FastCore', true); }

/**
 * Rate limit a key for the caller.
 *
 * @param string $bucket   A short name, e.g. 'page', 'api', 'login'
 * @param int    $maxHits  Max hits within window
 * @param int    $windowSec Window length in seconds
 * @return array{ok:bool, retry_after:int}
 */
function vx_rate_limit(string $bucket, int $maxHits, int $windowSec): array {
    $bucket = preg_replace('~[^a-zA-Z0-9_\-]~', '', $bucket);
    if ($bucket === '') $bucket = 'default';

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // If XFF has a list, take the first.
    if (is_string($ip) && strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    $now = time();
    $winStart = $now - max(1, $windowSec);

    $id = hash('sha256', $bucket . '|' . $ip);
    $path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'vx_rl_' . $id . '.json';

    $hits = [];
    $fp = @fopen($path, 'c+');
    if ($fp) {
        @flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['hits']) && is_array($data['hits'])) {
                $hits = $data['hits'];
            }
        }

        // prune
        $hits = array_values(array_filter($hits, function($t) use ($winStart){
            return is_int($t) && $t >= $winStart;
        }));

        if (count($hits) >= $maxHits) {
            $oldest = (int)min($hits);
            $retryAfter = max(1, ($oldest + $windowSec) - $now);
            // write back pruned list
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['hits' => $hits]));
            fflush($fp);
            @flock($fp, LOCK_UN);
            fclose($fp);
            return ['ok' => false, 'retry_after' => $retryAfter];
        }

        $hits[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(['hits' => $hits]));
        fflush($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        return ['ok' => true, 'retry_after' => 0];
    }

    // If we can't store, don't block (fail-open).
    return ['ok' => true, 'retry_after' => 0];
}

function vx_rate_limit_or_429(string $bucket, int $maxHits, int $windowSec, bool $isApi = false): void {
    $r = vx_rate_limit($bucket, $maxHits, $windowSec);
    if ($r['ok']) return;

    http_response_code(429);
    header('Retry-After: ' . (int)$r['retry_after']);

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'rate_limited',
            'retry_after' => (int)$r['retry_after'],
        ]);
        exit;
    }

    // Minimal HTML (no theme dependency)
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Slow down</title>'
       . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#060816;color:#eef2ff;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:18px}'
       . '.c{max-width:520px;border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:16px;background:rgba(11,16,36,.92)}'
       . '.m{color:rgba(168,178,209,.9);margin-top:8px}</style></head><body>'
       . '<div class="c"><div style="font-weight:900;font-size:18px">Too many requests</div>'
       . '<div class="m">Please try again in ' . (int)$r['retry_after'] . 's.</div></div></body></html>';
    exit;
}
