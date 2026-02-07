<?php
// core/vx_refqual.php
// Referral qualification + anti-abuse escrow for points.
// - "Qualified paid referral" requires min paid amount (default $10)
// - Cooldown (default 24h) before qualification
// - One device/IP heuristics: repeated ref_ip_hash / ref_ua_hash clusters can auto-flag
// - Admin can review flagged/blocked items (see adminka/trust.php)

declare(strict_types=1);
if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/vx_app_settings.php';
require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/referral_guard.php';

function vx_refqual_min_paid_usd(): float {
  $v = (float)vx_app_setting('ref_min_paid_usd', '10');
  if ($v < 0) $v = 0;
  if ($v > 100000) $v = 100000;
  return $v;
}

function vx_refqual_delay_hours(): int {
  $h = (int)vx_app_setting('ref_qualify_delay_hours', '24');
  if ($h < 0) $h = 0;
  if ($h > 168) $h = 168; // 7 days cap
  return $h;
}

function vx_refqual_strict_mode(): bool {
  return (bool)vx_app_setting('strict_ref_hardening', true);
}

function vx_refqual_schema_ensure($db): void {
  if (!$db) return;
  try {
    $db->query(
      "CREATE TABLE IF NOT EXISTS vx_ref_qualifications (\n"
      ."  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
      ."  referrer_uid INT NOT NULL,\n"
      ."  buyer_uid INT NOT NULL,\n"
      ."  tarif_id INT NOT NULL DEFAULT 0,\n"
      ."  usd_value DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
      ."  hold_points BIGINT NOT NULL DEFAULT 0,\n"
      ."  event_ts INT NOT NULL DEFAULT 0,\n"
      ."  qualify_after INT NOT NULL DEFAULT 0,\n"
      ."  qualified_at INT NOT NULL DEFAULT 0,\n"
      ."  status VARCHAR(16) NOT NULL DEFAULT 'pending',\n"
      ."  reason VARCHAR(190) NULL,\n"
      ."  ip_hash CHAR(64) NOT NULL DEFAULT '',\n"
      ."  ua_hash CHAR(64) NOT NULL DEFAULT '',\n"
      ."  meta_json MEDIUMTEXT NULL,\n"
      ."  PRIMARY KEY (id),\n"
      ."  UNIQUE KEY ux_buyer (buyer_uid),\n"
      ."  KEY ix_ref_status (referrer_uid, status),\n"
      ."  KEY ix_due (qualify_after, status)\n"
      .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
  } catch (Throwable $e) {}
}

function vx_refqual_flags_for_buyer($db, int $buyerUid, int $referrerUid): array {
  // Heuristics using already-captured ref_ip_hash / ref_ua_hash on db_users.
  // We cannot guarantee correctness (VPN/shared networks), so we FLAG first and let admin approve.
  $flags = [];
  try {
    $u = $db->query('SELECT ref_ip_hash, ref_ua_hash, reg FROM db_users WHERE id=? LIMIT 1', $buyerUid)->fetchArray();
    $ip = (string)($u['ref_ip_hash'] ?? '');
    $ua = (string)($u['ref_ua_hash'] ?? '');
    $reg = (int)($u['reg'] ?? 0);
    if ($reg > 0 && (time() - $reg) < 120) {
      $flags[] = 'fast_signup';
    }

    if ($ip !== '') {
      // If the same referrer has multiple referrals from the same ip hash, it's suspicious.
      $r1 = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE rid=? AND ref_ip_hash=?', $referrerUid, $ip)->fetchArray();
      $c1 = (int)($r1['c'] ?? 0);
      if ($c1 >= 2) $flags[] = 'ip_cluster_ref';

      // If this ip hash appears across many accounts, it may be a farm/VPN.
      $r2 = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE ref_ip_hash=?', $ip)->fetchArray();
      $c2 = (int)($r2['c'] ?? 0);
      if ($c2 >= 6) $flags[] = 'ip_cluster_global';
    }

    if ($ua !== '') {
      $r3 = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE rid=? AND ref_ua_hash=?', $referrerUid, $ua)->fetchArray();
      $c3 = (int)($r3['c'] ?? 0);
      if ($c3 >= 3) $flags[] = 'ua_cluster_ref';

      $r4 = $db->query('SELECT COUNT(*) AS c FROM db_users WHERE ref_ua_hash=?', $ua)->fetchArray();
      $c4 = (int)($r4['c'] ?? 0);
      if ($c4 >= 10) $flags[] = 'ua_cluster_global';
    }
  } catch (Throwable $e) {}

  return $flags;
}

/**
 * Called when a referred user does a first paid guardian activation.
 * Creates/updates a qualification record.
 */
function vx_refqual_record_paid_activation($db, int $buyerUid, int $referrerUid, float $usdValue, int $tarifId, int $holdPoints = 0): array {
  if (!$db || $buyerUid <= 0 || $referrerUid <= 0 || $buyerUid === $referrerUid) {
    return ['ok'=>false, 'status'=>'invalid'];
  }

  vx_refqual_schema_ensure($db);

  $minUsd = vx_refqual_min_paid_usd();
  $now = time();

  if ($usdValue < $minUsd) {
    // Record as blocked (does not qualify)
    try {
      $meta = ['buyer_uid'=>$buyerUid,'referrer_uid'=>$referrerUid,'tarif_id'=>$tarifId,'usd_value'=>$usdValue,'min_usd'=>$minUsd];
      $db->query(
        "INSERT INTO vx_ref_qualifications (referrer_uid,buyer_uid,tarif_id,usd_value,hold_points,event_ts,qualify_after,qualified_at,status,reason,ip_hash,ua_hash,meta_json)\n"
        ."VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)\n"
        ."ON DUPLICATE KEY UPDATE referrer_uid=VALUES(referrer_uid), tarif_id=VALUES(tarif_id), usd_value=VALUES(usd_value), hold_points=GREATEST(hold_points, VALUES(hold_points)), event_ts=VALUES(event_ts), status='blocked', reason=VALUES(reason), meta_json=VALUES(meta_json)",
        $referrerUid, $buyerUid, $tarifId, $usdValue, $holdPoints, $now, 0, 0, 'blocked', 'min_amount',
        (string)($db->query('SELECT ref_ip_hash FROM db_users WHERE id=? LIMIT 1', $buyerUid)->fetchArray()['ref_ip_hash'] ?? ''),
        (string)($db->query('SELECT ref_ua_hash FROM db_users WHERE id=? LIMIT 1', $buyerUid)->fetchArray()['ref_ua_hash'] ?? ''),
        json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
      );
    } catch (Throwable $e) {}
    return ['ok'=>false, 'status'=>'blocked', 'reason'=>'min_amount'];
  }

  $delay = vx_refqual_delay_hours() * 3600;
  $due = $now + $delay;

  // Heuristic flags
  $flags = [];
  try { $flags = vx_refqual_flags_for_buyer($db, $buyerUid, $referrerUid); } catch (Throwable $e) { $flags=[]; }
  $status = $flags ? 'flagged' : 'pending';
  $reason = $flags ? implode(',', $flags) : null;

  // Pull stored hashes from db_users (set at referral-apply time)
  $ipHash = '';
  $uaHash = '';
  try {
    $u = $db->query('SELECT ref_ip_hash, ref_ua_hash FROM db_users WHERE id=? LIMIT 1', $buyerUid)->fetchArray();
    $ipHash = (string)($u['ref_ip_hash'] ?? '');
    $uaHash = (string)($u['ref_ua_hash'] ?? '');
  } catch (Throwable $e) {}

  $meta = [
    'buyer_uid'=>$buyerUid,
    'referrer_uid'=>$referrerUid,
    'tarif_id'=>$tarifId,
    'usd_value'=>$usdValue,
    'min_usd'=>$minUsd,
    'delay_h'=>vx_refqual_delay_hours(),
    'flags'=>$flags,
    'strict'=>vx_refqual_strict_mode(),
  ];

  try {
    $db->query(
      "INSERT INTO vx_ref_qualifications (referrer_uid,buyer_uid,tarif_id,usd_value,hold_points,event_ts,qualify_after,qualified_at,status,reason,ip_hash,ua_hash,meta_json)\n"
      ."VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)\n"
      ."ON DUPLICATE KEY UPDATE referrer_uid=VALUES(referrer_uid), tarif_id=VALUES(tarif_id), usd_value=VALUES(usd_value), hold_points=GREATEST(hold_points, VALUES(hold_points)), event_ts=VALUES(event_ts), qualify_after=VALUES(qualify_after), status=VALUES(status), reason=VALUES(reason), ip_hash=VALUES(ip_hash), ua_hash=VALUES(ua_hash), meta_json=VALUES(meta_json)",
      $referrerUid, $buyerUid, $tarifId, $usdValue, $holdPoints, $now, $due, 0, $status, $reason, $ipHash, $uaHash,
      json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
    );
  } catch (Throwable $e) {
    return ['ok'=>false,'status'=>'error'];
  }

  return ['ok'=>true,'status'=>$status,'qualify_after'=>$due,'flags'=>$flags];
}

function vx_refqual_count_qualified($db, int $referrerUid): int {
  if (!$db || $referrerUid <= 0) return 0;
  vx_refqual_schema_ensure($db);
  try {
    $r = $db->query(
      "SELECT COUNT(*) AS c FROM vx_ref_qualifications WHERE referrer_uid=? AND status='qualified'",
      $referrerUid
    )->fetchArray();
    return (int)($r['c'] ?? 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function vx_refqual_process_due($db, int $limit = 20): int {
  if (!$db) return 0;
  vx_refqual_schema_ensure($db);
  $now = time();
  $n = 0;

  // Process a small batch globally to avoid requiring the referrer to log in.
  try {
    $q = $db->query(
      "SELECT id, referrer_uid, buyer_uid, hold_points, tarif_id, usd_value, status, qualify_after\n"
      ."FROM vx_ref_qualifications\n"
      ."WHERE status IN ('pending') AND qualify_after>0 AND qualify_after<=?\n"
      ."ORDER BY qualify_after ASC, id ASC\n"
      ."LIMIT ".$limit,
      $now
    );
    if ($q) {
      while ($row = $q->fetchArray()) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $ref = (int)($row['referrer_uid'] ?? 0);
        $buyer = (int)($row['buyer_uid'] ?? 0);
        $hold = (int)($row['hold_points'] ?? 0);
        $tarifId = (int)($row['tarif_id'] ?? 0);
        $usd = (float)($row['usd_value'] ?? 0);

        // Final check: referrer still valid & buyer still refers to them
        $okLink = false;
        try {
          $u = $db->query('SELECT rid FROM db_users WHERE id=? LIMIT 1', $buyer)->fetchArray();
          $okLink = ((int)($u['rid'] ?? 0) === $ref);
        } catch (Throwable $e) { $okLink = false; }

        if (!$okLink) {
          $db->query("UPDATE vx_ref_qualifications SET status='blocked', reason='link_changed' WHERE id=? LIMIT 1", $id);
          continue;
        }

        // Qualify
        $db->query("UPDATE vx_ref_qualifications SET status='qualified', qualified_at=? WHERE id=? AND status='pending' LIMIT 1", $now, $id);

        // Release held referral points (strict mode only)
        if ($hold > 0 && vx_refqual_strict_mode()) {
          try {
            $db->query('UPDATE db_users SET points_total = points_total + ?, points_spendable = points_spendable + ? WHERE id=?', $hold, $hold, $ref);
            // Best-effort ledger (ctx: ref_buy_vault_qualified)
            if (function_exists('vx_column_exists') && vx_column_exists($db, 'db_points_ledger', 'meta_json')) {
              $meta = ['buyer_uid'=>$buyer,'tarif_id'=>$tarifId,'usd_value'=>$usd,'released_after'=>'cooldown'];
              $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, ref_uid, tarif_id, usd_value, meta_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                $ref, $hold, 'ref_buy_vault_qualified', $buyer, $tarifId, $usd, json_encode($meta, JSON_UNESCAPED_SLASHES), $now
              );
            } else {
              $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, ref_uid, tarif_id, usd_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                $ref, $hold, 'ref_buy_vault_qualified', $buyer, $tarifId, $usd, $now
              );
            }
          } catch (Throwable $e) {}
        }

        // Notify referrer (in-app)
        try {
          $uk = 'ref_q_'.$buyer;
          $cta = '/user/affiliate';
          vx_notify_once($db, $ref, $uk, 'ref_paid', 'success', 'Referral qualified', 'Your referral is now qualified. Leaderboards + tiers updated.', [
            'buyer_uid'=>$buyer,
            'tarif_id'=>$tarifId,
            'usd_value'=>$usd,
            'cta'=>$cta,
          ]);
        } catch (Throwable $e) {}

        $n++;
      }
    }
  } catch (Throwable $e) {
    return $n;
  }

  return $n;
}
