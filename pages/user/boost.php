
<style>
.gf-boost-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.gf-boost{background:rgba(10,25,20,.55);border:1px solid rgba(0,255,150,.25);border-radius:16px;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.35);position:relative}
.gf-boost h3{margin:0 0 6px;font-size:16px}
.gf-boost p{opacity:.8;font-size:13px}
.gf-boost .timer{font-size:12px;color:#6fffd2;margin-top:8px}
.gf-boost.active{outline:2px solid rgba(0,255,150,.6)}
</style>
<?php
/**
 * File: /user/plans.php (Premium visual polish • Investor notes • Correct Points calc)
 * - Fixed Hero CSS (was missing, causing "doesnt look visually correct")
 * - Added next-level expand/collapse "Details" panel (fees + points explained)
 * - Fixed season countdown selector (was looking for .vx-season, but markup uses .vx-season-mini)
 * - Mobile/desktop refined layout + chips + season widget
 * - Keeps existing caps/ledger/ref math + AJAX purchase flow
 */
if (!defined('FastCore')) { exit('Opss!'); }

// Embed mode: when included inside /user/dashboard we should avoid emitting
// global page assets (bootstrap/fontawesome links) that can duplicate/conflict.
$vxDashEmbed = defined('VX_DASH_EMBED') && VX_DASH_EMBED;

global $db, $user, $uid, $config;

require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';
require_once __DIR__ . "/../../core/vx_rarity.php";
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/idk.php';

/* ---------- AJAX DETECTION ---------- */
$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) ||
    (isset($_POST['ajax']) && $_POST['ajax'] == '1') ||
    (isset($_GET['ajax']) && $_GET['ajax'] == '1')
);

/* ---------- USER CONTEXT ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = isset($uid) ? (int)$uid : (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { echo '<div class="alert alert-warning text-center m-3">Please sign in.</div>'; return; }
if (!isset($db) || !($db instanceof db)) { echo '<div class="alert alert-danger text-center m-3">Database unavailable.</div>'; return; }

/* Hydrate user if needed */
if (!isset($user) || !is_array($user) || empty($user['id'])) {
    $u = $db->query('SELECT * FROM db_users WHERE id = ?', $uid)->fetchArray();
    if (!$u) { echo '<div class="alert alert-danger text-center m-3">Account not found.</div>'; return; }
    $user = $u;
}

// Display name for viral share / UI (fail-soft)
$vx_userName = '';
foreach (['username','login','name','tg_username'] as $k) {
    if (!empty($user[$k]) && is_string($user[$k])) { $vx_userName = (string)$user[$k]; break; }
}
if ($vx_userName === '') {
    $vx_userName = 'User' . (string)$uid;
}

$opt['title'] = 'Seed Plans';
$currencyCode = htmlspecialchars($config->valuta ?? 'USD', ENT_QUOTES, 'UTF-8');

/* ---------- CONSTANTS ---------- */
$VX_CONV = 100;   // 100 pts -> 1 VX
$VX_USD  = 0.10;  // indicative list $/VX

/* ---------- EXPIRE FINISHED PURCHASES ---------- */
$now = time();
$expired = $db->query(
    'SELECT id, speed, hashpower FROM db_store WHERE uid = ? AND status = 1 AND `end` < ? ORDER BY `end` DESC',
    $uid, $now
)->fetchAll();

if (!empty($expired)) {
    foreach ($expired as $row) {
        $sp  = (float)$row['speed'];
        $hp  = (float)$row['hashpower'];
        $sid = (int)$row['id'];
        $db->query(
            'UPDATE db_users
               SET speed = IF(speed >= ?, speed - ?, 0),
                   bank  = IF(bank  >= ?, bank  - ?, 0)
             WHERE id = ?',
            $sp, $sp, $hp, $hp, $uid
        );
        $db->query('UPDATE db_store SET status = 2 WHERE id = ?', $sid);
    }
    $user = $db->query('SELECT * FROM db_users WHERE id = ?', $uid)->fetchArray();
}

/* ---------- PURCHASE (POST, JSON for AJAX) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item'])) {
    $planId   = (int)$_POST['item'];
    $returnTo = $_SERVER['REQUEST_URI'] ?? (defined('VX_GUARDIANS_MODE') && VX_GUARDIANS_MODE ? '/user/guardians' : '/user/plans');

    $plan = $db->query('SELECT * FROM db_tarif WHERE id = ? LIMIT 1', $planId)->fetchArray();
    if (!$plan) {
        $msg = 'Selected plan not found.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'type'=>'error','msg'=>$msg]); exit; }
        $_SESSION['notification'] = '<div class="alert alert-danger text-center">'.$msg.'<button class="close-btn">Close</button></div>';
        header('Location: ' . $returnTo); exit;
    }

    // Season + active cap (per-season)
    $season = vx_get_current_season($db);
    if (empty($season['ok'])) {
        $msg = 'Season system unavailable. Please try again.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'type'=>'error','msg'=>$msg]); exit; }
        $_SESSION['notification'] = '<div class="alert alert-danger text-center">'.$msg.'<button class="close-btn">Close</button></div>';
        header('Location: ' . $returnTo); exit;
    }
    $hasPass = false;
    try { $hasPass = vx_season_pass_active($db, (int)$uid, (int)$season['id']); } catch (Throwable $e) {}
    $capInfo = vx_check_season_cap_buffered($db, (int)$season['id'], (int)$planId, $hasPass);
    if (!$capInfo['ok']) {
        $ends = (int)($season['ends_at'] ?? 0);
        $left = $ends > 0 ? max(0, $ends - time()) : 0;
        $msg = 'This mutant is LOCKED (seasonal slots filled). Reopens next season. Season Pass holders may still have buffer slots.';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'type'=>'warning','msg'=>$msg,'code'=>'season_cap_reached','season'=>['id'=>(int)$season['id'],'no'=>(int)$season['season_no'],'ends_at'=>$ends,'ends_in'=>$left],'cap'=>$capInfo]);
            exit;
        }
        $_SESSION['notification'] = '<div class="alert alert-warning text-center">'.$msg.'<button class="close-btn">Close</button></div>';
        header('Location: ' . $returnTo); exit;
    }

    $limitPerPlan = 10;
    $cntRow = $db->query(
        'SELECT COUNT(*) AS cnt FROM db_store WHERE uid = ? AND tarif = ? AND status IN (1,2)',
        $uid, $planId
    )->fetchArray();
    $owned = (int)($cntRow['cnt'] ?? 0);
    if ($owned >= $limitPerPlan) {
        $msg = 'You have reached your personal limit of 10 active/finished mutants for this tier.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'type'=>'warning','msg'=>$msg]); exit; }
        $_SESSION['notification'] = '<div class="alert alert-danger text-center">'.$msg.'<button class="close-btn">Close</button></div>';
        header('Location: ' . $returnTo); exit;
    }

    $userFresh = $db->query('SELECT id, money_p, money_b, rid FROM db_users WHERE id = ? LIMIT 1', $uid)->fetchArray();
    if (!$userFresh) {
        $msg = 'Unable to load your balances.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'type'=>'error','msg'=>$msg]); exit; }
        $_SESSION['notification'] = '<div class="alert alert-danger text-center">'.$msg.'<button class="close-btn">Close</button></div>';
        header('Location: ' . $returnTo); exit;
    }

    $price        = (float)$plan['price'];
    $speedPercent = (float)$plan['speed'];
    $periodDays   = (int)$plan['period'];
    $endTs        = $now + (60 * 60 * 24 * $periodDays);
    $firstDayE    = round(($price * $speedPercent) / 100, 4);

    $holdAvail    = (float)$userFresh['money_b'];
    $holdUsed     = min($holdAvail, $price);
    $payFromMain  = $price - $holdUsed;

    if ($price > ((float)$userFresh['money_p'] + (float)$userFresh['money_b'])) {
        $availableTotal = (float)$userFresh['money_p'] + (float)$userFresh['money_b'];
        $msg = 'Insufficient balance. Available: $' . number_format($availableTotal, 2, '.', '') .
               " {$currencyCode} • Required: $" . number_format($price, 2, '.', '') . " {$currencyCode}";
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'type'=>'warning','msg'=>$msg]); exit; }
        $_SESSION['notification'] = '<div class="alert alert-danger text-center">'.$msg.'<button class="close-btn">Close</button></div>';
        header('Location: ' . $returnTo); exit;
    }

    $hashPower = round($price, 4);
    $speedAdd  = $speedPercent;

    $db->query(
        'UPDATE db_users
            SET money_p = money_p - ? + ?,
                money_b = IF(money_b >= ?, money_b - ?, 0),
                speed   = speed + ?,
                bank    = bank + ?,
                bankin  = bankin + ?,
                `last`  = ?
          WHERE id = ?',
        $payFromMain, $firstDayE, $holdUsed, $holdUsed, $speedAdd, $hashPower, $price, $now, $uid
    );

    $db->query(
        'INSERT INTO db_store (uid, tarif, title, speed, hashpower, `add`, `end`, `status`, `last`, season_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
        $uid, $planId, $plan['title'], $speedAdd, $hashPower, $now, $endTs, $now, (int)$season['id']
    );

    /* Points ledger (server truth) */
    try {
        $ptsRow   = $db->query('SELECT points_award FROM db_tarif_points WHERE tarif_id = ? LIMIT 1', $planId)->fetchArray();
        $basePts = (int)($ptsRow['points_award'] ?? 0);
        if ($basePts <= 0) { $basePts = (int)round($price * 1); }

        // Perceived boost: show crossed-out base, then boosted total.
        $bonusRate = 0.0;
        if ($price <= 50) $bonusRate = 1.00;
        elseif ($price <= 200) $bonusRate = 0.80;
        elseif ($price <= 500) $bonusRate = 0.60;
        elseif ($price <= 1000) $bonusRate = 0.45;
        elseif ($price <= 5000) $bonusRate = 0.30;
        elseif ($price <= 10000) $bonusRate = 0.20;
        else $bonusRate = 0.12;
        $bonusPts = (int)floor(max(0, $basePts) * $bonusRate);
        $buyerPts = (int)max(0, $basePts + $bonusPts);

        if ($buyerPts > 0) {
            try { $db->query('UPDATE db_users SET points_total = points_total + ?, points_spendable = points_spendable + ? WHERE id = ?', $buyerPts, $buyerPts, $uid); } catch (Throwable $e) {}
            try { $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, ref_uid, tarif_id, usd_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', $uid, $buyerPts, 'buy_mutant', null, $planId, $price, $now); } catch (Throwable $e) {}
            $rid = (int)($userFresh['rid'] ?? 0);
            if ($rid > 0 && $rid !== $uid) {
                $refPts = (int)floor($buyerPts * 0.10);
                if ($refPts > 0) {
                    try { $db->query('UPDATE db_users SET points_total = points_total + ?, points_spendable = points_spendable + ? WHERE id = ?', $refPts, $refPts, $rid); } catch (Throwable $e) {}
                    try { $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, ref_uid, tarif_id, usd_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', $rid, $refPts, 'ref_mutant', $uid, $planId, $price, $now); } catch (Throwable $e) {}
                }
            }
        }
    } catch (Throwable $e) {}

    $msg = 'Seed planted: ' . htmlspecialchars($plan['title'], ENT_QUOTES, 'UTF-8') . ' — $' . number_format($price,2) . ' ' . $currencyCode;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        $botName   = !empty($config->telegram_bot) ? preg_replace('~[^A-Za-z0-9_]+~','',(string)$config->telegram_bot) : 'GreenFarmAppBot';
        $shareTGDL = 'https://t.me/' . rawurlencode($botName) . '/launch?startapp=' . rawurlencode('ref_' . $uid);
        $shareText = '🏅 @' . preg_replace('~[^A-Za-z0-9_]+~', '', (string)$vx_userName) . ' activated "'.(string)$plan['title'].'" on GreenFarm and boosted their season rank. Join here: ' . $shareTGDL;
        echo json_encode([
          'ok'=>true,
          'type'=>'success',
          'msg'=>$msg,
          'plan'=>['id'=>$planId,'title'=>$plan['title'],'price'=>$price],
          'base_points'=>isset($basePts)?(int)$basePts:0,
          'bonus_points'=>isset($bonusPts)?(int)$bonusPts:0,
          'total_points'=>isset($buyerPts)?(int)$buyerPts:0,
          'share'=>['text'=>$shareText],
        ]);
        exit;
    }
    $_SESSION['notification'] = '<div class="alert alert-success text-center">'.$msg.'<button class="close-btn">Close</button></div>';
    header('Location: ' . $returnTo); exit;
}

