<?php
declare(strict_types=1);

if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/vx_affiliate.php';
require_once __DIR__ . '/../../core/vx_retention.php';

global $db, $config, $opt;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  // Telegram WebApp session bridge
  require_once __DIR__ . '/../../api/require_tg_session.php';
  $uid = (int)($GLOBALS['UID'] ?? 0);
}
if ($uid <= 0) { header('Location: /'); exit; }

$opt['title'] = 'Affiliate';

$me = [];
try {
  $me = $db->query('SELECT id, login, tg_username, ref_code FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray() ?: [];
} catch (Throwable $e) { $me = []; }

$st = vx_affiliate_status($db, $uid);

$bot = ltrim((string)vx_app_setting('telegram_bot', $config->telegram_bot ?? 'GreenFarmAppBot'), '@');
$refCode = (string)($me['ref_code'] ?? '');
$shareDeep = 'https://t.me/'.rawurlencode($bot);
if ($refCode !== '') { $shareDeep .= '?start='.rawurlencode('ref_'.$refCode); }

// Layout wrapper
require_once __DIR__ . '/../menu-h.php';
?>

<div class="vx-page" style="max-width:980px;margin:0 auto;padding:16px">
  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="margin:0;font-weight:900">Affiliate</h2>
      <div style="opacity:.75;font-size:13px">Invite friends → they activate a paid Guardian → you progress.</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="vx-btn" href="<?= htmlspecialchars($shareDeep, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" style="text-decoration:none">Share link</a>
      <a class="vx-btn vx-btn-ghost" href="/user/dashboard" style="text-decoration:none">Back to dashboard</a>
    </div>
  </div>

  <div class="vx-card" style="margin-top:14px;padding:14px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(12,18,28,.70)">
    <?php
      $qualified = (int)($st['qualified'] ?? 0);
      $required  = (int)($st['required'] ?? 10);
      $pct = $required > 0 ? min(100, (int)floor(($qualified / $required) * 100)) : 0;
      $tier = (int)($st['tier'] ?? 0);
      $vpDay = (int)($st['vp_day'] ?? 0);
      $lpDay = (int)($st['lp_day'] ?? 0);
      $tiers = (array)($st['tiers'] ?? []);
      $eligible = (bool)($st['eligible'] ?? false);
      $claimed = (bool)($st['claimed'] ?? false);
      $step = (int)($st['bonus_step'] ?? vx_affiliate_tier_bonus_step());
      // find next tier target
      $nextTier = null;
      foreach ($tiers as $t) {
        if ($qualified < (int)($t['refs'] ?? 0)) { $nextTier = $t; break; }
      }
    ?>

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="font-weight:900">Affiliate Guardian Progress</div>
      <div style="font-size:13px;opacity:.85">Qualified paid referrals: <b><?= $qualified; ?></b> / <b><?= $required; ?></b></div>
    </div>
    <div style="margin-top:10px;height:12px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10);overflow:hidden">
      <div style="height:100%;width:<?= $pct; ?>%;background:linear-gradient(90deg, rgba(0,243,255,1), rgba(124,92,255,1));"></div>
    </div>
    <div style="margin-top:10px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between">
      <div style="font-size:13px;opacity:.80">Current tier: <b><?= $tier > 0 ? $tier : 0; ?></b> • Daily bonus: <b>+<?= $vpDay; ?> VP/day</b> • <b>+<?= $lpDay; ?> LP/day</b></div>
      <div style="font-size:12px;opacity:.70">Your share code: <b><?= htmlspecialchars($refCode, ENT_QUOTES, 'UTF-8'); ?></b></div>
    </div>

    <!-- Tier ladder -->
    <div style="margin-top:14px;display:grid;grid-template-columns:repeat(4, minmax(0, 1fr));gap:10px">
      <?php foreach ($tiers as $t):
        $tRefs = (int)($t['refs'] ?? 0);
        $tTier = (int)($t['tier'] ?? 0);
        $isDone = ($qualified >= $tRefs && $tRefs > 0);
        $isCurrent = ($tier === $tTier && $isDone);
        $extra = max(0, $tTier-1) * $step;
      ?>
        <div style="padding:12px;border-radius:16px;border:1px solid rgba(148,163,184,.18);background:rgba(2,6,23,.48);position:relative;overflow:hidden;min-height:98px">
          <div style="font-weight:1000;display:flex;align-items:center;justify-content:space-between;gap:8px">
            <span><?= htmlspecialchars((string)($t['label'] ?? ('Tier '.$tTier)), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($isCurrent): ?>
              <span style="font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid rgba(34,211,238,.35);background:rgba(34,211,238,.12);font-weight:1000">Current</span>
            <?php elseif ($isDone): ?>
              <span style="font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid rgba(77,255,181,.28);background:rgba(77,255,181,.10);font-weight:1000">Unlocked</span>
            <?php else: ?>
              <span style="font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);font-weight:1000;opacity:.8">Locked</span>
            <?php endif; ?>
          </div>
          <div style="margin-top:6px;font-size:12px;opacity:.78;font-weight:900">Requires <b><?= $tRefs; ?></b> paid refs</div>
          <div style="margin-top:10px;font-size:12px;opacity:.9;font-weight:1000">Bonus: <b>+<?= (int)vx_affiliate_vp_per_day() + $extra; ?> VP/day</b> • <b>+<?= (int)vx_affiliate_lp_per_day() + $extra; ?> LP/day</b></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:12px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="font-size:13px;opacity:.85">
        <?php if ($nextTier): ?>
          Next tier in <b><?= max(0, (int)($nextTier['refs'] ?? 0) - $qualified); ?></b> qualified paid referral<?= ((int)($nextTier['refs'] ?? 0) - $qualified) === 1 ? '' : 's'; ?>.
        <?php else: ?>
          You’re at the max tier. Absolute machine.
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button id="vxAffClaim" class="vx-btn<?= $eligible ? ' vx-btn-primary' : ' vx-btn-ghost'; ?>" type="button" <?= ($eligible ? '' : 'disabled'); ?> style="opacity:<?= ($eligible ? '1' : ($claimed ? '.65' : '.55')); ?>">
          <?= $claimed ? 'Claimed' : ($eligible ? 'Claim Affiliate Guardian' : 'Locked'); ?>
        </button>
      </div>
    </div>

    <div style="margin-top:12px;font-size:13px;opacity:.85;line-height:1.55">
      <div>✅ A referral qualifies when they activate <b>any paid Guardian</b> (Founders + Affiliate don’t count).</div>
      <div>🏅 Tier bonuses: every tier after unlock adds <b>+<?= (int)vx_affiliate_tier_bonus_step(); ?> VP/day</b> + <b>+<?= (int)vx_affiliate_tier_bonus_step(); ?> LP/day</b>.</div>
    </div>
  </div>

  <div class="vx-card" style="margin-top:14px;padding:14px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(12,18,28,.70)">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="font-weight:900">Your referrals</div>
      <button class="vx-btn vx-btn-ghost" id="vxAffRefresh" type="button">Refresh</button>
    </div>
    <div id="vxAffList" style="margin-top:10px;font-size:13px;opacity:.85">Loading…</div>
  </div>
</div>

<script>
(function(){
  async function claim(){
    const btn = document.getElementById('vxAffClaim');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = 'Claiming…';
    try{
      const r = await fetch('/api/user/affiliate_claim.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}});
      const j = await r.json();
      if (!j || !j.ok) throw new Error(j?.msg || 'failed');
      btn.textContent = 'Claimed';
      btn.classList.remove('vx-btn-primary');
      btn.classList.add('vx-btn-ghost');
    }catch(e){
      btn.disabled = false;
      btn.textContent = old;
      alert('Could not claim yet. Make sure you have enough qualified paid referrals.');
    }
  }

  async function load(){
    const el = document.getElementById('vxAffList');
    if (!el) return;
    el.textContent = 'Loading…';
    try {
      const r = await fetch('/api/user/affiliate_refs.php?limit=60', {credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok) throw new Error('bad');
      const items = j.items || [];
      if (!items.length) { el.innerHTML = '<div style="opacity:.7">No referrals yet. Share your link to start.</div>'; return; }
      const rows = items.map(it => {
        const ok = it.qualified ? '✅ Qualified' : '⏳ Pending';
        return `<div style="display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)">
          <div>${it.user}</div>
          <div style="opacity:${it.qualified?1:0.7}">${ok}</div>
        </div>`;
      }).join('');
      el.innerHTML = rows;
    } catch (e) {
      el.innerHTML = '<div style="opacity:.75">Could not load referrals. Try again.</div>';
    }
  }
  document.getElementById('vxAffRefresh')?.addEventListener('click', load);
  document.getElementById('vxAffClaim')?.addEventListener('click', claim);
  load();
})();
</script>

<?php
// bottom nav shell
require_once __DIR__ . '/../menu-f.php';
?>
