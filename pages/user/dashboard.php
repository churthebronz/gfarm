<?php
declare(strict_types=1);

// This page is loaded via the router, but can also be hit directly in some setups.
// Ensure FastCore is defined BEFORE loading any core files that guard on it.
if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/earnings_helpers.php';
require_once __DIR__ . "/../../core/vx_guardians.php";
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';
require_once __DIR__ . '/../../core/vx_activity.php';

// NOTE:
// Some routes include pages inside a closure (error boundary). In that case,
// normal variables like $db/$config from the parent include scope are NOT
// automatically visible here unless we explicitly pull them from $GLOBALS.
global $db, $config, $opt;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

// Whats-new banner (fail-soft). Some builds reference $vx_whatsNew near the footer.
// If it's undefined/null, our error boundary can escalate notices into a 500.
$vx_whatsNew = '';
try {
    if (is_array($config) && isset($config['whats_new'])) {
        $vx_whatsNew = (string)$config['whats_new'];
    } elseif (is_object($config) && isset($config->whats_new)) {
        $vx_whatsNew = (string)$config->whats_new;
    }
} catch (Throwable $e) { $vx_whatsNew = ''; }

/* ----------------------------------
   GreenFarm Dashboard (home-theme)
      - VX Tabs unified: single script, single "active" class
-----------------------------------*/

require_once __DIR__ . '/../../core/vx_daily_shop.php';
require_once __DIR__ . '/../../core/vx_guardian_art.php';
require_once __DIR__ . '/../../core/vx_rarity.php';

/* ---------- Daily Shop (no cron) ---------- */
$vx_daily_shop = null;
$vx_daily_plans = [];
$vx_daily_featured_id = 0;
$vx_daily_reset_ts = 0;
try {
  if (function_exists('vx_daily_shop_pick')) {
    $vx_daily_shop = vx_daily_shop_pick($db, 9);
    $vx_daily_plans = (array)($vx_daily_shop['plans'] ?? []);
    $vx_daily_featured_id = (int)($vx_daily_shop['featured_id'] ?? 0);
    $vx_daily_reset_ts = (int)($vx_daily_shop['reset_ts'] ?? 0);
  }
} catch (Throwable $e) {}

$opt['title'] = 'GreenFarm Dashboard';

/* Logout */
$seg1 = '';
try {
    if (isset($pg) && is_object($pg) && isset($pg->segment) && is_array($pg->segment)) {
        $seg1 = (string)($pg->segment[1] ?? '');
    } else {
        // fallback for direct hits
        $parts = explode('/', trim((string)($_SERVER['REQUEST_URI'] ?? ''), '/'));
        $seg1 = (string)($parts[1] ?? '');
    }
} catch (Throwable $e) { $seg1 = ''; }
if ($seg1 === 'logout') {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    session_destroy();
    header('Location: /');
    exit;
}

/* Session & Auth */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

// If no PHP session uid (Telegram WebApp case), use tg_sess via require_tg_session.php.
// Use SOFT mode here so page requests can still redirect cleanly instead of emitting JSON 401.
if ($uid <= 0) {
    if (!defined('VX_SOFT_AUTH')) { define('VX_SOFT_AUTH', true); }
    require_once __DIR__ . '/../../api/require_tg_session.php';
    $uid = isset($GLOBALS['UID']) ? (int)$GLOBALS['UID'] : 0;
}

// Still no user? Kick back to home
if ($uid <= 0) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/vx_founders.php';

if (!function_exists('vx_meta_get_many')) {
    /**
     * Batch-load multiple user_meta keys in one query to reduce dashboard query fan-out.
     * Returns map: [key => value]. Missing keys are omitted.
     */
    function vx_meta_get_many($db, int $uid, array $keys): array {
        $out = [];
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static function($k){ return $k !== ''; })));
        if ($uid <= 0 || empty($keys) || !is_object($db) || !method_exists($db, 'query')) return $out;
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT meta_key, meta_value FROM user_meta WHERE user_id=? AND meta_key IN ($ph)";
        $params = array_merge([$uid], $keys);
        try {
            $rows = $db->query($sql, $params)->fetchAll();
            foreach ((array)$rows as $r) {
                $k = (string)($r['meta_key'] ?? '');
                if ($k === '') continue;
                $out[$k] = (string)($r['meta_value'] ?? '');
            }
        } catch (Throwable $e) {}
        return $out;
    }
}

// Fail-safe: even if a user lands on dashboard through a restored PHP session,
// we still want first-login Founders grants to happen.
try {
    $vxFndTickKey = 'vx_founders_tick_at_' . (string)$uid;
    $vxFndTickAt = (int)($_SESSION[$vxFndTickKey] ?? 0);
    if ($vxFndTickAt < (time() - 600)) {
        vx_founders_on_login($db, $uid, time());
        $_SESSION[$vxFndTickKey] = time();
    }
} catch (Throwable $e) {}
$vxStreak = 0;
// Claim streak: increments when the user successfully collects yield (not just opens the app)

