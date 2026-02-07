<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
  require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/csrf.php';
require_once __DIR__ . '/../../core/rate_limit.php';
  require_once __DIR__ . '/../../core/schema_helpers.php';
  require_once __DIR__ . '/../../core/seasons.php';
  require_once __DIR__ . '/../../core/season_pass.php';
  require_once __DIR__ . '/../../core/season pass.php';
  require_once __DIR__ . '/../../core/vx_legacy.php';
  require_once __DIR__ . '/../../core/vx_guardians.php';
  require_once __DIR__ . '/../../core/auth_mw.php';
} catch (Throwable $e) {
  // Weekly quest: start at least 1 vault this week
  try{
    require_once __DIR__ . '/../../core/vx_retention.php';
    $wk = gmdate('o-\\WW');
    vx_meta_set($db, $uid, 'qwk_'.$wk.'_vault', '1');
    // optional events log
    try{
      $db->query('INSERT INTO events_log (user_id, event_type, ctx, ip, ua, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        $uid, 'vault_start', 'tarif_'.$planId,
        (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,190),
        $now
      );
    }catch(Throwable $e){}
  }catch(Throwable $e){}

  echo json_encode(array('ok'=>false,'msg'=>'Bootstrap failed')); exit;
}

global $db;
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
if ($uid <= 0) { http_response_code(401); echo json_encode(array('ok'=>false,'msg'=>'Unauthorized')); exit; }


vx_csrf_validate_or_exit();
vx_rate_limit_or_429('buy_vault_u'.$uid, 8, 600, true);

// Idempotency (per minute) to prevent double-buy
$bucket = (int)floor(time()/60);
$idemKey = 'buy_vault:'.$uid.':'.$bucket.':'.substr(sha1(json_encode($_POST)),0,10);
$idem = vx_idempo_begin($db, $idemKey, 900);
if (($idem['status'] ?? '') === 'done') { echo json_encode($idem['response']); exit; }
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$planId = 0;
if (is_array($body) && isset($body['item'])) { $planId = (int)$body['item']; }
elseif (isset($_POST['item'])) { $planId = (int)$_POST['item']; }
if ($planId <= 0) { echo json_encode(array('ok'=>false,'msg'=>'Missing plan id')); exit; }

$now = time();
$currency = 'USD';

