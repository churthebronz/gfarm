<?php
// Paykassa SCI IPN handler — FINAL
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

header('Content-Type: text/plain; charset=utf-8');

session_start();
define('FastCore', true);

// Load config and helpers (ensure $db exists)
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/schema_ensure.php';
vx_schema_ensure($db);
require_once __DIR__ . '/core/idempotency.php';
require_once __DIR__ . '/core/referrals.php';

/**
 * Idempotent referral credit for Paykassa IPN:
 * - credits 5% to upline only if db_insert.ref_credited = 0
 * - flips ref_credited = 1 on success
 */
if (!function_exists('vx_referral_credit_once')) {
    
/**
 * Referral payout on every successful deposit.
 * Idempotent per deposit row via db_insert.ref_credited.
 */
/**
 * Referral payout on every successful deposit.
 * - Pays to money_b (locked / bonus) to reduce abuse.
 * - Idempotent per deposit row via db_insert.ref_credited.
 * - Optional  */
/**
 * Referral payout on every successful deposit.
 * - Pays to money_p (available immediately).
 * - Idempotent per deposit row via db_insert.ref_credited.
 */
function vx_referral_credit_once(db $db, int $depositId, int $buyerId, float $depositUsd, float $pct = 5.00): bool {
    $row = $db->query('SELECT ref_credited FROM db_insert WHERE id = ? LIMIT 1', $depositId)->fetchArray();
    if (!$row) return false;
    if ((int)($row['ref_credited'] ?? 0) === 1) return true;

    $u = $db->query('SELECT id, rid FROM db_users WHERE id = ? LIMIT 1', $buyerId)->fetchArray();
    if (!$u) return true;

    $rid = (int)($u['rid'] ?? 0);
    if ($rid <= 0 || $rid === $buyerId) {
        $db->query('UPDATE db_insert SET ref_credited=1 WHERE id=? LIMIT 1', $depositId);
        return true;
    }

    $refCash = round(max(0.0, $depositUsd) * ($pct / 100.0), 6);
    if ($refCash <= 0) {
        $db->query('UPDATE db_insert SET ref_credited=1 WHERE id=? LIMIT 1', $depositId);
        return true;
    }

    $db->beginTransaction();
    try {
        // Pay referrer in money_p (withdrawable) and track income.
        $db->query('UPDATE db_users SET money_p = money_p + ?, income = income + ? WHERE id = ?', $refCash, $refCash, $rid);

        // Referral earnings log (idempotent by deposit_id+rid)
        try {
            $pctUsed = $pct;
            $ts = time();
            $sec = (string)(getenv('REF_RECEIPT_SECRET') ?: '');
            if ($sec === '') { try { $sec = (string)($GLOBALS['config']->tg_webhook_secret ?? ''); } catch (Throwable $e) {} }
            $payload = 'VXREF|'.$depositId.'|'.$rid.'|'.$buyerId.'|'.number_format($depositUsd, 2, '.', '').'|'.number_format($refCash, 6, '.', '').'|'.$ts.'|'.number_format($pctUsed, 2, '.', '');
            $sig = substr(hash_hmac('sha256', $payload, $sec), 0, 18);
            $receipt = $payload.'|'.$sig;

            $db->query(
              'INSERT OR IGNORE INTO vx_ref_earnings (rid, buyer_id, deposit_id, deposit_usd, reward_usd, pct_used, receipt, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
              $rid, $buyerId, $depositId, $depositUsd, $refCash, $pctUsed, $receipt, $ts
            );
        } catch (Throwable $e) {}// Track how much this buyer has generated for their referrer
        $db->query('UPDATE db_users SET ref_to = ref_to + ? WHERE id = ?', $refCash, $buyerId);

        $db->query('UPDATE db_insert SET ref_credited=1 WHERE id=? LIMIT 1', $depositId);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[paykassa_referral] '.$e->getMessage());
        return false;
    }
}

}

// Autoload core classes (PaykassaSCI, db wrapper, etc.)
spl_autoload_register(function ($lfc) {
    $path = __DIR__ . '/core/' . $lfc . '.php';
    if (is_file($path)) require $path;
});

global $db, $config;