/* DB */
if (!isset($db) || !($db instanceof db)) {
    exit('DB not available');
}

/* ==== USER & EARNINGS ==== */
$user = $db->query(
    "SELECT 
        login,
        username,
        sum_in,
        sum_out,
        bank,
        income,
        points_spendable,
        points_total,
        ref_code,
        tg_username,
        tg_name,
        vx_onboarded
     FROM db_users 
     WHERE id = ? 
     LIMIT 1",
    [$uid]
)->fetchArray() ?: [];

$vxMeta = vx_meta_get_many($db, $uid, [
    'claim_streak_days',
    'genesis_badge',
    'founders_badge',
    'affiliate_badge',
    'partner_badge',
    'lp_balance',
    'founders_popup_seen'
]);
$vxStreak = (int)($vxMeta['claim_streak_days'] ?? 0);

/* SAFE FALLBACKS */
$wallet         = (float)(($user['bank'] ?? 0) + ($user['income'] ?? 0));
$pointsBalance  = (float)($user['points_spendable'] ?? 0);
$pointsLifetime = (float)($user['points_total'] ?? 0);
$refCode        = (string)($user['ref_code'] ?? '');

/* Display name */
$displayName = '';
if (!empty($user['tg_username'])) {
    $displayName = '@' . ltrim((string)$user['tg_username'], '@');
} elseif (!empty($user['tg_name'])) {
    $displayName = (string)$user['tg_name'];
} else {
    $displayName = (string)($user['login'] ?? '');
}

// Season Pass badge (the "Season Pass" meta is treated as season pass for backwards-compat)
$hasSeasonPass = false;
$seasonPassLabel = 'Season Pass';
try {
    $season = vx_get_current_season($db);
    $sid = (int)($season['id'] ?? 0);
    if ($sid > 0) {
        $hasSeasonPass = (bool)vx_season_pass_active($db, $uid, $sid);
    }
} catch (Throwable $e) {
    $hasSeasonPass = false;
}

// Back-compat: older installs used a meta flag called "genesis_badge".
if (!$hasSeasonPass) {
    try {
        $hasSeasonPass = ((string)($vxMeta['genesis_badge'] ?? '0') === '1');
    } catch (Throwable $e) {}
}

// New badges (cosmetic + social proof)
$hasFounders = false;
$hasFounders = ((string)($vxMeta['founders_badge'] ?? '0') === '1');
try {
  $hasAffiliate = ((string)($vxMeta['affiliate_badge'] ?? '0') === '1');
  if (!$hasAffiliate) $hasAffiliate = ((string)($vxMeta['partner_badge'] ?? '0') === '1'); // back-compat
} catch (Throwable $e) { $hasAffiliate = false; }

/* VX list price (indicative) */
$vxUsdPrice = 0.10;

/* === Points → VX → USD (target) helpers === */
$VX_CONV       = 100; // 100 VP → 1 VX (target)
$vxFromSpend   = (int)floor($pointsBalance  / $VX_CONV);
$usdFromSpend  = $vxFromSpend * $vxUsdPrice;
$vxFromLife    = (int)floor($pointsLifetime / $VX_CONV);
$usdFromLife   = $vxFromLife * $vxUsdPrice;

/* Back-compat (not used in chips anymore, but kept in case) */
$estVX    = $pointsLifetime / 100;
$estVXVal = $estVX * $vxUsdPrice;

/* ==== EARNINGS DATA ==== */
$st = vx_earnings_touch($db, $uid);
$profit    = (float)($st['pending'] ?? 0);
$perSecond = (float)($st['per_second'] ?? 0);
$dailyAmount   = $perSecond * 86400;
$speedDailyPct = $wallet > 0 ? ($dailyAmount / $wallet * 100) : 0;

/* ==== PRIME (12-month pass = $100 USD value in VX) ==== */
$primeUsd = 100.00;
$primeVx  = $vxUsdPrice > 0 ? $primeUsd / $vxUsdPrice : 0; // typically 100 / 0.10 = 1000 VX

/* ==== SEASONS ==== */
$season = vx_get_current_season($db);
$seasonId = (int)($season['id'] ?? 0);
$seasonNo = (int)($season['season_no'] ?? 0);
$seasonStarts = (int)($season['starts_at'] ?? time());
$seasonEnds   = (int)($season['ends_at'] ?? (time() + 86400));
$seasonPct    = vx_season_progress_pct($season);
$seasonLeft   = max(0, $seasonEnds - time());
$mySeason     = ($seasonId > 0) ? vx_user_season_counts($db, $seasonId, $uid) : ['active'=>0,'completed'=>0,'spent'=>0.0];
$weeklyRank   = vx_weekly_ref_rank($db, $uid);