/* ---------- LOAD PLANS ---------- */
$vx_daily = null;
$vx_daily_featured_id = 0;
$vx_daily_reset_ts = 0;

if (defined('VX_GUARDIANS_MODE') && VX_GUARDIANS_MODE) {
  // Daily rotating shop (no cron) — same for all — Mythic excluded
  require_once __DIR__ . '/../../core/vx_daily_shop.php';
  try {
    $vx_daily = vx_daily_shop_pick($db, 9);
    $plans = (array)($vx_daily['plans'] ?? []);
    $vx_daily_featured_id = (int)($vx_daily['featured_id'] ?? 0);
    $vx_daily_reset_ts = (int)($vx_daily['reset_ts'] ?? 0);
  } catch (Throwable $e) {
    $plans = [];
  }

  // Fail-soft fallback
  if (!$plans) {
    try {
      $plans = $db->query("SELECT * FROM db_tarif WHERE is_active = 1 AND kind = 'plan' AND unlock_method = 'purchase' ORDER BY COALESCE(sort_order, 999999) ASC, COALESCE(guardian_no, 999999) ASC, id ASC LIMIT 9")->fetchAll();
    } catch (Throwable $e) {
      $plans = $db->query("SELECT * FROM db_tarif ORDER BY id ASC LIMIT 9")->fetchAll();
    }
  }
} else {
  $plans = $db->query('SELECT * FROM db_tarif ORDER BY id ASC')->fetchAll();
}
/* ---------- SEASON META (for UI counters + caps) ---------- */
$season = vx_get_current_season($db);
$seasonId = (int)($season['id'] ?? 0);
$seasonNo = (int)($season['season_no'] ?? 0);
$seasonStartsAt = (int)($season['starts_at'] ?? 0);
$seasonEndsAt   = (int)($season['ends_at'] ?? 0);

// Season Pass state for cap buffer + cosmetics
$vx_userHasPass = false;
try { if (!empty($uid) && $seasonId > 0) { $vx_userHasPass = vx_season_pass_active($db, (int)$uid, $seasonId); } } catch (Throwable $e) {}
$seasonEndsIn   = ($seasonEndsAt > 0) ? max(0, $seasonEndsAt - time()) : 0;
$capsByTarif    = ($seasonId > 0) ? vx_get_caps_for_season($db, $seasonId) : [];
$usedByTarif    = ($seasonId > 0) ? vx_get_usage_for_season($db, $seasonId) : [];

function vx_hms(int $sec): string {
  $sec = max(0, $sec);
  $d = intdiv($sec, 86400);
  $sec -= $d * 86400;
  $h = intdiv($sec, 3600);
  $sec -= $h * 3600;
  $m = intdiv($sec, 60);
  return ($d > 0 ? $d.'d ' : '') . sprintf('%02dh %02dm', $h, $m);
}

/* Compute top-tier price -> referral potential preview */
$topPriceRaw = 0.0;
if ($plans) { foreach ($plans as $tp) { $topPriceRaw = max($topPriceRaw, (float)$tp['price']); } }
$topPts     = (int) round(($topPriceRaw / $VX_USD) * $VX_CONV); // price * 1000
$topRefPts  = (int) floor($topPts * 0.10); // 10% of friend's points
$topRefVx   = $topRefPts / $VX_CONV;
$topRefUsd  = $topRefVx * $VX_USD;
$tenRefPts  = $topRefPts * 10;
$tenRefVx   = $tenRefPts / $VX_CONV;
$tenRefUsd  = $tenRefVx * $VX_USD;

/* --- REF LINKS (USER-SPECIFIC) --- */
$botName   = !empty($config->telegram_bot) ? preg_replace('~[^A-Za-z0-9_]+~','',(string)$config->telegram_bot) : 'GreenFarmAppBot';
$refCode   = 'ref_' . $uid; // deep-link code
$shareTGDL = 'https://t.me/' . rawurlencode($botName) . '/launch?startapp=' . rawurlencode($refCode);
$origin    = ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://' ) . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$shareWeb  = $origin . '/?i=' . $uid;
$shareText = 'Join me on GreenFarm — activate a mutant, earn daily yield + points. Use my link: ' . $shareTGDL . ' (web fallback: ' . $shareWeb . ') 🚀';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function vx_left_human(int $secs): string {
  $secs = max(0, $secs);
  $d = intdiv($secs, 86400); $secs -= $d*86400;
  $h = intdiv($secs, 3600);  $secs -= $h*3600;
  $m = intdiv($secs, 60);
  if ($d > 0) return $d.'d '.$h.'h '.$m.'m';
  if ($h > 0) return $h.'h '.$m.'m';
  return $m.'m';
}
?>
<?php if (!$vxDashEmbed): ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <div class="bg-plasma" aria-hidden="true"></div>
<?php endif; ?>