// ---- Helper: pay 5% cash to upline on a successful deposit (withdrawable) ----
if (!function_exists('vx_pay_ref_on_deposit')) {
    /**
     * Credits 5% of a successful deposit to the referrer (upline).
     * Relies on db_insert.status=1 idempotency (we only call after marking success).
     */
    function vx_pay_ref_on_deposit(db $db, int $buyerId, float $depositUsd, float $pct = 5.00, string $ctx = 'dep:paykassa'): bool {
        try {
            // Find upline
            $u = $db->query('SELECT id, rid FROM db_users WHERE id = ? LIMIT 1', $buyerId)->fetchArray();
            if (!$u) return false;
            $rid = (int)($u['rid'] ?? 0);
            if ($rid <= 0 || $rid === $buyerId) return true; // no referrer or self-ref

            $refCash = round($depositUsd * ($pct / 100), 2);
            if ($refCash <= 0) return true;

            // Credit upline immediately
            $db->query('UPDATE db_users SET money_p = money_p + ? WHERE id = ?', $refCash, $rid);

            // Audit
            $db->query(
                'INSERT INTO db_ref_earn (ref_uid, user_uid, usd, rate, ctx, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                $rid, $buyerId, $refCash, $pct, $ctx, time()
            );
            return true;
        } catch (Throwable $e) {
            error_log('[vx_pay_ref_on_deposit] '.$e->getMessage());
            return false;
        }
    }
}

try {
    $paykassa = new PaykassaSCI(
        $config->pkm_id ?? '',   // merchant id
        $config->pkm_pass ?? ''  // merchant password
    );

    // Required POST value
    $private_hash = $_POST['private_hash'] ?? '';
    $res = $paykassa->checkOrderIpn($private_hash);

    if (!empty($res['error'])) {
        echo 'error';
        exit;
    }

    // OK, extract
    $id          = (int)$res['data']['order_id'];   // local order id (db_insert.id)
    $amount      = (float)$res['data']['amount'];   // coin amount (or fiat per Paykassa)
    $currency    = (string)$res['data']['currency']; // e.g. USDT.TRC20 / BTC / etc
    $system      = (string)$res['data']['system'];   // e.g. Tether TRC20
    $timeNow     = time();

    // Resolve USD (site currency) via db_paysystem.pairs (fallback 1.0)
    $ps = $db->query('SELECT * FROM db_paysystem WHERE currency = ? LIMIT 1', strtoupper($currency))->fetchArray();
    $rate   = isset($ps['pairs']) ? (float)$ps['pairs'] : 1.0;
    $sumUSD = round($amount * $rate, 2);

    // Fetch pending deposit
    $ins = $db->query('SELECT * FROM db_insert WHERE id = ? LIMIT 1', $id)->fetchArray();
    if (!$ins) { echo $id . '|error'; exit; }

    // Already processed? (prevents double payouts on retries)
    if ((int)$ins['status'] !== 0) {
        echo $id . '|success';
        exit;
    }

    $uid = (int)$ins['uid'];
    // --- Idempotent credit (atomic) ---
    // Protect against Paykassa retries/races: claim db_insert once (status 0 -> 1).
    $db->beginTransaction();

    $ins = $db->query('SELECT id, uid, sum, status, rid FROM db_insert WHERE id=? LIMIT 1 FOR UPDATE', $id)->fetchArray();
    if (!$ins || (int)$ins['id'] !== (int)$id) {
        $db->rollBack();
        echo 'err'; exit;
    }

    if ((int)$ins['status'] !== 0) {
        $db->commit();
        echo $id . '|success'; exit;
    }

    // Mark processed first (status gate)
    $db->query(
        'UPDATE db_insert SET status = ?, sum = ?, sum_x = ?, `end` = ?, `sys` = ? WHERE id = ? AND status = 0',
        1, $sumUSD, $sumUSD, $timeNow, $currency, $id
    );

    $rc = (int)($db->query('SELECT ROW_COUNT() AS rc')->fetchArray()['rc'] ?? 0);
    if ($rc <= 0) {
        $db->commit();
        echo $id . '|success'; exit;
    }

    $uid = (int)$ins['uid'];

    // Credit user (money_p only — spendable)
    $db->query(
        'UPDATE db_users SET sum_in = sum_in + ?, money_p = money_p + ? WHERE id = ?',
        $sumUSD, $sumUSD, $uid
    );

    // Stats
    $db->query('UPDATE db_stats SET inserts = inserts + ? WHERE id = 1', $sumUSD);

    // Referral payout: every deposit (idempotent per deposit id) — pays to money_p (available immediately)
    $refPct = 0.0;
    try { $refPct = (float)($config->ref_percent ?? 5.0); } catch (Throwable $e) { $refPct = 5.0; }
    if ($refPct > 0) {
        vx_referral_credit_once($db, $id, $uid, $sumUSD, $refPct);
    }

    $db->commit();
// Required response for Paykassa
    echo $id . '|success';
} catch (Throwable $e) {
    error_log('[paykassa_ipn] '.$e->getMessage());
    echo 'error';
    exit;
}
