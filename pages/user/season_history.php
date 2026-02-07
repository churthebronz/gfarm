<?php
declare(strict_types=1);
if (!defined('FastCore')) { exit('Opss!'); }

require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/seasons.php';

$opt['title'] = 'Season History';

// Session & Auth
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  require_once __DIR__ . '/../../api/require_tg_session.php';
  $uid = isset($GLOBALS['UID']) ? (int)$GLOBALS['UID'] : 0;
}
if ($uid <= 0) { header('Location: /'); exit; }

// Admin gate helper for showing Launch Checklist link
$isAdmin = ($uid === 1);
try {
  $u = $db->query('SELECT role FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
  if ((int)($u['role'] ?? 0) >= 9) $isAdmin = true;
} catch (Throwable $e) { /* ignore */ }


if (!isset($db) || !($db instanceof db)) { exit('DB not available'); }

$season = vx_get_current_season($db);
$seasonId = (int)($season['id'] ?? 0);

$now = time();
$startsAt = (int)($season['starts_at'] ?? $now);
$endsAt   = (int)($season['ends_at'] ?? ($now + 86400));
$pct      = vx_season_progress_pct($season);

$my = ($seasonId > 0) ? vx_user_season_counts($db, $seasonId, $uid) : ['active'=>0,'completed'=>0,'spent'=>0.0];
$weekly = vx_weekly_ref_leaderboard($db, 10);
$myRank = vx_weekly_ref_rank($db, $uid);

// Recent seasons
$seasons = [];
try {
  $seasons = $db->query('SELECT * FROM vx_seasons ORDER BY id DESC LIMIT 10')->fetchAll();
} catch (Throwable $e) { $seasons = []; }

function fmt_dt(int $ts): string { return date('M j, Y', $ts); }
function fmt_left(int $seconds): string {
  if ($seconds <= 0) return '0h';
  $d = intdiv($seconds, 86400); $seconds %= 86400;
  $h = intdiv($seconds, 3600); $seconds %= 3600;
  $m = intdiv($seconds, 60);
  if ($d > 0) return $d.'d '.$h.'h';
  if ($h > 0) return $h.'h '.$m.'m';
  return $m.'m';
}

$left = fmt_left($endsAt - $now);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
:root{
  --vx-bg:#060816;
  --vx-surface:#0b1024;
  --vx-surface2:#0e1430;
  --vx-border:rgba(255,255,255,.08);
  --vx-text:#eaf0ff;
  --vx-muted:#a8b2d1;
  --vx-accent:#00ffe0;
  --vx-accent2:#58a6ff;
  --vx-glow:0 24px 80px rgba(0,255,224,.08), 0 8px 26px rgba(88,166,255,.08);
}

body{
  background:
    radial-gradient(1100px 600px at -10% -10%, rgba(0,255,224,.10), transparent 40%),
    radial-gradient(1100px 600px at 110% 110%, rgba(88,166,255,.10), transparent 40%),
    var(--vx-bg);
  color:var(--vx-text);
}

.shell{
  width:100%;max-width:1120px;margin:18px auto 26px;padding:18px 14px;
  background:linear-gradient(180deg, rgba(11,16,36,.86), rgba(11,16,36,.94));
  border:1px solid var(--vx-border);
  border-radius:22px;
  box-shadow:var(--vx-glow);
  backdrop-filter:blur(16px);
}

.hdr{
  display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;
  margin-bottom:12px;
}
.hdr h1{ margin:0; font-size:1.25rem; font-weight:900; letter-spacing:.02em; }
.hdr .sub{ color:var(--vx-muted); font-size:.92rem; }

.cardx{
  border-radius:18px;
  border:1px solid var(--vx-border);
  background:rgba(15,23,42,0.72);
  box-shadow:0 10px 30px rgba(0,0,0,.28);
}

.progressx{
  height:10px;
  background:rgba(255,255,255,.08);
  border-radius:999px;
  overflow:hidden;
}
.progressx > div{
  height:100%;
  width:0%;
  background:linear-gradient(90deg, rgba(0,255,224,.95), rgba(88,166,255,.95));
}

.mini{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
}
@media(min-width:992px){ .mini{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
.mini .k{ color:var(--vx-muted); font-size:.86rem; }
.mini .v{ font-weight:950; font-size:1.05rem; }

.tbl thead th{
  color:rgba(226,232,240,.9);
  border-bottom:1px solid rgba(255,255,255,.10);
}
.tbl td{
  border-top:1px solid rgba(255,255,255,.07);
  color:rgba(226,232,240,.92);
}

.badge-pill{
  display:inline-flex;align-items:center;gap:8px;
  padding:6px 10px;border-radius:999px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(2,6,23,.35);
  font-weight:800;
}

a.btnvx{
  display:inline-flex;align-items:center;gap:8px;
  padding:10px 12px;border-radius:12px;
  border:1px solid rgba(255,255,255,.12);
  text-decoration:none;color:var(--vx-text);
  background:rgba(2,6,23,.30);
}
a.btnvx:hover{ filter:brightness(1.08); }
a.btnvx.primary{
  background:linear-gradient(135deg, rgba(0,255,224,.22), rgba(88,166,255,.18));
  border-color:rgba(0,255,224,.30);
}
</style>

<div class="shell">
  <div class="hdr">
    <div>
      <h1><i class="fa-solid fa-calendar-week"></i> Seasons</h1>
      <div class="sub">Proof + pace. Seasons keep vault caps fresh while your active vault timers keep running.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btnvx primary" href="/user"><i class="fa-solid fa-vault"></i> Dashboard</a>
      <a class="btnvx" href="/user/plans"><i class="fa-solid fa-layer-group"></i> Seeds</a>
      <?php if (!empty($isAdmin)): ?>
      <a class="btnvx" href="/user/launch"><i class="fa-solid fa-screwdriver-wrench"></i> Launch Checklist</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Current Season -->
  <div class="cardx p-3 p-lg-4 mb-3">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
      <div>
        <div class="badge-pill mb-2"><i class="fa-solid fa-circle-play" style="color:#22c55e"></i> Season <?= (int)($season['season_no'] ?? 0); ?> Live</div>
        <div style="font-weight:950;font-size:1.15rem;">Ends in <?= htmlspecialchars($left, ENT_QUOTES); ?></div>
        <div class="text-muted" style="color:var(--vx-muted)!important">
          <?= fmt_dt($startsAt); ?> → <?= fmt_dt($endsAt); ?> • <?= $pct; ?>% complete
        </div>
      </div>
      <div style="min-width:260px;flex:1;max-width:420px;">
        <div class="progressx" aria-label="Season progress">
          <div style="width:<?= $pct; ?>%"></div>
        </div>
        <div class="d-flex justify-content-between mt-2" style="color:var(--vx-muted);font-size:.9rem;">
          <span>Start</span><span>Finish</span>
        </div>
      </div>
    </div>

    <div class="mini mt-3">
      <div class="cardx p-3">
        <div class="k">My active vaults</div>
        <div class="v"><?= (int)$my['active']; ?></div>
      </div>
      <div class="cardx p-3">
        <div class="k">My completed</div>
        <div class="v"><?= (int)$my['completed']; ?></div>
      </div>
      <div class="cardx p-3">
        <div class="k">My spend (entry)</div>
        <div class="v">$<?= number_format((float)$my['spent'], 2, '.', ''); ?></div>
      </div>
      <div class="cardx p-3">
        <div class="k">Weekly ref rank</div>
        <div class="v"><?= $myRank['rank'] > 0 ? '#'.(int)$myRank['rank'] : '—'; ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <!-- Weekly leaderboard -->
    <div class="col-12 col-lg-5">
      <div class="cardx p-3 p-lg-4 h-100">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div style="font-weight:950"><i class="fa-solid fa-trophy"></i> Weekly Referral Leaders</div>
          <div class="text-muted" style="color:var(--vx-muted)!important;font-size:.9rem;">rolling 7 days</div>
        </div>

        <?php if (!$weekly): ?>
          <div class="text-muted" style="color:var(--vx-muted)!important">No referral earnings logged yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table tbl mb-0">
              <thead>
                <tr>
                  <th style="width:54px">#</th>
                  <th>Leader</th>
                  <th class="text-end">Earned</th>
                  <th class="text-end">Refs</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($weekly as $i => $r): ?>
                  <tr>
                    <td><?= $i + 1; ?></td>
                    <td><?= htmlspecialchars((string)$r['name'], ENT_QUOTES); ?></td>
                    <td class="text-end">$<?= number_format((float)$r['usd'], 2, '.', ''); ?></td>
                    <td class="text-end"><?= (int)$r['refs']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Season history -->
    <div class="col-12 col-lg-7">
      <div class="cardx p-3 p-lg-4 h-100">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div style="font-weight:950"><i class="fa-solid fa-clock-rotate-left"></i> Season History</div>
          <div class="text-muted" style="color:var(--vx-muted)!important;font-size:.9rem;">last 10</div>
        </div>

        <?php if (!$seasons): ?>
          <div class="text-muted" style="color:var(--vx-muted)!important">No seasons created yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table tbl mb-0">
              <thead>
                <tr>
                  <th>Season</th>
                  <th>Window</th>
                  <th class="text-end">Activations</th>
                  <th class="text-end">Volume</th>
                  <th class="text-end">Top ref</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($seasons as $srow):
                $sid = (int)($srow['id'] ?? 0);
                $sno = (int)($srow['season_no'] ?? 0);
                $ss  = (int)($srow['starts_at'] ?? 0);
                $se  = (int)($srow['ends_at'] ?? 0);
                $stt = ($sid > 0) ? vx_season_stats_row($db, $sid, $ss, $se) : ['total'=>0,'volume'=>0,'top_ref_uid'=>0,'top_ref_usd'=>0];
                $topName = '—';
                if (!empty($stt['top_ref_uid'])) {
                  $tu = $db->query('SELECT tg_username, tg_name, login FROM db_users WHERE id = ? LIMIT 1', (int)$stt['top_ref_uid'])->fetchArray();
                  if (!empty($tu['tg_username'])) $topName = '@'.ltrim((string)$tu['tg_username'], '@');
                  elseif (!empty($tu['tg_name'])) $topName = (string)$tu['tg_name'];
                  else $topName = (string)($tu['login'] ?? 'User');
                }
              ?>
                <tr>
                  <td>
                    <span class="badge-pill">
                      <?php if ($sid === $seasonId): ?>
                        <i class="fa-solid fa-circle-play" style="color:#22c55e"></i>
                      <?php else: ?>
                        <i class="fa-regular fa-circle" style="color:rgba(226,232,240,.55)"></i>
                      <?php endif; ?>
                      S<?= $sno; ?>
                    </span>
                  </td>
                  <td style="color:var(--vx-muted)"><?= fmt_dt($ss); ?> → <?= fmt_dt($se); ?></td>
                  <td class="text-end"><?= (int)$stt['total']; ?></td>
                  <td class="text-end">$<?= number_format((float)$stt['volume'], 2, '.', ''); ?></td>
                  <td class="text-end">
                    <?= htmlspecialchars($topName, ENT_QUOTES); ?>
                    <?php if (!empty($stt['top_ref_usd'])): ?>
                      <span style="color:var(--vx-muted);font-size:.9rem;">($<?= number_format((float)$stt['top_ref_usd'], 2, '.', ''); ?>)</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>