$vxNow = time();

// LP balance (stored as meta; fail-soft)
$lpBalance = 0;
$lpBalance = (int)($vxMeta['lp_balance'] ?? 0);

// Crossbreed state + badges for UI
$crossbreedAlerts = [];$vxGuardianBadges = [];
try { if (function_exists("vx_guardians_touch_user")) vx_guardians_touch_user($db, $uid, $vxNow); } catch (Throwable $e) {}
try { if (function_exists("vx_guardians_pending_crossbreed")) $crossbreedAlerts = vx_guardians_pending_crossbreed($db, $uid, $vxNow, 3); } catch (Throwable $e) { $crossbreedAlerts = []; }
try { if (function_exists("vx_guardians_active_badges")) $vxGuardianBadges = vx_guardians_active_badges($db, $uid, $vxNow); } catch (Throwable $e) { $vxGuardianBadges = []; }

/* ==== ACTIVE VAULT GUARDIANS (for dashboard list) ==== */
$activeGuardians = [];
try {
    $now = time();
    $activeGuardians = $db->query(
        "SELECT s.id, s.tarif, s.`end`, s.`add`, t.title, t.price, t.speed, t.period
         FROM db_store s
         LEFT JOIN db_tarif t ON t.id = s.tarif
         WHERE s.uid = ? AND s.status = 1 AND (s.`end` = 0 OR s.`end` > ?)
         ORDER BY (CASE WHEN s.`end`=0 THEN 2147483647 ELSE s.`end` END) ASC, s.id DESC
         LIMIT 8",
        [$uid, $now]
    )->fetchAll();
} catch (Throwable $e) {
    $activeGuardians = [];
}

// Onboarding overlay (first login): shows only until dismissed.
// We also use it to present the Founders reward card during the launch window.
$vxShowOnboard = false;
$vxFoundersWindow = false;
$vxFoundersVpDay = 0;
$vxFoundersPopupSeen = false;
$vxFoundersClaimed = false;
$vxShowFoundersEveryTime = false;
$vxFoundersLaunchTs = 0;
$vxFoundersEndsTs = 0;
$vxFoundersDaysLeft = 0;
$vxFoundersDaysTotal = 30;
try {
    $vxOnb = (int)($user['vx_onboarded'] ?? 0);
    $vxFoundersWindow = function_exists('vx_founders_window_active') ? (bool)vx_founders_window_active($db, time()) : false;
    $vxFoundersVpDay = (int)vx_app_setting('founders_vp_per_day', 5);
    if ($vxFoundersVpDay < 1) $vxFoundersVpDay = 5;
    if ($vxFoundersVpDay > 100) $vxFoundersVpDay = 100;
    $vxFoundersPopupSeen = ((string)($vxMeta['founders_popup_seen'] ?? '0') === '1');

    // Claim state: founders badge is set when the Founders Guardian is granted.
    $vxFoundersClaimed = (function_exists('vx_user_has_founders_badge')) ? (bool)vx_user_has_founders_badge($db, $uid) : ((string)($vxMeta['founders_badge'] ?? '0') === '1');

    // The Founders popup can feel like a "blur" overlay on entry (especially in TG webview).
    // Keep it focused: only auto-show when the window is active AND the user hasn't claimed yet,
    // and they haven't dismissed it before.
    $vxShowFoundersEveryTime = ($vxFoundersWindow && !$vxFoundersClaimed && !$vxFoundersPopupSeen);

    // Countdown info for copy/UI.
    try {
      $vxFoundersLaunchTs = function_exists('vx_founders_launch_ts') ? (int)vx_founders_launch_ts($db, time()) : 0;
      $vxFoundersDaysTotal = (int)vx_app_setting('founders_window_days', 30);
      if ($vxFoundersDaysTotal < 1) $vxFoundersDaysTotal = 30;
      $vxFoundersEndsTs = ($vxFoundersLaunchTs > 0) ? ($vxFoundersLaunchTs + ($vxFoundersDaysTotal * 86400)) : 0;
      $vxFoundersDaysLeft = ($vxFoundersEndsTs > 0) ? (int)ceil(max(0, $vxFoundersEndsTs - time()) / 86400) : 0;
    } catch (Throwable $e) {}

    if ($vxOnb !== 1 && empty($activeGuardians) && !$vxFoundersPopupSeen) {
        $vxShowOnboard = true;
    }
} catch (Throwable $e) { $vxShowOnboard = false; }