<main class="vx-page" id="vxPlans">
  <div class="vx-shell">

    <!-- HERO (fixed + pro) -->
    <section class="vx-card vx-hero">
      <div class="vx-hero-row">
        <div class="vx-hero-left">
          <div class="vx-kicker">
            <i class="fa-solid fa-bolt"></i>
            <span>Mutant Crops</span>
          </div>

          <h1 class="vx-hero-title">
            Activate a Mutant Crop
            <span class="vx-hero-title-accent">Daily Yield</span>
            <span class="vx-hero-title-muted">+ VP targets</span>
          </h1>

          <div class="vx-hero-facts">
            <div class="vx-fact">
              <div class="k">Conversion target</div>
              <div class="v">100 VP ≈ 1 VX</div>
            </div>
            <div class="vx-fact">
              <div class="k">Indicative VX value</div>
              <div class="v">$<?= number_format($VX_USD,2); ?>/VX</div>
            </div>
            <div class="vx-fact">
              <div class="k">Platform fees</div>
              <div class="v">5% in / 5% out</div>
            </div>
            <div class="vx-fact">
              <div class="k">Fee routing</div>
              <div class="v">3% Treasury • 2% Ops</div>
            </div>
          </div>

          <p class="vx-hero-sub">
            Earn yield daily while stacking <b>VP</b> that target your USD entry in VX value.
            Fees fund liquidity, uptime, and growth through a transparent split.
          </p>

          <!-- NEXT LEVEL: tap-to-expand details -->
          <div class="vx-details">
            <button class="vx-details-btn" type="button" id="vxDetailsBtn" aria-expanded="false" aria-controls="vxDetailsPanel">
              <i class="fa-solid fa-circle-info"></i>
              <span>View details</span>
              <i class="fa-solid fa-chevron-down vx-details-caret" aria-hidden="true"></i>
            </button>

            <div class="vx-details-panel" id="vxDetailsPanel" hidden>
              <div class="vx-details-grid">
                <div class="vx-dcard">
                  <div class="t"><i class="fa-solid fa-star"></i> VP targets</div>
                  <div class="p">
                    Targets are shown as: <b>USD entry → VX → VP</b>.
                    With <b>$<?= number_format($VX_USD,2); ?>/VX</b>, your entry targets
                    <b>(entry / VX_USD)</b> VX, then <b>× <?= (int)$VX_CONV; ?></b> to Points.
                  </div>
                </div>

                <div class="vx-dcard">
                  <div class="t"><i class="fa-solid fa-scale-balanced"></i> Fee split</div>
                  <div class="p">
                    Platform fees are <b>5% in</b> and <b>5% out</b>.
                    Routing: <b>3% to Treasury</b> + <b>2% to Ops</b> (liquidity, uptime, growth).
                  </div>
                </div>

                <div class="vx-dcard">
                  <div class="t"><i class="fa-solid fa-users"></i> Referral</div>
                  <div class="p">
                    When friends earn Points from a mutant activation, you receive <b>10%</b> of those Points.
                    (Cash withdrawal bonuses are handled elsewhere per your platform rules.)
                  </div>
                </div>

                <div class="vx-dcard">
                  <div class="t"><i class="fa-solid fa-shield-halved"></i> Season caps</div>
                  <div class="p">
                    Some mutants can be <b>season-limited</b>. If the season cap fills up, that mutant shows as <b>LOCKED</b>
                    until the next season — but existing mutant timers keep running.
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>

        <div class="vx-hero-right">
          <div class="vx-pill-grid">
            <span class="vx-pill"><i class="fa-solid fa-bolt"></i> Daily accrual</span>
            <span class="vx-pill"><i class="fa-solid fa-star"></i> Points scale</span>
            <span class="vx-pill"><i class="fa-solid fa-users"></i> +10% friend Points</span>
            <span class="vx-pill"><i class="fa-solid fa-shield-halved"></i> Treasury-backed</span>
            <span class="vx-pill"><i class="fa-solid fa-fire"></i> VX sink mechanics</span>
          </div>

          <?php if (!empty($seasonId) && !empty($seasonNo)): ?>
            <div class="vx-season-mini" data-ends="<?= (int)$seasonEndsAt; ?>">
              <div class="vx-season-mini-left">
                <div class="vx-season-mini-badge">
                  <i class="fa-solid fa-circle-play"></i>
                  <span>Season <?= (int)$seasonNo; ?> Live</span>
                </div>
                <div class="vx-season-mini-note">
                  Caps reset each season. Active mutant timers keep running.
                </div>
              </div>
              <div class="vx-season-mini-right">
                <div class="vx-season-mini-k">Ends in</div>
                <div class="vx-season-mini-v" id="vxPhaseCountdown"><?= h(vx_left_human((int)$seasonEndsIn)); ?></div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>


    <!-- Bottom navigation is provided globally by vx_shell.css / layout.
         (Removed duplicate in-page dock to avoid two docks showing at once.) -->

    <!-- Filters -->
    <div class="filters" role="tablist" aria-label="Seed duration filters">
      <button class="f-btn active" data-filter="all"    role="tab" aria-selected="true">All</button>
      <button class="f-btn"        data-filter="short"  role="tab" aria-selected="false">≤ 15d</button>
      <button class="f-btn"        data-filter="mid"    role="tab" aria-selected="false">16–30d</button>
      <button class="f-btn"        data-filter="long"   role="tab" aria-selected="false">> 30d</button>
    </div>

    <!-- Session notifications -->
    <div id="notification">
      <?php if (!empty($_SESSION['notification'])) { echo $_SESSION['notification']; unset($_SESSION['notification']); } ?>
    </div>

    <!-- Plans grid -->
    <div class="row g-3 px-1" id="plans">
    <?php
    if (!$plans){
        echo '<div class="col-12"><div class="alert alert-secondary my-2 text-center">No active mutants available.</div></div>';
    } else {
        $bestId = null;

        // In Guardians mode, the "Featured of the Day" is server-chosen (daily rotating legendary)
        if (defined('VX_GUARDIANS_MODE') && VX_GUARDIANS_MODE && !empty($vx_daily_featured_id)) {
          $bestId = (int)$vx_daily_featured_id;
        } else {
          // Default: best value heuristic (speed * period)
          $bestScore = -INF;
          foreach ($plans as $pp) {
            $s = ((float)($pp['speed'] ?? 0)) * ((int)($pp['period'] ?? 0));
            if ($s > $bestScore) { $bestScore = $s; $bestId = (int)($pp['id'] ?? 0); }
          }
        }

        $idx = 1;
        foreach ($plans as $p):
            $id         = (int)$p['id'];
            $title      = (string)($p['title'] ?? ('Seed '.$id));
            $period     = (int)($p['period'] ?? 0);
            $speed_raw  = (float)($p['speed'] ?? 0);
            $speed      = number_format($speed_raw, 2);
            $price_raw  = (float)($p['price'] ?? 0);
            $rarMeta    = vx_rarity_for_tarif($db, $id, $title, $price_raw);
            $rarity     = (string)($rarMeta["rarity"] ?? "common");
            $rarClass   = vx_rarity_css_class($rarity);

            $price      = number_format($price_raw, 2);
            $perDay     = number_format(($price_raw * $speed_raw) / 100, 2);
            $total      = number_format(($price_raw * $speed_raw * $period) / 100, 2);
            $roi_total  = number_format($speed_raw * $period, 0);

            /* Points = USD price in VX tokens => pts = price * 1000 */
            $award_vx  = ($VX_USD > 0) ? ($price_raw / $VX_USD) : 0;
            $award_pts = (int) round($award_vx * $VX_CONV);
            $award_usd = $award_vx * $VX_USD;


            // Guardian VP/LP daily rates (UI preview, server-truth settings)
            $gset = function_exists('vx_guardian_settings') ? vx_guardian_settings() : ['rarity_mult'=>[], 'vp_crossbreed_mult'=>[1.0], 'lp_crossbreed_mult'=>[1.0]];
            $gdef = function_exists('vx_guardian_definition') ? vx_guardian_definition($db, $id, $title, $price_raw) : ['vp_per_day'=>0, 'lp_per_day'=>0, 'rarity'=>$rarity];
            $vpBaseDay = (int)($gdef['vp_per_day'] ?? 0);
            $lpBaseDay = (int)($gdef['lp_per_day'] ?? 0);
            $rmArr = (array)($gset['rarity_mult'] ?? []);
            $rarMult2 = isset($rmArr[$rarity]) ? (float)$rmArr[$rarity] : 1.0;
            $vpMult0 = (float)((array)($gset['vp_crossbreed_mult'] ?? [1.0])[0] ?? 1.0);
            $vpMult1 = (float)((array)($gset['vp_crossbreed_mult'] ?? [1.0])[1] ?? $vpMult0);
            $lpMult1 = (float)((array)($gset['lp_crossbreed_mult'] ?? [1.0])[1] ?? 1.0);
            $vpDayL0 = max(0.0, $vpBaseDay * $rarMult2 * $vpMult0);
            $vpDayL1 = max(0.0, $vpBaseDay * $rarMult2 * $vpMult1);
            $lpDayL1 = max(0.0, $lpBaseDay * $rarMult2 * $lpMult1);

            $cntRow2   = $db->query('SELECT COUNT(*) AS cnt FROM db_store WHERE uid = ? AND tarif = ? AND status IN (1,2)', $uid, $id)->fetchArray();
            $owned2    = (int)($cntRow2['cnt'] ?? 0);
            $remainingSlots = max(0, 10 - $owned2);

            $durTag = ($period <= 15) ? 'short' : (($period <= 30) ? 'mid' : 'long');

            // Season caps (0 = unlimited)
            $capPhase = (int)($capsByTarif[$id] ?? 0);
            $usedPhase = (int)($usedByTarif[$id] ?? 0);

            $capEffective = $capPhase;
            $capBuffer = 0;

            if ($capPhase > 0 && !empty($vx_userHasPass)) {
              $capBuffer = (int)ceil($capPhase * 0.05);
              $capEffective = $capPhase + max(1, $capBuffer);
            }

            $seasonLocked = ($capPhase > 0 && $usedPhase >= $capEffective);
            $seasonRemaining = ($capPhase > 0) ? max(0, $capEffective - $usedPhase) : -1;

            $preload = ($idx <= 2) ? 'metadata' : 'none';
    ?>
      <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
        <article class="vx-mutant vx-mutant-card <?= $rarClass; ?> <?= $id === $bestId ? 'is-best':''; ?>" data-dur="<?= $durTag; ?>" data-plan-id="<?= $id; ?>" data-title="<?= h($title); ?>" data-price="<?= (float)$price_raw; ?>" data-perday="<?= (float)(($price_raw * $speed_raw)/100); ?>" data-total="<?= (float)(($price_raw * $speed_raw * $period)/100); ?>" data-period="<?= (int)$period; ?>" data-vp="<?= (int)$award_pts; ?>" data-vpday0="<?= (int)floor($vpDayL0); ?>" data-vpday1="<?= (int)floor($vpDayL1); ?>" data-lpday1="<?= (int)floor($lpDayL1); ?>" data-rarity="<?= h($rarity); ?>" data-season="<?= (int)$seasonNo; ?>">
          <?php if ($id === $bestId): ?>
            <?php if (defined('VX_GUARDIANS_MODE') && VX_GUARDIANS_MODE): ?>
              <div class="vx-best vx-best-featured"><i class="fa-solid fa-crown"></i> Featured of the Day</div>
            <?php else: ?>
              <div class="vx-best"><i class="fa-solid fa-fire"></i> Best ROI</div>
            <?php endif; ?>
          <?php endif; ?>

          <div class="vx-mutant-media">
            <video class="vx-mutant-video" aria-label="Seed <?= h($title); ?> preview"
                   muted loop playsinline preload="none" data-lazy="1">
              <source data-src="/img/item/<?= $id; ?>.webm" type="video/webm">
              <source data-src="/img/item/<?= $id; ?>.mp4"  type="video/mp4">
            </video>

            <div class="vx-overlay">
              <div class="vx-tag <?= $seasonLocked ? 'vx-tag-locked' : ''; ?>">
                <span class="dot"></span> <?= $seasonLocked ? 'LOCKED' : 'Live'; ?>
              </div>
              <div class="vx-tag vx-tag-rarity" title="Rarity">
                <i class="fa-solid fa-gem" aria-hidden="true"></i>
                <span><?= vx_rarity_label($rarity); ?></span>
              </div>

              <?php if ($capPhase > 0): ?>
                <div class="vx-capchip <?= $seasonLocked ? 'is-locked' : ''; ?>">
                  <i class="fa-solid fa-layer-group"></i>
                  <span>
                    <?= (int)$seasonRemaining; ?>/<?= (int)$capPhase; ?>
                    <?php if (!empty($vx_userHasPass) && $capBuffer > 0): ?>
                      <em class="vx-capchip-plus">+<?= (int)$capBuffer; ?></em>
                    <?php endif; ?>
                  </span>
                </div>
              <?php endif; ?>
              <div class="vx-tag vx-tag-roi"><i class="fa-solid fa-arrow-trend-up"></i> <?= $roi_total; ?>%</div>
              <?php if ($award_pts > 0): ?>
                <div class="vx-tag vx-tag-pts" title="Activation VP bonus (awarded on activation)">
                  <i class="fa-solid fa-star"></i>
                  <span class="pts-main">+<?= number_format($award_pts); ?> VP</span>
                  <span class="pts-mini">Activation bonus • ≈ <?= number_format($award_vx, 0); ?> VX target</span>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="vx-mutant-body">
            <div class="vx-mutant-top">
              <div class="vx-tier">Tier <?= $idx; ?></div>
              <div class="vx-serial" title="Set / Serial">S<?= (int)$seasonNo; ?> • #<?= (int)$id; ?></div>
              <h3 class="vx-mutant-title"><?= h($title); ?></h3>
            </div>

            <div class="vx-metrics">
              <div class="vx-m">
                <div class="k">Entry</div>
                <div class="v">$<?= $price; ?> <span><?= $currencyCode; ?></span></div>
              </div>
              <div class="vx-m">
                <div class="k">Daily</div>
                <div class="v">$<?= $perDay; ?> <span><?= $currencyCode; ?></span></div>
                <div class="s"><?= $speed; ?>% / day</div>
              </div>
              <div class="vx-m">
                <div class="k">Duration</div>
                <div class="v"><?= $period; ?>d</div>
              </div>
              <div class="vx-m">
                <div class="k">Est. Return</div>
                <div class="v">$<?= $total; ?> <span><?= $currencyCode; ?></span></div>
              </div>
            </div>

            <!-- TCG-style: What you gain (per card) -->
            <div class="vx-gains-strip" aria-label="What you gain">
              <div class="vx-g">
                <div class="ic"><i class="fa-solid fa-sack-dollar"></i></div>
                <div class="tx">
                  <div class="k">Yield</div>
                  <div class="v">$<?= $perDay; ?>/day</div>
                </div>
              </div>

              <div class="vx-g">
                <div class="ic"><i class="fa-solid fa-star"></i></div>
                <div class="tx">
                  <div class="k">VP / day</div>
                  <div class="v">≈ <?= number_format($vpDayL0, 0); ?> VP</div>
                </div>
              </div>

              <div class="vx-g">
                <div class="ic"><i class="fa-solid fa-crown"></i></div>
                <div class="tx">
                  <div class="k">LP / day</div>
                  <div class="v"><?php if ($lpDayL1 > 0): ?>Unlock at Crossbreed 1<?php else: ?>—<?php endif; ?></div>
                </div>
              </div>

              <div class="vx-g">
                <div class="ic"><i class="fa-solid fa-clock"></i></div>
                <div class="tx">
                  <div class="k">Cycle</div>
                  <div class="v"><?= (int)$period; ?> days</div>
                </div>
              </div>
            </div>

            <div class="vx-mutant-cta">
              <form method="post">
                <input type="hidden" name="item" value="<?= $id; ?>">
                <?php $canBuy = ($remainingSlots > 0) && !$seasonLocked; ?>
                <button class="btn vx-btn-primary w-100 vc-btn" type="submit" <?= $canBuy ? '' : 'disabled'; ?>>
                  <?= $seasonLocked ? 'Season Locked' : ($remainingSlots > 0 ? 'Plant Seed' : 'Limit Reached'); ?>
                </button>
              </form>
              <div class="vx-remaining">
                <span class="vx-remaining-label">Your personal slots</span>
                <span class="vx-remaining-value"><?= $owned2; ?>/10</span>
              </div>
            </div>

            <!-- TCG-style: Card back / details -->
            <button class="vx-cardflip" type="button" aria-expanded="false">
              <i class="fa-solid fa-layer-group"></i> Card Details
            </button>

            <div class="vx-cardback" hidden>
              <div class="vx-cardback-grid">
                <div class="vx-back">
                  <div class="t"><i class="fa-solid fa-bolt"></i> Earnings</div>
                  <div class="p">
                    <div class="vx-earnlines">
                      <div class="row"><span>Daily yield</span><b>$<?= $perDay; ?> <?= $currencyCode; ?></b></div>
                      <div class="row"><span>Est. total yield</span><b>$<?= $total; ?> <?= $currencyCode; ?></b></div>
                      <div class="row"><span>Activation bonus</span><b>+<?= number_format($award_pts); ?> VP</b></div>
                      <div class="row"><span>Daily VP (crossbreed 0)</span><b>≈ <?= number_format((int)floor($vpDayL0)); ?> VP/day</b></div>
                      <div class="row"><span>Daily VP (crossbreed 1)</span><b>≈ <?= number_format((int)floor($vpDayL1)); ?> VP/day</b></div>
                      <div class="row"><span>Daily LP (crossbreed 1+)</span><b><?= ($lpDayL1>0?('≈ '.number_format((int)floor($lpDayL1)).' LP/day'):'Locked'); ?></b></div>
                    </div>
                    <div class="vx-mini" style="margin-top:8px;">
                      LP only starts after your first successful crossbreed (and after a full cycle maturity).
                    </div>
<div class="vx-back-cta">
                      <button class="vx-proj-btn" type="button" data-proj="1">
                        <i class="fa-solid fa-chart-line"></i> View Projection
                      </button>
                    </div></div>
                </div>

                <div class="vx-back">
                  <div class="t"><i class="fa-solid fa-users"></i> Referral</div>
                  <div class="p">
                    You earn <b>10%</b> of your friend’s VP bonus when they activate.<br>
                    <b>Example:</b> +<?= number_format((int)floor($award_pts * 0.10)); ?> VP for this tier.
                  </div>
                </div>

                <div class="vx-back">
                  <div class="t"><i class="fa-solid fa-shield-halved"></i> Season Slots</div>
                  <div class="p">
                    <?php if ($capPhase > 0): ?>
                      Used: <b><?= (int)$usedPhase; ?>/<?= (int)$capPhase; ?></b><br>
                      Remaining: <b><?= (int)$seasonRemaining; ?></b>
                      <?php if (!empty($vx_userHasPass) && $capBuffer > 0): ?>
                        <br><span class="vx-mutedline">Season Pass buffer: +<?= (int)$capBuffer; ?> slots</span>
                      <?php endif; ?>
                    <?php else: ?>
                      Unlimited seasonal slots.
                    <?php endif; ?>
                  </div>
                </div>

                <div class="vx-back">
                  <div class="t"><i class="fa-solid fa-gem"></i> Rarity</div>
                  <div class="p">
                    This Guardian is <b><?= vx_rarity_label($rarity); ?></b>. Higher rarity becomes more prestigious in your Mutant Index.
                  </div>
                </div>
              </div>
            </div>

            <!-- Season slots (real counters) -->
            <div class="vx-community">
              <div class="vx-community-badge">🏁 Season <?= (int)$seasonNo; ?></div>
              <div class="vx-community-main">
                <div class="vx-community-text">
                  <?php if ($capPhase > 0): ?>
                    Active this season: <b><?= (int)$usedPhase; ?>/<?= (int)$capPhase; ?></b> · <?= (int)$seasonRemaining; ?> left
                  <?php else: ?>
                    Active this season: <b><?= (int)$usedPhase; ?></b> · Unlimited slots
                  <?php endif; ?>
                </div>
              </div>
            </div>

          </div>
        </article>
      </div>
    <?php $idx++; endforeach; } ?>
    </div>

  </div>
