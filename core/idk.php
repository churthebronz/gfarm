<?php
declare(strict_types=1);
// File: /core/idk.php
// Purpose: Session-backed one-time tokens for sensitive actions (dedupe/double-submit protection).
//
// This project aims to be production-ready without cron jobs. For these flows,
// session storage is sufficient and keeps the DB clean.

if (!defined('FastCore')) { define('FastCore', true); }

/**
 * Issue a one-time token for a given scope.
 *
 * @param string $scope Logical namespace (e.g. 'season_pass').
 * @param int $ttlSec Token expiry in seconds (default: 15 minutes).
 */
function vx_issue_idk(string $scope, int $ttlSec = 900): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $scope = trim($scope);
    if ($scope === '') { $scope = 'default'; }

    $ttl = max(60, (int)$ttlSec);
    $exp = time() + $ttl;

    try {
        $raw = random_bytes(18);
        $tok = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    } catch (Throwable $e) {
        $tok = bin2hex((string)microtime(true) . ':' . mt_rand());
    }

    if (!isset($_SESSION['vx_idk']) || !is_array($_SESSION['vx_idk'])) {
        $_SESSION['vx_idk'] = [];
    }
    if (!isset($_SESSION['vx_idk'][$scope]) || !is_array($_SESSION['vx_idk'][$scope])) {
        $_SESSION['vx_idk'][$scope] = [];
    }

    // Opportunistic cleanup (no cron): remove expired tokens for this scope.
    foreach ($_SESSION['vx_idk'][$scope] as $k => $v) {
        if (!is_array($v)) { unset($_SESSION['vx_idk'][$scope][$k]); continue; }
        $e = (int)($v['exp'] ?? 0);
        if ($e > 0 && $e < time()) { unset($_SESSION['vx_idk'][$scope][$k]); }
    }

    $_SESSION['vx_idk'][$scope][$tok] = ['exp' => $exp];
    return $tok;
}

/**
 * Consume a token (one-time). Returns true if valid.
 */
function vx_consume_idk(string $scope, string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $scope = trim($scope);
    $token = trim($token);
    if ($scope === '' || $token === '') return false;

    $bag = $_SESSION['vx_idk'][$scope] ?? null;
    if (!is_array($bag)) return false;
    $row = $bag[$token] ?? null;
    if (!is_array($row)) return false;

    $exp = (int)($row['exp'] ?? 0);
    unset($_SESSION['vx_idk'][$scope][$token]);
    if ($exp > 0 && $exp < time()) return false;
    return true;
}
