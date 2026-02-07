<?php
// pages/user/history.php
declare(strict_types=1);
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/schema_ensure.php';

global $db, $config, $opt;

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /', true, 302); exit; }

vx_schema_ensure($db);

$opt['title'] = 'Wallet History';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2, '.', ','); }

$items = [];

$has = function(string $t) use ($db): bool {
  try { return vx_table_exists($db, $t); } catch (Throwable $e) { return false; }
};

$cur = (string)($config->valuta ?? 'USD');

/** Deposits */
if ($has('db_insert')) {
  try {
    $q = $db->query("SELECT id, sum, sys, status, `end` AS ts, del AS ts2 FROM db_insert WHERE uid=? ORDER BY COALESCE(`end`, del, id) DESC LIMIT 80", $uid);
    while($r = $q->fetchArray()){
      $ts = (int)($r['ts'] ?? 0); if ($ts<=0) $ts = (int)($r['ts2'] ?? 0);
      $items[] = [
        'ts'=>$ts,
        'type'=>'Deposit',
        'sign'=>'+',
        'amount'=>(float)($r['sum'] ?? 0),
        'status'=>(int)($r['status'] ?? 0),
        'meta'=>['id'=>(int)($r['id'] ?? 0), 'sys'=>(string)($r['sys'] ?? '')],
      ];
    }
  } catch (Throwable $e) {}
}

/** Withdrawals */
if ($has('db_payout')) {
  try {
    $q = $db->query("SELECT id, sum, status, sys, psys, purse, txid, proof, paid_at, `add` AS ts, del AS ts2 FROM db_payout WHERE uid=? ORDER BY COALESCE(del, paid_at, `add`, id) DESC LIMIT 80", $uid);
    while($r = $q->fetchArray()){
      $ts = (int)($r['ts2'] ?? 0); if ($ts<=0) $ts = (int)($r['paid_at'] ?? 0); if ($ts<=0) $ts = (int)($r['ts'] ?? 0);
      $items[] = [
        'ts'=>$ts,
        'type'=>'Withdraw',
        'sign'=>'-',
        'amount'=>(float)($r['sum'] ?? 0),
        'status'=>(int)($r['status'] ?? 0),
        'meta'=>[
          'id'=>(int)($r['id'] ?? 0),
          'sys'=>(string)($r['sys'] ?? ''),
          'purse'=>(string)($r['purse'] ?? ''),
          'txid'=>(string)($r['txid'] ?? ''),
          'proof'=>(string)($r['proof'] ?? ''),
        ],
      ];
    }
  } catch (Throwable $e) {}
}

/** Referral earnings */
if ($has('vx_ref_earnings')) {
  try {
    $q = $db->query("SELECT buyer_id, deposit_id, deposit_usd, reward_usd, pct_used, receipt, created_at AS ts FROM vx_ref_earnings WHERE rid=? ORDER BY created_at DESC LIMIT 80", $uid);
    while($r = $q->fetchArray()){
      $items[] = [
        'ts'=>(int)($r['ts'] ?? 0),
        'type'=>'Referral',
        'sign'=>'+',
        'amount'=>(float)($r['reward_usd'] ?? 0),
        'status'=>1,
        'meta'=>[
          'buyer'=>(int)($r['buyer_id'] ?? 0),
          'deposit_id'=>(int)($r['deposit_id'] ?? 0),
          'deposit_usd'=>(float)($r['deposit_usd'] ?? 0),
          'pct'=>(float)($r['pct_used'] ?? 0),
          'receipt'=>(string)($r['receipt'] ?? ''),
        ],
      ];
    }
  } catch (Throwable $e) {}
}

/** Season pass purchases */
if ($has('vx_season_passes')) {
  try {
    $q = $db->query("SELECT season_id, paid_usd, paid_xtr, method, created_at AS ts FROM vx_season_passes WHERE uid=? ORDER BY created_at DESC LIMIT 60", $uid);
    while($r = $q->fetchArray()){
      $items[] = [
        'ts'=>(int)($r['ts'] ?? 0),
        'type'=>'Season Pass',
        'sign'=>'-',
        'amount'=>(float)($r['paid_usd'] ?? 0),
        'status'=>1,
        'meta'=>[
          'season_id'=>(int)($r['season_id'] ?? 0),
          'method'=>(string)($r['method'] ?? ''),
          'xtr'=>(int)($r['paid_xtr'] ?? 0),
        ],
      ];
    }
  } catch (Throwable $e) {}
}

