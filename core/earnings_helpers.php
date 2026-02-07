<?php
// File: /core/earnings_helpers.php
// Earnings accrual + claim helpers for vaults (db_store + db_tarif) and accumulator (db_earnings).

if (!defined('FastCore')) define('FastCore', true);

// Guardian chains (crossbreed + VP/LP accrual)
require_once __DIR__ . '/vx_guardians.php';

/**
 * Total per-second accrual rate for a user across active vaults.
 * Model: daily = price * (speed/100). perSecond = daily / 86400.
 */
function vx_rate_per_second($db, int $uid): float {
    if ($uid <= 0 || !$db) return 0.0;
    $now = time();
    $rate = 0.0;
    try {
        $q = $db->query(
            "SELECT s.tarif, s.speed, s.status, s.`end`, t.price
             FROM db_store s
             LEFT JOIN db_tarif t ON t.id = s.tarif
             WHERE s.uid = ? AND s.status = 1 AND s.`end` > ?",
            [$uid, $now]
        );
        if ($q) {
            while ($r = $q->fetchArray()) {
                $price = (float)($r['price'] ?? 0);
                // If price is missing, fall back to stored hashpower as "entry" amount.
                if ($price <= 0) {
                    $hp = 0.0;
                    try {
                        $hpRow = $db->query('SELECT hashpower FROM db_store WHERE uid=? AND tarif=? LIMIT 1', [$uid, (int)$r['tarif']])->fetchArray();
                        $hp = (float)($hpRow['hashpower'] ?? 0);
                    } catch (Throwable $e) {}
                    $price = $hp;
                }
                $speed = (float)($r['speed'] ?? 0);
                if ($price > 0 && $speed > 0) {
                    $daily = $price * ($speed / 100.0);
                    $rate += ($daily / 86400.0);
                }
            }
        }
    } catch (Throwable $e) {
        return 0.0;
    }
    return (float)$rate;
}

/**
 * Recalculate and persist pending earnings for a user.
 * Returns array: ['pending'=>float,'updated_at'=>int,'per_second'=>float]
 */
