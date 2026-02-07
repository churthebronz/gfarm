<?php
if (!defined('FastCore')) define('FastCore', true);
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /auth', true, 302); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($n){ return number_format((float)$n, 0, '.', ','); }
$opt['title'] = 'LP History';

vx_guardians_schema_ensure($db);

$total = 0;
$rows = [];
try {
  $r = $db->query('SELECT COALESCE(SUM(delta),0) AS s FROM vx_lp_ledger WHERE uid=?', $uid)->fetchArray();
  $total = (int)($r['s'] ?? 0);
} catch (Throwable $e) {}

try{
  $q = $db->query("SELECT l.delta, l.ctx, l.chain_id, l.created_at, t.title AS plan_title
                     FROM vx_lp_ledger l
                     LEFT JOIN vx_guardian_chains c ON c.id = l.chain_id
                     LEFT JOIN db_tarif t ON t.id = c.tarif
                    WHERE l.uid=?
                    ORDER BY l.created_at DESC
                    LIMIT 80", $uid);
  if ($q) { while($r=$q->fetchArray()){ $rows[]=$r; } }
}catch(Throwable $e){}
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/modern-normalize/2.0.0/modern-normalize.min.css" rel="stylesheet" />
<style>
:root{--vx-bg:#060816; --vx-surface:#0b1024; --vx-border:rgba(255,255,255,.10); --vx-text:#eaf0ff; --vx-muted:#a8b2d1; --vx-accent:#ffdd77;}
body{background:radial-gradient(1200px 700px at 110% -10%, rgba(255,221,119,.12), transparent 45%) #060816; color:var(--vx-text);}
.vx-wrap{max-width:960px;margin:0 auto;padding:14px;}
.vx-card{background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02)) var(--vx-surface);border:1px solid var(--vx-border);border-radius:16px;padding:16px;}
.vx-grid{display:grid;gap:12px;grid-template-columns:1fr;} @media(min-width:820px){.vx-grid{grid-template-columns:1.2fr .8fr}}
.vx-stat{display:grid;grid-template-columns:1fr;gap:12px}
.vx-stat .box{border:1px solid var(--vx-border);border-radius:14px;padding:14px}
.vx-stat .k{color:var(--vx-muted)}
.vx-stat .v{font-weight:900;font-size:1.6rem}
.vx-cta{display:flex;gap:10px;flex-wrap:wrap}
.vx-cta a{border:1px solid var(--vx-border);border-radius:12px;padding:10px 12px;text-decoration:none;color:var(--vx-text);background:rgba(255,255,255,.03)}
.vx-list{display:flex;flex-direction:column;gap:8px;max-height:420px;overflow:auto}
.vx-row{display:flex;justify-content:space-between;gap:10px;border:1px solid var(--vx-border);border-radius:12px;padding:10px 12px;background:rgba(255,255,255,.03)}
.vx-row .l{opacity:.92}
.vx-row .r{font-weight:800;white-space:nowrap}
.small{font-size:.85rem;color:var(--vx-muted)}
</style>
<div class="vx-wrap">
  <div class="vx-grid">
    <div class="vx-card">
      <h2 style="margin:0 0 10px 0;">Legacy Points (LP) &nbsp;<span style="color:var(--vx-accent)">Prestige</span></h2>
      <div class="vx-stat">
        <div class="box"><div class="k">Total LP</div><div class="v"><?= fmt($total) ?></div><div class="small">LP unlocks after your first crossbreed and begins accruing after a full 30-day cycle.</div></div>
      </div>
      <div class="vx-cta" style="margin-top:12px;">
        <a href="/user/dashboard">Dashboard</a>
        <a href="/user/vp-history">VP History</a>
        <a href="/user/codex">Mutant Index</a>
      </div>
    </div>

    <div class="vx-card">
      <h3 style="margin:0 0 8px 0;">Recent LP accrual</h3>
      <div class="vx-list">
        <?php if (!$rows): ?>
          <div class="vx-row"><div class="l">No LP entries yet. Crossbreed a Guardian to unlock LP.</div><div class="r">—</div></div>
        <?php else: foreach($rows as $r):
          $delta = (int)($r['delta'] ?? 0);
          $ctx = (string)($r['ctx'] ?? 'lp');
          $title = (string)($r['plan_title'] ?? 'Mutant Crop');
          $when = (int)($r['created_at'] ?? 0);
        ?>
          <div class="vx-row">
            <div class="l">
              <div><?= h($title) ?></div>
              <div class="small"><?= h($ctx) ?> • <?= $when ? date('M j, Y H:i', $when) : '—' ?></div>
            </div>
            <div class="r">+<?= (int)$delta ?> LP</div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