/** Points */
if ($has('db_points_ledger')) {
  try {
    $q = $db->query("SELECT delta, ctx, created_at AS ts FROM db_points_ledger WHERE uid=? ORDER BY created_at DESC LIMIT 80", $uid);
    while($r = $q->fetchArray()){
      $d = (int)($r['delta'] ?? 0);
      $items[] = [
        'ts'=>(int)($r['ts'] ?? 0),
        'type'=>'Points',
        'sign'=>($d>=0?'+':'-'),
        'amount'=>abs($d),
        'status'=>1,
        'meta'=>['ctx'=>(string)($r['ctx'] ?? '')],
      ];
    }
  } catch (Throwable $e) {}
}

usort($items, fn($a,$b)=> (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0));
$items = array_slice($items, 0, 120);

$stLabel = function(string $type, int $st): array {
  if ($type === 'Withdraw') {
    if ($st === 0) return ['Pending', 'wait'];
    if ($st < 0) return ['Rejected', 'bad'];
    return ['Paid', 'ok'];
  }
  if ($type === 'Deposit') {
    if ($st === 0) return ['Pending', 'wait'];
    if ($st < 0) return ['Rejected', 'bad'];
    return ['Credited', 'ok'];
  }
  return ['Done', 'ok'];
};
?>
<style>
.vx-hwrap{max-width:1120px;margin:0 auto;}
.vx-card2{
  border-radius:22px;
  border:1px solid rgba(148,163,184,.14);
  background:radial-gradient(1000px 420px at 12% 0%, rgba(251,191,36,.10), transparent 60%),
             radial-gradient(900px 420px at 98% 0%, rgba(0,255,224,.08), transparent 58%),
             linear-gradient(180deg, rgba(10,16,32,.62), rgba(2,6,23,.52));
  box-shadow:0 18px 45px rgba(0,0,0,.45);
  overflow:hidden;
}
.vx-row{
  display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
  padding:12px 14px;
  border-top:1px solid rgba(148,163,184,.10);
}
.vx-row:first-child{border-top:0;}
.vx-k{font-weight:1000;letter-spacing:.01em;}
.vx-sub2{opacity:.75;font-size:.86rem;margin-top:2px;line-height:1.3;}
.vx-amt{font-weight:1100;}
.vx-pill{
  display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;
  border:1px solid rgba(148,163,184,.14);
  background:rgba(15,23,42,.45);
  font-weight:900;font-size:.78rem;
}
.vx-pill.ok{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.08);}
.vx-pill.wait{border-color:rgba(251,191,36,.22);background:rgba(251,191,36,.08);}
.vx-pill.bad{border-color:rgba(248,113,113,.22);background:rgba(248,113,113,.08);}
.vx-mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono','Courier New', monospace;}
.vx-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
.vx-actions .btn{border-radius:999px;font-weight:950;}
.vx-proof{
  margin-top:8px;
  padding:10px 12px;
  border-radius:16px;
  border:1px solid rgba(148,163,184,.12);
  background:rgba(15,23,42,.35);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}
@media (max-width:520px){
  .vx-row{flex-direction:column;}
  .vx-proof .vx-mono{max-width:100% !important;}
}
</style>

