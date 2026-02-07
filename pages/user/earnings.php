<?php
// File: /pages/user/earnings.php
// Purpose: Consolidated earnings hub (Yield + VP + LP) with real ledgers (no placeholders).
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/schema_ensure.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /auth', true, 302); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt2($n){ return number_format((float)$n, 2, '.', ','); }
function fmti($n){ return number_format((float)$n, 0, '.', ','); }

$opt['title'] = 'My Earnings';

vx_guardians_schema_ensure($db);
vx_schema_ensure($db);

// Totals
$yieldClaimed = 0.0;
$vpTotal = 0; $vpSpendable = 0;
$lpTotal = 0;

try {
  $r = $db->query('SELECT COALESCE(SUM(amount_usd),0) AS s FROM vx_yield_ledger WHERE uid=?', $uid)->fetchArray();
  $yieldClaimed = (float)($r['s'] ?? 0);
} catch (Throwable $e) {}

try {
  $u = $db->query('SELECT points_total, points_spendable FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
  $vpTotal = (int)($u['points_total'] ?? 0);
  $vpSpendable = (int)($u['points_spendable'] ?? 0);
} catch (Throwable $e) {}

try {
  $r = $db->query('SELECT COALESCE(SUM(delta),0) AS s FROM vx_lp_ledger WHERE uid=?', $uid)->fetchArray();
  $lpTotal = (int)($r['s'] ?? 0);
} catch (Throwable $e) {}

// Recent rows
$yieldRows = $vpRows = $lpRows = [];

try {
  $q = $db->query('SELECT amount_usd, ctx, created_at FROM vx_yield_ledger WHERE uid=? ORDER BY created_at DESC LIMIT 60', $uid);
  if ($q) while($r=$q->fetchArray()) $yieldRows[] = $r;
} catch (Throwable $e) {}

try {
  $q = $db->query('SELECT delta, ctx, created_at FROM db_points_ledger WHERE uid=? ORDER BY created_at DESC LIMIT 60', $uid);
  if ($q) while($r=$q->fetchArray()) $vpRows[] = $r;
} catch (Throwable $e) {}

try {
  $q = $db->query("SELECT l.delta, l.ctx, l.chain_id, l.created_at, t.title AS plan_title
                     FROM vx_lp_ledger l
                     LEFT JOIN vx_guardian_chains c ON c.id = l.chain_id
                     LEFT JOIN db_tarif t ON t.id = c.tarif
                    WHERE l.uid=?
                    ORDER BY l.created_at DESC
                    LIMIT 60", $uid);
  if ($q) while($r=$q->fetchArray()) $lpRows[] = $r;
} catch (Throwable $e) {}

?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/modern-normalize/2.0.0/modern-normalize.min.css" rel="stylesheet" />
<style>
:root{--bg:#060816;--surf:#0b1024;--b:rgba(255,255,255,.10);--t:#eaf0ff;--m:#a8b2d1;--a:#00ffe0;--a2:#ffdd77;--r:18px}
body{background:radial-gradient(1200px 700px at 110% -10%, rgba(0,255,224,.10), transparent 45%),radial-gradient(900px 600px at -10% 10%, rgba(255,221,119,.10), transparent 40%),#060816;color:var(--t)}
.wrap{max-width:1040px;margin:0 auto;padding:14px}
.grid{display:grid;gap:12px;grid-template-columns:1fr}
@media(min-width:920px){.grid{grid-template-columns:1.15fr .85fr}}
.card{background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02)) var(--surf);border:1px solid var(--b);border-radius:var(--r);padding:16px}
.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.h1{margin:0;font-weight:1000;letter-spacing:.01em}
.pill{display:inline-flex;gap:8px;align-items:center;padding:8px 12px;border:1px solid var(--b);border-radius:999px;background:rgba(255,255,255,.03);text-decoration:none;color:var(--t);font-weight:900}
.pill:hover{border-color:rgba(0,255,224,.35)}
.stats{display:grid;gap:10px;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:12px}
@media(max-width:720px){.stats{grid-template-columns:1fr}}
.box{border:1px solid var(--b);border-radius:14px;padding:12px;background:rgba(255,255,255,.03)}
.k{color:var(--m);font-weight:900;font-size:.9rem}
.v{font-weight:1100;font-size:1.55rem;margin-top:4px;font-variant-numeric:tabular-nums}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.tab{cursor:pointer;border:1px solid var(--b);border-radius:999px;padding:9px 12px;background:rgba(255,255,255,.03);color:var(--t);font-weight:1000}
.tab.active{background:linear-gradient(90deg, rgba(0,255,224,.20), rgba(255,221,119,.18));border-color:rgba(0,255,224,.35)}
.list{display:flex;flex-direction:column;gap:8px;max-height:520px;overflow:auto;margin-top:12px}
.row{display:flex;justify-content:space-between;gap:10px;border:1px solid var(--b);border-radius:14px;padding:10px 12px;background:rgba(255,255,255,.03)}
.l{min-width:0}
.title{font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sub{color:var(--m);font-size:.86rem;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.r{text-align:right;font-weight:1100;white-space:nowrap}
.pos{color:var(--a)}
.pos2{color:var(--a2)}
.hint{color:var(--m);font-size:.9rem;line-height:1.35;margin-top:8px}
</style>

<div class="wrap">
  <div class="grid">
    <div class="card">
      <div class="top">
        <div>
          <h2 class="h1">My Earnings</h2>
          <div class="hint">All numbers below are live from your ledgers: <b>Yield</b> claims, <b>VP</b> activity, and <b>LP</b> prestige accrual.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a class="pill" href="/user/dashboard">Dashboard</a>
          <a class="pill" href="/user/codex">Mutant Index</a>
          <a class="pill" href="/user/plans">Activate</a>
        </div>
      </div>

      <div class="stats">
        <div class="box">
          <div class="k">Yield claimed (lifetime)</div>
          <div class="v">$<?= fmt2($yieldClaimed) ?></div>
          <div class="hint">Tracks every collection into your balance.</div>
        </div>
        <div class="box">
          <div class="k">VP (spendable / lifetime)</div>
          <div class="v"><?= fmti($vpSpendable) ?> <span style="color:var(--m);font-weight:900">/</span> <?= fmti($vpTotal) ?></div>
          <div class="hint">Seasonal performance currency (future VX conversion).</div>
        </div>
        <div class="box">
          <div class="k">LP (lifetime)</div>
          <div class="v"><?= fmti($lpTotal) ?></div>
          <div class="hint">Unlocked after crossbreed ≥ 1 and the first 30-day cycle.</div>
        </div>
      </div>

      <div class="tabs" role="tablist" aria-label="Earnings tabs">
        <button class="tab active" data-tab="yield" role="tab" aria-selected="true">Yield</button>
        <button class="tab" data-tab="vp" role="tab" aria-selected="false">VP</button>
        <button class="tab" data-tab="lp" role="tab" aria-selected="false">LP</button>
      </div>
    </div>

    <div class="card" id="earningsPanel">
      <div id="tab_yield">
        <h3 style="margin:0">Yield ledger</h3>
        <div class="hint">Shows real claim events. Your daily accrual is handled by the earnings engine and becomes claimable as it accumulates.</div>
        <div class="list">
          <?php if (!$yieldRows): ?>
            <div class="row"><div class="l"><div class="title">No yield claims yet</div><div class="sub">Your first claim will show here.</div></div><div class="r">—</div></div>
          <?php else: foreach($yieldRows as $r):
            $amt=(float)($r['amount_usd']??0);
            $ctx=(string)($r['ctx']??'claim');
            $when=(int)($r['created_at']??0);
          ?>
            <div class="row">
              <div class="l"><div class="title"><?= h($ctx) ?></div><div class="sub"><?= $when ? date('M j, Y H:i', $when) : '—' ?></div></div>
              <div class="r pos">+ $<?= fmt2($amt) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div id="tab_vp" style="display:none">
        <h3 style="margin:0">VP activity</h3>
        <div class="hint">Includes vault buys, referrals, and daily VP accrual ticks.</div>
        <div class="list">
          <?php if (!$vpRows): ?>
            <div class="row"><div class="l"><div class="title">No VP entries</div><div class="sub">Activate a Guardian to begin earning VP daily.</div></div><div class="r">—</div></div>
          <?php else: foreach($vpRows as $r):
            $d=(int)($r['delta']??0);
            $ctx=(string)($r['ctx']??'vp');
            $when=(int)($r['created_at']??0);
            $sign=$d>0?'+':'';
          ?>
            <div class="row">
              <div class="l"><div class="title"><?= h($ctx) ?></div><div class="sub"><?= $when ? date('M j, Y H:i', $when) : '—' ?></div></div>
              <div class="r <?= $d>=0?'pos':'' ?>"><?= $sign . (int)$d ?> VP</div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div id="tab_lp" style="display:none">
        <h3 style="margin:0">LP prestige accrual</h3>
        <div class="hint">LP is rare: it starts after you crossbreed and only after the first full 30-day cycle completes.</div>
        <div class="list">
          <?php if (!$lpRows): ?>
            <div class="row"><div class="l"><div class="title">No LP entries</div><div class="sub">Crossbreed a Guardian to unlock LP earning.</div></div><div class="r">—</div></div>
          <?php else: foreach($lpRows as $r):
            $d=(int)($r['delta']??0);
            $ctx=(string)($r['ctx']??'lp');
            $title=(string)($r['plan_title']??'Mutant Crop');
            $when=(int)($r['created_at']??0);
          ?>
            <div class="row">
              <div class="l"><div class="title"><?= h($title) ?></div><div class="sub"><?= h($ctx) ?> • <?= $when ? date('M j, Y H:i', $when) : '—' ?></div></div>
              <div class="r pos2">+<?= (int)$d ?> LP</div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var tabs = document.querySelectorAll('.tab');
  function show(k){
    document.getElementById('tab_yield').style.display = (k==='yield')?'block':'none';
    document.getElementById('tab_vp').style.display = (k==='vp')?'block':'none';
    document.getElementById('tab_lp').style.display = (k==='lp')?'block':'none';
    tabs.forEach(function(b){
      var on = (b.dataset.tab===k);
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on?'true':'false');
    });
    try{
      if (window.Telegram && Telegram.WebApp && Telegram.WebApp.HapticFeedback){
        Telegram.WebApp.HapticFeedback.impactOccurred('light');
      }
    }catch(e){}
  }
  tabs.forEach(function(b){ b.addEventListener('click', function(){ show(b.dataset.tab); }); });
})();
</script>
