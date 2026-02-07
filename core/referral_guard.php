<?php
declare(strict_types=1);
if (!defined('FastCore')) { exit('Opss!'); }

require_once __DIR__ . '/vx_app_settings.php';
require_once __DIR__ . '/events_log.php';

function vx_hash(string $s): string { return hash('sha256', $s); }

function vx_client_ip(): string {
  $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
  if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
  return (string)$ip;
}
function vx_user_agent(): string { return (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); }

function vx_extract_ref_code(string $startParam): string {
  $p = trim((string)$startParam);
  if ($p === '') return '';
  $low = strtolower($p);
  if (in_array($low, ['launch','webauth','test','open','app'], true)) return '';
  if (str_starts_with($low, 'rc_')) $p = substr($p, 3);
  // strip everything but alnum
  $p = preg_replace('/[^A-Za-z0-9]/', '', (string)$p) ?? '';
  if ($p === '' || strlen($p) < 4 || strlen($p) > 32) return '';
  return $p;
}

function vx_log_event($db, int $uid, string $event, array $meta = []): void {
  // Use schema-adaptive logger (preferred schema uses user_id/event_type, legacy uses uid/event).
  try { vx_events_log($db, (string)$event, (int)$uid, $meta); } catch (Throwable $e) {}
}

/**
 * Apply referral to a user if not already set/locked.
 * Extra hardening:
 * - Only allow within first hour of account creation
 * - No self-ref
 * - Block if user already has a successful deposit
 * - Rate-limit by IP hash (per-ref and global)
 */
function vx_apply_referral_guard($db, int $uid, string $startParam): array {
  $code = vx_extract_ref_code($startParam);
  if ($code === '') return [false, 'no_ref_code', 0];

  $ref = $db->query('SELECT id FROM db_users WHERE ref_code=? LIMIT 1', $code)->fetchArray();
  $rid = (int)($ref['id'] ?? 0);
  if ($rid <= 0) return [false, 'ref_not_found', 0];
  if ($rid === $uid) return [false, 'self_ref', 0];

  $u = $db->query('SELECT id, rid, rid_lock, rid_set_at, reg FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
  if (!$u || empty($u['id'])) return [false, 'user_missing', 0];

  $curRid = (int)($u['rid'] ?? 0);
  $locked = (int)($u['rid_lock'] ?? 0);
  $ridSetAt = (int)($u['rid_set_at'] ?? 0);
  $reg = (int)($u['reg'] ?? 0);
  $now = time();

  if ($locked === 1 || $curRid > 0 || $ridSetAt > 0) return [false, 'already_set', $curRid];

  // Admin-tunable strict mode: when OFF, allow a wider window and skip IP rate-limits.
  $strict = (bool)vx_app_setting('strict_ref_hardening', true);
  $windowSeconds = (int)vx_app_setting('ref_window_seconds', $strict ? 3600 : 86400);

  // only allow setting referral within window after registration
  if ($reg > 0 && ($now - $reg) > $windowSeconds) return [false, 'window_expired', 0];

  // block if invitee already has a successful deposit (status=1)
  try {
    $dep = $db->query('SELECT id FROM db_insert WHERE uid=? AND status=1 LIMIT 1', $uid)->fetchArray();
    if ($dep && !empty($dep['id'])) return [false, 'has_deposit', 0];
  } catch (Throwable $e) {
    // if db_insert missing, ignore
  }

  $ipHash = vx_hash(vx_client_ip());
  $uaHash = vx_hash(vx_user_agent());

  // rate limits (strict only)
  if (!$strict) {
    // Apply + lock atomically best-effort
    try {
      $db->query(
        'UPDATE db_users SET rid=?, rid_set_at=?, rid_lock=1, ref_start_param=?, ref_ip_hash=?, ref_ua_hash=? WHERE id=? AND (rid IS NULL OR rid=0) AND rid_lock=0 AND rid_set_at=0',
        $rid, $now, $code, $ipHash, $uaHash, $uid
      );
    } catch (Throwable $e) {
      return [false, 'update_failed', $rid];
    }

    $u2 = $db->query('SELECT rid, rid_lock FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
    $finalRid = (int)($u2['rid'] ?? 0);
    $finalLock = (int)($u2['rid_lock'] ?? 0);
    if ($finalRid !== $rid || $finalLock !== 1) return [false, 'write_failed', $rid];
    return [true, 'applied', $rid];
  }

  // strict mode: max 5 per ref per hour from same ip
  $window = 3600;
  $maxPerRefPerHour = 5;
  try {
    $cntRow = $db->query(
      'SELECT COUNT(1) AS c FROM db_users WHERE rid=? AND rid_set_at>? AND ref_ip_hash=?',
      $rid, ($now - $window), $ipHash
    )->fetchArray();
    if ((int)($cntRow['c'] ?? 0) >= $maxPerRefPerHour) return [false, 'rate_limited_ip_ref', $rid];
  } catch (Throwable $e) {}

  // global rate limit: max 12 new referrals per hour from same ip (any ref)
  $maxGlobalPerHour = 12;
  try {
    $cntRow2 = $db->query(
      'SELECT COUNT(1) AS c FROM db_users WHERE rid_set_at>? AND ref_ip_hash=?',
      ($now - $window), $ipHash
    )->fetchArray();
    if ((int)($cntRow2['c'] ?? 0) >= $maxGlobalPerHour) return [false, 'rate_limited_ip_global', 0];
  } catch (Throwable $e) {}

  // Apply + lock atomically best-effort
  try {
    $db->query(
      'UPDATE db_users SET rid=?, rid_set_at=?, rid_lock=1, ref_start_param=?, ref_ip_hash=?, ref_ua_hash=? WHERE id=? AND (rid IS NULL OR rid=0) AND rid_lock=0 AND rid_set_at=0',
      $rid, $now, $code, $ipHash, $uaHash, $uid
    );
  } catch (Throwable $e) {
    return [false, 'update_failed', $rid];
  }

  $u2 = $db->query('SELECT rid, rid_lock FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
  $finalRid = (int)($u2['rid'] ?? 0);
  $finalLock = (int)($u2['rid_lock'] ?? 0);
  if ($finalRid !== $rid || $finalLock !== 1) return [false, 'write_failed', $rid];

  return [true, 'applied', $rid];
}
