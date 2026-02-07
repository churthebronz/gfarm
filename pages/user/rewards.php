<?php
// GreenFarm — Rewards Hub (Premium, retention-first)
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/vx_retention.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /auth', true, 302); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2, '.', ''); }

$opt['title'] = 'Rewards';
$currency = (string)($config->valuta ?? 'USD');

// Streak (safe even if vx_meta_get missing)
$vxStreak = 0;
try {
  if (function_exists('vx_meta_get')) {
    $vxStreak = (int)vx_meta_get($db, $uid, 'streak_days', '0');
  }
} catch (Throwable $e) { $vxStreak = 0; }

// User balances (robust across schema versions)
$user = [];
$wallet = 0.0;
$spendable = 0;
$lifetime = 0;
$refsCount = 0;
$refCode = '';

try {
  $hasPtsUsers = function_exists('vx_column_exists')
    && vx_column_exists($db, 'db_users', 'points_spendable')
    && vx_column_exists($db, 'db_users', 'points_total');

  if ($hasPtsUsers) {
    $u = $db->query("SELECT login, ref_code, refs, bank, income, points_spendable, points_total, reg FROM db_users WHERE id=? LIMIT 1", $uid);
  } else {
    $u = $db->query("SELECT login, ref_code, refs, bank, income, reg FROM db_users WHERE id=? LIMIT 1", $uid);
  }
  $user = $u ? ($u->fetchArray() ?: []) : [];

  $wallet = (float)($user['bank'] ?? 0) + (float)($user['income'] ?? 0);
  $refsCount = (int)($user['refs'] ?? 0);
  $refCode   = (string)($user['ref_code'] ?? '');

  if ($hasPtsUsers) {
    $spendable = (int)($user['points_spendable'] ?? 0);
    $lifetime  = (int)($user['points_total'] ?? 0);
  } else {
    if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_user_points')) {
      $p = $db->query("SELECT points, lifetime_points FROM db_user_points WHERE uid=? LIMIT 1", $uid);
      $pr = $p ? ($p->fetchArray() ?: []) : [];
      $spendable = (int)($pr['points'] ?? 0);
      $lifetime  = (int)($pr['lifetime_points'] ?? 0);
    }
  }
} catch (Throwable $e) {
  // keep defaults
}

// Referral link
// Share the invite page URL (rich OG preview + card image).
$refLink = rtrim((string)($config->siteurl ?? ''), '/');
if (stripos($refLink, 'http') !== 0) $refLink = 'https://' . $refLink;
$refLink .= '/invite/' . rawurlencode($refCode !== '' ? $refCode : '');

// Recent points ledger
$recentPoints = [];
try {
  if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_points_ledger')) {
    $q = $db->query("SELECT delta, ctx, created_at FROM db_points_ledger WHERE uid=? ORDER BY created_at DESC LIMIT 12", $uid);
    if ($q) while ($r = $q->fetchArray()) $recentPoints[] = $r;
  }
} catch (Throwable $e) {}

// Referral earnings
$recentRef = [];
$refEarnSum = 0.0;
try {
  if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_ref_earn')) {
    $q = $db->query("SELECT usd, rate, ctx, created_at FROM db_ref_earn WHERE ref_uid=? ORDER BY created_at DESC LIMIT 12", $uid);
    if ($q) while ($r = $q->fetchArray()) $recentRef[] = $r;

    $q2 = $db->query("SELECT COALESCE(SUM(usd),0) AS s FROM db_ref_earn WHERE ref_uid=?", $uid);
    $r2 = $q2 ? ($q2->fetchArray() ?: []) : [];
    $refEarnSum = (float)($r2['s'] ?? 0);
  }
} catch (Throwable $e) {}

// Season (SAFE: do NOT fatal if old seasons.php)
$endsIn = 0;
try {
  $season = [];
  if (function_exists('vx_current_season')) {
    $season = (array)vx_current_season($db);
    $endsIn = (int)($season['ends_in'] ?? 0);
  } elseif (function_exists('vx_get_current_season')) {
    $season = (array)vx_get_current_season($db);
    $ea = (int)($season['ends_at'] ?? 0);
    $endsIn = $ea > 0 ? max(0, $ea - time()) : 0;
  }
} catch (Throwable $e) { $endsIn = 0; }