try {
  // Plan
  $plan = $db->query('SELECT * FROM db_tarif WHERE id = ? LIMIT 1', $planId)->fetchArray();
  if (!$plan) { echo json_encode(array('ok'=>false,'msg'=>'Plan not found')); exit; }

  // Season + active cap
  $season = vx_get_current_season($db);
  if (empty($season['ok'])) { echo json_encode(array('ok'=>false,'msg'=>'Season system unavailable')); exit; }
  // HARD LOCK: block buys if admin locked the current season
  if (!empty($season['is_locked']) && (int)$season['is_locked'] === 1) {
    $ends = (int)($season['ends_at'] ?? 0);
    $in = $ends > 0 ? max(0, $ends - time()) : 0;
    echo json_encode(array(
      'ok'=>false,
      'msg'=>'This season is LOCKED by admin. Please wait for the next season.',
      'code'=>'season_locked',
      'season'=>array('id'=>(int)$season['id'],'no'=>(int)$season['season_no'],'ends_at'=>$ends,'ends_in'=>$in),
    ));
    exit;
  }
$hasPass = false;
  try { $hasPass = vx_season_pass_active($db, (int)$uid, (int)$season['id']); } catch (Throwable $e) {}
  $capInfo = vx_check_season_cap_buffered($db, (int)$season['id'], (int)$planId, $hasPass);
  if (!$capInfo['ok']) {
    $ends = (int)($season['ends_at'] ?? 0);
    $in = $ends > 0 ? max(0, $ends - time()) : 0;
    $msg = 'This vault is LOCKED (seasonal slots filled). Reopens next season. Season Pass holders may have extra buffer slots if available.';
    echo json_encode(array(
      'ok'=>false,
      'msg'=>$msg,
      'code'=>'season_cap_reached',
      'season'=>array('id'=>(int)$season['id'],'no'=>(int)$season['season_no'],'ends_at'=>$ends,'ends_in'=>$in),
      'cap'=>$capInfo,
    ));
    exit;
  }

  // Limit per plan
  $limit = 10;
  $cntRow = $db->query('SELECT COUNT(*) AS c FROM db_store WHERE uid = ? AND tarif = ? AND status IN (1,2)', $uid, $planId)->fetchArray();
  $owned = (int)($cntRow && isset($cntRow['c']) ? $cntRow['c'] : 0);
  if ($owned >= $limit) { echo json_encode(array('ok'=>false,'msg'=>'Limit reached for this plan')); exit; }

  // Economics
  $price = (float)$plan['price'];
  $speed = (float)$plan['speed'];
  $days  = (int)$plan['period'];
  $end   = $now + 60*60*24*$days;
  $first = round(($price * $speed) / 100, 4);

  // User balances (fresh)
  $user = $db->query('SELECT id, money_p, money_b, rid FROM db_users WHERE id = ? LIMIT 1', $uid)->fetchArray();
  if (!$user) { echo json_encode(array('ok'=>false,'msg'=>'User not found')); exit; }

  $holdAvail = (float)$user['money_b'];
  $holdUse   = min($holdAvail, $price);
  $toPay     = $price - $holdUse;
  if ($price > ((float)$user['money_p'] + (float)$user['money_b'])) {
    echo json_encode(array('ok'=>false,'msg'=>'Insufficient balance')); exit;
  }

  // Apply
  $db->query(
    'UPDATE db_users SET money_p = money_p - ? + ?, money_b = IF(money_b >= ?, money_b - ?, 0), speed = speed + ?, bank = bank + ?, bankin = bankin + ?, `last` = ? WHERE id = ?',
    $toPay, $first, $holdUse, $holdUse, $speed, $price, $price, $now, $uid
  );
  $db->query(
    'INSERT INTO db_store (uid, tarif, title, speed, hashpower, `add`, `end`, `status`, `last`, season_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
    $uid, $planId, (string)$plan['title'], $speed, $price, $now, $end, $now, (int)$season['id']
  );
  $newStoreId = (int)$db->lastInsert();

  // Activity (Harvest Rush counters)
  try {
    require_once __DIR__ . '/../../core/vx_activity.php';
    // Public-safe activity for tickers
    try {
      $rar = '';
      try { require_once __DIR__ . '/../../core/vx_guardians.php'; $def = vx_guardian_definition($db, (int)$planId); $rar = (string)($def['rarity'] ?? ''); } catch (Throwable $e) {}
      vx_activity_log($db, (int)$uid, 'vault_buy', (float)$price, [
        'tarif_id'=>$planId,
        'season_id'=>(int)$season['id'],
        'store_id'=>$newStoreId,
        'title'=>(string)($plan['title'] ?? 'Mutant Crop'),
        'rarity'=>$rar,
      ]);
    } catch (Throwable $e) {}
  } catch (Throwable $e) {}

  // Crossbreed chain hook (fail-soft)
  $crossbreedInfo = ['ok'=>true,'chain_id'=>0,'crossbreed'=>false,'crossbreed_level'=>0,'rarity'=>'common','reason'=>'new'];
  try {
    if (function_exists('vx_guardian_on_purchase')) {
      $crossbreedInfo = vx_guardian_on_purchase(
        $db,
        (int)$uid,
        (int)$planId,
        (int)$newStoreId,
        (int)$season['id'],
        (string)($plan['title'] ?? ''),
        (float)$price,
        (int)$now
      );
    }
  } catch (Throwable $e) { $crossbreedInfo = ['ok'=>true,'chain_id'=>0,'crossbreed'=>false,'crossbreed_level'=>0,'rarity'=>'common','reason'=>'new']; }

  // Achievements: first paid + first crossbreed (small VP/LP bonuses) — fail-soft.
  try {
    require_once __DIR__ . '/../../core/vx_achievements.php';
    if ((float)$price > 0 && function_exists('vx_achievements_on_first_paid')) {
      vx_achievements_on_first_paid($db, (int)$uid, (int)$planId, (float)$price);
    }
    if (!empty($crossbreedInfo['crossbreed']) && function_exists('vx_achievements_on_crossbreed')) {
      $lvlA = (int)($crossbreedInfo['crossbreed_level'] ?? 1);
      vx_achievements_on_crossbreed($db, (int)$uid, $lvlA);
    }
  } catch (Throwable $e) {}

  // VP (Harvest Points) & referral
  $basePts = 0;
  try {
    $ptsRow = $db->query('SELECT points_award FROM db_tarif_points WHERE tarif_id = ? LIMIT 1', $planId)->fetchArray();
    if ($ptsRow && isset($ptsRow['points_award'])) $basePts = (int)$ptsRow['points_award'];
    if ($basePts <= 0) $basePts = (int)round($price * 1); // fallback
  } catch (Throwable $e) { $basePts = (int)round($price * 1); }

  // Perceived boost: show a crossed-out base, then the boosted total.
  // Keep it deterministic and price-tiered (so it feels fair).
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

  // Apply live event multiplier (Harvest Rush 12:00–15:00 UTC) + boosts
  $mult = 1;
  $refMult = 1.0;
  $refBoostMult = 1.0;
  $refBasePct = 0.10;
  try {
    require_once __DIR__ . '/../../core/vx_events.php';
    require_once __DIR__ . '/../../core/vx_boosts.php';
    require_once __DIR__ . '/../../core/vx_ref_boost.php';
    $mult = (int)vx_event_vault_rush_multiplier($db, $uid);
    if (vx_boost_is_active($db, $uid, 'referral_magnet')) {
      $refMult = 1.2;
    }
    $refBasePct = (float)vx_ref_base_points_pct();
  } catch (Throwable $e) {}

  if ($buyerPts > 0) {
    // Apply multiplier to full (base+bonus) so Harvest Rush feels huge.
    if ($mult > 1) { $buyerPts = (int)floor($buyerPts * $mult); }
    try {
      require_once __DIR__ . '/../../core/vx_points.php';
      vx_points_add($db, $uid, $buyerPts, 'vault_buy', [
        'tarif_id'=>$planId,
        'usd_value'=>$price,
        'base_pts'=>$basePts,
        'bonus_pts'=>$bonusPts,
        'mult'=>$mult,
        'ts'=>$now
      ]);
    } catch (Throwable $e) {}
    // Founder badge: earned by activating any vault before snapshot
    try { vx_grant_genesis_badge_if_eligible($db, $uid); } catch (Throwable $e) {}
    // Community pool (season bonus pool): add 2% of buyer points (not deducted from user)
    try {
      require_once __DIR__ . '/../../core/vx_pool.php';
require_once __DIR__ . '/../../core/vx_quests.php';
require_once __DIR__ . '/../../core/vx_ref_tiers.php';
      $poolAdd = (int)floor($buyerPts * 0.02);
      if ($poolAdd > 0) vx_pool_add($db, (int)$season['id'], $poolAdd, 'vault_buy', ['uid'=>$uid,'tarif_id'=>$planId]);
    } catch (Throwable $e) {}

    $rid = isset($user['rid']) ? (int)$user['rid'] : 0;
    if ($rid > 0) {
      // Referral points: base % * referral-magnet boost * referrer's ladder boost.
      try {
        require_once __DIR__ . '/../../core/vx_ref_boost.php';
        $refBoostMult = (float)vx_ref_points_boost_mult($db, $rid);
      } catch (Throwable $e) { $refBoostMult = 1.0; }

      $refPts = (int)floor($buyerPts * $refBasePct * $refMult * $refBoostMult);
      $strict = true;
      try {
        require_once __DIR__ . '/../../core/vx_app_settings.php';
        $strict = (bool)vx_app_setting('strict_ref_hardening', true);
      } catch (Throwable $e) { $strict = true; }

      // Strict mode: escrow referral points until the referral becomes "qualified paid" (cooldown + min USD + anti-abuse).
      // Non-strict mode: credit immediately (legacy behavior).
      if ($refPts > 0 && !$strict) {
        try { $db->query('UPDATE db_users SET points_total = points_total + ?, points_spendable = points_spendable + ? WHERE id = ?', $refPts, $refPts, $rid); } catch (Throwable $e) {}
        // Ledger insert (best-effort). If meta_json exists, store the exact pct used.
        try {
          require_once __DIR__ . '/../../core/schema_helpers.php';
          $meta = [
            'buyer_uid'=>$uid,
            'tarif_id'=>$planId,
            'usd_value'=>$price,
            'buyer_pts'=>$buyerPts,
            'base_pct'=>$refBasePct,
            'magnet_mult'=>$refMult,
            'boost_mult'=>$refBoostMult,
            'effective_pct'=>($refBasePct * $refMult * $refBoostMult),
          ];
          if (function_exists('vx_column_exists') && vx_column_exists($db, 'db_points_ledger', 'meta_json')) {
            $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, ref_uid, tarif_id, usd_value, meta_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
              $rid, $refPts, 'ref_buy_vault', $uid, $planId, $price, json_encode($meta, JSON_UNESCAPED_SLASHES), $now
            );
          } else {
            $db->query('INSERT INTO db_points_ledger (uid, delta, ctx, ref_uid, tarif_id, usd_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
              $rid, $refPts, 'ref_buy_vault', $uid, $planId, $price, $now
            );
          }
        } catch (Throwable $e) {}
      }

      // Telegram push + social proof: notify referrer on first paid activation.
      try {
        require_once __DIR__ . '/../../core/vx_affiliate.php';
        require_once __DIR__ . '/../../core/vx_tg_notify.php';
        require_once __DIR__ . '/../../core/vx_activity.php';

        // Is this buyer's first EVER paid plan guardian? (prevents spam)
        $qFirst = $db->query(
          "SELECT COUNT(*) AS c FROM vx_guardian_chains c JOIN db_tarif t ON t.id=c.tarif WHERE c.uid=? AND c.kind='plan' AND t.price>0",
          $uid
        );
        $rowFirst = $qFirst ? ($qFirst->fetchArray() ?: []) : [];
        $paidCount = (int)($rowFirst['c'] ?? 0);

        if ($paidCount === 1) {
          // Record qualification event (min USD + cooldown + anti-abuse). In strict mode, also releases held referral points after cooldown.
          $rq = ['ok'=>true,'status'=>'pending','qualify_after'=>0];
          try {
            require_once __DIR__ . '/../../core/vx_refqual.php';
            $hold = 0;
            if ($strict && $refPts > 0) { $hold = $refPts; }
            $rq = vx_refqual_record_paid_activation($db, (int)$uid, (int)$rid, (float)$price, (int)$planId, (int)$hold);
          } catch (Throwable $e) {}

          // In-app notification for referrer (pending / flagged)
          try {
            require_once __DIR__ . '/../../core/vx_retention.php';
            $lvl = (($rq['status'] ?? 'pending') === 'flagged') ? 'warning' : 'info';
            $ttl = (($rq['status'] ?? 'pending') === 'flagged') ? 'Referral under review' : 'Referral pending';
            $msg2 = 'Your referral activated their first paid Guardian.';
            if (($rq['status'] ?? 'pending') === 'flagged') {
              $msg2 .= ' This one was flagged by anti-abuse checks and may require review.';
            } else {
              $msg2 .= ' It will qualify after the cooldown.';
            }
            $msg2 .= ' Keep going to unlock the Affiliate Guardian.';
            vx_notify_once($db, (int)$rid, 'ref_paid_'.$uid, 'ref_paid_pending', $lvl, $ttl, $msg2, [
              'buyer_uid'=>(int)$uid,
              'tarif_id'=>(int)$planId,
              'usd_value'=>(float)$price,
              'qualify_after'=>(int)($rq['qualify_after'] ?? 0),
              'status'=>(string)($rq['status'] ?? 'pending'),
              'cta'=>'/user/inbox'
            ]);
          } catch (Throwable $e) {}

          // Social proof feed (still fine)
          try { vx_activity_log($db, (int)$rid, 'ref', 0, ['buyer_uid'=>(int)$uid]); } catch (Throwable $e) {}

          // Telegram push
          vx_tg_send_once($db, (int)$rid, 'ref_paid_'.$uid, "🎉 Your referral activated a paid Guardian!\n\nQualification will finalize after the cooldown.");
        }

        // Tier milestone pushes (10/25/50/100 by default)
        $qualifiedNow = vx_affiliate_qualified_paid_refs($db, (int)$rid);
        $tierNow = vx_affiliate_tier_from_qualified($qualifiedNow);
        $tierPrev = (int)vx_meta_get($db, (int)$rid, 'affiliate_tier_notified', '0');
        if ($tierNow > $tierPrev) {
          vx_meta_set($db, (int)$rid, 'affiliate_tier_notified', (string)$tierNow);
          $msg = "🏅 Affiliate tier reached: Tier " . $tierNow . "\n\nQualified paid referrals: " . $qualifiedNow . "\nDaily bonus: +" . (vx_affiliate_tier_bonus_step() * max(0, $tierNow-1)) . " VP/day, +" . (vx_affiliate_tier_bonus_step() * max(0, $tierNow-1)) . " LP/day";
          vx_tg_send_to_user($db, (int)$rid, $msg);
          try { vx_activity_log($db, (int)$rid, 'rank_up', (float)$tierNow, ['rank'=>$tierNow]); } catch (Throwable $e) {}

          // Achievement reward for referrer (small VP/LP) — fail-soft.
          try {
            require_once __DIR__ . '/../../core/vx_achievements.php';
            if (function_exists('vx_achievements_on_affiliate_tier')) {
              vx_achievements_on_affiliate_tier($db, (int)$rid, (int)$tierNow, (int)$qualifiedNow);
            }
          } catch (Throwable $e) {}
        }
      } catch (Throwable $e) {}
    }

    // Legacy VP: earned ONLY by reactivating a guardian after it has fully completed.
    // If the user had a completed guardian of the same plan that has not yet been awarded,
    // award Legacy VP now.
    try {
      $eligible = vx_legacy_find_eligible_completed($db, (int)$uid, (int)$planId);
      if ($eligible) {
        $rate = 0.25;
        try {
          // Allow tuning via app settings.
          if (function_exists('vx_app_setting')) {
            $rate = (float)vx_app_setting('legacy_vp_rate', '0.25');
          }
        } catch (Throwable $e) { $rate = 0.25; }

        if ($rate < 0.05) $rate = 0.05;
        if ($rate > 1.00) $rate = 1.00;
        $legacyPts = (int)floor($buyerPts * $rate);
        if ($legacyPts < 1) $legacyPts = 1;

        if (vx_legacy_add($db, (int)$uid, $legacyPts, 'reactivate', [
          'tarif_id'=>(int)$planId,
          'store_id'=>(int)($eligible['id'] ?? 0),
          'buyer_pts'=>(int)$buyerPts,
          'rate'=>$rate,
          'ts'=>$now,
        ])) {
          $sid = (int)($eligible['id'] ?? 0);
          vx_legacy_mark_awarded($db, (int)$uid, $sid);
          // Also mark that this completed guardian will not become a collector (user reactivated).
          try { vx_meta_set($db, (int)$uid, 'reactivated_after_store_'.$sid, '1'); } catch (Throwable $e) {}

          // In-app notification / popup.
          try {
            require_once __DIR__ . '/../../core/vx_retention.php';
            vx_notify_once($db, (int)$uid, 'legacy_award_'.$sid, 'legacy_award', 'success', 'Legacy VP earned', 'You reactivated a completed Mutant Crop and earned +'.$legacyPts.' Legacy VP.', [
              'tarif_id'=>(int)$planId,
              'store_id'=>$sid,
              'legacy_vp'=>$legacyPts,
            ]);
          } catch (Throwable $e) {}
        }
      }
    } catch (Throwable $e) {}
  }

  // Notify crossbreed (UI popups use notifications feed)
  try {
    if (!empty($crossbreedInfo['crossbreed'])) {
      require_once __DIR__ . '/../../core/vx_retention.php';
      $lvl = (int)($crossbreedInfo['crossbreed_level'] ?? 1);
      $rar = (string)($crossbreedInfo['rarity'] ?? 'common');
      $txt = ($lvl >= 5)
        ? 'Max crossbreed reached! This Guardian will become a Mutant Index collectible after it completes.'
        : 'Crossbreed successful! VP now scales higher. LP will accrue during this 30-day cycle.';
      vx_notify_once($db, (int)$uid, 'crossbreed_'.$planId.'_lvl_'.$lvl.'_store_'.$newStoreId, 'crossbreed', 'success', 'Guardian Crossbreed • Level '.$lvl, $txt, [
        'tarif_id'=>(int)$planId,
        'store_id'=>(int)$newStoreId,
        'chain_id'=>(int)($crossbreedInfo['chain_id'] ?? 0),
        'crossbreed_level'=>$lvl,
        'rarity'=>$rar,
      ]);
    }
  } catch (Throwable $e) {}

  // Daily/Weekly quests + referral tiers
  try {
    vx_daily_mark_task($db, $uid, 'vault');
    vx_weekly_mark($db, $uid, 'vault');
    vx_daily_try_award($db, $uid);
    // update tier badges (best-effort)
    vx_ref_tier_status($db, $uid);
    if (isset($rid) && (int)$rid > 0) { vx_ref_tier_status($db, (int)$rid); }
  } catch (Throwable $e) {}