/* ==== VAULT RUSH (always visible) ==== */
$vxRushCacheKey = 'vx_dash_rush_' . (string)$uid;
$vxRushCache = (array)($_SESSION[$vxRushCacheKey] ?? []);
$vxRushCacheTtl = 30;
$vxUseRushCache = (
  !empty($vxRushCache)
  && (int)($vxRushCache['uid'] ?? 0) === $uid
  && (int)($vxRushCache['ts'] ?? 0) >= (time() - $vxRushCacheTtl)
  && (int)($vxRushCache['day_start'] ?? 0) === (int)strtotime('today', $vxNow)
);
$vxDayStart = strtotime('today', $vxNow);
$vxDayEnd = $vxDayStart + 86400;
$vxRush = [
  'next_at' => 0,
  'next_in' => 0,
  'vaults_today' => 0,
  'claims_today' => 0,
  'active_users' => 0,
  'user_rank' => 0,
];

if ($vxUseRushCache) {
  $vxRush = (array)($vxRushCache['data'] ?? $vxRush);
} else try {
  // Rush cadence: every 6h by default (configurable later)
  $interval = 21600;
  $vxRush['next_at'] = (int)(ceil($vxNow / $interval) * $interval);
  $vxRush['next_in'] = max(0, $vxRush['next_at'] - $vxNow);

  // Seed activations today (prefer activity log; fall back to store add)
  $vxRush['vaults_today'] = 0;
  try {
    $vxRush['vaults_today'] = (int)vx_activity_count_since($db, 'vault_buy', (int)$vxDayStart);
  } catch (Throwable $e) {
    $r = $db->query("SELECT COUNT(*) AS c FROM db_store WHERE status=1 AND `add` >= ? AND `add` < ?", [$vxDayStart, $vxDayEnd])->fetchArray();
    $vxRush['vaults_today'] = (int)($r['c'] ?? 0);
  }

  // Claims today (real, based on activity log)
  try {
    $vxRush['claims_today'] = (int)vx_activity_count_since($db, 'claim', (int)$vxDayStart);
  } catch (Throwable $e) { $vxRush['claims_today'] = 0; }

  $r = $db->query("SELECT COUNT(DISTINCT uid) AS c FROM db_store WHERE status=1 AND (`end`=0 OR `end`>?)", [$vxNow])->fetchArray();
  $vxRush['active_users'] = (int)($r['c'] ?? 0);

  // Season rank (VP+LP) — true seasonal pressure
  try {
    require_once __DIR__ . '/../../core/vx_season_points.php';
    $sx = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
    $sid = (int)($sx['id'] ?? 0);
    if ($sid > 0) {
      $me = $db->query('SELECT vp_total, lp_total FROM vx_season_points WHERE uid=? AND season_id=? LIMIT 1', [$uid, $sid])->fetchArray();
      $meVp = (int)($me['vp_total'] ?? 0);
      $meLp = (int)($me['lp_total'] ?? 0);
      // LP is prestige; weigh it higher by default (tunable later)
      $lpWeight = 3;
      $meScore = $meVp + ($meLp * $lpWeight);
      $r = $db->query('SELECT COUNT(*) AS better FROM vx_season_points WHERE season_id=? AND (vp_total + (lp_total * ?)) > ?', [$sid, $lpWeight, $meScore])->fetchArray();
      $vxRush['user_rank'] = (int)($r['better'] ?? 0) + 1;
    } else {
      $vxRush['user_rank'] = 1;
    }
  } catch (Throwable $e) {
    $vxRush['user_rank'] = 1;
  }
  $_SESSION[$vxRushCacheKey] = [
    'uid' => $uid,
    'ts' => time(),
    'day_start' => (int)$vxDayStart,
    'data' => $vxRush,
  ];
} catch (Throwable $e) {
  // fail-soft
}

/* ==== Feature-flag for token listing (controls prime activation) ==== */
$tokenListed = (bool)($GLOBALS['VX_LISTED'] ?? false);

/* ==== AJAX CLAIM HANDLER (form POST – kept for backwards compat) ==== */
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim'])) {
    $okCsrf = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
    if (!$okCsrf) {
        $resp = ['ok' => false, 'msg' => 'Security token invalid.'];
    } else {
        $resp = vx_earnings_claim($db, $uid);
        if (!isset($resp['msg'])) {
            $resp['msg'] = $resp['ok'] ? 'Yield collected successfully!' : 'Nothing to collect.';
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp);
    exit;
}
?>