<div class="vx-hwrap">
  <div class="vx-actions">
    <a class="btn vx-btn-primary" href="/user/deposit"><i class="fa-solid fa-wallet"></i> Deposit</a>
    <a class="btn vx-btn-ghost" href="/user/withdraw"><i class="fa-solid fa-arrow-up-right-from-square"></i> Withdraw</a>
    <a class="btn vx-btn-ghost" href="/user/referals"><i class="fa-solid fa-user-group"></i> Referrals</a>
  </div>

  <div class="vx-card2">
    <div style="padding:14px 14px 10px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div>
        <div class="vx-chip"><i class="fa-solid fa-clock-rotate-left"></i> Wallet</div>
        <div style="font-weight:1200;font-size:1.12rem;margin-top:4px">Wallet history</div>
        <div class="vx-sub2">Deposits, withdrawals, referrals, Season Pass purchases, and recent points.</div>
      </div>
      <span class="vx-pill ok"><i class="fa-solid fa-shield-check"></i> Live ledger</span>
    </div>

    <?php if (empty($items)): ?>
      <div style="padding:14px" class="vx-sub2"><i class="fa-solid fa-circle-info"></i> No history yet.</div>
    <?php else: ?>
      <?php foreach ($items as $it):
        $ts = (int)($it['ts'] ?? 0);
        $type = (string)($it['type'] ?? 'Event');
        $sign = (string)($it['sign'] ?? '');
        $amt  = (float)($it['amount'] ?? 0);
        $status = (int)($it['status'] ?? 0);
        $meta = (array)($it['meta'] ?? []);
        [$label, $pill] = $stLabel($type, $status);
      ?>
        <div class="vx-row">
          <div style="min-width:0">
            <div class="vx-k">
              <?= h($type); ?> <span style="opacity:.7;font-weight:900">• <?= $ts>0 ? date('M j, H:i', $ts) : '—'; ?></span>
            </div>
            <div class="vx-sub2">
              <?php if ($type === 'Deposit'): ?>
                #<?= (int)($meta['id'] ?? 0); ?> • <?= h($meta['sys'] ?? ''); ?>
              <?php elseif ($type === 'Withdraw'): ?>
                #<?= (int)($meta['id'] ?? 0); ?> • <?= h($meta['sys'] ?? ''); ?>
                <?php if (!empty($meta['purse'])): ?> • <span class="vx-mono"><?= h($meta['purse']); ?></span><?php endif; ?>
                <?php if (!empty($meta['txid'])): ?> • TX <span class="vx-mono"><?= h($meta['txid']); ?></span><?php endif; ?>
              <?php elseif ($type === 'Referral'): ?>
                Buyer #<?= (int)($meta['buyer'] ?? 0); ?> • Deposit #<?= (int)($meta['deposit_id'] ?? 0); ?>
                <?php if (!empty($meta['pct'])): ?> • <?= number_format((float)$meta['pct'],2); ?>%<?php endif; ?>
              <?php elseif ($type === 'Season Pass'): ?>
                Season #<?= (int)($meta['season_id'] ?? 0); ?> • <?= h(($meta['method'] ?? '') ?: ''); ?>
                <?php if (!empty($meta['xtr'])): ?> • <?= (int)$meta['xtr']; ?> XTR<?php endif; ?>
              <?php elseif ($type === 'Points'): ?>
                <?= h(($meta['ctx'] ?? '') ?: 'Points'); ?>
              <?php endif; ?>
            </div>

            <?php if ($type === 'Withdraw' && !empty($meta['proof'])): ?>
              <div class="vx-proof">
                <div class="vx-mono" style="font-size:.78rem;opacity:.92;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%">
                  <?= h($meta['proof']); ?>
                </div>
                <button type="button" class="vx-pill" data-copy="<?= h($meta['proof']); ?>"><i class="fa-solid fa-copy"></i> Copy proof</button>
              </div>
            <?php endif; ?>

            <?php if ($type === 'Referral' && !empty($meta['receipt'])): ?>
              <div class="vx-proof">
                <div class="vx-mono" style="font-size:.78rem;opacity:.92;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%">
                  <?= h($meta['receipt']); ?>
                </div>
                <button type="button" class="vx-pill" data-copy="<?= h($meta['receipt']); ?>"><i class="fa-solid fa-copy"></i> Copy receipt</button>
              </div>
            <?php endif; ?>
          </div>

          <div style="text-align:right;flex:0 0 auto">
            <div class="vx-amt">
              <?= h($sign); ?>
              <?php if ($type === 'Points'): ?>
                <?= number_format($amt, 0, '.', ','); ?>
              <?php else: ?>
                <?= money($amt); ?> <?= h($cur); ?>
              <?php endif; ?>
            </div>
            <div style="margin-top:6px;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap">
              <span class="vx-pill <?= h($pill); ?>"><i class="fa-solid fa-circle"></i> <?= h($label); ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= htmlspecialchars($CSP_NONCE, ENT_QUOTES); ?>">
(function(){
  const toast = (m)=> (window.toast ? window.toast(m) : null);

  async function doCopy(text){
    try{ await navigator.clipboard.writeText(text); toast && toast('Copied'); }
    catch(e){
      try{
        const ta=document.createElement('textarea');
        ta.value=text; ta.style.position='fixed'; ta.style.left='-9999px';
        document.body.appendChild(ta); ta.focus(); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        toast && toast('Copied');
      }catch(e2){}
    }
  }

  document.addEventListener('click', (ev)=>{
    const b = ev.target && (ev.target.closest ? ev.target.closest('[data-copy]') : null);
    if(!b) return;
    ev.preventDefault();
    const t = b.getAttribute('data-copy') || '';
    if (!t) return;
    doCopy(t);
  });
})();
</script>
