<?php
// GreenFarm — Referrals (Premium, mobile-first)
// NOTE: No strict_types here to avoid router output order issues.
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/vx_ref_tiers.php';
require_once __DIR__ . '/../../core/vx_ref_boost.php';
vx_schema_ensure($db);

global $db, $config, $opt;

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /'); exit; }

$opt['title'] = 'Referrals';

// Referral earnings log
$refLog = [];
try {
  $q = $db->query('SELECT buyer_id, deposit_id, deposit_usd, reward_usd, pct_used, receipt, created_at FROM vx_ref_earnings WHERE rid=? ORDER BY created_at DESC LIMIT 25', (int)$uid);
  if ($q) { while($r = $q->fetchArray()){ $refLog[] = $r; } }
} catch (Throwable $e) {}


function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Safe DB query helpers for FastCore DB wrappers that may echo SQL errors.
 * These helpers swallow echoed errors and return empty results safely.
 */
function vx_db_query_safe($db, string $sql) {
  if (!$db || $sql === '') return null;
  try {
    ob_start();
    $res = null;
    try { $res = $db->query($sql); } catch (Throwable $e) { $res = null; }
    $out = ob_get_clean();
    if (is_string($out) && trim($out) !== '') return null;
    return $res ?: null;
  } catch (Throwable $e) {
    return null;
  }
}
function vx_db_fetch_one_safe($db, string $sql): array {
  $res = vx_db_query_safe($db, $sql);
  if (!$res) return [];
  try {
    ob_start();
    $row = null;
    try { $row = $res->fetchArray(); } catch (Throwable $e) { $row = null; }
    $out = ob_get_clean();
    if (is_string($out) && trim($out) !== '') return [];
    return is_array($row) ? $row : [];
  } catch (Throwable $e) { return []; }
}
function vx_db_fetch_all_safe($db, string $sql, int $limit = 500): array {
  $res = vx_db_query_safe($db, $sql);
  if (!$res) return [];
  $rows = [];
  $i = 0;
  try {
    while ($i < $limit) {
      ob_start();
      $row = null;
      try { $row = $res->fetchArray(); } catch (Throwable $e) { $row = null; }
      $out = ob_get_clean();
      if (is_string($out) && trim($out) !== '') break;
      if (!is_array($row) || empty($row)) break;
      $rows[] = $row;
      $i++;
    }
  } catch (Throwable $e) {}
  return $rows;
}

/* --------- User / referral code --------- */
$user = vx_db_fetch_one_safe($db, 'SELECT id, login, ref_code FROM db_users WHERE id='.(int)$uid.' LIMIT 1');
$code = !empty($user['ref_code']) ? (string)$user['ref_code'] : ('ref_' . (string)$uid);

/* --------- Bot username --------- */
$bot = '';
if (!empty($config->telegram_bot))      { $bot = ltrim((string)$config->telegram_bot, '@'); }
elseif (!empty($config->bot_username))  { $bot = ltrim((string)$config->bot_username, '@'); }
elseif (!empty($config->telegram))      { $bot = ltrim((string)$config->telegram, '@'); }
$bot = preg_replace('~[^A-Za-z0-9_]+~', '', (string)$bot);

/* Mini App deep link uses /launch?startapp= (Direct Link Mini App). Bot fallback uses start= */
$link_startapp = $bot ? ("https://t.me/{$bot}/launch?startapp=" . rawurlencode($code)) : '';
$link_start    = $bot ? ("https://t.me/{$bot}?start="    . rawurlencode($code)) : '';
$link_tg       = $bot ? ("tg://resolve?domain={$bot}&start=" . rawurlencode($code)) : '';

/* --------- Stats --------- */
$rowCnt = vx_db_fetch_one_safe($db, 'SELECT COUNT(*) AS c FROM db_users WHERE rid='.(int)$uid);
$downline_count = (int)($rowCnt['c'] ?? 0);