<!-- === GREENFARM DASHBOARD vNEXT (Premium Hub) === -->
<div class="gf-dash2">

  <div class="gf-dash-head">
    <div>
      <div class="gf-dash-title">Your Farm Hub</div>
      <div class="gf-dash-sub">Shop • Mutants • Earnings • Profile</div>
    </div>
    <div class="gf-sec-meta"><span class="gf-dot"></span> Resets in <span class="gf-countdown" data-reset-ts="<?= (int)$vx_daily_reset_ts; ?>">--:--:--</span></div>
  </div>

  <div class="gf-actions" aria-label="Quick actions">
    <a class="gf-act" href="/user/guardians"><i class="fa-solid fa-store"></i> Shop</a>
    <a class="gf-act" href="/user/guardians"><i class="fa-solid fa-seedling"></i> My Mutants</a>
    <a class="gf-act" href="/user/wallet" data-open-wallet="1"><i class="fa-solid fa-wallet"></i> Wallet</a>
    <a class="gf-act" href="/user/affiliate"><i class="fa-solid fa-user-group"></i> Invite</a>
    <a class="gf-act" href="/user/history"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
    <a class="gf-act" href="/user/codex"><i class="fa-solid fa-book"></i> Codex</a>
  </div>

  <!-- 1) DAILY SHOP -->
  <section class="gf-section gf-dashshop" aria-label="Daily GreenFarm Shop">
    <div class="gf-sec-head">
      <div class="gf-sec-title">🛒 Daily GreenFarm Shop</div>
      <div class="gf-sec-meta">9 picks for 24h • 1 Featured Legendary</div>
    </div>

    <?php if (!empty($vx_daily_plans)): ?>
      <div class="gf-slider" id="gfDashDailySlider" data-autoplay="1">
        <button class="gf-snav gf-snav-left" type="button" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></button>

        <div class="gf-track-wrap">
          <div class="gf-track">
            <?php foreach ($vx_daily_plans as $p):
              $id = (int)($p['id'] ?? 0);
              $title = (string)($p['title'] ?? ('Mutant ' . $id));
              $price = (float)($p['price'] ?? 0);
              $period  = (int)($p['period'] ?? 0);
              $percent = (float)($p['percent'] ?? ($p['percent_day'] ?? 0));
              $speed   = (float)($p['speed'] ?? 0);
              $min     = (float)($p['min'] ?? ($p['min_sum'] ?? 0));
              $max     = (float)($p['max'] ?? ($p['max_sum'] ?? 0));

              $gno = (int)($p['guardian_no'] ?? 0);
              if ($gno <= 0) $gno = $id;
              $dexNo = '#' . str_pad((string)$gno, 3, '0', STR_PAD_LEFT);

              $fallbackKey = (string)($p['img'] ?? '');
              $rar = 'common';
              try { $rx = vx_rarity_for_tarif($db, $id, $title, $price); $rar = (string)($rx['rarity'] ?? 'common'); } catch (Throwable $e) {}
              $rar = function_exists('vx_rarity_normalize') ? vx_rarity_normalize($rar) : strtolower($rar);

              $artKey = '';
              try { $artKey = vx_guardian_art_key_for_level($db, $id, 0, $fallbackKey); } catch (Throwable $e) { $artKey = $fallbackKey; }

              $artUrl = '/assets/img/guardians/placeholder.png';
              $ak = trim((string)$artKey);
              if ($ak !== '' && (strpos($ak, 'http') === 0 || strpos($ak, '/') === 0)) { $artUrl = $ak; }
              else { $artUrl = function_exists('vx_img_items_url_from_key') ? vx_img_items_url_from_key((string)$ak) : ('/img/items/' . preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$ak) . '.png'); }

              $isFeatured = ($id > 0 && $id === (int)$vx_daily_featured_id);

              // Stars derived from rarity (UI only)
              $rarStars = 1;
              switch ($rar) {
                case 'rare': $rarStars = 2; break;
                case 'epic': $rarStars = 3; break;
                case 'mythic': $rarStars = 4; break;
                case 'legendary': $rarStars = 5; break;
                default: $rarStars = 1; break;
              }
              $starsHtml = '';
              for ($si=0; $si<$rarStars; $si++){ $starsHtml .= '<i class="fa-solid fa-star"></i>'; }

              $dailyPct = (float)$percent;
              $cycleDays = (int)$period;
              $totalPct = ($dailyPct > 0 && $cycleDays > 0) ? ($dailyPct * $cycleDays) : 0;

              // Lore / flavor
              $lore = trim((string)($p['text'] ?? ''));
              if ($lore !== '') {
                $lore = preg_replace('~\s+~', ' ', $lore);
                if (strlen($lore) > 220) $lore = substr($lore, 0, 217) . '...';
              }
              $priceUsd = (float)$price;
            ?>
              <article class="gf-gcard gf-gcard-<?= htmlspecialchars($rar, ENT_QUOTES); ?> <?= $isFeatured ? 'is-featured' : ''; ?>" data-id="<?= $id; ?>" role="group" aria-label="<?= htmlspecialchars($title, ENT_QUOTES); ?>">
                <div class="gf-gframe" aria-hidden="true"></div>
                <span class="gf-gbeam" aria-hidden="true"><i class="gf-gbeam-dot"></i></span>
                <span class="gf-gshine" aria-hidden="true"></span>

                <header class="gf-ghead">
                  <div class="gf-gname"><?= htmlspecialchars($title, ENT_QUOTES); ?></div>
                  <div class="gf-gsubrow">
                    <div class="gf-gdex"><?= htmlspecialchars($dexNo, ENT_QUOTES); ?></div>
                    <div class="gf-gstars" aria-label="Rarity stars"><?= $starsHtml; ?></div>
                  </div>
                </header>

                <div class="gf-gmedia">
                  <?php if ($isFeatured): ?>
                    <div class="gf-feature-pill"><i class="fa-solid fa-crown"></i> Featured</div>
                  <?php endif; ?>
                  <img loading="lazy" decoding="async" src="<?= htmlspecialchars($artUrl, ENT_QUOTES); ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES); ?>">
                  <div class="gf-grarity">
                    <span class="gf-rdot" aria-hidden="true"></span>
                    <span class="gf-rtxt"><?= strtoupper(htmlspecialchars($rar, ENT_QUOTES)); ?></span>
                  </div>
                </div>

                <div class="gf-gbody">
                  <div class="gf-gquick" aria-label="Key stats">
                    <span class="gf-qchip gf-qchip-a"><i class="fa-solid fa-bolt"></i> <?= $dailyPct > 0 ? rtrim(rtrim(number_format($dailyPct, 2), '0'), '.') . '%/day' : '—'; ?></span>
                    <span class="gf-qchip gf-qchip-b"><i class="fa-regular fa-clock"></i> <?= $cycleDays > 0 ? ($cycleDays . ' days') : '—'; ?></span>
                    <span class="gf-qchip gf-qchip-c"><i class="fa-solid fa-chart-line"></i> <?= $totalPct > 0 ? rtrim(rtrim(number_format($totalPct, 2), '0'), '.') . '% cycle' : '—'; ?></span>
                  </div>

                  <button class="gf-morebtn" type="button" aria-expanded="false">
                    <span>More info</span>
                    <i class="fa-solid fa-chevron-down"></i>
                  </button>

                  <div class="gf-more" hidden>
                    <?php if ($lore !== ''): ?>
                      <div class="gf-lore">
                        <div class="gf-lore-k">Lore</div>
                        <div class="gf-lore-v"><?= htmlspecialchars($lore, ENT_QUOTES); ?></div>
                      </div>
                    <?php endif; ?>

                    <div class="gf-gstats2" role="list" aria-label="Detailed stats">
                      <div class="gf-s2" role="listitem"><div class="k">Price</div><div class="v">$<?= number_format($priceUsd, 2); ?> USD</div></div>
                      <div class="gf-s2" role="listitem"><div class="k">Cycle</div><div class="v"><?= $cycleDays > 0 ? ($cycleDays . ' days') : '—'; ?></div></div>
                      <div class="gf-s2" role="listitem"><div class="k">Min</div><div class="v"><?= $min > 0 ? number_format($min, 2) : '—'; ?></div></div>
                      <div class="gf-s2" role="listitem"><div class="k">Max</div><div class="v"><?= $max > 0 ? number_format($max, 2) : '—'; ?></div></div>
                    </div>

                    <div class="gf-gprice">
                      <div class="gf-gprice-k">Price</div>
                      <div class="gf-gprice-v">$<?= number_format($priceUsd, 2); ?> USD</div>
                    </div>
                    <a class="gf-gbtn" href="/user/guardians">
                      Go to Shop <i class="fa-solid fa-arrow-right"></i>
                    </a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>

        <button class="gf-snav gf-snav-right" type="button" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    <?php else: ?>
      <div class="gf-mini">No plans available right now.</div>
    <?php endif; ?>
  </section>

  <!-- 2) EARNINGS (clean KPIs) -->
  <section class="gf-section" aria-label="Earnings">
    <div class="gf-sec-head">
      <div class="gf-sec-title">💰 Earnings</div>
      <div class="gf-sec-meta">Collect everything in one click</div>
    </div>

    <div class="gf-kpi">
      <div class="gf-k"><div class="t">GF Balance</div><div class="v"><?= isset($balance) ? htmlspecialchars((string)$balance, ENT_QUOTES) : '—'; ?></div></div>
      <div class="gf-k"><div class="t">GF Points</div><div class="v"><?= isset($points) ? htmlspecialchars((string)$points, ENT_QUOTES) : '—'; ?></div></div>
      <div class="gf-k"><div class="t">GF Shards</div><div class="v"><?= isset($shards) ? htmlspecialchars((string)$shards, ENT_QUOTES) : '—'; ?></div></div>
    </div>

    <div class="gf-cta">
      <button class="gf-btn2" type="button" data-collect-all><i class="fa-solid fa-hand-holding-dollar"></i> Collect All</button>
      <a class="gf-btn2" href="/user/earnings"><i class="fa-solid fa-chart-line"></i> Earnings Details</a>
    </div>
  </section>

  <!-- 3) MY MUTANTS (summary grid) -->
  <section class="gf-section" aria-label="My Mutants">
    <div class="gf-sec-head">
      <div class="gf-sec-title">🌱 My Mutants</div>
      <div class="gf-sec-meta">Your active collection</div>
    </div>

    <div class="gf-mut-grid">
      <div class="gf-mini"><div class="n">Collection</div><div class="s">Tap “My Mutants” to inspect & evolve</div></div>
      <div class="gf-mini"><div class="n">Boosts</div><div class="s">Catalysts, passes, multipliers</div></div>
      <div class="gf-mini"><div class="n">Index</div><div class="s">Complete sets for rewards</div></div>
    </div>

    <div class="gf-cta">
      <a class="gf-btn2" href="/user/guardians"><i class="fa-solid fa-seedling"></i> My Mutants</a>
      <a class="gf-btn2" href="/user/gallery"><i class="fa-solid fa-images"></i> Gallery</a>
    </div>
  </section>

  
  <!-- OWNED MUTANTS (LIGHTWEIGHT SUMMARY) -->
  <section class="gf-section" aria-label="Owned Mutants">
    <div class="gf-sec-head">
      <div class="gf-sec-title">🌱 Owned Mutants</div>
      <div class="gf-sec-meta">Active assets</div>
    </div>
    <div class="gf-mut-grid">
      <?php if (!empty($user_mutants)): foreach ($user_mutants as $m): ?>
        <div class="gf-mini">
          <div class="n"><?= htmlspecialchars($m['title'] ?? 'Mutant', ENT_QUOTES); ?></div>
          <div class="s">Rarity: <?= htmlspecialchars($m['rarity'] ?? 'common', ENT_QUOTES); ?></div>
        </div>
      <?php endforeach; else: ?>
        <div class="gf-mini"><div class="n">No mutants yet</div><div class="s">Visit shop to get started</div></div>
      <?php endif; ?>
    </div>
  </section>


