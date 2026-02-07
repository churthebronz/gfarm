<?php 
if (!defined('FastCore')) { exit('Opss! 😱'); }

global $db, $uid, $login;

/* Ensure we have session-derived helpers */
$uid   = isset($uid)   ? (int)$uid   : (int)($_SESSION['uid']   ?? 0);
$login = isset($login) ? $login      : (string)($_SESSION['login'] ?? '');

if ($uid <= 0) {
    echo '<div class="alert alert-warning text-center">Please sign in to view bonuses.</div>';
    return;
}

# Заголовок
$opt['title'] = 'Bonuses';

# Bonus Configuration
$telegramBonusAmount = 5.00; // Telegram bonus amount set to $5 USD
$telegramLink = 'https://t.me/cashfarmfun'; // Telegram group link
$depositBonuses = [
    1 => ['amount' => 0.10, 'min_deposit' => 5.00,  'label' => '10% First Deposit Bonus'],
    2 => ['amount' => 0.15, 'min_deposit' => 50.00, 'label' => '15% First Deposit Bonus'],
    3 => ['amount' => 0.20, 'min_deposit' => 100.00,'label' => '20% First Deposit Bonus'],
];

# Has Telegram bonus been claimed?
$tgBonusCheck = $db->query(
    "SELECT 1 FROM db_bonus_tg WHERE status = 1 AND uid = ? LIMIT 1",
    $uid
)->numRows();

# Claim Telegram bonus
if (isset($_GET['claim_telegram']) && !$tgBonusCheck) {
    $now = time();
    $db->query(
        "INSERT INTO db_bonus_tg (uid, login, status, date_add, amount) VALUES (?, ?, 1, ?, ?)",
        $uid, $login, $now, $telegramBonusAmount
    );
    $db->query(
        "UPDATE db_users SET money_p = money_p + ? WHERE id = ?",
        $telegramBonusAmount, $uid
    );
    $_SESSION['notification'] = '<div class="alert alert-success text-center">Telegram bonus of $' . number_format($telegramBonusAmount, 2) . ' claimed successfully!</div>';
    header('Location: /user/bonus');
    exit;
}

# Already-claimed deposit bonus (if any)
$bonusRow = $db->query(
    "SELECT bonus_id, sum, `add` FROM db_bonus WHERE uid = ? AND bonus_id IN (1,2,3) LIMIT 1",
    $uid
)->fetchArray();
$hasClaimedDepositBonus = !empty($bonusRow);
$claimedBonusId = (int)($bonusRow['bonus_id'] ?? 0);

# First successful deposit
$firstDeposit = $db->query(
    "SELECT `sum` FROM db_insert WHERE uid = ? AND status = 1 ORDER BY `add` ASC LIMIT 1",
    $uid
)->fetchArray();
$firstDepositAmount = $firstDeposit ? (float)$firstDeposit['sum'] : 0.0;

# Determine eligible deposit bonus
$eligibleBonusId = 0;
if     ($firstDepositAmount >= 100.00) $eligibleBonusId = 3;
elseif ($firstDepositAmount >= 50.00)  $eligibleBonusId = 2;
elseif ($firstDepositAmount >= 5.00)   $eligibleBonusId = 1;

# Process deposit bonus claim (strict: GET must match eligibility)
$claimId = isset($_GET['claim_deposit']) ? (int)$_GET['claim_deposit'] : 0;
if ($claimId && !$hasClaimedDepositBonus && $eligibleBonusId === $claimId) {
    if (isset($depositBonuses[$claimId]) && $firstDepositAmount >= $depositBonuses[$claimId]['min_deposit']) {
        $bonusAmount = round($firstDepositAmount * $depositBonuses[$claimId]['amount'], 2);
        $now = time();
        $db->query(
            "INSERT INTO db_bonus (uid, login, sum, `add`, bonus_id) VALUES (?, ?, ?, ?, ?)",
            $uid, $login, $bonusAmount, $now, $claimId
        );
        $db->query(
            "UPDATE db_users SET money_p = money_p + ? WHERE id = ?",
            $bonusAmount, $uid
        );
        $_SESSION['notification'] =
            '<div class="alert alert-success text-center">' .
            $depositBonuses[$claimId]['label'] . ' of $' . number_format($bonusAmount, 2) .
            ' claimed successfully!</div>';
        header('Location: /user/bonus');
        exit;
    } else {
        $_SESSION['notification'] = '<div class="alert alert-danger text-center">Invalid bonus claim attempt.</div>';
        header('Location: /user/bonus');
        exit;
    }
}
?>

