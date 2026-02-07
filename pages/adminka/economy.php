<?php
// pages/adminka/economy.php
// GreenFarm Admin — Economy monitor (no cron, season-first)

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $adm;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['admin'])) {
  echo '<div class="alert alert-danger text-center">Admin access required.</div>';
  return;
}

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/vx_season_points.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/vx_rarity.php';

$opt['title'] = 'Admin • Economy';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$now = time();
$season = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
$sid = (int)($season['id'] ?? 0);
$seasonNo = (int)($season['season_no'] ?? 1);
$seasonStarts = (int)($season['starts_at'] ?? $season['start_ts'] ?? 0);
$seasonEnds = (int)($season['ends_at'] ?? $season['end_ts'] ?? 0);

$lpWeight = (float)vx_app_setting('lp_leaderboard_weight', 5.0);
if ($lpWeight < 0) $lpWeight = 0;

// Totals for current season
$totVp = 0; $totLp = 0; $usersWithPoints = 0;
try {
  vx_season_points_schema_ensure($db);
  $r = $db->query('SELECT COUNT(*) AS c, COALESCE(SUM(vp_total),0) AS svp, COALESCE(SUM(lp_total),0) AS slp FROM vx_season_points WHERE season_id=?', $sid)->fetchArray();
  $usersWithPoints = (int)($r['c'] ?? 0);
  $totVp = (int)($r['svp'] ?? 0);
  $totLp = (int)($r['slp'] ?? 0);
} catch (Throwable $e) {}

$top = [];
try { $top = vx_season_points_top($db, $sid, 25, $lpWeight); } catch (Throwable $e) { $top = []; }

// Last 24h deltas (VP + LP) by uid
$since24 = $now - 86400;
$vp24 = []; $lp24 = [];
try {
  if (function_exists('vx_table_exists') && vx_table_exists($db,'db_points_ledger')) {
    $q = $db->query("SELECT uid, COALESCE(SUM(delta),0) AS s FROM db_points_ledger WHERE created_at >= ? GROUP BY uid", $since24);
    while ($q && ($r=$q->fetchArray())) { $vp24[(int)$r['uid']] = (int)($r['s'] ?? 0); }
  }
} catch (Throwable $e) {}
try {
  if (function_exists('vx_table_exists') && vx_table_exists($db,'vx_lp_ledger')) {
    $q = $db->query("SELECT uid, COALESCE(SUM(delta),0) AS s FROM vx_lp_ledger WHERE created_at >= ? GROUP BY uid", $since24);
    while ($q && ($r=$q->fetchArray())) { $lp24[(int)$r['uid']] = (int)($r['s'] ?? 0); }
  }
} catch (Throwable $e) {}

// Expected daily accrual from active guardians
$expected = []; // uid => ['vp'=>float,'lp'=>float,'chains'=>int]
try {
  vx_guardians_schema_ensure($db);
  $s = vx_guardian_settings();
  $rm = $s['rarity_mult'] ?? [];
  $vpRe = $s['vp_crossbreed_mult'] ?? [1.0];
  $lpRe = $s['lp_crossbreed_mult'] ?? [0.0];

  $q = $db->query("SELECT uid, tarif, rarity, crossbreed_level FROM vx_guardian_chains WHERE status='active'");
  while ($q && ($c=$q->fetchArray())) {
    $uid = (int)($c['uid'] ?? 0);
    if ($uid<=0) continue;
    $tarif = (int)($c['tarif'] ?? 0);
    $rar = (string)($c['rarity'] ?? 'common');
    $lvl = (int)($c['crossbreed_level'] ?? 0);

    $def = vx_guardian_definition($db, $tarif);
    $vpBase = (int)($def['vp_per_day'] ?? 0);
    $lpBase = (int)($def['lp_per_day'] ?? 0);

    $rarMult = isset($rm[$rar]) ? (float)$rm[$rar] : 1.0;
    $vpMult = (float)($vpRe[$lvl] ?? end($vpRe));
    $lpMult = (float)($lpRe[$lvl] ?? end($lpRe));

    $evp = (float)$vpBase * $rarMult * $vpMult;
    $elp = ($lvl >= 1) ? ((float)$lpBase * $rarMult * $lpMult) : 0.0;

    if (!isset($expected[$uid])) $expected[$uid] = ['vp'=>0.0,'lp'=>0.0,'chains'=>0];
    $expected[$uid]['vp'] += $evp;
    $expected[$uid]['lp'] += $elp;
    $expected[$uid]['chains'] += 1;
  }
} catch (Throwable $e) {}