</main>


<!-- Projection Modal (shared) -->
<div class="vx-modal" id="vxProjModal" hidden aria-hidden="true">
  <div class="vx-modal-backdrop" data-close="1"></div>
  <div class="vx-modal-card" role="dialog" aria-modal="true" aria-labelledby="vxProjTitle">
    <button class="vx-modal-x" type="button" data-close="1" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="vx-modal-top">
      <div class="vx-modal-k">Projection</div>
      <div class="vx-modal-h" id="vxProjTitle">Mutant Crop</div>
      <div class="vx-modal-sub" id="vxProjSub">Season • Rarity</div>
    </div>

    <div class="vx-modal-grid">
      <div class="vx-mcard">
        <div class="k">Entry</div>
        <div class="v" id="vxProjEntry">$0.00</div>
      </div>
      <div class="vx-mcard">
        <div class="k">Daily Yield</div>
        <div class="v" id="vxProjDaily">$0.00/day</div>
      </div>
      <div class="vx-mcard">
        <div class="k">Cycle</div>
        <div class="v" id="vxProjCycle">0 days</div>
      </div>
      <div class="vx-mcard">
        <div class="k">Est. Total Yield</div>
        <div class="v" id="vxProjTotal">$0.00</div>
      </div>
      <div class="vx-mcard">
        <div class="k">VP Bonus</div>
        <div class="v" id="vxProjVP">+0 VP</div>
      </div>
      <div class="vx-mcard">
        <div class="k">Daily VP (crossbreed 0)</div>
        <div class="v" id="vxProjVPDay0">≈ 0 VP/day</div>
      </div>
      <div class="vx-mcard">
        <div class="k">Daily LP (crossbreed 1)</div>
        <div class="v" id="vxProjLPDay1">≈ 0 LP/day</div>
      </div>
      <div class="vx-mcard">
        <div class="k">Referral (10%)</div>
        <div class="v" id="vxProjRef">+0 VP</div>
      </div>
    </div>

    <div class="vx-modal-note">
      Projections are based on the current plan parameters. Yield continues only while active. VP bonus is awarded at activation.
    </div>
  </div>