<style>
    .telegram-section {
        background: linear-gradient(135deg, #0088cc, #005f99);
        color: white;
        padding: 1rem;
        border-radius: 15px;
        text-align: center;
        margin-top: 2rem;
    }
    .telegram-section h3 {
        font-weight: 700;
        margin-bottom: 1rem;
    }
    .telegram-section .highlight {
        color: #ffdd00;
        font-weight: bold;
    }
    .telegram-section img {
        max-width: 64px;
        width: 64px;
        height: auto;
        margin-bottom: 1rem;
    }
    .btn-telegram, .btn-deposit {
        background-color: #ffffff;
        color: #0088cc;
        border: 2px solid #0088cc;
        border-radius: 10px;
        padding: 0.75rem 2rem;
        font-weight: 600;
        transition: all 0.3s;
        text-decoration: none;
    }
    .btn-telegram:hover, .btn-deposit:hover {
        background-color: #0088cc;
        color: white;
    }
    .btn-telegram.disabled, .btn-telegram:disabled, .btn-deposit.disabled, .btn-deposit:disabled {
        background-color: #cccccc;
        color: #666666;
        border-color: #cccccc;
        cursor: not-allowed;
    }
    #notification .alert {
        margin-bottom: 0;
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1000;
        min-width: 300px;
    }
</style>

<div class="center-title mt-3">
    <h2>CashFarm 💸🌾 Bonuses</h2>
    <p style="line-height: 1.1;">We reward you with bonuses check them out!</p>
</div>

<?php
if (!empty($_SESSION['notification'])) {
    echo '<div id="notification" class="text-center">' . $_SESSION['notification'] . '</div>';
    unset($_SESSION['notification']);
}
?>

<div class="telegram-section">
    <div class="text-center">
        <img loading="lazy" decoding="async" src="/img/tg.png" alt="Telegram Icon">
    </div>
    <h3>Join Our Telegram Community!</h3>
    <p>Get a <span class="highlight">$5 USD Bonus</span> by joining our Telegram group.</p>
    <?php if ($tgBonusCheck == 0) { ?>
        <a class="btn btn-telegram" href="?claim_telegram=1" onclick="window.open('<?php echo $telegramLink; ?>', '_blank');">Join Now</a>
    <?php } else {
        $bonusData = $db->query(
            "SELECT amount, date_add FROM db_bonus_tg WHERE status = 1 AND uid = ? LIMIT 1",
            $uid
        )->fetchArray();
    ?>
        <div class="text-success mt-3">
            <b>✅ Bonus Claimed! 🎉</b>
            <div class="mt-2">
                <p>Bonus Amount: <span class="text-success">$<?php echo number_format((float)$bonusData['amount'], 2); ?> USD 💸</span></p>
                <p>Date Claimed: <span class="text-info"><?php echo date("d M Y - H:i", (int)$bonusData['date_add']); ?> ⏰</span></p>
            </div>
        </div>
        <a class="btn btn-telegram disabled" disabled>Claimed 🎉</a>
    <?php } ?>
</div><br>