$ref_cash_total = null;
if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_ref_earn')) {
  $row = vx_db_fetch_one_safe($db, 'SELECT COALESCE(SUM(usd * (rate/100.0)),0) AS s FROM db_ref_earn WHERE ref_uid='.(int)$uid);
  if (!$row) $row = vx_db_fetch_one_safe($db, 'SELECT COALESCE(SUM(usd * (rate/100.0)),0) AS s FROM db_ref_earn WHERE ref='.(int)$uid);
  if ($row) $ref_cash_total = (float)($row['s'] ?? 0.0);
}

$ref_points_total = 0;
if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_points_ledger')) {
  $rowp = vx_db_fetch_one_safe($db, "SELECT COALESCE(SUM(delta),0) AS s FROM db_points_ledger WHERE uid=".(int)$uid." AND ctx IN ('ref_buy_vault','ref_vault')");
  $ref_points_total = (int)($rowp['s'] ?? 0);
}

// Tier + boost status (qualified refs drive both)
$tier = ['ok'=>false];
$boost = ['ok'=>false];
try { $tier = vx_ref_tier_status($db, $uid); } catch (Throwable $e) {}
try { $boost = vx_ref_boost_status($db, $uid); } catch (Throwable $e) {}

/* --------- Downline list --------- */
$refs = vx_db_fetch_all_safe(
  $db,
  'SELECT id, login, tg_username, tg_name, reg
   FROM db_users
   WHERE rid='.(int)$uid.'
   ORDER BY id DESC
   LIMIT 200',
  260
);

$shareText = 'Join me on GreenFarm — earn Points and climb the leaderboards. Use my link: ' . $link_startapp;
try {
  if (is_array($tier) && (bool)($tier['ok'] ?? false) && !empty($tier['share_message'])) {
    $shareText = (string)$tier['share_message'] . "\n" . $link_startapp;
  } elseif ((bool)vx_app_setting('share_templates_enabled', true)) {
    $tpls = vx_app_setting('share_templates', []);
    if (is_array($tpls) && !empty($tpls)) {
      $pick = $tpls[array_rand($tpls)];
      $pick = (string)$pick;
      if ($pick !== '') {
        $shareText = str_replace('{link}', $link_startapp, $pick);
      }
    }
  }
} catch (Throwable $e) {}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
:root{
  --vx-bg:#060816;
  --vx-surface:rgba(11,16,36,.92);
  --vx-surface-2:rgba(15,23,42,.78);
  --vx-border:rgba(255,255,255,.10);
  --vx-text:#eef2ff;
  --vx-muted:#a8b2d1;
  --vx-accent:#00ffe0;
  --vx-accent-2:#58a6ff;
  --vx-good:#22c55e;
  --vx-warn:#fbbf24;
  --r:18px;
  --shadow:0 18px 52px rgba(0,0,0,.46);
}
*{ box-sizing:border-box; }
body{
  background:
    radial-gradient(1100px 700px at -15% -20%, rgba(0,255,224,.10), transparent 45%),
    radial-gradient(1100px 700px at 115% 110%, rgba(88,166,255,.12), transparent 45%),
    var(--vx-bg);
  color:var(--vx-text);
  font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
}
.nowrap{ white-space:nowrap; }
.vx-shell{ max-width:1100px; margin:16px auto 28px; padding:0 12px; }

.vx-card{
  background:linear-gradient(180deg, rgba(11,16,36,.92), rgba(9,13,28,.98));
  border:1px solid var(--vx-border);
  border-radius:var(--r);
  box-shadow:var(--shadow);
  overflow:hidden;
}
.vx-card + .vx-card{ margin-top:12px; }

.vx-hd{
  padding:14px 14px 12px;
  border-bottom:1px solid rgba(255,255,255,.08);
  display:flex; align-items:flex-start; justify-content:space-between; gap:10px; flex-wrap:wrap;
}
.vx-chip{
  display:inline-flex; align-items:center; gap:8px;
  border-radius:999px;
  padding:8px 12px;
  font-weight:950;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(15,23,42,.62);
  color:var(--vx-text);
}
.vx-title{ margin:0; font-weight:1000; letter-spacing:.01em; font-size:1.18rem; }
.vx-sub{ margin:6px 0 0; color:var(--vx-muted); font-size:.95rem; }
.vx-bd{ padding:14px; }