</div>
<script>
(function(){
  function applyVH(){ document.documentElement.style.setProperty('--vh', window.innerHeight + 'px'); }
  applyVH(); window.addEventListener('resize', applyVH, { passive:true });

  function toast(m){ try { vxToast(m); } catch(e){ alert(m); } }

  document.addEventListener('DOMContentLoaded', function(){

    // Expand/collapse details
    (function(){
      var btn = document.getElementById('vxDetailsBtn');
      var panel = document.getElementById('vxDetailsPanel');
      if (!btn || !panel) return;
      btn.addEventListener('click', function(){
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        panel.hidden = expanded ? true : false;
        btn.classList.toggle('open', !expanded);
      });
    })();

    // Season countdown (FIXED selector + cadence)
    (function(){
      var bar = document.querySelector('.vx-season-mini');
      var el = document.getElementById('vxPhaseCountdown');
      if (!bar || !el) return;
      var ends = parseInt(bar.getAttribute('data-ends') || '0', 10);
      if (!ends) return;

      function fmt(secs){
        secs = Math.max(0, secs);
        var d = Math.floor(secs/86400); secs -= d*86400;
        var h = Math.floor(secs/3600); secs -= h*3600;
        var m = Math.floor(secs/60);
        if (d>0) return d+'d '+h+'h '+m+'m';
        if (h>0) return h+'h '+m+'m';
        return m+'m';
      }
      function tick(){
        var left = ends - Math.floor(Date.now()/1000);
        el.textContent = fmt(left);
      }
      tick();
      setInterval(tick, 30000);
    })();

    // Filters
    var fBtns = document.querySelectorAll('.f-btn');
    fBtns.forEach(function(b){
      b.addEventListener('click', function(){
        fBtns.forEach(function(x){ x.classList.remove('active'); x.setAttribute('aria-selected','false'); });
        b.classList.add('active'); b.setAttribute('aria-selected','true');
        var tag = b.dataset.filter;
        document.querySelectorAll('#plans .vx-mutant').forEach(function(card){
          var d = card.getAttribute('data-dur') || '';
          card.parentElement.style.display = (tag === 'all' || d === tag) ? '' : 'none';
        });
      });
    });

    // Card flip (TCG details)
    document.querySelectorAll('.vx-cardflip').forEach(function(btn){
      btn.addEventListener('click', function(){
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        btn.classList.toggle('open', !expanded);
        var panel = btn.nextElementSibling;
        if (panel && panel.classList && panel.classList.contains('vx-cardback')) {
          panel.hidden = expanded ? true : false;
        }
      });
    });



    // Projection modal (per card)
    (function(){
      var modal = document.getElementById('vxProjModal');
      if(!modal) return;
      var titleEl = document.getElementById('vxProjTitle');
      var subEl = document.getElementById('vxProjSub');
      var entryEl = document.getElementById('vxProjEntry');
      var dailyEl = document.getElementById('vxProjDaily');
      var cycleEl = document.getElementById('vxProjCycle');
      var totalEl = document.getElementById('vxProjTotal');
      var vpEl = document.getElementById('vxProjVP');
      var vpDay0El = document.getElementById('vxProjVPDay0');
      var vpDay1El = document.getElementById('vxProjVPDay1');
      var lpDay1El = document.getElementById('vxProjLPDay1');
      var refEl = document.getElementById('vxProjRef');

      function money(v){
        var n = Number(v||0);
        if(!isFinite(n)) n = 0;
        return '$' + n.toFixed(2);
      }
      function open(card){
        if(!card) return;
        var t = card.getAttribute('data-title') || 'Mutant Crop';
        var rarity = card.getAttribute('data-rarity') || '';
        var season = card.getAttribute('data-season') || '';
        var price = Number(card.getAttribute('data-price')||0);
        var perday = Number(card.getAttribute('data-perday')||0);
        var total = Number(card.getAttribute('data-total')||0);
        var period = Number(card.getAttribute('data-period')||0);
        var vp = Number(card.getAttribute('data-vp')||0);
        var vpDay0 = Number(card.getAttribute('data-vpday0')||0);
        var vpDay1 = Number(card.getAttribute('data-vpday1')||0);
        var lpDay1 = Number(card.getAttribute('data-lpday1')||0);
        var vpDay0 = Number(card.getAttribute('data-vpday0')||0);
        var vpDay1 = Number(card.getAttribute('data-vpday1')||0);
        var lpDay1 = Number(card.getAttribute('data-lpday1')||0);
        var ref = Math.floor(vp * 0.10);

        if(titleEl) titleEl.textContent = t;
        if(subEl) subEl.textContent = (season ? ('Season '+season+' • ') : '') + (rarity ? (rarity.charAt(0).toUpperCase()+rarity.slice(1)) : '');
        if(entryEl) entryEl.textContent = money(price);
        if(dailyEl) dailyEl.textContent = money(perday) + '/day';
        if(cycleEl) cycleEl.textContent = String(period||0) + ' days';
        if(totalEl) totalEl.textContent = money(total);
        if(vpEl) vpEl.textContent = '+' + (vp||0).toLocaleString() + ' VP';
        if(vpDay0El) vpDay0El.textContent = '≈ ' + (vpDay0||0).toLocaleString() + ' VP/day';
        if(vpDay1El) vpDay1El.textContent = '≈ ' + (vpDay1||0).toLocaleString() + ' VP/day';
        if(lpDay1El) lpDay1El.textContent = '≈ ' + (lpDay1||0).toLocaleString() + ' LP/day';
        if(refEl) refEl.textContent = '+' + (ref||0).toLocaleString() + ' VP';

        modal.hidden = false;
        modal.setAttribute('aria-hidden','false');
        document.documentElement.classList.add('vx-modal-open');
      }
      function close(){
        modal.hidden = true;
        modal.setAttribute('aria-hidden','true');
        document.documentElement.classList.remove('vx-modal-open');
      }

      modal.addEventListener('click', function(e){
        var t = e.target;
        if(!t) return;
        if(t.getAttribute && t.getAttribute('data-close') === '1') { close(); return; }
        var p = t.closest ? t.closest('[data-close="1"]') : null;
        if(p) { close(); return; }
      });
      document.addEventListener('keydown', function(e){
        if(e.key === 'Escape' && !modal.hidden) close();
      });

      document.querySelectorAll('.vx-proj-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var card = btn.closest('.vx-mutant');
          open(card);
        });
      });

      // Bonus: clicking the artwork opens projection (feels TCG)
      document.querySelectorAll('.vx-mutant-media').forEach(function(media){
        media.addEventListener('click', function(e){
          // ignore clicks on buttons/links inside overlays
          if(e.target && (e.target.closest && e.target.closest('button, a, form'))) return;
          var card = media.closest('.vx-mutant');
          open(card);
        });
      });
    })();

    // Lazy video play
    var cards = document.querySelectorAll('.vx-mutant');
    var MAX_PLAYING = (window.matchMedia && window.matchMedia('(max-width: 520px)').matches) ? 1 : 2;
    var playing = new Set();

    function hydrate(video){
      if (!video || video.dataset.hydrated==='1') return;
      var sources = video.querySelectorAll('source[data-src]');
      sources.forEach(function(s){ if(!s.src){ s.src = s.dataset.src; } });
      try{ video.load(); }catch(e){}
      video.dataset.hydrated='1';
    }
    function tryPlay(video){
      if (!video || playing.has(video)) return;
      if (playing.size >= MAX_PLAYING) return;
      var p = video.play();
      if (p && typeof p.then === 'function') {
        p.then(function(){ playing.add(video); }).catch(function(){});
      } else { playing.add(video); }
    }
    function pause(video){
      if (!video) return;
      try{ video.pause(); }catch(e){}
      playing.delete(video);
    }

    if (!('IntersectionObserver' in window)) {
      cards.forEach(function(c){
        c.classList.add('visible');
        var v = c.querySelector('video[data-lazy="1"]');
        hydrate(v); tryPlay(v);
      });
    } else {
      var io = new IntersectionObserver(function(es){
        es.forEach(function(e){
          var card = e.target;
          var v = card.querySelector('video[data-lazy="1"]');
          if (!v) return;
          if (e.isIntersecting) {
            card.classList.add('visible');
            hydrate(v); tryPlay(v);
          } else { pause(v); }
        });
      }, { threshold: 0.15, rootMargin: '240px 0px' });
      cards.forEach(function(c){ io.observe(c); });
    }

    // AJAX purchase
    document.querySelectorAll('#plans form[method="post"]').forEach(function(f){
      var btn = f.querySelector('button[type="submit"], .vc-btn');
      var itemInput = f.querySelector('input[name="item"]');
      if (!itemInput) return;
      f.addEventListener('submit', function(ev){
        ev.preventDefault(); ev.stopPropagation();
        var planId = parseInt(itemInput.value, 10);
        if (!planId) { toast('Missing plan id'); return false; }
        if (btn) btn.disabled = true;

        var fd = new FormData(); fd.append('item', String(planId)); fd.append('ajax', '1');
        fetch(window.location.pathname + window.location.search, {
          method: 'POST', credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        })
        .then(function(r){ return r.text(); })
        .then(function(text){
          var data;
          try { data = JSON.parse(text); }
          catch(e) {
            var lower = text.toLowerCase();
            var ok       = /mutant activated|purchase successful|alert-success/.test(text);
            var insuf    = lower.indexOf('insufficient balance') !== -1 || lower.indexOf('not enough funds') !== -1;
            var limitHit = lower.indexOf('personal limit of 10') !== -1 || lower.indexOf('limit reached') !== -1;
            var msg;
            if (insuf) msg = 'Insufficient balance for this mutant. Top up and try again.';
            else if (limitHit) msg = 'You have reached your 10-mutant personal limit for this tier.';
            else if (ok) msg = 'Seed planted. If stats don’t update instantly, refresh your dashboard.';
            else msg = 'We couldn’t read the server response. Please refresh your dashboard to confirm your mutant status.';
            data = { ok: ok, msg: msg };
          }
          toast(data.msg || (data.ok ? 'Seed planted' : 'Purchase failed'));
          if (data.ok) {
            // Reward burst (crossed-out base -> boosted total)
            try {
              if (window.vxRewardBurst) window.vxRewardBurst(
                Number(data.total_points||0) || Number(data.plan?.price||0) || 0,
                btn || f,
                Number(data.base_points||0) || 0
              );
            } catch(e){}

            // One-tap brag share
            try {
              if (data.share && data.share.text && window.vxOpenShareModal) {
                window.vxOpenShareModal(String(data.share.text));
              }
            } catch(e){}

            var vc = f.closest('.vx-mutant-body'); if (!vc) return;
            var wrap = vc.querySelector('.vx-remaining'); var valEl = wrap ? wrap.querySelector('.vx-remaining-value') : null;
            if (valEl) {
              var parts = valEl.textContent.split('/');
              var used  = parseInt((parts[0]||'').trim(), 10) || 0;
              var total = parseInt((parts[1]||'').trim(), 10) || 10;
              used = Math.min(total, used + 1); valEl.textContent = used + '/' + total;
              if (used >= total && btn) { btn.disabled = true; btn.textContent = 'Limit Reached'; }
            }
            try { if (window.vxRefreshMiniStats) vxRefreshMiniStats(); } catch(e){}
          }
        })
        .catch(function(err){ console.error('plans buy fetch failed:', err); toast('Network error, please try again.'); })
        .finally(function(){ if (btn) btn.disabled = false; });
        return false;
      }, { capture:true });
    });

  });
})();
</script>

