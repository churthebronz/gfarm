<?php
declare(strict_types=1);
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/vx_season_points.php';
if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $opt;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

$opt['title'] = 'Season Leaderboard';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  require_once __DIR__ . '/../../api/require_tg_session.php';
  $uid = (int)($GLOBALS['UID'] ?? 0);
}
if ($uid <= 0) { header('Location: /'); exit; }

$season = [];
try { $season = vx_get_current_season($db); } catch (Throwable $e) { $season = []; }
$sid = (int)($season['id'] ?? 0);
$sno = (int)($season['season_no'] ?? 1);
$starts = (int)($season['starts_at'] ?? 0);
$ends = (int)($season['ends_at'] ?? 0);

$lpWeight = 5.0;
try { $lpWeight = (float)vx_app_setting('lp_leaderboard_weight', 5.0); } catch (Throwable $e) {}
if ($lpWeight < 0) $lpWeight = 0;
if ($lpWeight > 50) $lpWeight = 50;

$me = ['rank'=>null,'vp'=>0,'lp'=>0,'score'=>0];
$top = [];
try {
  if ($sid > 0) {
    $me = vx_season_points_rank($db, $sid, $uid, $lpWeight);
    $top = vx_season_points_top($db, $sid, 50, $lpWeight);
  }
} catch (Throwable $e) { $top = []; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_ts(int $ts): string { return $ts>0 ? date('M j, Y', $ts) : '—'; }

?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root{
  --vx-bg:#060816; --vx-surface:#0b1024; --vx-surface-2:#0e1430; --vx-border:#1f2746;
  --vx-text:#eaf0ff; --vx-muted:#a8b2d1; --vx-accent:#00ffe0; --vx-accent-2:#58a6ff;
  --vx-glow:0 24px 80px rgba(0,255,224,.08), 0 8px 26px rgba(88,166,255,.08);
}
.vx-shell{max-width:1120px;margin:18px auto 30px;padding:0 14px;}
.vx-card{background:linear-gradient(180deg, rgba(11,16,36,.86), rgba(11,16,36,.94));border:1px solid rgba(255,255,255,.08);border-radius:22px;box-shadow:var(--vx-glow);backdrop-filter:blur(14px);}
.vx-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:18px 18px 12px;}
.vx-title{margin:0;font-weight:1000;letter-spacing:.02em;font-size:1.25rem;color:var(--vx-text);}
.vx-sub{color:rgba(226,232,240,.84);margin-top:6px;line-height:1.4;}
.vx-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid rgba(148,163,184,.18);background:rgba(2,6,23,.35);color:rgba(255,255,255,.92);font-weight:900;font-size:.92rem;}
.vx-pill b{color:var(--vx-accent);}

.kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:0 18px 18px;}
@media(max-width:900px){.kpis{grid-template-columns:1fr;}}
.kpi{border-radius:18px;border:1px solid rgba(148,163,184,.16);background:rgba(2,6,23,.34);padding:14px;}
.kpi .k{color:rgba(226,232,240,.72);font-size:.86rem;}
.kpi .v{font-weight:1000;font-size:1.15rem;margin-top:6px;}
.kpi small{color:rgba(148,163,184,.9)}

.tableWrap{padding:0 18px 18px;}
.table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border-radius:18px;border:1px solid rgba(148,163,184,.16);}
.table th,.table td{padding:12px 12px;border-bottom:1px solid rgba(148,163,184,.12);text-align:left;}
.table th{background:rgba(15,23,42,.58);color:rgba(226,232,240,.86);font-size:.86rem;font-weight:1000;}
.table td{color:rgba(234,240,255,.92);font-weight:800;}
.table tr:last-child td{border-bottom:none;}
.name{display:flex;gap:10px;align-items:center;}
.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.20);font-weight:900;font-size:.82rem;color:rgba(255,255,255,.92)}
.muted{color:rgba(148,163,184,.95);font-weight:800}
</style>

<div class="vx-shell">
  <div class="vx-card">
    <div class="vx-head">
      <div>
        <h1 class="vx-title"><i class="fa-solid fa-trophy" style="color:rgba(251,191,36,.95);margin-right:8px"></i>Season <?= (int)$sno; ?> Leaderboard</h1>
        <div class="vx-sub">Ranks are computed as <b>VP + (LP × <?= h((string)$lpWeight); ?>)</b>. LP is prestige-weighted.</div>
        <div class="vx-sub" style="margin-top:8px">Season window: <b><?= h(fmt_ts($starts)); ?></b> → <b><?= h(fmt_ts($ends)); ?></b></div>
      </div>
      <div class="vx-pill">Your rank: <b><?= $me['rank'] ? '#'.(int)$me['rank'] : '—'; ?></b></div>
    </div>

    <div class="kpis">
      <div class="kpi"><div class="k">Your VP this season</div><div class="v"><?= number_format((int)$me['vp']); ?></div><small>Season totals</small></div>
      <div class="kpi"><div class="k">Your LP this season</div><div class="v"><?= number_format((int)$me['lp']); ?></div><small>Unlocked at crossbreed ≥ 1</small></div>
      <div class="kpi"><div class="k">Your season score</div><div class="v"><?= number_format((int)round((float)$me['score'])); ?></div><small>VP + LP×weight</small></div>
    </div>

    <div class="tableWrap">
      <?php if (empty($top)): ?>
        <div class="muted" style="padding:14px 2px">No leaderboard data yet for this season. Activate a Mutant Crop to start earning VP daily.</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th style="width:70px">#</th>
              <th>User</th>
              <th style="width:160px">VP</th>
              <th style="width:160px">LP</th>
              <th style="width:180px">Score</th>
            </tr>
          </thead>
          <tbody>
            <?php $i=0; foreach ($top as $r): $i++; 
              $name = (string)($r['tg_name'] ?? $r['tg_username'] ?? $r['login'] ?? ('User '.$r['uid']));
              $vp = (int)($r['vp_total'] ?? 0);
              $lp = (int)($r['lp_total'] ?? 0);
              $score = (float)($r['score'] ?? (float)$vp);
              $isMe = ((int)($r['uid'] ?? 0) === $uid);
            ?>
              <tr>
                <td><span class="badge" style="<?= $isMe ? 'border-color:rgba(0,255,224,.45)' : '' ?>"><?= $i; ?></span></td>
                <td>
                  <div class="name">
                    <i class="fa-solid fa-user" style="color:rgba(148,163,184,.95)"></i>
                    <div>
                      <div><?= h($name); ?><?= $isMe ? ' <span class="badge" style="margin-left:8px">You</span>' : '' ?></div>
                      <div class="muted" style="font-size:.82rem;font-weight:900">UID <?= (int)($r['uid'] ?? 0); ?></div>
                    </div>
                  </div>
                </td>
                <td><?= number_format($vp); ?></td>
                <td><?= number_format($lp); ?></td>
                <td><?= number_format((int)round($score)); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