.vx-btn{
  border:0; border-radius:12px; padding:10px 12px;
  font-weight:950;
  display:inline-flex; align-items:center; gap:8px;
  text-decoration:none;
  user-select:none;
}
.vx-btn:active{ transform:translateY(1px); }
.vx-btn-primary{
  background:linear-gradient(135deg, var(--vx-accent), var(--vx-accent-2));
  color:#001014;
  box-shadow:0 12px 28px rgba(0,255,224,.18), 0 10px 26px rgba(88,166,255,.14);
}
.vx-btn-ghost{
  background:rgba(15,23,42,.92);
  color:#e2e8f0;
  border:1px solid rgba(148,163,184,.30);
}
.vx-btn-soft{
  background:rgba(15,23,42,.72);
  color:#e2e8f0;
  border:1px solid rgba(148,163,184,.22);
}

.vx-statgrid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
@media(min-width:760px){ .vx-statgrid{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
.vx-stat{
  border-radius:16px;
  border:1px solid rgba(255,255,255,.10);
  background:linear-gradient(180deg, rgba(15,23,42,.70), rgba(2,6,23,.55));
  padding:12px;
}
.vx-stat .k{ color:var(--vx-muted); font-size:.88rem; display:flex; align-items:center; gap:8px; }
.vx-stat .k i{ color:var(--vx-accent); }
.vx-stat .v{ font-weight:1100; font-size:1.36rem; margin-top:4px; line-height:1.05; }
.vx-stat .s{ margin-top:6px; font-size:.86rem; color:rgba(226,232,240,.82); }

.vx-layout{ display:grid; gap:12px; grid-template-columns:1fr; }
@media(min-width:960px){ .vx-layout{ grid-template-columns:1.2fr .8fr; align-items:start; } }

.vx-panel{
  border-radius:16px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(15,23,42,.72);
  padding:12px;
}

.vx-label{ font-weight:950; display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.vx-label i{ color:var(--vx-accent-2); }

.vx-textarea{
  width:100%;
  min-height:66px;
  max-height:220px;
  border:1px solid rgba(0,255,224,.26);
  border-radius:14px;
  padding:10px 10px;
  background:rgba(2,6,23,.55);
  color:var(--vx-text);
  outline:0;
  font-family:"JetBrains Mono","SF Mono",ui-monospace,Menlo,monospace;
  font-size:.92rem;
  line-height:1.35;
  overflow:auto;
  resize:none;
  white-space:nowrap;
}
.vx-actions{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }

.vx-note{
  margin-top:10px;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid rgba(148,163,184,.18);
  background:rgba(2,6,23,.44);
  color:rgba(226,232,240,.86);
  font-size:.92rem;
  line-height:1.45;
}

.vx-table-wrap{
  border-radius:16px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(15,23,42,.72);
  overflow:hidden;
}
.vx-table{ width:100%; border-collapse:collapse; }
.vx-table th, .vx-table td{ padding:10px 10px; border-bottom:1px solid rgba(148,163,184,.14); vertical-align:middle; }
.vx-table thead th{ background:rgba(2,6,23,.55); font-size:.78rem; text-transform:uppercase; letter-spacing:.06em; color:#e5e7eb; font-weight:1000; }
.vx-muted{ color:var(--vx-muted); }
.vx-name{ font-weight:950; }
.vx-small{ font-size:.88rem; }

.vx-badge{
  display:inline-flex; align-items:center; gap:6px;
  border-radius:999px;
  padding:5px 10px;
  font-weight:900;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(2,6,23,.55);
}
.vx-badge.ok{ border-color:rgba(34,197,94,.45); color:#86efac; background:rgba(34,197,94,.10); }

/* Toast: hidden by default (display:none) — only appears when triggered */
.vx-toast{
  position:fixed; z-index:9999; left:50%;
  transform:translateX(-50%) translateY(10px);
  bottom:18px;
  background:rgba(2,6,23,.92); color:#fff;
  border:1px solid rgba(255,255,255,.14);
  border-radius:12px;
  padding:10px 12px;
  font-weight:900;
  display:none;
  opacity:0;
  pointer-events:none;
  transition:opacity .16s ease, transform .16s ease;
}
.vx-toast.show{
  display:block;
  opacity:1;
  transform:translateX(-50%) translateY(0);
}

@media (prefers-reduced-motion: reduce){
  .vx-toast{ transition:none; }
  .vx-btn:active{ transform:none; }
}
</style>

<div class="vx-shell">

  <!-- Summary -->
  <section class="vx-card">
    <div class="vx-hd">
      <div>
        <div class="vx-chip"><i class="fa-solid fa-user-group"></i> Referrals</div>
        <div class="vx-sub">Boost your <b>VX allocation</b> — referrals earn automatically. VP (Harvest Points) convert to <b>VX</b> at listing.</div>
      </div>
      <a class="vx-btn vx-btn-ghost" href="/user/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Dashboard
      </a>
    </div>

    <div class="vx-bd">
      <div class="vx-statgrid">
        <div class="vx-stat">
          <div class="k"><i class="fa-solid fa-user-plus"></i> Total invited</div>
          <div class="v"><?= number_format($downline_count); ?></div>
          <div class="s">People who joined from your link.</div>
        </div>

        <div class="vx-stat">
          <div class="k"><i class="fa-solid fa-coins"></i> Cash rewards</div>
          <div class="v"><?= $ref_cash_total === null ? '—' : ('$'.number_format($ref_cash_total, 2, '.', '')); ?></div>
          <div class="s">Paid out automatically when withdrawals are enabled.</div>
        </div>

        <div class="vx-stat">
          <div class="k"><i class="fa-solid fa-star"></i> Points rewards</div>
          <div class="v"><?= number_format($ref_points_total); ?></div>
          <div class="s">Earned when your invites activate vaults.</div>
        </div>

        <div class="vx-stat">
          <div class="k"><i class="fa-solid fa-bolt"></i> Referral boost</div>
          <div class="v">
            <?php
              $tierName = (string)($tier['current']['name'] ?? '');
              $boostPct = (int)($boost['current']['pct'] ?? 0);
              $effPct = (float)($boost['effective_pct'] ?? 0.10);
              $eff = max(0.0, min(0.50, $effPct)) * 100;
            ?>
            <?= h($tierName ?: '—'); ?> • <?= (int)round($eff); ?>%
          </div>
          <div class="s">
            You earn <b><?= (int)round($eff); ?>%</b> of your referral’s Points purchases
            <?php if (!empty($boost['next'])): ?>
              • Next: <?= h((string)($boost['next']['name'] ?? '')); ?> at <?= (int)($boost['next']['min'] ?? 0); ?> qualified
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Invite + Team -->
  <section class="vx-card">
    <div class="vx-hd">
      <div>
        <h2 class="vx-title mb-0">Your invite link</h2>
        <div class="vx-sub">Send this in Telegram. New users must open the link to connect to you.</div>
      </div>

      <?php if ($bot): ?>
        <span class="vx-badge ok"><i class="fa-solid fa-shield-check"></i> Bot ready</span>
      <?php else: ?>
        <span class="vx-badge"><i class="fa-solid fa-triangle-exclamation"></i> Bot not set</span>
      <?php endif; ?>
    </div>

    <div class="vx-bd">
      <?php if (!$bot): ?>
        <div class="alert alert-warning mb-0">
          Bot username is not configured. Set <code>$config->telegram_bot</code> (recommended) or <code>$config->bot_username</code>/<code>$config->telegram</code>.
        </div>
      <?php else: ?>

        <div class="vx-layout">
          <!-- Invite tools -->
          <div class="vx-panel">
            <div class="vx-label"><i class="fa-solid fa-link"></i> Mini App link</div>
            <textarea id="vxRefLink" class="vx-textarea" rows="2" wrap="off" readonly spellcheck="false"><?= h($link_startapp); ?></textarea>

            <div class="vx-actions">
              <button type="button" class="vx-btn vx-btn-primary" id="vxCopyLink">
                <i class="fa-regular fa-copy"></i> Copy
              </button>

              <button type="button" class="vx-btn vx-btn-soft" id="vxShareTG">
                <i class="fa-brands fa-telegram"></i> Share
              </button>

              <a class="vx-btn vx-btn-ghost" href="<?= h($link_startapp); ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-telegram"></i> Open
              </a>

              <a class="vx-btn vx-btn-ghost" href="<?= h($link_start); ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-robot"></i> Bot link
              </a>

              <a class="vx-btn vx-btn-ghost" href="<?= h($link_tg); ?>">
                <i class="fa-solid fa-bolt"></i> tg://
              </a>
            </div>

            <div class="vx-note">
              Tip: Pin your link in your profile and group chats for consistent referrals.
            </div>
          </div>

          <!-- Team list -->
          <div class="vx-panel">
            <div class="vx-label"><i class="fa-solid fa-network-wired"></i> Your team</div>

            <div class="vx-table-wrap">
              <div class="table-responsive">
                <table class="vx-table">
                  <thead>
                    <tr>
                      <th>User</th>
                      <th class="text-end">Joined</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$refs): ?>
                      <tr><td colspan="2" class="text-center vx-muted">No invited users yet.</td></tr>
                    <?php else: foreach ($refs as $r):
                      $name = '';
                      if (!empty($r['tg_username'])) $name = '@'.ltrim((string)$r['tg_username'], '@');
                      elseif (!empty($r['tg_name'])) $name = (string)$r['tg_name'];
                      else $name = (string)($r['login'] ?? ('User #'.(int)$r['id']));
                      $joined = !empty($r['reg']) ? date('d M Y - H:i', (int)$r['reg']) : '—';
                    ?>
                      <tr>
                        <td>
                          <div class="vx-name"><?= h($name); ?></div>
                          <div class="vx-muted vx-small">ID #<?= (int)$r['id']; ?></div>
                        </td>
                        <td class="text-end nowrap"><?= h($joined); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="vx-muted vx-small mt-2">
              Only users who open your invite link are counted.
            </div>
          </div>
        </div>

      <?php endif; ?>
    </div>
  </section>


  <!-- Referral earnings -->
  <section class="vx-card" style="margin-top:12px;">
    <div class="vx-hd">
      <div>
        <div class="vx-chip"><i class="fa-solid fa-receipt"></i> Earnings</div>
        <h2 class="vx-title mb-0">Referral earnings</h2>
        <div class="vx-sub">Instant credits from your referrals. Latest 25 payouts.</div>
      </div>
      <a class="vx-btn" href="/user/history"><i class="fa-solid fa-clock-rotate-left"></i> Wallet history</a>
    </div>

    <div class="vx-bd">
      <?php if (empty($refLog)): ?>
        <div class="vx-mini"><i class="fa-solid fa-circle-info"></i> No referral payouts yet.</div>
      <?php else: ?>
        <div style="display:grid;gap:10px">
          <?php foreach ($refLog as $r): ?>
            <div style="display:flex;flex-direction:column;gap:8px;padding:12px;border-radius:18px;border:1px solid rgba(148,163,184,.14);background:rgba(2,6,23,.38)">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                <div style="font-weight:1000">
                  +$<?= number_format((float)($r['reward_usd'] ?? 0), 2); ?>
                  <span style="opacity:.7;font-weight:900">from deposit #<?= (int)($r['deposit_id'] ?? 0); ?></span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <?php if (!empty($r['pct_used'])): ?>
                    <span class="vx-badge ok"><i class="fa-solid fa-percent"></i> <?= number_format((float)$r['pct_used'], 2); ?>%</span>
                  <?php endif; ?>
                  <span class="vx-badge ok"><i class="fa-solid fa-bolt"></i> Instant</span>
                </div>
              </div>

              <div class="vx-sub" style="opacity:.85">
                Buyer <b>#<?= (int)($r['buyer_id'] ?? 0); ?></b>
                • Deposit $<?= number_format((float)($r['deposit_usd'] ?? 0), 2); ?>
                • <?= date('M j, H:i', (int)($r['created_at'] ?? time())); ?>
              </div>

              <?php if (!empty($r['receipt'])): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 12px;border-radius:16px;border:1px solid rgba(148,163,184,.12);background:rgba(15,23,42,.35)">
                  <div style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:.78rem;opacity:.92;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%">
                    <?= htmlspecialchars((string)$r['receipt'], ENT_QUOTES); ?>
                  </div>
                  <button type="button" class="vx-btn" data-copy="<?= htmlspecialchars((string)$r['receipt'], ENT_QUOTES); ?>">
                    <i class="fa-solid fa-copy"></i> Copy receipt
                  </button>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

</div>

<div id="vxToast" class="vx-toast" role="status" aria-live="polite" aria-atomic="true"></div>

<script>
(function(){
  var toastTimer = null;

  function showToast(msg){
    var t = document.getElementById('vxToast');
    if(!t) return;
    t.textContent = msg || 'Done';
    t.classList.add('show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 1300);
  }

  function copyText(text){
    text = (text || '').toString();
    if (!text) { showToast('Nothing to copy'); return; }

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function(){ showToast('Copied'); })
        .catch(function(){ fallbackCopy(text); });
      return;
    }
    fallbackCopy(text);
  }
  // Copy receipt buttons
  document.addEventListener('click', function(ev){
    var btn = ev.target && (ev.target.closest ? ev.target.closest('[data-copy]') : null);
    if (!btn) return;
    ev.preventDefault();
    copyText(btn.getAttribute('data-copy') || '');
  });


  function fallbackCopy(text){
    try{
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      ta.style.top = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try { document.execCommand('copy'); } catch(e){}
      document.body.removeChild(ta);
      showToast('Copied');
    }catch(e){
      showToast('Copy failed');
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Hard-hide toast on load (prevents any weird CSS overrides)
    var t = document.getElementById('vxToast');
    if (t) { t.classList.remove('show'); }

    var linkEl = document.getElementById('vxRefLink');
    var copyBtn = document.getElementById('vxCopyLink');
    if (copyBtn && linkEl) {
      copyBtn.addEventListener('click', function(e){
        e.preventDefault();
        copyText(linkEl.value || '');
      });
    }

    // Telegram share
    var shareBtn = document.getElementById('vxShareTG');
    // Share the invite page URL (rich preview + card image).
    var refCode = <?= json_encode((string)$ref_code, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
    var deepLink = window.location.origin + (refCode ? ('/invite/' + encodeURIComponent(refCode)) : '/invite');
    var shareText = <?= json_encode($shareText, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
    var tgShareUrl = 'https://t.me/share/url?url=' + encodeURIComponent(deepLink) + '&text=' + encodeURIComponent(shareText + "\n" + deepLink);

    if (shareBtn) {
      shareBtn.addEventListener('click', function(e){
        try{ fetch('/api/user/share_event.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({channel:'telegram',ctx:'refs_share'})}).catch(()=>{}); }catch(_e){}
        try{ if (window.Telegram && Telegram.WebApp && Telegram.WebApp.HapticFeedback){ Telegram.WebApp.HapticFeedback.impactOccurred('light'); } }catch(_e){}

        e.preventDefault();
        try {
          if (window.Telegram && Telegram.WebApp && Telegram.WebApp.openTelegramLink) {
            Telegram.WebApp.openTelegramLink(tgShareUrl);
            return;
          }
        } catch(err){}
        window.open(tgShareUrl, '_blank', 'noopener');
      });
    }
  });
})();
</script>