// Anomaly list: actual > expected * 1.8 (guardrails), show top 25
$anoms = [];
foreach ($expected as $uid=>$ex) {
  $aVp = (int)($vp24[$uid] ?? 0);
  $aLp = (int)($lp24[$uid] ?? 0);
  $eVp = (float)($ex['vp'] ?? 0);
  $eLp = (float)($ex['lp'] ?? 0);

  $vpRatio = ($eVp > 0.0) ? ($aVp / $eVp) : ($aVp > 0 ? 999.0 : 0.0);
  $lpRatio = ($eLp > 0.0) ? ($aLp / $eLp) : ($aLp > 0 ? 999.0 : 0.0);

  if ($vpRatio >= 1.8 || $lpRatio >= 1.8) {
    $anoms[] = ['uid'=>$uid,'chains'=>(int)$ex['chains'],'vp24'=>$aVp,'lp24'=>$aLp,'evp'=>$eVp,'elp'=>$eLp,'vpRatio'=>$vpRatio,'lpRatio'=>$lpRatio];
  }
}
usort($anoms, function($a,$b){ return ($b['vpRatio']<=>$a['vpRatio']); });
$anoms = array_slice($anoms, 0, 25);

// Resolve user names
$names = [];
try {
  if (!empty($anoms) || !empty($top)) {
    $uids = [];
    foreach ($anoms as $x) $uids[] = (int)$x['uid'];
    foreach ($top as $r) $uids[] = (int)($r['uid'] ?? 0);
    $uids = array_values(array_unique(array_filter($uids)));
    if ($uids) {
      $in = implode(',', array_map('intval', $uids));
      $q = $db->query("SELECT id, login, tg_username, tg_name FROM db_users WHERE id IN ($in)");
      while ($q && ($r=$q->fetchArray())) {
        $id = (int)($r['id'] ?? 0);
        $names[$id] = (string)($r['tg_username'] ?? $r['tg_name'] ?? $r['login'] ?? ('UID '.$id));
      }
    }
  }
} catch (Throwable $e) {}

include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/menu.php';
?>