// Events log (optional)
try {
  if (function_exists('vx_table_exists') && vx_table_exists($db, 'events_log')) {
    $db->query(
      'INSERT INTO events_log (user_id, event_type, ctx, ip, ua, created_at) VALUES (?, ?, ?, ?, ?, ?)',
      $uid,
      'vault_buy',
      'tarif_'.$planId,
      (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
      substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
      $now
    );
  }
} catch (Throwable $e) {}


  $shareText = '🔐 I just planted a Seed on GreenFarm and boosted my season placement. Join me: '.(string)($_SERVER['HTTP_HOST'] ?? '').'\n\nStart: https://t.me/share/url?url=&text=';

  echo json_encode(array(
    'ok'=>true,
    'msg'=>'Purchase successful: '.(string)$plan['title'].' — $'.number_format($price,2).' '.$currency,
    'crossbreed'=> $crossbreedInfo,
    'base_points'=>$basePts,
    'bonus_points'=>$bonusPts,
    'total_points'=>$buyerPts,
    'remaining'=> max(0, $limit - ($owned + 1)),
    'season'=>array(
      'id'=>(int)$season['id'],
      'no'=>(int)$season['season_no'],
      'starts_at'=>(int)$season['starts_at'],
      'ends_at'=>(int)$season['ends_at'],
    ),
    'cap'=>array(
      'cap'=>(int)$capInfo['cap'],
      'used'=>(int)($capInfo['used'] + 1),
      'remaining'=>(int)max(0, ((int)$capInfo['cap'] > 0 ? (int)$capInfo['cap'] : 0) - ((int)$capInfo['used'] + 1)),
    ),
    'share'=>array(
      'text'=>'🏅 I just planted a Seed on GreenFarm and boosted my season placement. Join me: '.(isset($_SERVER['HTTP_HOST']) ? 'https://'.$_SERVER['HTTP_HOST'] : ''),
    )
  ));
  exit;
} catch (Throwable $e) {
  echo json_encode(array('ok'=>false,'msg'=>'Unexpected error')); exit;
}