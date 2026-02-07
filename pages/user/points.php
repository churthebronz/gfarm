<?php
if (!defined('FastCore')) define('FastCore', true);
require_once __DIR__ . '/../../core/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /auth', true, 302); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($n){ return number_format((float)$n, 0, '.', ','); }
$opt['title'] = 'VP History';
$user = $db->query("SELECT points_spendable, points_total FROM db_users WHERE id=? LIMIT 1", $uid);
$u = $user ? ($user->fetchArray() ?: []) : [];
$spendable = (int)($u['points_spendable'] ?? 0);
$lifetime  = (int)($u['points_total'] ?? 0);
$rows = [];
try{
  $q = $db->query("SELECT delta, ctx, created_at FROM db_points_ledger WHERE uid=? ORDER BY created_at DESC LIMIT 50", $uid);
  if ($q) { while($r=$q->fetchArray()){ $rows[]=$r; } }
}catch(Throwable $e){}
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/modern-normalize/2.0.0/modern-normalize.min.css" rel="stylesheet" />
<style>
:root{--vx-bg:#060816; --vx-surface:#0b1024; --vx-border:rgba(255,255,255,.10); --vx-text:#eaf0ff; --vx-muted:#a8b2d1; --vx-accent:#00ffe0;}
body{background:radial-gradient(1200px 700px at 110% -10%, rgba(0,255,224,.10), transparent 45%) #060816;}
.vx-wrap{max-width:960px;margin:0 auto;padding:14px;}
.vx-card{background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02)) var(--vx-surface);border:1px solid var(--vx-border);border-radius:16px;padding:16px;}
.vx-grid{display:grid;gap:12px;grid-template-columns:1fr;} @media(min-width:820px){.vx-grid{grid-template-columns:1.2fr .8fr}}
.vx-stat{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.vx-stat .box{border:1px solid var(--vx-border);border-radius:14px;padding:14px}
.vx-stat .k{color:var(--vx-muted)}
.vx-stat .v{font-weight:900;font-size:1.6rem}
.vx-cta{display:flex;gap:10px;flex-wrap:wrap}
.vx-cta a{border:1px solid var(--vx-border);border-radius:12px;padding:10px 12px;text-decoration:none;color:var(--vx-text);background:rgba(255,255,255,.03)}
.vx-list{display:flex;flex-direction:column;gap:8px;max-height:360px;overflow:auto}
.vx-row{display:flex;justify-content:space-between;gap:10px;border:1px solid var(--vx-border);border-radius:12px;padding:10px 12px;background:rgba(255,255,255,.03)}
.vx-row .l{opacity:.9}.vx-row .r{font-weight:800}
</style>
<div class="vx-wrap">
  <div class="vx-grid">
    <div class="vx-card">
      <h2 style="margin:0 0 10px 0;">Harvest Points (VP) &nbsp;<span style="color:var(--vx-accent)">Season Earnings</span></h2>
      <div class="vx-stat">
        <div class="box"><div class="k">Spendable VP</div><div class="v"><?= fmt($spendable) ?></div></div>
        <div class="box"><div class="k">Lifetime VP</div><div class="v"><?= fmt($lifetime) ?></div></div>
      </div>
      <div class="vx-cta" style="margin-top:12px;">
        <a href="/user/leaderboard">Leaderboards</a>
        <a href="/user/seasons">Seasons</a>
        <a href="/user/history">Farm History</a>
      </div>
    </div>
    <div class="vx-card">
      <h3 style="margin:0 0 8px 0;">Recent activity</h3>
      <?php if (!$rows): ?>
        <div class="vx-row"><div class="l">No recent entries.</div><div class="r">—</div></div>
      <?php else: foreach($rows as $r):
        $delta = (int)($r['delta'] ?? 0);
        $ctx = (string)($r['ctx'] ?? 'activity');
        $sign = $delta > 0 ? '+' : '';
      ?>
        <div class="vx-row">
          <div class="l"><?= h($ctx) ?></div>
          <div class="r"><?= $sign . $delta ?> VP</div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
