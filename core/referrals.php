<?php
// /core/referrals.php
if (!defined('FastCore')) exit('Opss!');

if (!function_exists('ref_credit_on_deposit')) {
    /**
     * Credit referrer when $user_uid makes a confirmed deposit of $usd.
     * $rate is percent (e.g. 5.0 for 5%).
     */
    function ref_credit_on_deposit(db $db, int $user_uid, float $usd, float $rate = 5.0, string $ctx = 'deposit'): void {
        if ($usd <= 0) return;

        $u = $db->query('SELECT id, rid FROM db_users WHERE id = ? LIMIT 1', $user_uid)->fetchArray();
        if (!$u) return;

        $ref_uid = (int)$u['rid'];
        if ($ref_uid <= 0 || $ref_uid === $user_uid) return;

        $bonus = round($usd * ($rate / 100), 2);
        if ($bonus <= 0) return;

        // Credit referrer balance and stats
        $db->query(
            'UPDATE db_users SET money_p = money_p + ?, income = income + ?, ref_to = ref_to + ? WHERE id = ?',
            $bonus, $bonus, $bonus, $ref_uid
        );

        // Log referral earning
        $db->query(
            'INSERT INTO db_ref_earn (ref_uid, user_uid, usd, rate, ctx, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            $ref_uid, $user_uid, $usd, $rate, $ctx, time()
        );
    }
}

/**
 * Ensure a shareable referral code exists for a user; returns the code.
 */
if (!function_exists('ensure_ref_code')) {
    function ensure_ref_code(db $db, int $uid): string {
        $row = $db->query('SELECT ref_code FROM db_users WHERE id = ? LIMIT 1', $uid)->fetchArray();
        if (!empty($row) && !empty($row['ref_code'])) return (string)$row['ref_code'];

        do {
            $code = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
            $exists = $db->query('SELECT id FROM db_users WHERE ref_code = ? LIMIT 1', $code)->fetchArray();
        } while ($exists);

        $db->query('UPDATE db_users SET ref_code = ? WHERE id = ?', $code, $uid);
        return $code;
    }
}