?>
<div class="container" style="max-width:920px;padding-top:12px;">

  <div class="vx-card" style="padding:14px 14px 12px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div>
        <div style="font-weight:900;font-size:16px;letter-spacing:.2px;">Season Earnings</div>
        <div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">
          Points earned in <b>Season</b> convert to <b>VX</b> at listing.
          <?php if ($endsIn > 0): ?>
            <span class="vx-chip vx-chip--warn" style="margin-left:6px;">Season ends in <?= (int)floor($endsIn/86400) ?>d</span>
          <?php endif; ?>
          <span class="vx-chip" style="margin-left:6px;border-color:rgba(255,154,46,.22);background:rgba(255,154,46,.10);color:rgba(255,255,255,.92);">
            <i class="fa-solid fa-fire" style="margin-right:6px;color:#ff9a2e;"></i><?= (int)$vxStreak; ?> day streak
          </span>
        </div>
      </div>
      <a class="vx-chip vx-chip--accent" href="/user/deposit" style="text-decoration:none;">Plant Seed</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr;gap:10px;margin-top:12px;">
      <div class="vx-card" style="padding:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div>
          <div style="color:rgba(234,240,255,.72);font-size:12px;">Season Points</div>
          <div style="font-weight:950;font-size:22px;letter-spacing:.2px;"><?= number_format($spendable, 0, '.', ',') ?></div>
        </div>
        <div style="text-align:right">
          <div style="color:rgba(234,240,255,.72);font-size:12px;">All-time points</div>
          <div style="font-weight:900;"><?= number_format($lifetime, 0, '.', ',') ?></div>
          <div style="color:rgba(234,240,255,.6);font-size:11px;">Total earned all time</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="vx-card" style="padding:12px;">
          <div style="color:rgba(234,240,255,.72);font-size:12px;">Referral earnings (auto-paid)</div>
          <div style="font-weight:950;font-size:18px;margin-top:4px;"><?= money($refEarnSum) ?> <?= h($currency) ?></div>
          <div style="color:rgba(234,240,255,.6);font-size:11px;margin-top:2px;">Instant distribution when your referrals generate activity.</div>
        </div>
        <div class="vx-card" style="padding:12px;">
          <div style="color:rgba(234,240,255,.72);font-size:12px;">Wallet balance</div>
          <div style="font-weight:950;font-size:18px;margin-top:4px;"><?= money($wallet) ?> <?= h($currency) ?></div>
          <div style="color:rgba(234,240,255,.6);font-size:11px;margin-top:2px;">Use <b>Deposit</b> to activate vaults and earn faster.</div>
        </div>
      </div>

      <!-- Daily check-in -->
      <div class="vx-card" style="padding:12px;margin-top:10px;border:1px solid rgba(255,154,46,.20);background:linear-gradient(135deg, rgba(255,154,46,.10), rgba(0,255,224,.06));">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <div>
            <div style="font-weight:900;">Daily check-in</div>
            <div style="color:rgba(234,240,255,.70);font-size:12px;margin-top:2px;">Claim your daily bonus — streak boosts included.</div>
          </div>
          <button id="vxCheckinBtn" class="vx-btn" type="button" style="min-width:148px;">Claim</button>
        </div>
        <div id="vxCheckinMsg" style="color:rgba(234,240,255,.65);font-size:12px;margin-top:8px;display:none;"></div>
      </div>

      <!-- Event + Pool + Boosts -->
      <div style="display:grid;grid-template-columns:1fr;gap:10px;margin-top:10px;">
        <div class="vx-card" id="vxEventCard" style="padding:12px;border:1px solid rgba(255,154,46,.22);background:linear-gradient(135deg, rgba(255,154,46,.08), rgba(255,255,255,.02));">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
              <div style="font-weight:900;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-bolt" style="color:#ff9a2e;"></i><span>Harvest Rush</span></div>
              <div id="vxEventSub" style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">Daily 12:00–15:00 UTC • Earn <b>2×</b> points on vault activations.</div>
            </div>
            <span class="vx-chip" id="vxEventTimer">Checking…</span>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr;gap:10px;">
          <div class="vx-card" style="padding:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
              <div>
                <div style="font-weight:900;">Season Pool</div>
                <div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">Grows with activity (snapshot eligible).</div>
              </div>
              <span class="vx-chip" id="vxPoolChip">—</span>
            </div>
            <div style="color:rgba(234,240,255,.60);font-size:11px;margin-top:8px;">Current season pool (Season Points)</div>
          </div>
        </div>

        <div class="vx-card" style="padding:12px;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
              <div style="font-weight:900;">Boost Shop</div>
              <div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">Spend Season Points on boosts (anti-abuse + speed).</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <a class="vx-chip" href="/user/points" style="text-decoration:none;">Points →</a>
              <a class="vx-chip" href="/user/token" style="text-decoration:none;">VX Token →</a>
            </div>
          </div>
          <div id="vxBoostList" style="display:grid;grid-template-columns:1fr;gap:10px;margin-top:10px;"></div>
        </div>
      </div>

    </div>
  </div>

  <!-- Share Kit -->
  <div class="vx-card" style="margin-top:12px;padding:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
      <div>
        <div style="font-weight:900;">Referral Share Kit</div>
        <div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">One tap share → more referrals → higher rank.</div>
      </div>
      <span class="vx-chip"><?= number_format($refsCount,0,'.',',') ?> referrals</span>
    </div>

    <div style="margin-top:10px;display:grid;grid-template-columns:1fr;gap:10px;">
      <div class="vx-card" style="padding:12px;">
        <div style="color:rgba(234,240,255,.72);font-size:12px;">Your link</div>
        <div style="font-weight:800;font-size:13px;word-break:break-all;margin-top:4px;"><?= h($refLink) ?></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
          <button class="vx-btn vx-btn--primary" type="button" id="vxShareTg">Share to Telegram</button>
          <button class="vx-btn" type="button" id="vxCopyLink">Copy link</button>
          <button class="vx-btn" type="button" id="vxCopyMsg">Copy message</button>
        </div>
        <div id="vxCopyToast" style="display:none;margin-top:10px;color:rgba(234,240,255,.8);font-size:12px;">Copied.</div>
      </div>
    </div>
  </div>

  <!-- Recent activity -->
  <div style="display:grid;grid-template-columns:1fr;gap:12px;margin-top:12px;">
    <div class="vx-card" style="padding:14px;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div>
          <div style="font-weight:900;">Recent Activity</div>
          <div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">Latest 12 ledger events.</div>
        </div>
        <a class="vx-chip" href="/user/points" style="text-decoration:none;">Open Points</a>
      </div>

      <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
        <?php if (empty($recentPoints)): ?>
          <div class="vx-card" style="padding:12px;color:rgba(234,240,255,.72);">No points activity yet. Start a vault to earn.</div>
        <?php else: foreach($recentPoints as $r): $d=(int)($r['delta']??0); ?>
          <div class="vx-mini-item">
            <div class="k"><?= h((string)($r['ctx'] ?? 'Points')) ?></div>
            <div class="v" style="<?= $d>=0 ? 'color:var(--vx-good);' : 'color:var(--vx-warn);' ?>"><?= ($d>=0?'+':'') . (int)$d ?> pts</div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="vx-card" style="padding:14px;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div>
          <div style="font-weight:900;">Recent Referral Payouts</div>
          <div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">Latest 12 payouts.</div>
        </div>
        <a class="vx-chip" href="/user/refs" style="text-decoration:none;">Open Referrals</a>
      </div>

      <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
        <?php if (empty($recentRef)): ?>
          <div class="vx-card" style="padding:12px;color:rgba(234,240,255,.72);">No referral payouts yet. Share your link.</div>
        <?php else: foreach($recentRef as $r): ?>
          <div class="vx-mini-item">
            <div class="k"><?= h((string)($r['ctx'] ?? 'Referral')) ?></div>
            <div class="v" style="color:var(--vx-good);">+<?= money((float)($r['usd']??0)) ?> <?= h($currency) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Daily Quests + Weekly Pack -->
  <div class="vx-card" style="padding:14px;margin-top:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
      <div>
        <div style="font-weight:900;font-size:14px;">Daily Quests</div>
        <div style="color:rgba(234,240,255,.68);font-size:12px;margin-top:2px;">Complete all 3 to unlock the daily bonus.</div>
      </div>
      <div class="vx-chip vx-chip--accent" id="vxDQBonus" style="display:none;text-decoration:none;">+100</div>
    </div>

    <div id="vxDailyQuests" style="margin-top:10px;display:grid;gap:8px;">
      <div class="vx-skel skel-rows"></div>
    </div>

    <div id="vxWeeklyPack" style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div>
          <div style="font-weight:900;font-size:13px;">Weekly Quest Pack</div>
          <div style="color:rgba(234,240,255,.62);font-size:12px;margin-top:2px;">Finish Check-in + Share + Seed this week to claim.</div>
        </div>
        <button class="vx-btn vx-btn--accent" id="vxWeeklyClaim" type="button" style="white-space:nowrap;">Claim +150</button>
      </div>
      <div id="vxWeeklyHint" style="margin-top:8px;color:rgba(234,240,255,.62);font-size:12px;"></div>
    </div>
  </div>

  <!-- Referral Tier -->
  <div class="vx-card" style="padding:14px;margin-top:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
      <div>
        <div style="font-weight:900;font-size:14px;">Referral Tier</div>
        <div style="color:rgba(234,240,255,.68);font-size:12px;margin-top:2px;">Rank up by getting qualified referrals.</div>
      </div>
      <div class="vx-chip" id="vxTierChip" style="border-color:rgba(255,154,46,.35);background:rgba(255,154,46,.10);">Loading…</div>
    </div>

    <div id="vxTierBody" style="margin-top:10px;">
      <div class="vx-skel skel-rows"></div>
    </div>

    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
      <button class="vx-btn vx-btn--accent" type="button" id="vxTierShare">Share to Telegram</button>
      <button class="vx-btn vx-btn--ghost" type="button" id="vxTierCopy">Copy message</button>
    </div>
  </div>

  <!-- Live Activity -->
  <div class="vx-card" style="padding:14px;margin-top:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
      <div>
        <div style="font-weight:900;font-size:14px;">Live Activity</div>
        <div style="color:rgba(234,240,255,.68);font-size:12px;margin-top:2px;">Real movement across GreenFarm (no names).</div>
      </div>
      <button class="vx-btn vx-btn--ghost" type="button" id="vxActRefresh">Refresh</button>
    </div>
    <div id="vxActivityFeed" style="margin-top:10px;display:grid;gap:8px;">
      <div class="vx-skel skel-rows"></div>
    </div>
  </div>

  <!-- Alerts -->
  <div class="vx-card" style="padding:14px;margin-top:12px;margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
      <div style="font-weight:900;">Alerts</div>
      <button type="button" id="vxAlertsMarkAll" class="vx-btn vx-btn--ghost" style="padding:8px 10px;font-size:12px;">
        Mark all read
      </button>
    </div>
    <div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:4px;">Soft updates only — vaults, rank moves, season timing.</div>

    <div id="vxAlertsList" class="vx-skel" style="margin-top:10px;padding:12px;">
      <div style="height:12px;width:70%;border-radius:10px;"></div>
      <div style="height:10px;width:50%;border-radius:10px;margin-top:10px;"></div>
      <div style="height:10px;width:60%;border-radius:10px;margin-top:10px;"></div>
    </div>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){

  const VX_REF_LINK = <?= json_encode($refLink) ?>;

  // ----- share kit copy + telegram share -----
  (function(){
    const refLink = VX_REF_LINK;
    const msg = "Join me on GreenFarm — earn, climb the ranks, and claim weekly rewards.\n\n" + refLink;

    const toast = document.getElementById('vxCopyToast');
    function showToast(t){
      if(!toast) return;
      toast.textContent = t || 'Copied.';
      toast.style.display='block';
      clearTimeout(window.__vx_toast_t);
      window.__vx_toast_t = setTimeout(()=>toast.style.display='none', 1200);
    }
    async function copy(text){
      try{
        await navigator.clipboard.writeText(text);
        showToast('Copied.');
      }catch(e){
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('Copied.');
      }
    }

    const btnLink = document.getElementById('vxCopyLink');
    const btnMsg  = document.getElementById('vxCopyMsg');
    const btnTg   = document.getElementById('vxShareTg');

    if(btnLink) btnLink.addEventListener('click', ()=>copy(refLink));
    if(btnMsg)  btnMsg.addEventListener('click', ()=>copy(msg));

    if(btnTg){
      btnTg.addEventListener('click', ()=>{
        try{ fetch('/api/user/share_event.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({channel:'telegram',ctx:'rewards_share'})}).catch(()=>{}); }catch(e){}
        try{ if (window.Telegram?.WebApp?.HapticFeedback){ Telegram.WebApp.HapticFeedback.impactOccurred('light'); } }catch(e){}

        const shareUrl = "https://t.me/share/url?url=" + encodeURIComponent(refLink) + "&text=" + encodeURIComponent(msg);
        try{
          if (window.Telegram?.WebApp?.openTelegramLink) Telegram.WebApp.openTelegramLink(shareUrl);
          else window.open(shareUrl, "_blank", "noopener");
        }catch(e){
          window.open(shareUrl, "_blank", "noopener");
        }
      });
    }
  })();

  // ----- quests + tier + activity -----
  (function(){
    async function getJSON(url, opts){
      const r = await fetch(url, Object.assign({credentials:'include'}, opts||{}));
      if(!r.ok) throw new Error('HTTP '+r.status);
      return await r.json();
    }
    function esc(s){ return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

    const dq = document.getElementById('vxDailyQuests');
    const dqBonus = document.getElementById('vxDQBonus');
    const wkHint = document.getElementById('vxWeeklyHint');
    const wkBtn = document.getElementById('vxWeeklyClaim');

    const tierChip = document.getElementById('vxTierChip');
    const tierBody = document.getElementById('vxTierBody');
    const btnShare = document.getElementById('vxTierShare');
    const btnCopy  = document.getElementById('vxTierCopy');

    const act = document.getElementById('vxActivityFeed');
    const actBtn = document.getElementById('vxActRefresh');

    let shareMessage = '';
    const shareLink = VX_REF_LINK;

    function buildQuestRow(label, done){
      return `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid rgba(255,255,255,.10);border-radius:12px;background:rgba(255,255,255,.03);">
          <div style="font-weight:800;">${esc(label)}</div>
          <div class="vx-chip" style="border-color:${done?'rgba(0,255,160,.25)':'rgba(255,255,255,.16)'};background:${done?'rgba(0,255,160,.10)':'rgba(255,255,255,.06)'};">
            ${done?'Done':'Todo'}
          </div>
        </div>`;
    }

    async function loadQuests(){
      if(!dq || !wkHint || !wkBtn || !tierChip || !tierBody) return;
      try{
        const j = await getJSON('/api/user/quests_status.php');
        if(!j.ok) throw new Error('bad');

        const d = j.daily || {tasks:{}};
        dq.innerHTML = [
          buildQuestRow('Claim daily check-in', !!(d.tasks||{}).checkin),
          buildQuestRow('Share an invite', !!(d.tasks||{}).share),
          buildQuestRow('Activate a vault', !!(d.tasks||{}).vault),
        ].join('');

        if(dqBonus){
          if(d.all_done && !d.bonus_claimed){
            dqBonus.style.display = '';
            dqBonus.textContent = '+100';
          } else {
            dqBonus.style.display = 'none';
          }
        }

        const w = j.weekly || {tasks:{}};
        const parts = [];
        parts.push(`${(w.tasks||{}).checkin?'✅':'⬜'} Check-in`);
        parts.push(`${(w.tasks||{}).share?'✅':'⬜'} Share`);
        parts.push(`${(w.tasks||{}).vault?'✅':'⬜'} Seed`);
        wkHint.textContent = `This week: ${parts.join(' • ')}.`;

        if (w.claimed){
          wkBtn.disabled = true; wkBtn.textContent = 'Claimed';
        } else if (!w.all_done){
          wkBtn.disabled = true; wkBtn.textContent = 'Locked';
        } else {
          wkBtn.disabled = false; wkBtn.textContent = 'Claim +150';
        }

        const t = j.ref_tier;
        if (t && t.ok){
          tierChip.textContent = `${t.tier.name}`;
          tierChip.style.borderColor = t.tier.ring || 'rgba(255,154,46,.35)';
          tierChip.style.background = t.tier.color || 'rgba(255,154,46,.10)';

          let next = '';
          if (t.next){
            const need = Math.max(0, (t.next.min||0) - (t.qualified||0));
            next = `<div style="margin-top:8px;color:rgba(234,240,255,.66);font-size:12px;">Next: <b>${esc(t.next.name)}</b> in ${need} qualified referral(s).</div>`;
          } else {
            next = `<div style="margin-top:8px;color:rgba(234,240,255,.66);font-size:12px;">Max tier reached.</div>`;
          }

          tierBody.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid rgba(255,255,255,.10);border-radius:12px;background:rgba(255,255,255,.03);">
              <div>
                <div style="font-weight:900;">Qualified referrals</div>
                <div style="color:rgba(234,240,255,.66);font-size:12px;margin-top:2px;">Referrals that actually started activity.</div>
              </div>
              <div style="font-weight:950;font-size:20px;">${esc(t.qualified)}</div>
            </div>
            ${next}
            <div style="margin-top:10px;color:rgba(234,240,255,.72);font-size:12px;white-space:pre-line;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px;background:rgba(0,0,0,.12);">${esc(t.share_message||'')}</div>
          `;
          shareMessage = (t.share_message||'');
        }
      }catch(e){
        // keep silent
      }
    }

    async function claimWeekly(){
      try{
        await getJSON('/api/user/quests_claim_weekly.php', {method:'POST'});
        await loadQuests();
        if (window.Telegram?.WebApp?.HapticFeedback) window.Telegram.WebApp.HapticFeedback.notificationOccurred('success');
      }catch(e){}
    }

    async function loadActivity(){
      if(!act) return;
      try{
        const j = await getJSON('/api/user/activity_feed.php');
        if(!j.ok) throw new Error('bad');
        const items = j.items || [];
        if(!items.length){
          act.innerHTML = `<div style="padding:10px;border:1px solid rgba(255,255,255,.10);border-radius:12px;background:rgba(255,255,255,.03);color:rgba(234,240,255,.66);font-size:12px;">No recent activity.</div>`;
          return;
        }
        act.innerHTML = items.slice(0,8).map(it=>`
          <div style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid rgba(255,255,255,.10);border-radius:12px;background:rgba(255,255,255,.03);">
            <div style="font-size:16px">${esc(it.icon||'⚡')}</div>
            <div style="flex:1">
              <div style="font-weight:900">${esc(it.title||'Activity')}</div>
              <div style="color:rgba(234,240,255,.62);font-size:12px;margin-top:2px">${esc(it.ctx||'')}</div>
            </div>
            <div style="color:rgba(234,240,255,.55);font-size:11px">${new Date((it.ts||0)*1000).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>
          </div>
        `).join('');
      }catch(e){
        act.innerHTML = `<div style="padding:10px;border:1px solid rgba(255,255,255,.10);border-radius:12px;background:rgba(255,255,255,.03);color:rgba(234,240,255,.66);font-size:12px;">Activity temporarily unavailable.</div>`;
      }
    }

    if(wkBtn) wkBtn.addEventListener('click', claimWeekly);
    if(actBtn) actBtn.addEventListener('click', loadActivity);

    if(btnCopy){
      btnCopy.addEventListener('click', async ()=>{
        try{
          await navigator.clipboard.writeText((shareMessage||'') + "\n" + (shareLink||''));
          if (window.Telegram?.WebApp?.HapticFeedback) window.Telegram.WebApp.HapticFeedback.notificationOccurred('success');
        }catch(e){}
      });
    }
    if(btnShare){
      btnShare.addEventListener('click', ()=>{
        try{
          const tg = window.Telegram?.WebApp;
          const u = 'https://t.me/share/url?url=' + encodeURIComponent(shareLink||'') + '&text=' + encodeURIComponent((shareMessage||'') + "\n" + (shareLink||''));
          if (tg?.openTelegramLink) tg.openTelegramLink(u);
          else window.open(u, '_blank');
        }catch(e){}
      });
    }

    loadQuests();
    loadActivity();
  })();

  // ----- event + league + pool + boosts -----
  (function(){
    const elTimer = document.getElementById('vxEventTimer');
    const elEventSub = document.getElementById('vxEventSub');
    const elPool = document.getElementById('vxPoolChip');
    const elBoostList = document.getElementById('vxBoostList');

    function fmtSeconds(s){
      s = Math.max(0, s|0);
      const h = Math.floor(s/3600);
      const m = Math.floor((s%3600)/60);
      const ss = s%60;
      if (h>0) return `${h}h ${m}m`;
      if (m>0) return `${m}m ${ss}s`;
      return `${ss}s`;
    }

    async function loadEvent(){
      if (!elTimer) return;
      try{
        const r = await fetch('/api/user/event_status.php', {credentials:'same-origin'});
        const j = await r.json();
        if (!j || !j.ok) throw new Error('bad');
        if (j.active){
          elTimer.textContent = `LIVE • ${fmtSeconds(j.ends_in)}`;
          if (elEventSub) elEventSub.textContent = `Harvest Rush is live now — Earn ${j.multiplier}× Points on vault activations.`;
        } else {
          elTimer.textContent = `Starts in ${fmtSeconds(j.starts_in)}`;
          if (elEventSub) elEventSub.textContent = `Daily 12:00–15:00 UTC • Earn 2× Points on vault activations.`;
        }
      }catch(e){
        elTimer.textContent = '—';
      }
    }

    async function loadPool(){
      if (!elPool) return;
      try{
        const r = await fetch('/api/user/pool_status.php', {credentials:'same-origin'});
        const j = await r.json();
        if (!j || !j.ok) throw new Error('bad');
        elPool.textContent = (j.pool_points||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' pts';
      }catch(e){ elPool.textContent='—'; }
    }

    function boostCard(b){
      const wrap = document.createElement('div');
      wrap.className = 'vx-card';
      wrap.style.padding = '12px';
      wrap.style.border = '1px solid rgba(255,255,255,.08)';
      const now = Math.floor(Date.now()/1000);
      const active = !!b.active && (b.until||0) > now;
      const left = active ? (b.until - now) : 0;
      wrap.innerHTML =
        `<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;">`+
          `<div>`+
            `<div style="font-weight:900;">${(b.title||b.key)}</div>`+
            `<div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">${(b.desc||'')}</div>`+
            `<div style="color:rgba(234,240,255,.60);font-size:11px;margin-top:6px;">`+
              (active ? `Active • ${fmtSeconds(left)} left` : `Cost: ${(b.cost||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',')} pts`)+
            `</div>`+
          `</div>`+
          `<div>`+
            `<button class="vx-btn ${active ? '' : 'vx-btn--primary'}" type="button" ${active?'disabled':''} data-boost="${b.key}">`+
              (active ? 'Active' : 'Buy')+
            `</button>`+
          `</div>`+
        `</div>`;
      return wrap;
    }

    async function loadBoosts(){
      if (!elBoostList) return;
      elBoostList.innerHTML = '';
      try{
        const r = await fetch('/api/user/boosts_status.php', {credentials:'same-origin'});
        if (!r.ok) throw new Error('HTTP '+r.status);
        const j = await r.json();
        const list = (j && j.ok && Array.isArray(j.boosts)) ? j.boosts : [];
        if (!list.length){
          elBoostList.innerHTML = '<div style="color:rgba(234,240,255,.72);font-size:12px;">Boost shop unavailable.</div>';
          return;
        }
        for (const b of list){
          elBoostList.appendChild(boostCard(b));
        }
        elBoostList.querySelectorAll('button[data-boost]').forEach(btn=>{
          btn.addEventListener('click', async ()=>{
            const key = btn.getAttribute('data-boost');
            if (!key) return;

            btn.disabled = true;
            const old = btn.textContent;
            btn.textContent = '...';

            try{
              // send as x-www-form-urlencoded (most reliable across PHP versions)
              const rr = await fetch('/api/user/boost_buy.php', {
                method:'POST',
                credentials:'same-origin',
                headers:{
                  'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
                  'Accept':'application/json'
                },
                body:'key=' + encodeURIComponent(key)
              });

              const jj = await rr.json();
              if (jj && jj.ok){
                try{ if (window.vxToast) vxToast('Boost activated'); }catch(_){}
              } else {
                const m = (jj && (jj.msg||jj.error)) ? (jj.msg||jj.error) : 'Unable to buy boost';
                try{ if (window.vxToast) vxToast(m); }catch(_){}
              }
            }catch(e){
              try{ if (window.vxToast) vxToast('Unable to buy boost'); }catch(_){}
            }finally{
              btn.disabled = false;
              btn.textContent = old;
              loadBoosts();
            }
          });
        });
      }catch(e){
        elBoostList.innerHTML = '<div style="color:rgba(234,240,255,.72);font-size:12px;">Boost shop unavailable.</div>';
      }
    }

    loadEvent(); loadPool(); loadBoosts();
    setInterval(loadEvent, 1000);
  })();

  // ----- check-in -----
  (function(){
    const btn = document.getElementById('vxCheckinBtn');
    const msg = document.getElementById('vxCheckinMsg');
    if (!btn) return;

    async function doCheckin(){
      btn.disabled = true;
      btn.textContent = '...';
      try{
        const r = await fetch('/api/user/checkin.php', { method:'POST', credentials:'include' });
        let d = null;
        try{ d = await r.json(); }catch(_){ d = null; }
        if (!d) throw new Error('bad_response');

        if (d.ok){
          btn.textContent = 'Claimed';
          if (msg){ msg.style.display='block'; msg.textContent = `Nice. +${d.pts} Season Points added.`; }
          try{ if (window.vxToast) vxToast(`+${d.pts} Season Points`); }catch(_){}
          try{ if (window.vxRewardBurst) vxRewardBurst(d.pts, btn); }catch(_e){}
        } else {
          btn.textContent = 'Claim';
          const t = d.error === 'already_checked_in' ? 'Already claimed today.' : 'Try again.';
          if (msg){ msg.style.display='block'; msg.textContent = t; }
          try{ if (window.vxToast) vxToast(t); }catch(_){}
        }
      }catch(e){
        btn.textContent = 'Claim';
        if (msg){ msg.style.display='block'; msg.textContent = 'Unable to check in right now.'; }
      }finally{
        btn.disabled = false;
      }
    }

    btn.addEventListener('click', doCheckin);
  })();

  // ----- alerts -----
  (function(){
    const list = document.getElementById('vxAlertsList');
    const btnAll = document.getElementById('vxAlertsMarkAll');
    function esc(s){ return (s||'').toString().replace(/[&<>"']/g, m=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;" }[m])); }
    function rel(ts){
      if(!ts) return '';
      const d = Math.max(0, Math.floor(Date.now()/1000 - ts));
      if(d < 60) return d+'s ago';
      if(d < 3600) return Math.floor(d/60)+'m ago';
      if(d < 86400) return Math.floor(d/3600)+'h ago';
      return Math.floor(d/86400)+'d ago';
    }
    async function load(){
      if(!list) return;
      try{
        const res = await fetch('/api/user/notifications_feed.php?limit=15', {credentials:'same-origin'});
        const j = await res.json();
        const items = (j && j.ok && Array.isArray(j.items)) ? j.items : [];
        const wrap = document.createElement('div');
        wrap.style.display='grid';
        wrap.style.gap='10px';

        if(items.length===0){
          wrap.innerHTML = '<div style="padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(255,255,255,.04);color:rgba(234,240,255,.72);font-size:12px;">No alerts right now. Keep building.</div>';
        } else {
          items.forEach(it=>{
            const card = document.createElement('div');
            const isRead = (it.is_read|0)===1;
            card.style.padding='12px';
            card.style.borderRadius='14px';
            card.style.border='1px solid rgba(255,255,255,.08)';
            card.style.background= isRead ? 'rgba(255,255,255,.03)' : 'rgba(255,154,46,.06)';
            card.style.cursor='pointer';
            card.innerHTML =
              '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">'+
                '<div>'+
                  '<div style="font-weight:900;font-size:13px;">'+esc(it.title||'Update')+'</div>'+
                  '<div style="color:rgba(234,240,255,.72);font-size:12px;margin-top:2px;">'+esc(it.message||'')+'</div>'+
                '</div>'+
                '<div style="color:rgba(234,240,255,.55);font-size:11px;white-space:nowrap;margin-top:2px;">'+rel(it.ts)+'</div>'+
              '</div>';
            card.addEventListener('click', async ()=>{
              try{
                await fetch('/api/user/notifications_mark.php', {
                  method:'POST',
                  credentials:'same-origin',
                  headers:{'Content-Type':'application/x-www-form-urlencoded'},
                  body:'id='+encodeURIComponent(it.id)
                });
                load();
              }catch(e){}
            });
            wrap.appendChild(card);
          });
        }

        list.classList.remove('vx-skel');
        list.innerHTML='';
        list.appendChild(wrap);
      }catch(e){
        list.classList.remove('vx-skel');
        list.innerHTML = '<div style="padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(255,255,255,.04);color:rgba(234,240,255,.72);font-size:12px;">Alerts temporarily unavailable.</div>';
      }
    }
    if(btnAll){
      btnAll.addEventListener('click', async ()=>{
        try{
          await fetch('/api/user/notifications_mark.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'all=1'
          });
          load();
        }catch(e){}
      });
    }
    load();
  })();

});
</script>