function vx_earnings_touch($db, int $uid): array {
    if ($uid <= 0 || !$db) return ['pending'=>0.0,'updated_at'=>0,'per_second'=>0.0];
    $now = time();

    // Always keep guardian state machine up to date (matured/codex)
    try { if (function_exists('vx_guardians_touch_user')) vx_guardians_touch_user($db, $uid, $now); } catch (Throwable $e) {}

    // Accrue VP/LP alongside yield (fail-soft)
    try { if (function_exists('vx_guardians_accrue_points')) vx_guardians_accrue_points($db, $uid, $now); } catch (Throwable $e) {}

    // Ensure accumulator row exists
    try {
        $row = $db->query('SELECT pending, updated_at FROM db_earnings WHERE uid = ? LIMIT 1', [$uid])->fetchArray();
        if (!$row) {
            $db->query('INSERT INTO db_earnings (uid, pending, updated_at) VALUES (?, 0.00000000, ?)', [$uid, $now]);
            $row = ['pending'=>0.0,'updated_at'=>$now];
        }
    } catch (Throwable $e) {
        // If db_earnings missing or broken, degrade gracefully.
        $row = ['pending'=>0.0,'updated_at'=>$now];
    }

    $pending = (float)($row['pending'] ?? 0);
    $lastUpd = (int)($row['updated_at'] ?? 0);
    if ($lastUpd <= 0) $lastUpd = $now;

    // Accrue since last update based on each vault's own `last` timestamp.
    $accrued = 0.0;
    $perSecond = 0.0;
    try {
        $q = $db->query(
            "SELECT s.id, s.tarif, s.speed, s.hashpower, s.status, s.`end`, s.`last`, t.price
             FROM db_store s
             LEFT JOIN db_tarif t ON t.id = s.tarif
             WHERE s.uid = ? AND s.status = 1",
            [$uid]
        );
        if ($q) {
            while ($v = $q->fetchArray()) {
                $end = (int)($v['end'] ?? 0);
                $last = (int)($v['last'] ?? 0);
                if ($last <= 0) $last = (int)($v['add'] ?? $now);

                // Only accrue up to end time
                $tTo = ($end > 0) ? min($now, $end) : $now;
                if ($tTo <= $last) {
                    // Still count rate if active
                    $price = (float)($v['price'] ?? 0);
                    if ($price <= 0) $price = (float)($v['hashpower'] ?? 0);
                    $speed = (float)($v['speed'] ?? 0);
                    if ($price > 0 && $speed > 0 && ($end <= 0 || $end > $now)) {
                        $daily = $price * ($speed / 100.0);
                        $perSecond += $daily / 86400.0;
                    }
                    continue;
                }

                $elapsed = $tTo - $last;
                if ($elapsed < 1) $elapsed = 0;

                $price = (float)($v['price'] ?? 0);
                if ($price <= 0) $price = (float)($v['hashpower'] ?? 0);
                $speed = (float)($v['speed'] ?? 0);
                if ($price > 0 && $speed > 0) {
                    $daily = $price * ($speed / 100.0);
                    $rate = $daily / 86400.0;
                    // Rate only counts while before end
                    if ($end <= 0 || $end > $now) $perSecond += $rate;
                    $accrued += $rate * $elapsed;
                }

                // If vault ended, mark as finished
                if ($end > 0 && $end <= $now) {
                    try { $db->query('UPDATE db_store SET status = 2 WHERE id = ?', [(int)$v['id']]); } catch (Throwable $e) {}
                }

                // Move vault last pointer forward to avoid double-counting next touch
                try { $db->query('UPDATE db_store SET `last` = ? WHERE id = ?', [$tTo, (int)$v['id']]); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    if ($accrued > 0) {
        $pending = $pending + $accrued;
        try {
            $db->query('UPDATE db_earnings SET pending = ?, updated_at = ? WHERE uid = ?', [$pending, $now, $uid]);
        } catch (Throwable $e) {
            // ignore
        }
    } else {
        // still bump updated_at occasionally to avoid huge deltas later
        if (($now - $lastUpd) > 600) {
            try { $db->query('UPDATE db_earnings SET updated_at = ? WHERE uid = ?', [$now, $uid]); } catch (Throwable $e) {}
        }
    }

    // Also accrue VP/LP (scaled by crossbreed). This is independent from yield claims.
    $pts = ['vp_added'=>0,'lp_added'=>0];
    try { if (function_exists('vx_guardians_accrue_points')) { $pts = vx_guardians_accrue_points($db, $uid, $now); } } catch (Throwable $e) {}

    return [
        'pending'    => (float)$pending,
        'updated_at' => (int)$now,
        'per_second' => (float)$perSecond,
        'vp_added'   => (int)($pts['vp_added'] ?? 0),
        'lp_added'   => (int)($pts['lp_added'] ?? 0),
    ];
}

/**
 * Claim pending earnings → credits db_users.bank and resets db_earnings.pending.
 */
function vx_earnings_claim($db, int $uid): array {
    if ($uid <= 0 || !$db) return ['ok'=>false,'msg'=>'Unauthorized'];

    // First, touch to bring pending up to date
    $st = vx_earnings_touch($db, $uid);
    $pending = (float)($st['pending'] ?? 0);
    if ($pending <= 0.00000001) {
        return ['ok'=>false,'msg'=>'Nothing to collect.'];
    }

    // Atomically move pending -> bank and zero the accumulator.
    // Prevents double-claim from concurrent requests/tabs.
    try {
        $db->query(
            "UPDATE db_users u JOIN db_earnings e ON e.uid=u.id\n"
            ."SET u.bank = u.bank + e.pending, e.pending = 0.00000000, e.updated_at = ?\n"
            ."WHERE u.id = ? AND e.pending > 0.00000001",
            time(), $uid
        );
        $aff = (method_exists($db,'affectedRows') ? (int)$db->affectedRows() : 1);
        if ($aff <= 0) {
            return ['ok'=>false,'msg'=>'Nothing to collect.'];
        }
    } catch (Throwable $e) {
        return ['ok'=>false,'msg'=>'Could not credit balance.'];
    }

    // Yield ledger (audit trail) — enables the "My Earnings" page and ops reconciliation.
    try {
        require_once __DIR__ . '/schema_ensure.php';
        if (function_exists('vx_schema_ensure')) { vx_schema_ensure($db); }
        $meta = json_encode([
            'ts' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $db->query(
            "INSERT INTO vx_yield_ledger (uid, guardian_id, amount_usd, ctx, meta_json, created_at) VALUES (?, NULL, ?, 'claim', ?, ?)",
            $uid, (float)$pending, $meta, time()
        );
    } catch (Throwable $e) {}

    // Activity log for Harvest Rush stats (production: no placeholders)
    try {
        require_once __DIR__ . '/vx_activity.php';
        require_once __DIR__ . '/seasons.php';
        $sx = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
        $sid = (int)($sx['id'] ?? 0);
        vx_activity_log($db, $uid, 'claim', (float)$pending, ['season_id'=>$sid,'ts'=>time()]);
    } catch (Throwable $e) {}

    // Claim streak (retention): tick only on successful claim.
    $claimStreak = 0;
    try {
        require_once __DIR__ . '/vx_retention.php';
        $cs = vx_claim_streak_tick($db, $uid);
        $claimStreak = (int)($cs['streak'] ?? 0);
        // Log a lightweight event so the user can see "why numbers changed" in the activity panel.
        if (!empty($cs['changed'])) {
            require_once __DIR__ . '/vx_activity.php';
            vx_activity_log($db, $uid, 'claim_streak', (float)($cs['streak'] ?? 0), ['day'=>$cs['today'] ?? gmdate('Y-m-d')]);
        }
    } catch (Throwable $e) {}

    // Achievements (small VP/LP bonuses) — fail-soft.
    try {
        require_once __DIR__ . '/vx_achievements.php';
        if (function_exists('vx_achievements_on_claim')) {
            vx_achievements_on_claim($db, $uid, (float)$pending, (int)$claimStreak);
        }
    } catch (Throwable $e) {}

    return ['ok'=>true,'claimed'=>(float)$pending,'claim_streak'=>$claimStreak,'msg'=>'Yield collected successfully!'];
}