<!-- 4) PROFILE + COSMETICS (hooks) -->
  <section class="gf-section" aria-label="Profile">
    <div class="gf-sec-head">
      <div class="gf-sec-title">👤 Your Farm</div>
      <div class="gf-sec-meta">Badges • Frames • Season</div>
    </div>

    <div class="gf-profile">
      <div class="gf-avatar" aria-label="Avatar frame slot"></div>
      <div>
        <div style="font-weight:1100; font-size:14px;"><?= isset($user['login']) ? htmlspecialchars((string)$user['login'], ENT_QUOTES) : 'Farmer'; ?></div>
        <div class="gf-badge"><span class="slot" aria-hidden="true"></span> Badge slot (cosmetics ready)</div>
        <div class="gf-badge"><span class="slot" aria-hidden="true"></span> Frame slot (cosmetics ready)</div>
      </div>
    </div>

    <div class="gf-cta">
      <a class="gf-btn2" href="/user/affiliate"><i class="fa-solid fa-user-group"></i> Invite & Earn</a>
      <a class="gf-btn2" href="/user/codex"><i class="fa-solid fa-book"></i> Codex</a>
    </div>
  </section>

</div>


<style>
:root{ --gf-shell: min(1140px, calc(100% - 16px)); }
.gf-dash2{ width:100%; max-width:var(--gf-shell); margin:0 auto; padding:12px 0 18px; }
.gf-dash-head{ display:flex; align-items:flex-end; justify-content:space-between; gap:10px; padding:6px 8px 12px; }
.gf-dash-title{ font-size:18px; font-weight:900; letter-spacing:.2px; }
.gf-dash-sub{ font-size:12px; opacity:.78; }
.gf-actions{ display:flex; gap:8px; flex-wrap:wrap; padding:0 8px 12px; }
.gf-act{ display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:14px;
  background:rgba(10,25,20,.55); border:1px solid rgba(0,255,150,.22);
  box-shadow:0 10px 26px rgba(0,0,0,.28); text-decoration:none; color:inherit; font-weight:800; font-size:13px; }