<style>
:root{
  --vx-bg:#050712;
  --vx-border:rgba(255,255,255,0.10);
  --vx-text:#f8f9ff;
  --vx-muted:rgba(226,232,240,0.86);
  --vx-muted2:rgba(226,232,240,0.70);
  --vx-accent:#00ffe0;
  --vx-accent2:#38bdf8;
  --vx-warn:#fbbf24;
  --vx-hot:#f97316;

  --vx-radius:20px;
  --vx-radius-sm:16px;
  --vx-shadow:0 18px 45px rgba(0,0,0,0.55);
  --vx-shadow-soft:0 12px 30px rgba(0,0,0,0.38);

  --vx-header-safe: 68px;
}
*{ box-sizing:border-box; }
body{ background:var(--vx-bg); color:var(--vx-text); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
.bg-plasma{ position:fixed; inset:0; z-index:-1; pointer-events:none; opacity:0.55;
  background:
    radial-gradient(circle at 0% 0%, rgba(0,255,224,0.22), transparent 55%),
    radial-gradient(circle at 100% 0%, rgba(88,101,242,0.20), transparent 55%),
    radial-gradient(circle at 0% 100%, rgba(255,0,128,0.16), transparent 55%),
    radial-gradient(circle at 50% 120%, rgba(2,6,23,1), rgba(2,6,23,1));
}
.vx-page{ width:100%; padding-top:var(--vx-header-safe); padding-bottom:120px; }
.vx-shell{ width:100%; max-width:1120px; margin:0 auto; padding:12px 12px 22px; }

/* Shared card */
.vx-card{ background:rgba(5,7,18,0.84); border:1px solid var(--vx-border); border-radius:var(--vx-radius); box-shadow:var(--vx-shadow-soft); }

/* Kicker (used in hero) */
.vx-kicker{
  display:inline-flex; align-items:center; gap:10px;
  padding:6px 12px;
  border-radius:999px;
  background:rgba(2,6,23,0.45);
  border:1px solid rgba(148,163,184,0.18);
  font-weight:1000;
  font-size:.90rem;
  color:rgba(226,232,240,0.96);
}
.vx-kicker i{ color:var(--vx-accent); }

/* ===== HERO (FIXED: this was missing in your CSS, causing "doesnt look visually correct") ===== */
.vx-hero{ padding:14px; box-shadow:var(--vx-shadow); }
.vx-hero-row{
  display:grid;
  grid-template-columns: minmax(0,1.25fr) minmax(0,.85fr);
  gap: 14px;
  align-items:start;
}
@media (max-width: 940px){
  .vx-hero-row{ grid-template-columns: 1fr; }
}

.vx-hero-title{
  margin:10px 0 0;
  font-size: clamp(1.25rem, 2.2vw, 1.55rem);
  font-weight: 1100;
  letter-spacing: .01em;
  line-height: 1.12;
}
.vx-hero-title-accent{
  display:inline-block;
  margin-left: 8px;
  padding: 3px 10px;
  border-radius: 999px;
  background: linear-gradient(135deg, rgba(251,191,36,0.18), rgba(249,115,22,0.18));
  border: 1px solid rgba(251,191,36,0.28);
  color: #fff;
}
.vx-hero-title-muted{
  display:block;
  margin-top: 8px;
  color: rgba(226,232,240,0.75);
  font-weight: 950;
  font-size: .98rem;
}

.vx-hero-facts{
  margin-top: 12px;
  display:grid;
  grid-template-columns: repeat(2, minmax(0,1fr));
  gap: 10px;
}
@media (max-width: 520px){
  .vx-hero-facts{ grid-template-columns: 1fr; }
}
.vx-fact{
  border-radius:14px;
  border:1px solid rgba(148,163,184,0.16);
  background: rgba(15,23,42,0.72);
  padding:10px 12px;
}
.vx-fact .k{ font-size:.82rem; color: var(--vx-muted2); font-weight: 900; }
.vx-fact .v{ margin-top:4px; font-size:1.02rem; font-weight:1100; font-variant-numeric: tabular-nums; }

.vx-hero-sub{
  margin-top: 10px;
  color: rgba(226,232,240,0.90);
  font-size: 1.0rem;
  line-height: 1.6;
}

/* Next-level details (tap-to-expand) */
.vx-details{ margin-top: 10px; }
.vx-details-btn{
  width: 100%;
  display:flex; align-items:center; justify-content:space-between; gap: 10px;
  border-radius: 14px;
  padding: 10px 12px;
  border: 1px solid rgba(148,163,184,0.20);
  background: rgba(2,6,23,0.40);
  color: rgba(226,232,240,0.95);
  font-weight: 1000;
  cursor: pointer;
}
.vx-details-btn i{ color: var(--vx-accent2); }
.vx-details-btn .vx-details-caret{ color: rgba(226,232,240,0.80); transition: transform .18s ease; }
.vx-details-btn.open .vx-details-caret{ transform: rotate(180deg); }

.vx-details-panel{
  margin-top: 10px;
  border-radius: 14px;
  border: 1px solid rgba(148,163,184,0.14);
  background: rgba(15,23,42,0.62);
  padding: 12px;
}
.vx-details-grid{
  display:grid;
  grid-template-columns: repeat(2, minmax(0,1fr));
  gap: 10px;
}
@media (max-width: 720px){
  .vx-details-grid{ grid-template-columns: 1fr; }
}
.vx-dcard{
  border-radius: 14px;
  border: 1px solid rgba(148,163,184,0.14);
  background: rgba(2,6,23,0.35);
  padding: 10px 12px;
}
.vx-dcard .t{
  display:flex; align-items:center; gap: 10px;
  font-weight: 1100;
  color:#fff;
}
.vx-dcard .t i{ color: var(--vx-warn); }
.vx-dcard .p{
  margin-top: 7px;
  color: rgba(226,232,240,0.88);
  line-height: 1.5;
  font-size: .95rem;
}

/* Right column (pills + season) */
.vx-pill-grid{
  display:grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
.vx-pill{
  display:flex; align-items:center; gap:10px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid rgba(148,163,184,0.16);
  background: rgba(15,23,42,0.72);
  font-size: .92rem;
  font-weight: 1000;
  color: rgba(226,232,240,0.96);
}
.vx-pill i{ color: var(--vx-accent2); }

/* Season mini widget */
.vx-season-mini{
  margin-top: 10px;
  border-radius: 14px;
  border: 1px solid rgba(34,211,238,0.22);
  background: linear-gradient(135deg, rgba(34,211,238,0.10), rgba(251,191,36,0.10));
  padding: 10px 12px;
  display:flex;
  justify-content:space-between;
  gap: 12px;
  align-items:flex-start;
}
.vx-season-mini-badge{ display:inline-flex; align-items:center; gap:8px; font-weight:1100; }
.vx-season-mini-note{ margin-top: 6px; color: rgba(226,232,240,0.86); font-size:.92rem; line-height:1.35; }
.vx-season-mini-right{ text-align:right; min-width: 120px; }
.vx-season-mini-k{ color: rgba(226,232,240,0.70); font-size:.82rem; font-weight: 1000; }
.vx-season-mini-v{ font-weight: 1200; font-size: 1.05rem; }

/* Filters */
.filters{ display:flex; align-items:center; gap:8px; padding:10px 2px 8px; flex-wrap:wrap; }
.f-btn{ border:1px solid rgba(148,163,184,0.45); background:rgba(15,23,42,0.9); color:#e5e7eb; padding:8px 12px; border-radius:999px; font-weight:900; font-size:0.78rem; letter-spacing:0.02em; cursor:pointer; }
.f-btn.active,.f-btn:hover{ background:linear-gradient(90deg,var(--vx-warn),var(--vx-hot)); border-color:rgba(251,191,36,0.95); color:#111827; box-shadow:0 0 18px rgba(251,191,36,0.25); }

/* Seed cards */
.vx-mutant{ height:100%; border-radius:18px; overflow:hidden; border:1px solid rgba(148,163,184,0.16); background:rgba(5,7,18,0.84); box-shadow:var(--vx-shadow-soft); transform:translateY(16px); opacity:0; transition:transform .22s ease, opacity .22s ease, border-color .22s ease, box-shadow .22s ease; position:relative; }
.vx-mutant.visible{ transform:translateY(0); opacity:1; }
.vx-mutant:hover{ border-color:rgba(34,211,238,0.40); box-shadow:0 18px 54px rgba(34,211,238,0.14); }
.vx-mutant.is-best{ border-color:rgba(251,191,36,0.45); box-shadow:0 18px 54px rgba(251,191,36,0.12); }
.vx-best{ position:absolute; top:10px; right:10px; z-index:4; padding:7px 11px; border-radius:999px; font-size:.82rem; font-weight:1000; color:#0b0f1a; background:linear-gradient(135deg,var(--vx-warn),var(--vx-hot)); }
.vx-best-featured{ background:linear-gradient(135deg,#34d399,#22d3ee); box-shadow:0 10px 30px rgba(34,211,238,.18); }
.vx-mutant-media{ position:relative; height:210px; background:#020617; }
@media (max-width:575px){ .vx-mutant-media{ height:220px; } }
@media (min-width:992px){ .vx-mutant-media{ height:230px; } }
.vx-mutant-video{ width:100%; height:100%; object-fit:cover; display:block; }
.vx-overlay{ position:absolute; inset:0; pointer-events:none; display:flex; flex-direction:column; justify-content:flex-end; gap:10px; padding:12px; background:linear-gradient(180deg, rgba(2,6,23,0.00) 32%, rgba(2,6,23,0.82) 100%); }
.vx-tag{ align-self:flex-start; display:inline-flex; gap:7px; align-items:flex-start; padding:7px 11px; border-radius:999px; background:rgba(2,6,23,0.62); border:1px solid rgba(148,163,184,0.18); font-size:.86rem; font-weight:950; color:rgba(226,232,240,0.97); }
.vx-tag-locked{ border-color:rgba(251,113,133,0.45); background:rgba(127,29,29,0.25); }
.vx-tag .dot{ width:9px;height:9px;border-radius:50%; background:#22c55e; box-shadow:0 0 0 0 rgba(34,197,94,0.8); animation:livePulse 1.5s infinite; margin-top:3px; }
.vx-tag-roi i, .vx-tag-pts i{ color:var(--vx-warn); }
.vx-tag-pts{ display:block !important; max-width:100%; white-space:normal; word-break:break-word; overflow:visible; line-height:1.18; padding-right:12px; }
.vx-tag-pts .pts-main{ font-weight:1100; }
.vx-tag-pts .pts-mini{ display:block; margin-top:2px; font-size:.78rem; color:rgba(226,232,240,0.90); font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: clamp(180px, 26vw, 280px); }
.vx-tag-rarity{ border-color:rgba(255,255,255,0.14); background:rgba(2,6,23,0.58); }
.vx-tag-rarity i{ color:rgba(226,232,240,0.95); }


.vx-mutant-body{ padding:14px; }
.vx-mutant-top{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
.vx-tier{ padding:6px 11px; border-radius:999px; font-size:.84rem; font-weight:950; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:rgba(226,232,240,0.95); }
.vx-serial{ margin-left:auto; padding:6px 10px; border-radius:999px; font-size:.78rem; font-weight:1000; border:1px solid rgba(148,163,184,0.18); background:rgba(2,6,23,0.50); color:rgba(226,232,240,0.88); font-variant-numeric: tabular-nums; }
.vx-mutant-title{ margin:0; font-size:1.06rem; font-weight:1100; letter-spacing:.01em; text-align:right; flex:1; }
.vx-metrics{ margin-top:12px; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
.vx-m{ border-radius:14px; border:1px solid rgba(148,163,184,0.14); background:rgba(15,23,42,0.72); padding:10px 11px 9px; }
.vx-m .k{ font-size:.86rem; color:var(--vx-muted2); }
.vx-m .v{ font-weight:1100; font-size:1.02rem; }
.vx-m .v span{ font-size:.88rem; font-weight:900; opacity:.88; }
.vx-m .s{ margin-top:3px; font-size:.86rem; color:rgba(226,232,240,0.84); }
.vx-mutant-cta{ margin-top:12px; }
.vx-btn-primary{ border:0; border-radius:12px; padding:10px 16px; font-weight:1100; letter-spacing:.05em; text-transform:uppercase; font-size:.84rem; color:#001018; background:linear-gradient(135deg,var(--vx-accent),var(--vx-accent2)); box-shadow:0 12px 28px rgba(34,211,238,0.30); }
.vx-btn-primary[disabled]{ opacity:.55; cursor:not-allowed; box-shadow:none; filter:grayscale(.3); }
.vx-remaining{ margin-top:8px; display:flex; align-items:center; justify-content:space-between; font-size:.86rem; }
.vx-remaining-label{ color:var(--vx-muted2); }
.vx-remaining-value{ color:#facc15; font-weight:1100; }

.vx-community{ margin-top:8px; padding:7px 10px; border-radius:10px; background:rgba(15,23,42,0.70); border:1px solid rgba(55,65,81,0.8); display:flex; align-items:flex-start; gap:8px; font-size:0.86rem; }
.vx-community-badge{ display:inline-flex; align-items:center; justify-content:center; padding:4px 8px; border-radius:999px; background:rgba(251,146,60,0.12); border:1px solid rgba(251,146,60,0.8); font-weight:900; color:#fed7aa; white-space:nowrap; }
.vx-community-text{ color:#e5e7eb; font-weight:700; }

/* TCG strip: what you gain */
.vx-gains-strip{ margin-top:10px; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; }
@media (max-width:520px){ .vx-gains-strip{ grid-template-columns:1fr; } }
.vx-gains-strip .vx-g{ border-radius:14px; border:1px solid rgba(148,163,184,0.14); background:rgba(2,6,23,0.35); padding:10px 10px; display:flex; gap:10px; align-items:center; }
.vx-gains-strip .vx-g .ic{ width:34px; height:34px; border-radius:12px; display:flex; align-items:center; justify-content:center; border:1px solid rgba(34,211,238,0.22); background:linear-gradient(135deg, rgba(34,211,238,0.10), rgba(251,191,36,0.10)); }
.vx-gains-strip .vx-g .ic i{ color:rgba(226,232,240,0.95); }
.vx-gains-strip .vx-g .tx .k{ font-size:.80rem; color:rgba(226,232,240,0.70); font-weight:1000; }
.vx-gains-strip .vx-g .tx .v{ font-size:.98rem; font-weight:1200; color:#fff; line-height:1.15; }

/* Card back */
.vx-cardflip{ margin-top:10px; width:100%; border:1px solid rgba(148,163,184,0.22); background:rgba(2,6,23,0.40); color:rgba(226,232,240,0.95); border-radius:14px; padding:10px 12px; font-weight:1100; display:flex; align-items:center; justify-content:center; gap:10px; cursor:pointer; }
.vx-cardflip.open{ border-color:rgba(34,211,238,0.42); box-shadow:0 0 0 3px rgba(34,211,238,0.10); }
.vx-cardback{ margin-top:10px; border-radius:14px; border:1px solid rgba(148,163,184,0.14); background:rgba(15,23,42,0.60); padding:12px; }
.vx-cardback-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
@media (max-width:720px){ .vx-cardback-grid{ grid-template-columns:1fr; } }
.vx-back{ border-radius:14px; border:1px solid rgba(148,163,184,0.14); background:rgba(2,6,23,0.35); padding:10px 12px; }
.vx-back .t{ display:flex; align-items:center; gap:10px; font-weight:1200; color:#fff; }
.vx-back .t i{ color:var(--vx-warn); }
.vx-back .p{ margin-top:7px; color:rgba(226,232,240,0.88); line-height:1.5; font-size:.95rem; }
.vx-mutedline{ color:rgba(226,232,240,0.72); }

#notification{ position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); z-index:2000; width:100%; max-width:420px; padding:0 8px; }
#notification .alert{ background:#020617; color:#e5e7eb; border:1px solid rgba(34,197,94,0.75); border-radius:16px; padding:18px; box-shadow:0 14px 38px rgba(0,0,0,0.75); }
#notification .alert-danger{ border-color:rgba(220,38,38,0.9); color:#fecaca; text-align:center; }
#notification .close-btn{ display:block; margin:12px auto 0; padding:10px 18px; background:#22c55e; color:#022c22; border:0; border-radius:999px; font-weight:900; cursor:pointer; }

/* Sticky quick dock (Telegram-first) */
.vx-dock{
  position: fixed;
  left: 50%;
  bottom: 14px;
  transform: translateX(-50%);
  z-index: 1800;
  width: calc(min(1120px, 100vw) - 24px);
  max-width: 640px;
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  padding: 10px;
  border-radius: 18px;
  background: rgba(2,6,23,0.78);
  border: 1px solid rgba(148,163,184,0.18);
  box-shadow: 0 18px 50px rgba(0,0,0,0.55);
  backdrop-filter: blur(10px);
}
.vx-dock.vx-dock-desktop-hide{ display:none; }
.vx-dock-btn{
  border: 1px solid rgba(148,163,184,0.18);
  background: rgba(15,23,42,0.72);
  color: rgba(226,232,240,0.95);
  border-radius: 14px;
  padding: 10px 8px 9px;
  font-weight: 1000;
  cursor: pointer;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap: 6px;
  min-height: 54px;
}
.vx-dock-btn i{ color: rgba(226,232,240,0.95); }
.vx-dock-btn span{ font-size: .78rem; letter-spacing: .01em; }
.vx-dock-btn:active{ transform: translateY(1px); }
.vx-dock-btn.is-primary{
  border-color: rgba(34,211,238,0.35);
  background: linear-gradient(135deg, rgba(0,255,224,0.25), rgba(56,189,248,0.20));
}
@media (max-width: 520px){
  .vx-dock{ bottom: 10px; padding: 9px; gap: 8px; border-radius: 16px; }
  .vx-dock-btn{ min-height: 52px; }
}
@media (prefers-reduced-motion: reduce){
  .vx-dock-btn:active{ transform:none; }
}

@keyframes livePulse{ 0%{ box-shadow:0 0 0 0 rgba(34,197,94,.8);} 70%{ box-shadow:0 0 0 10px rgba(34,197,94,0);} 100%{ box-shadow:0 0 0 0 rgba(34,197,94,0);} }
@media (prefers-reduced-motion: reduce){ .vx-tag .dot{ animation:none !important; } .vx-mutant{ transition:none !important; } }


/* =====================
   TCG FOIL FRAMES + HOLO
   ===================== */
.vx-mutant{ position:relative; }
.vx-mutant::before,
.vx-mutant::after{ content:""; position:absolute; inset:0; border-radius:18px; pointer-events:none; }
/* frame glow */
.vx-mutant::before{ opacity:0.0; transition:opacity .22s ease; }
.vx-mutant:hover::before{ opacity:1; }
/* holo sweep */
.vx-mutant::after{
  opacity:0;
  mix-blend-mode:screen;
  background:
    radial-gradient(circle at 20% 10%, rgba(255,255,255,0.18), transparent 35%),
    radial-gradient(circle at 80% 60%, rgba(0,255,224,0.12), transparent 40%),
    linear-gradient(120deg, transparent 0%, rgba(255,255,255,0.14) 22%, transparent 45%, rgba(56,189,248,0.12) 62%, transparent 100%);
  transform: translateX(-20%) translateY(-10%) rotate(-6deg);
  transition: opacity .22s ease;
  animation: vxHolo 4.8s linear infinite;
}
.vx-mutant:hover::after{ opacity:.75; }
@keyframes vxHolo{
  0%{ transform: translateX(-26%) translateY(-18%) rotate(-8deg); filter:hue-rotate(0deg); }
  50%{ transform: translateX(26%) translateY(18%) rotate(-8deg); filter:hue-rotate(120deg); }
  100%{ transform: translateX(-26%) translateY(-18%) rotate(-8deg); filter:hue-rotate(240deg); }
}
@media (prefers-reduced-motion: reduce){
  .vx-mutant::after{ animation:none !important; }
}

/* rarity-specific foil frames */
.vx-tier-common{ --vx-frameA: rgba(148,163,184,0.28); --vx-frameB: rgba(148,163,184,0.08); }
.vx-tier-uncommon{ --vx-frameA: rgba(34,197,94,0.40); --vx-frameB: rgba(34,197,94,0.10); }
.vx-tier-rare{ --vx-frameA: rgba(56,189,248,0.45); --vx-frameB: rgba(56,189,248,0.10); }
.vx-tier-epic{ --vx-frameA: rgba(168,85,247,0.48); --vx-frameB: rgba(168,85,247,0.12); }
.vx-tier-legendary{ --vx-frameA: rgba(251,191,36,0.55); --vx-frameB: rgba(249,115,22,0.14); }
.vx-tier-mythic{ --vx-frameA: rgba(244,63,94,0.55); --vx-frameB: rgba(59,130,246,0.14); }

.vx-tier-common::before,
.vx-tier-uncommon::before,
.vx-tier-rare::before,
.vx-tier-epic::before,
.vx-tier-legendary::before,
.vx-tier-mythic::before{
  border: 1px solid var(--vx-frameA);
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.06) inset,
    0 0 0 6px var(--vx-frameB) inset,
    0 18px 60px rgba(0,0,0,0.35);
  background: radial-gradient(circle at 30% 0%, var(--vx-frameB), transparent 55%);
}

/* Rarity chip: pattern */
.vx-tag-rarity{
  position:relative;
  overflow:hidden;
}
.vx-tag-rarity::after{
  content:""; position:absolute; inset:0; opacity:.28; pointer-events:none;
  background:
    repeating-linear-gradient(135deg, rgba(255,255,255,0.16) 0 2px, transparent 2px 6px);
  mix-blend-mode: overlay;
}

/* =====================
   Projection Modal
   ===================== */
html.vx-modal-open, body.vx-modal-open{ overflow:hidden; }
.vx-modal{ position:fixed; inset:0; z-index:9999; display:flex; align-items:center; justify-content:center; padding:18px; }
.vx-modal[hidden]{ display:none !important; }
.vx-modal-backdrop{ position:absolute; inset:0; background:rgba(2,6,23,0.78); backdrop-filter: blur(10px); }
.vx-modal-card{ position:relative; width:100%; max-width:560px; border-radius:22px; border:1px solid rgba(148,163,184,0.18);
  background: linear-gradient(180deg, rgba(2,6,23,0.96), rgba(15,23,42,0.90));
  box-shadow: 0 24px 80px rgba(0,0,0,0.72);
  padding:16px; }
.vx-modal-x{ position:absolute; top:10px; right:10px; width:42px; height:42px; border-radius:14px; border:1px solid rgba(148,163,184,0.18);
  background: rgba(2,6,23,0.55); color: rgba(226,232,240,0.92); cursor:pointer; }
.vx-modal-top{ padding:6px 6px 10px; }
.vx-modal-k{ font-weight:1100; font-size:.78rem; letter-spacing:.12em; text-transform:uppercase; color: rgba(226,232,240,0.72); }
.vx-modal-h{ margin-top:4px; font-weight:1200; font-size:1.18rem; }
.vx-modal-sub{ margin-top:4px; color: rgba(226,232,240,0.82); font-weight:900; font-size:.92rem; }
.vx-modal-grid{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:10px; padding:6px; }
@media (max-width: 520px){ .vx-modal-grid{ grid-template-columns: 1fr; } }
.vx-mcard{ border-radius:16px; border:1px solid rgba(148,163,184,0.14); background:rgba(2,6,23,0.40); padding:10px 12px; }
.vx-mcard .k{ color: rgba(226,232,240,0.70); font-weight:1000; font-size:.82rem; }
.vx-mcard .v{ margin-top:4px; font-weight:1200; font-size:1.05rem; }
.vx-modal-note{ margin:10px 6px 4px; color: rgba(226,232,240,0.80); font-size:.92rem; line-height:1.5; }

/* Projection button */
.vx-back-cta{ margin-top:10px; }
.vx-proj-btn{ width:100%; border-radius:12px; border:1px solid rgba(34,211,238,0.28);
  background: linear-gradient(135deg, rgba(0,255,224,0.18), rgba(56,189,248,0.16));
  color:#e6fffb; font-weight:1100; padding:10px 12px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px; }
.vx-proj-btn:hover{ border-color:rgba(34,211,238,0.55); box-shadow:0 0 0 3px rgba(34,211,238,0.10); }


/* =====================
   MODAL + PROJECTION CTA
   ===================== */
html.vx-modal-open, html.vx-modal-open body{ overflow:hidden !important; }
.vx-back-cta{ margin-top:10px; }
.vx-proj-btn{ width:100%; display:flex; align-items:center; justify-content:center; gap:10px; padding:10px 12px; border-radius:12px; border:1px solid rgba(56,189,248,0.28); background:rgba(2,6,23,0.40); color:rgba(226,232,240,0.96); font-weight:1100; cursor:pointer; }
.vx-proj-btn i{ color:var(--vx-accent2); }
.vx-proj-btn:hover{ background:rgba(15,23,42,0.75); border-color:rgba(56,189,248,0.42); }

.vx-modal{ position:fixed; inset:0; z-index:5000; display:flex; align-items:center; justify-content:center; padding:14px; }
.vx-modal-backdrop{ position:absolute; inset:0; background:rgba(2,6,23,0.72); backdrop-filter: blur(8px); }
.vx-modal-card{ position:relative; width:100%; max-width:560px; border-radius:22px; border:1px solid rgba(148,163,184,0.18); background:rgba(5,7,18,0.92); box-shadow:0 22px 80px rgba(0,0,0,0.72); padding:16px; }
.vx-modal-x{ position:absolute; top:10px; right:10px; width:42px; height:42px; border-radius:999px; border:1px solid rgba(148,163,184,0.18); background:rgba(2,6,23,0.45); color:rgba(226,232,240,0.92); display:flex; align-items:center; justify-content:center; cursor:pointer; }
.vx-modal-x:hover{ background:rgba(15,23,42,0.85); }
.vx-modal-top{ padding-right:44px; }
.vx-modal-k{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; border:1px solid rgba(56,189,248,0.20); background:rgba(56,189,248,0.10); color:#e0f2fe; font-weight:1100; font-size:.82rem; }
.vx-modal-h{ margin-top:10px; font-size:1.25rem; font-weight:1200; }
.vx-modal-sub{ margin-top:4px; color:rgba(226,232,240,0.72); font-weight:900; }
.vx-modal-grid{ margin-top:14px; display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:10px; }
@media (max-width: 520px){ .vx-modal-grid{ grid-template-columns:1fr; } }
.vx-mcard{ border-radius:16px; border:1px solid rgba(148,163,184,0.14); background:rgba(15,23,42,0.72); padding:12px; }
.vx-mcard .k{ color:rgba(226,232,240,0.70); font-size:.82rem; font-weight:1000; }
.vx-mcard .v{ margin-top:6px; font-weight:1200; font-size:1.08rem; font-variant-numeric: tabular-nums; }
.vx-modal-note{ margin-top:12px; padding:10px 12px; border-radius:16px; border:1px solid rgba(148,163,184,0.14); background:rgba(2,6,23,0.35); color:rgba(226,232,240,0.86); line-height:1.45; }
</style>
    <!-- SEASON PASS -->
    <?php
      $vx_season = vx_get_current_season($db);
      $vx_sid = (int)($vx_season['id'] ?? 0);
      $vx_pass_active = ($vx_sid>0) ? vx_season_pass_active($db, (int)$uid, $vx_sid) : false;
      $vx_pass_idk = vx_issue_idk('season_pass');
    ?>
    <section class="vx-section" id="season-pass">
      <div class="vx-section-hd">
        <h2><i class="fa-solid fa-crown"></i> Season Pass</h2>
        <p>$25 per season • perks + cosmetics • resets every season.</p>
      </div>

      <div class="vx-prime vx-prime-premium vx-sp-frame">
        <div class="vx-prime-grid">
          <div class="vx-prime-left">
            <div class="vx-prime-topline">
              <span class="vx-prime-chip"><i class="fa-solid fa-crown"></i> Season Pass</span>
              <span class="vx-prime-tag"><i class="fa-solid fa-bolt"></i> +10% points</span>
              <span class="vx-prime-tag"><i class="fa-solid fa-chart-line"></i> +5% cap buffer</span>
            </div>

            <div class="vx-prime-h">Valid for this season only</div>

            <div class="vx-prime-price">
              <div class="vx-prime-price-main">
                <div class="k">Price</div>
                <div class="v">$<?= number_format(vx_season_pass_price_usd(), 0); ?></div>
              </div>
              <div class="vx-prime-price-sub">
                <div class="k">Telegram Stars</div>
                <div class="v"><?= (int)vx_season_pass_stars_xtr(); ?> XTR</div>
              </div>
              <div class="vx-prime-split">
                <span><i class="fa-solid fa-wand-magic-sparkles"></i> Cosmetics</span>
                <span><i class="fa-solid fa-rotate"></i> Resets next season</span>
              </div>
            </div>

            <div class="vx-prime-p">
              Unlock a premium badge + cosmetics across the app, plus a small competitive edge during this season.
            </div>

            <div class="vx-prime-feats vx-prime-feats-premium">
              <div class="vx-feat2">
                <div class="ic"><i class="fa-solid fa-crown"></i></div>
                <div class="bd"><b>Badge</b><span>profile + leaderboard</span></div>
              </div>
              <div class="vx-feat2">
                <div class="ic"><i class="fa-solid fa-bolt"></i></div>
                <div class="bd"><b>Boost</b><span>+10% points</span></div>
              </div>
              <div class="vx-feat2">
                <div class="ic"><i class="fa-solid fa-chart-line"></i></div>
                <div class="bd"><b>Buffer</b><span>+5% cap slots</span></div>
              </div>
            </div>
          </div>

          <div class="vx-prime-right">
            <div class="vx-prime-ctaCard">
              <div class="vx-prime-ctaHead">
                <div class="t">Activate now</div>
                <div class="s">One tap inside Telegram.</div>
              </div>

              <?php if ($vx_pass_active): ?>
                <button class="btn vx-btn-secondary w-100" disabled><i class="fa-solid fa-check"></i> Active this season</button>
              <?php else: ?>
                <button class="btn vx-btn-primary w-100" id="vxPassBuyBtn"><i class="fa-solid fa-crown"></i> Buy for $25</button>
                <button class="btn vx-btn-ghost w-100 mt-2" id="vxPassStarsBtn"><i class="fa-solid fa-star"></i> Pay with Stars</button>
              <?php endif; ?>

              <div class="vx-mini vx-prime-mini">
                <i class="fa-solid fa-circle-info"></i>
                Pass resets at rollover. Seed timers never reset.
              </div>
            </div>
          </div>
        </div>
      </div>

      <script>
      (function(){
        const buyBtn = document.getElementById('vxPassBuyBtn');
        const starsBtn = document.getElementById('vxPassStarsBtn');
        const toast = (m)=> (window.toast ? window.toast(m) : alert(m));
        const csrf = (document.querySelector('meta[name="vx-csrf"]')||{}).content || '';
        const idk = "<?= htmlspecialchars($vx_pass_idk, ENT_QUOTES); ?>";

        if (buyBtn){
          buyBtn.addEventListener('click', ()=>{
            buyBtn.disabled = true;
            fetch('/api/user/season_pass_buy.php', {
              method:'POST', credentials:'include',
              headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'csrf='+encodeURIComponent(csrf)+'&idk='+encodeURIComponent(idk)
            }).then(r=>r.json()).then(j=>{
              toast(j.msg||'Done');
              if (j.ok) location.reload();
              else buyBtn.disabled = false;
            }).catch(()=>{ buyBtn.disabled=false; toast('Network error'); });
          });
        }
        if (starsBtn){
          starsBtn.addEventListener('click', ()=>{
            starsBtn.disabled = true;
            fetch('/api/user/season_pass_stars_link.php', {
              method:'POST', credentials:'include',
              headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'csrf='+encodeURIComponent(csrf)
            }).then(r=>r.json()).then(j=>{
              if(j.ok && j.url){ location.href = j.url; return; }
              toast(j.msg||'Stars unavailable'); starsBtn.disabled=false;
            }).catch(()=>{ starsBtn.disabled=false; toast('Network error'); });
          });
        }
      })();
      </script>
    </section>



// duration handled via vx_boosts.duration_sec