<div class="container-fluid" style="max-width:1200px">
  <div class="d-flex align-items-end justify-content-between" style="gap:12px;flex-wrap:wrap">
    <div>
      <h3 style="margin:0">Economy</h3>
      <div style="color:rgba(148,163,184,.95)">Live health of VP/LP earning. No cron: everything is lazy-accrued on user traffic + heartbeat endpoints.</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn btn-outline-info" href="/<?php echo h($adm); ?>/guardian-tuning"><i class="fa fa-sliders"></i> Mutant tuning</a>
      <a class="btn btn-outline-warning" href="/<?php echo h($adm); ?>/seasons"><i class="fa fa-calendar"></i> Seasons</a>
    </div>
  </div>

  <div class="row" style="margin-top:14px">
    <div class="col-lg-4">
      <div class="card" style="background:rgba(2,6,23,.55); border:1px solid rgba(148,163,184,.15)">
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
            <div>
              <div style="font-weight:900">Season <?php echo (int)$seasonNo; ?></div>
              <div style="color:rgba(148,163,184,.95); font-size:.9rem"><?php echo $seasonStarts?date('M j, Y',$seasonStarts):'-'; ?> → <?php echo $seasonEnds?date('M j, Y',$seasonEnds):'-'; ?></div>
            </div>
            <div class="badge" style="background:rgba(59,130,246,.18); border:1px solid rgba(59,130,246,.35); color:#eaf0ff; padding:10px 12px; border-radius:14px">LP weight: <?php echo h((string)$lpWeight); ?>×</div>
          </div>
          <hr style="border-color:rgba(148,163,184,.15)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px;border-radius:14px;border:1px solid rgba(148,163,184,.15);background:rgba(15,23,42,.45)">
              <div style="color:rgba(148,163,184,.95);font-size:.85rem">Season VP total</div>
              <div style="font-weight:1000;font-size:1.15rem"><?php echo number_format($totVp); ?></div>
            </div>
            <div style="padding:12px;border-radius:14px;border:1px solid rgba(148,163,184,.15);background:rgba(15,23,42,.45)">
              <div style="color:rgba(148,163,184,.95);font-size:.85rem">Season LP total</div>
              <div style="font-weight:1000;font-size:1.15rem"><?php echo number_format($totLp); ?></div>
            </div>
            <div style="grid-column:1 / -1;padding:12px;border-radius:14px;border:1px solid rgba(148,163,184,.15);background:rgba(15,23,42,.45)">
              <div style="color:rgba(148,163,184,.95);font-size:.85rem">Users with points this season</div>
              <div style="font-weight:1000;font-size:1.1rem"><?php echo number_format($usersWithPoints); ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card" style="background:rgba(2,6,23,.55); border:1px solid rgba(148,163,184,.15)">
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
            <div style="font-weight:900">Top earners (Season)</div>
            <div style="color:rgba(148,163,184,.95);font-size:.9rem">Rank by VP + (LP × <?php echo h((string)$lpWeight); ?>)</div>
          </div>
          <div style="overflow:auto;margin-top:10px;border-radius:14px;border:1px solid rgba(148,163,184,.15)">
            <table class="table table-dark table-sm" style="margin:0;min-width:720px">
              <thead>
                <tr>
                  <th style="width:70px">#</th>
                  <th>User</th>
                  <th style="width:140px">VP</th>
                  <th style="width:140px">LP</th>
                  <th style="width:160px">Score</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($top)): ?>
                  <tr><td colspan="5" style="color:rgba(148,163,184,.95)">No season points yet.</td></tr>
                <?php else: ?>
                  <?php $i=0; foreach ($top as $r): $i++; $uid=(int)($r['uid'] ?? 0); $vp=(int)($r['vp_total'] ?? 0); $lp=(int)($r['lp_total'] ?? 0); $score=(float)($r['score'] ?? 0); ?>
                    <tr>
                      <td><?php echo $i; ?></td>
                      <td><?php echo h($names[$uid] ?? ('UID '.$uid)); ?></td>
                      <td><?php echo number_format($vp); ?></td>
                      <td><?php echo number_format($lp); ?></td>
                      <td><?php echo number_format((int)round($score)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row" style="margin-top:14px">
    <div class="col-lg-12">
      <div class="card" style="background:rgba(2,6,23,.55); border:1px solid rgba(148,163,184,.15)">
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
            <div style="font-weight:900">24h anomaly scan (earned vs expected)</div>
            <div style="color:rgba(148,163,184,.95);font-size:.9rem">Flags if GP or LP in last 24h ≥ 1.8× the expected daily rate from active mutants.</div>
          </div>

          <div style="overflow:auto;margin-top:10px;border-radius:14px;border:1px solid rgba(148,163,184,.15)">
            <table class="table table-dark table-sm" style="margin:0;min-width:980px">
              <thead>
                <tr>
                  <th style="width:80px">UID</th>
                  <th>User</th>
                  <th style="width:90px">Chains</th>
                  <th style="width:130px">VP (24h)</th>
                  <th style="width:150px">Expected VP/day</th>
                  <th style="width:100px">Ratio</th>
                  <th style="width:130px">LP (24h)</th>
                  <th style="width:150px">Expected LP/day</th>
                  <th style="width:100px">Ratio</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($anoms)): ?>
                  <tr><td colspan="9" style="color:rgba(148,163,184,.95)">No anomalies detected.</td></tr>
                <?php else: ?>
                  <?php foreach ($anoms as $x): $uid=(int)$x['uid']; ?>
                    <tr>
                      <td><?php echo $uid; ?></td>
                      <td><?php echo h($names[$uid] ?? ('UID '.$uid)); ?></td>
                      <td><?php echo (int)$x['chains']; ?></td>
                      <td><?php echo number_format((int)$x['vp24']); ?></td>
                      <td><?php echo number_format((int)round((float)$x['evp'])); ?></td>
                      <td><?php echo h(number_format((float)$x['vpRatio'], 2)); ?>×</td>
                      <td><?php echo number_format((int)$x['lp24']); ?></td>
                      <td><?php echo number_format((int)round((float)$x['elp'])); ?></td>
                      <td><?php echo h(number_format((float)$x['lpRatio'], 2)); ?>×</td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div style="color:rgba(148,163,184,.95); font-size:.9rem; margin-top:10px">
            Tip: if you see repeated anomalies, check mutant definitions, rarity multipliers, and crossbreed multipliers in <b>Mutant Tuning</b>.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/foot.php'; ?>