.gf-act i{ opacity:.92; }
.gf-section{ margin:10px 8px; padding:12px; border-radius:18px; background:rgba(10,25,20,.50);
  border:1px solid rgba(0,255,150,.18); box-shadow:0 14px 40px rgba(0,0,0,.32); position:relative; overflow:hidden; }
.gf-sec-head{ display:flex; align-items:flex-end; justify-content:space-between; gap:10px; margin-bottom:10px; }
.gf-sec-title{ font-size:15px; font-weight:1000; }
.gf-sec-meta{ font-size:12px; opacity:.78; display:flex; align-items:center; gap:8px; }
.gf-dot{ width:8px; height:8px; border-radius:99px; background:rgba(0,255,150,.95); box-shadow:0 0 18px rgba(0,255,150,.7); display:inline-block; }
.gf-kpi{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
@media (max-width:520px){ .gf-kpi{ grid-template-columns:1fr; } }
.gf-k{ padding:12px; border-radius:16px; background:rgba(0,0,0,.20); border:1px solid rgba(255,255,255,.08); }
.gf-k .t{ font-size:12px; opacity:.75; font-weight:800; }
.gf-k .v{ font-size:18px; font-weight:1100; margin-top:4px; }
.gf-cta{ margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; }
.gf-btn2{ display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:12px 14px; border-radius:14px;
  border:1px solid rgba(0,255,150,.26); background:linear-gradient(180deg, rgba(0,255,150,.16), rgba(0,0,0,.18));
  box-shadow:0 16px 36px rgba(0,0,0,.32); color:inherit; text-decoration:none; font-weight:1000; }
.gf-btn2:active{ transform:translateY(1px); }
.gf-mut-grid{ display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:10px; }
@media (min-width:860px){ .gf-mut-grid{ grid-template-columns:repeat(3, minmax(0,1fr)); } }
.gf-mini{ padding:12px; border-radius:16px; background:rgba(0,0,0,.20); border:1px solid rgba(255,255,255,.08); }
.gf-mini .n{ font-weight:1000; }
.gf-mini .s{ margin-top:4px; font-size:12px; opacity:.78; }
.gf-profile{ display:flex; align-items:center; gap:12px; }
.gf-avatar{ width:56px; height:56px; border-radius:18px; background:rgba(0,0,0,.25); border:1px solid rgba(255,255,255,.10);
  position:relative; overflow:hidden; }
.gf-avatar::after{ content:""; position:absolute; inset:-20px; background:radial-gradient(circle at 30% 20%, rgba(0,255,150,.35), transparent 55%); }
.gf-badge{ margin-top:2px; font-size:12px; opacity:.85; display:flex; align-items:center; gap:8px; }
.gf-badge .slot{ width:18px; height:18px; border-radius:99px; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.10); }