<div class="row row-cols-1">
    <div class="col col-lg-6 col-xl-4 p-2">
        <div class="card h-100 mb-2" style="overflow: hidden;box-shadow: 0px 0px 0rem 1px #e3e3f8 !important;">
            <div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb1.png" style="max-width: 64px;"></div>
            <h4 class="p-2 text-center mb-0 pb-0">BONUS 10% </h4>
            <h6 class="p-1 text-center mb-0 text-uppercase">1st Time Deposit Bonus #1</h6>
            <div class="p-1 text-center h-100"><small>
                First deposit of $5–$49.99 USD earns <span style="color: #f24;"><b>+10%</b></span> bonus to your balance.
            </small></div>
            <div class="text-center p-2">
                <?php if ($hasClaimedDepositBonus && $claimedBonusId === 1) {
                    $bonus1 = $db->query(
                        "SELECT sum, `add` FROM db_bonus WHERE bonus_id = 1 AND uid = ? LIMIT 1",
                        $uid
                    )->fetchArray();
                ?>
                    <div class="text-success mt-3">
                        <b>✅ Bonus Claimed! 🎉</b>
                        <div class="mt-2">
                            <p>Bonus Amount: <span class="text-success">$<?php echo number_format((float)$bonus1['sum'], 2); ?> USD 💸</span></p>
                            <p>Date Claimed: <span class="text-info"><?php echo date("d M Y - H:i", (int)$bonus1['add']); ?> ⏰</span></p>
                        </div>
                    </div>
                    <a class="btn btn-deposit disabled" disabled>Claimed 🎉</a>
                <?php } elseif (!$hasClaimedDepositBonus && $eligibleBonusId === 1) { ?>
                    <a class="btn btn-deposit" href="?claim_deposit=1">CLAIM BONUS</a>
                <?php } else { ?>
                    <a class="btn btn-deposit disabled" disabled><?php echo $hasClaimedDepositBonus ? 'Bonus Claimed' : 'Deposit $5–$49.99 to Claim'; ?></a>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col col-lg-6 col-xl-4 p-2">
        <div class="card h-100 mb-2" style="overflow: hidden;box-shadow: 0px 0px 0rem 1px #e3e3f8 !important;">
            <div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb1.png" style="max-width: 64px;"></div>
            <h4 class="p-2 text-center mb-0 pb-0">BONUS 15% </h4>
            <h6 class="p-1 text-center mb-0 text-uppercase">1st Time Deposit Bonus #2</h6>
            <div class="p-1 text-center h-100"><small>
                First deposit of $50–$99.99 USD earns <span style="color: #f24;"><b>+15%</b></span> bonus to your balance.
            </small></div>
            <div class="text-center p-2">
                <?php if ($hasClaimedDepositBonus && $claimedBonusId === 2) {
                    $bonus2 = $db->query(
                        "SELECT sum, `add` FROM db_bonus WHERE bonus_id = 2 AND uid = ? LIMIT 1",
                        $uid
                    )->fetchArray();
                ?>
                    <div class="text-success mt-3">
                        <b>✅ Bonus Claimed! 🎉</b>
                        <div class="mt-2">
                            <p>Bonus Amount: <span class="text-success">$<?php echo number_format((float)$bonus2['sum'], 2); ?> USD 💸</span></p>
                            <p>Date Claimed: <span class="text-info"><?php echo date("d M Y - H:i", (int)$bonus2['add']); ?> ⏰</span></p>
                        </div>
                    </div>
                    <a class="btn btn-deposit disabled" disabled>Claimed 🎉</a>
                <?php } elseif (!$hasClaimedDepositBonus && $eligibleBonusId === 2) { ?>
                    <a class="btn btn-deposit" href="?claim_deposit=2">CLAIM BONUS</a>
                <?php } else { ?>
                    <a class="btn btn-deposit disabled" disabled><?php echo $hasClaimedDepositBonus ? 'Bonus Claimed' : 'Deposit $50–$99.99 to Claim'; ?></a>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col col-lg-6 col-xl-4 p-2">
        <div class="card h-100 mb-2" style="overflow: hidden; box-shadow: 0px 0px 0rem 1px #e3e3f8 !important;">
            <div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb1.png" style="max-width: 64px;"></div>
            <h4 class="p-2 text-center mb-0 pb-0">BONUS 20%</h4>
            <h6 class="p-1 text-center mb-0 text-uppercase">1st Time Deposit Bonus #3</h6>
            <div class="p-1 text-center h-100"><small>
                First deposit of $100+ USD earns <span style="color: #f24;"><b>+20%</b></span> bonus to your balance.
            </small></div>
            <div class="text-center p-2">
                <?php if ($hasClaimedDepositBonus && $claimedBonusId === 3) {
                    $bonus3 = $db->query(
                        "SELECT sum, `add` FROM db_bonus WHERE bonus_id = 3 AND uid = ? LIMIT 1",
                        $uid
                    )->fetchArray();
                ?>
                    <div class="text-success mt-3">
                        <b>✅ Bonus Claimed! 🎉</b>
                        <div class="mt-2">
                            <p>Bonus Amount: <span class="text-success">$<?php echo number_format((float)$bonus3['sum'], 2); ?> USD 💸</span></p>
                            <p>Date Claimed: <span class="text-info"><?php echo date("d M Y - H:i", (int)$bonus3['add']); ?> ⏰</span></p>
                        </div>
                    </div>
                    <a class="btn btn-deposit disabled" disabled>Claimed 🎉</a>
                <?php } elseif (!$hasClaimedDepositBonus && $eligibleBonusId === 3) { ?>
                    <a class="btn btn-deposit" href="?claim_deposit=3">CLAIM BONUS</a>
                <?php } else { ?>
                    <a class="btn btn-deposit disabled" disabled><?php echo $hasClaimedDepositBonus ? 'Bonus Claimed' : 'Deposit $100+ to Claim'; ?></a>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col col-lg-6 col-xl-6 p-2">
        <div class="card h-100 mb-2" style="overflow: hidden;box-shadow: 0px 0px 0rem 1px #e3e3f8 !important;">
            <div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb2.png" style="max-width: 64px;"></div>
            <h4 class="p-2 text-center mb-0 pb-0">BONUS $5 - $100 USD</h4>
            <h6 class="p-1 text-center mb-0 text-uppercase">Video Bounty System</h6>
            <div class="p-1 text-center h-100"><small>
🌾 We’re thrilled to invite our farmers to share their experience by creating a Video Review of CashFarm! 📹 Connect with our admin team on our Telegram channel to claim your reward! 🚜
            </small></div>
            <div class="text-center p-2">
                <a class="btn btn-danger w-50 mb-2" href="https://t.me/cashfarmfun">CLAIM BONUS</a>
            </div>
        </div>
    </div>

    <div class="col col-lg-6 col-xl-6 p-2">
        <div class="card h-100 mb-2" style="overflow: hidden;box-shadow: 0px 0px 0rem 1px #e3e3f8 !important;">
            <div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb3.png" style="max-width: 64px;"></div>
            <h4 class="p-2 text-center mb-0 pb-0">BONUS $10 USD</h4>
            <h6 class="p-1 text-center mb-0 text-uppercase">BONUS</h6>
            <div class="p-1 text-center h-100"><small>
🎉 We’re pumped to reward you with a $10 USD bonus for hitting a team turnover of at least $250 USD! 💸 Connect with our admin team on Telegram to claim your prize! 🚀
            </small></div>
            <div class="text-center p-2">
                <a class="btn btn-danger w-50 mb-2" href="https://t.me/cashfarmfun">CLAIM BONUS</a>
            </div>
        </div>
    </div>
    <div class="col col-lg-6 col-xl-6 p-2">
        <div class="card h-100 mb-2" style="overflow: hidden;box-shadow: 0px 0px 0rem 1px #e3e3f8 !important;">
            <div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb3.png" style="max-width: 64px;"></div>
            <h4 class="p-2 text-center mb-0 pb-0">BONUS $50 USD</h4>
            <h6 class="p-1 text-center mb-0 text-uppercase">BONUS</h6>
            <div class="p-1 text-center h-100"><small>
🌟 Big cheers for your success! Get a $50 USD bonus for smashing a team turnover of at least $500 USD! 🎊 Contact our admin team on Telegram to grab your reward! 💪
            </small></div>
            <div class="text-center p-2">
                <a class="btn btn-danger w-50 mb-2" href="https://t.me/cashfarmfun">Claim Bonus</a>
            </div>
        </div>
    </div>

    <div class="col col-lg-6 col-xl-6 p-2">
        <div class="card h-100 mb-2" style="overflow: hidden;box-shadow: 0px 0px 0rem 1px #e3e3f8 !important;">
            <div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb3.png" style="max-width: 64px;"></div>
            <h4 class="p-2 text-center mb-0 pb-0">BONUS $100 USD</h4>
            <h6 class="p-1 text-center mb-0 text-uppercase">BONUS</h6>
            <div class="p-1 text-center h-100"><small>
🥳 Huge congrats! Unlock a $100 USD bonus for crushing a team turnover of at least $1500 USD! 💥 Reach out to our admin team on Telegram to claim your epic reward! 🌟 
            </small></div>
            <div class="text-center p-2">
                <a class="btn btn-danger w-50 mb-2" href="https://t.me/cashfarmfun">Claim Bonus</a>
            </div>
        </div>
    </div>
</div>

<br><br><br><br>

<script>
// Auto-hide notification after 2 seconds
document.addEventListener('DOMContentLoaded', function() {
    const notification = document.getElementById('notification');
    if (notification && notification.innerHTML.trim() !== '') {
        setTimeout(function() { notification.style.display = 'none'; }, 2000);
    }
});
</script>

<script>(function(){function r(f){if(document.readyState!=='loading')f();else document.addEventListener('DOMContentLoaded',f);}function t(m){try{vxToast(m);}catch(e){alert(m);}}r(function(){var a=document.querySelector('[data-action="tg-bonus"]');if(a)a.addEventListener('click',function(e){e.preventDefault();fetch('/api/user/bonus_claim.php?action=tg',{method:'POST',credentials:'include'}).then(r=>r.json()).then(j=>{t(j.msg||'Done');if(j.ok)location.reload();});});var d=document.querySelector('[data-action="daily-bonus"]');if(d)d.addEventListener('click',function(e){e.preventDefault();fetch('/api/user/bonus_claim.php?action=daily',{method:'POST',credentials:'include'}).then(r=>r.json()).then(j=>{t(j.msg||'Done');if(j.ok)location.reload();});});});})();</script>