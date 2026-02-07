<?php
if (!defined('FastCore')) { exit('Oops!'); }

/**
 * Fake payout ticker (optional / cosmetic).
 * Safe-guarded: no-op in Telegram WebView and when data is missing.
 */

// Turn it off completely by setting this to true.
if (defined('DISABLE_FAKE_PAYOUTS') && DISABLE_FAKE_PAYOUTS) { return; }

// Skip inside Telegram Mini-App to avoid noise there.
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (isset($_SERVER['HTTP_X_TELEGRAM_INIT_DATA']) || stripos($ua, 'Telegram') !== false) {
    return;
}

// Must have DB
if (!isset($db) || !($db instanceof db)) { return; }

// 20% chance to run (same behavior as original rand(1,5) == 1)
if (mt_rand(1, 5) !== 1) { return; }

// Pick a random “role 3” user safely
$user_row = $db->query(
    'SELECT id, login FROM db_users WHERE role = ? ORDER BY RAND() LIMIT 1',
    3
)->fetchArray();

if (empty($user_row) || !isset($user_row['id'], $user_row['login'])) {
    // No suitable user, bail quietly
    return;
}

$f_uid   = (int)$user_row['id'];
$f_login = (string)$user_row['login'];

// Amount generator
if (mt_rand(1, 3) === 1) {
    // Irregular amounts
    if (mt_rand(1, 4) === 1) {
        $random_sum = (float)mt_rand(1, 15);
    } else {
        $base = (float)mt_rand(1, 5);
        $random_sum = $base + (mt_rand(10, 99) / 100.0); // e.g., 3.57
    }
    $f_sum = round($random_sum, 2);
} else {
    // Weighted small “gift” amounts
    $gift_cnt = [1 => 0.3, 2 => 0.3, 3 => 0.5, 4 => 1.0, 5 => 0.1, 6 => 0.2, 7 => 1.0];
    $rand = mt_rand(1, 100);
    if      ($rand <= 35) { $i = 1; }
    elseif  ($rand <= 45) { $i = 2; }
    elseif  ($rand <= 55) { $i = 3; }
    elseif  ($rand <= 60) { $i = 4; }
    elseif  ($rand <= 84) { $i = 5; }
    elseif  ($rand <= 90) { $i = 6; }
    else                  { $i = 7; }

    $f_sum = isset($gift_cnt[$i]) ? (float)$gift_cnt[$i] : 0.3;
}
$f_sum = round($f_sum, 2);
if ($f_sum <= 0) { return; }

// Rate limit per user (match your code: 12 hours)
$todayLimitHours = 12;
$last = $db->query(
    'SELECT `add` FROM db_payout WHERE uid = ? ORDER BY id DESC LIMIT 1',
    $f_uid
)->fetchArray();
$lastAdd = isset($last['add']) ? (int)$last['add'] : 0;

$now  = time();
$span = 3600 * $todayLimitHours;
if ($lastAdd > 0 && $now < ($lastAdd + $span)) {
    // Too soon to add another fake payout
    return;
}

// Insert payout (status 3 = success), sys “777” like original
$del = $now + 60 * 60 * 24 * 15;
$db->query(
    'INSERT INTO db_payout (uid, login, sum, `sys`, `add`, `del`, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    $f_uid, $f_login, $f_sum, '777', $now, $del, 3
);

// Update user’s total paid out
$db->query(
    'UPDATE db_users SET sum_out = sum_out + ? WHERE id = ?',
    $f_sum, $f_uid
);

// Update global stats
$db->query(
    'UPDATE db_stats SET payments = payments + ? WHERE id = 1',
    $f_sum
);

// done