/* --- Reused Home Daily Drop card/slider CSS (subset) --- */
.gf-track{ align-items:flex-start; }
.gf-gcard{ align-self:flex-start; }
.gf-gcard:not(.is-open){
  filter: blur(2px) brightness(.55) saturate(.85);
  opacity:.6;
}
.gf-gcard.is-open{
  animation: gfJiggle .45s ease;
  z-index:60;
}
.gf-gcard.is-open .gf-more{
  display:block;
}
.gf-gcard.is-open .gf-morebtn span::before{ content:"Click card to close"; }
.gf-gcard.is-open .gf-morebtn i{ transform:rotate(180deg); }
.gf-morebtn span{ font-weight:1100; }
.gf-morebtn span::before{ content:"Click card for more info"; }
.gf-morebtn span::before{ content:"Click card to close"; }
.gf-morebtn i{ transform:rotate(180deg); }
.gf-more{
  display:block;
}
body.gf-inspect .gf-gcard:not(.is-open){
  filter: blur(2px) brightness(.55) saturate(.85);
  opacity:.6;
}
@keyframes gfJiggle{
  0%{ transform:rotate(0deg) scale(1); }

/* Minor: ensure slider nav visible in dashboard */
.gf-snav{ position:absolute; top:50%; transform:translateY(-50%); z-index:80;
  width:40px; height:40px; border-radius:14px; border:1px solid rgba(255,255,255,.10);
  background:rgba(0,0,0,.35); color:#fff; display:flex; align-items:center; justify-content:center; }
.gf-snav-left{ left:8px; }
.gf-snav-right{ right:8px; }
.gf-track-wrap{ overflow:hidden; border-radius:18px; }
.gf-track{ display:flex; gap:12px; transition:transform .35s ease; will-change:transform; }
.gf-gcard{ min-width: calc(100% - 2px); }
@media (min-width:860px){ .gf-gcard{ min-width: calc((100% - 24px) / 3); } }
</style>


