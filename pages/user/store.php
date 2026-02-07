<link rel="stylesheet" href="/assets/css/vx_rarity.css"><?php if(!defined('FastCore')){exit('Opss!');}

global $db, $uid;

$opt['title'] = 'Farm History';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function safeInt($v){ return (int)$v; }
function fmtMoney($v){ return '$'.number_format((float)$v, 2, '.', ''); }
function fmtPct($v){ return number_format((float)$v, 2).'%'; }
function fmtDate($ts){ return date('d M Y - H:i', (int)$ts); }

/** Target-rate constants (display only) */
$VX_USD  = 0.10; // $/VX target
$VX_CONV = 100;  // 100 pts = 1 VX

/** Pull vaults (db_store) + join plan meta (db_tarif)
 * NOTE: db_store.title historically contained farm-themed names (e.g. Fruit Farm).
 * We show the real vault/plan title from db_tarif.title for consistency.
 */
$vaults = $db->query(
  "SELECT s.id, s.tarif, s.title AS store_title, s.speed, s.hashpower, s.`add`, s.`end`, s.status,
          t.title AS plan_title, t.price, t.period, t.img,
          p.points_award
   FROM db_store s
   LEFT JOIN db_tarif t ON t.id = s.tarif
   LEFT JOIN db_tarif_points p ON p.tarif_id = s.tarif
   WHERE s.uid = ?
   ORDER BY s.`add` DESC",
  [$uid]
)->fetchAll();

/** Seed summary */
$now = time();
$totalVaults   = is_array($vaults) ? count($vaults) : 0;
$activeVaults  = 0;
$finishedVaults= 0;
$pendingVaults = 0;
$totalEntry    = 0.0;
$estDailyAll   = 0.0;

if ($vaults) {
  foreach ($vaults as $v) {
    $st = (int)($v['status'] ?? 0);
    if ($st === 1) $activeVaults++;
    elseif ($st === 2) $finishedVaults++;
    else $pendingVaults++;

    $entry = (float)($v['price'] ?? 0);
    if ($entry <= 0) $entry = (float)($v['hashpower'] ?? 0);
    $sp = (float)($v['speed'] ?? 0);
    $totalEntry += $entry;
    $estDailyAll += ($entry * $sp / 100.0);
  }
}

/** Pull points ledger */
$pointsRows = [];
$ledgerMode = 'ledger';

/** Try db_points_ledger first */
try {
  $q = $db->query(
    "SELECT id, uid, delta, ctx, ref_uid, tarif_id, usd_value, created_at
     FROM db_points_ledger
     WHERE uid = ?
     ORDER BY id DESC
     LIMIT 200",
    [$uid]
  );
  while($r = $q->fetchArray()){ $pointsRows[] = $r; }
} catch(Throwable $e){
  $pointsRows = [];
}

/** Fallback to db_points_log if ledger empty or not available */
if (!$pointsRows) {
  try {
    $ledgerMode = 'log';
    $q = $db->query(
      "SELECT id, uid, delta, type, ref_uid, tarif_id, note, created_at
       FROM db_points_log
       WHERE uid = ?
       ORDER BY id DESC
       LIMIT 200",
      [$uid]
    );
    while($r = $q->fetchArray()){ $pointsRows[] = $r; }
  } catch(Throwable $e){
    $pointsRows = [];
  }
}

/** Points summary from db_users (if fields exist) */
$pointsSpendable = null;
$pointsTotal     = null;
try {
  $u = $db->query("SELECT points_spendable, points_total FROM db_users WHERE id = ? LIMIT 1", [$uid])->fetchArray();
  if ($u) {
    $pointsSpendable = (int)($u['points_spendable'] ?? 0);
    $pointsTotal     = (int)($u['points_total'] ?? 0);
  }
} catch(Throwable $e){}

/** Target VX from spendable (prefer spendable; fallback to computed from ledger) */
$estPtsForVX = ($pointsSpendable !== null) ? $pointsSpendable : 0;
if ($estPtsForVX <= 0 && $pointsRows) {
  $tmp = 0;
  foreach ($pointsRows as $pr) { $tmp += (int)($pr['delta'] ?? 0); }
  $estPtsForVX = max(0, $tmp);
}
$estVX    = $estPtsForVX / $VX_CONV;
$estVXVal = $estVX * $VX_USD;

/** Context mapping */
function ctxLabel($ctx){
  $ctx = (string)$ctx;
  $map = [
    'buy_vault' => 'Seed purchase',
    'ref_vault' => 'Referral reward',
    'admin'     => 'Admin adjustment',
  ];
  return $map[$ctx] ?? ($ctx ?: '—');
}
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<div class="vx-history-page">
  <style>
    /* IMPORTANT: Scoped styles only — prevents breaking your global header/nav */
    .vx-history-page{
      --vx-bg:#060816;
      --vx-surface:#0b1024;
      --vx-surface-2:#0e1430;
      --vx-border:rgba(255,255,255,.10);
      --vx-text:#eaf0ff;
      --vx-muted:#a8b2d1;
      --vx-accent:#00ffe0;
      --vx-accent-2:#58a6ff;
      --vx-warn:#fbbf24;
      --vx-hot:#f97316;
      --vx-ok:#22c55e;
      --vx-err:#ef4444;
      --vx-radius:18px;
      --vx-shadow:0 18px 48px rgba(0,0,0,.45);
      font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      color:var(--vx-text);
    }
    .vx-history-page .vx-shell{ max-width:1120px; margin:14px auto 26px; padding:0 12px; }
    .vx-history-page .vx-hero{
      border-radius:22px; overflow:hidden;
      border:1px solid var(--vx-border);
      background:
        radial-gradient(1000px 520px at 0% 0%, rgba(0,255,224,.14), transparent 55%),
        radial-gradient(900px 520px at 100% 20%, rgba(88,166,255,.12), transparent 55%),
        linear-gradient(180deg, rgba(11,16,36,.92), rgba(9,13,28,.98));
      box-shadow:var(--vx-shadow);
      padding:14px;
    }
    .vx-history-page .vx-hero-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .vx-history-page .vx-title{
      margin:0; font-weight:1000; letter-spacing:.01em; font-size:1.28rem;
      display:flex; align-items:center; gap:10px;
    }
    .vx-history-page .vx-sub{ margin:6px 0 0; color:rgba(226,232,240,.86); font-size:.95rem; line-height:1.45; max-width:760px; }
    .vx-history-page .vx-back{
      text-decoration:none;
      display:inline-flex; align-items:center; gap:8px;
      padding:9px 12px; border-radius:999px;
      border:1px solid rgba(148,163,184,.22);
      background:rgba(2,6,23,.45);
      color:#e5e7eb; font-weight:900;
    }
    .vx-history-page .vx-back:hover{ border-color:rgba(0,255,224,.45); box-shadow:0 10px 26px rgba(0,255,224,.10); }

    .vx-history-page .vx-sum{
      margin-top:12px;
      display:grid; gap:10px;
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
    @media(min-width:840px){ .vx-history-page .vx-sum{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
    .vx-history-page .sum-card{
      border-radius:16px;
      border:1px solid rgba(255,255,255,.10);
      background:linear-gradient(180deg, rgba(15,23,42,.75), rgba(15,23,42,.55));
      padding:10px 12px;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.04);
      min-width:0;
    }
    .vx-history-page .sum-k{ color:rgba(226,232,240,.74); font-size:.86rem; display:flex; align-items:center; gap:8px; }
    .vx-history-page .sum-k i{ color:var(--vx-accent-2); }
    .vx-history-page .sum-v{ margin-top:6px; font-weight:1000; font-size:1.10rem; }
    .vx-history-page .sum-s{ margin-top:2px; font-size:.82rem; color:rgba(226,232,240,.70); }

    .vx-history-page .vx-tabs{
      margin-top:12px;
      border-radius:18px;
      border:1px solid rgba(255,255,255,0.08);
      background:rgba(5,7,18,0.72);
      box-shadow:0 14px 40px rgba(0,0,0,.35);
      overflow:hidden;
    }
    .vx-history-page .vx-tabbar{
      display:flex; gap:8px; flex-wrap:wrap;
      padding:10px;
      background:linear-gradient(180deg, rgba(10,16,32,0.92), rgba(10,16,32,0.82));
      border-bottom:1px solid rgba(148,163,184,0.16);
    }
    .vx-history-page .vx-tabbtn{
      appearance:none; border:1px solid rgba(148,163,184,0.16);
      background:rgba(15,23,42,0.62);
      color:#fff; font-weight:1000;
      padding:9px 12px; border-radius:12px;
      display:inline-flex; align-items:center; gap:8px;
      cursor:pointer;
      transition:transform .12s, border-color .18s, box-shadow .18s;
    }
    .vx-history-page .vx-tabbtn:hover{ transform:translateY(-1px); border-color:rgba(251,191,36,.55); box-shadow:0 8px 26px rgba(251,191,36,.10); }
    .vx-history-page .vx-tabbtn.active{
      border-color:rgba(0,255,224,.45);
      box-shadow:0 14px 34px rgba(0,255,224,.10);
      background:linear-gradient(180deg, rgba(15,23,42,.86), rgba(15,23,42,.66));
    }
    .vx-history-page .vx-pane{ display:none; padding:12px; }
    .vx-history-page .vx-pane.active{ display:block; }

    .vx-history-page .vx-tools{
      display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between;
      margin:0 0 10px 0;
    }
    .vx-history-page .vx-filters{ display:flex; gap:6px; flex-wrap:wrap; }
    .vx-history-page .chip{
      border:1px solid rgba(255,255,255,.14);
      background:rgba(15,23,42,.70);
      color:#fff; font-weight:900;
      padding:7px 12px; border-radius:999px;
      cursor:pointer; font-size:.86rem;
    }
    .vx-history-page .chip.active{ border-color:rgba(0,255,224,.45); box-shadow:0 10px 24px rgba(0,255,224,.10); }
    .vx-history-page .vx-search{ min-width:220px; flex:1; max-width:380px; }
    .vx-history-page .vx-search input{
      width:100%; border-radius:12px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(15,23,42,.82);
      color:#eaf0ff;
      padding:9px 12px;
      outline:none;
    }
    .vx-history-page .vx-search input:focus{ border-color:rgba(0,255,224,.45); box-shadow:0 0 0 3px rgba(0,255,224,.10); }

    /* Seed cards */
    .vx-history-page .vx-grid{ display:grid; grid-template-columns:1fr; gap:10px; }
    @media(min-width:760px){ .vx-history-page .vx-grid{ grid-template-columns:1fr 1fr; } }

    .vx-history-page .vx-card{
      border-radius:18px; overflow:hidden;
      border:1px solid rgba(255,255,255,.10);
      background:linear-gradient(180deg, rgba(11,16,36,.92), rgba(9,13,28,.98));
      box-shadow:0 14px 40px rgba(0,0,0,.35);
      position:relative;
    }
    .vx-history-page .vx-card-hd{
      display:flex; align-items:center; gap:10px;
      padding:10px 12px;
      border-bottom:1px solid rgba(255,255,255,.08);
    }
    .vx-history-page .vx-thumb{
      width:52px; height:52px;
      border-radius:12px;
      background:#020617;
      overflow:hidden;
      display:flex; align-items:center; justify-content:center;
      border:1px solid rgba(255,255,255,.08);
      flex:0 0 auto;
    }
    .vx-history-page .vx-thumb img{ width:100%; height:100%; object-fit:cover; }
    .vx-history-page .vx-titlewrap{ flex:1; min-width:0; }
    .vx-history-page .vx-name{
      margin:0; font-weight:1000; font-size:1.02rem;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .vx-history-page .vx-mini{ margin:2px 0 0; color:rgba(226,232,240,.72); font-size:.86rem; }
    .vx-history-page .badge{
      display:inline-flex; align-items:center; gap:6px;
      padding:6px 10px; border-radius:999px;
      font-weight:1000; font-size:.82rem;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(2,6,23,.55);
      flex:0 0 auto;
    }
    .vx-history-page .badge.ok{ color:#86efac; border-color:rgba(34,197,94,.55); background:rgba(34,197,94,.10); }
    .vx-history-page .badge.wait{ color:#fde68a; border-color:rgba(251,191,36,.55); background:rgba(251,191,36,.10); }
    .vx-history-page .badge.info{ color:#bae6fd; border-color:rgba(56,189,248,.55); background:rgba(56,189,248,.10); }
    .vx-history-page .badge.err{ color:#fecaca; border-color:rgba(239,68,68,.55); background:rgba(239,68,68,.10); }

    .vx-history-page .vx-card-bd{ padding:10px 12px 12px; }
    .vx-history-page .vx-meta{
      display:grid; gap:8px;
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
    @media(min-width:560px){ .vx-history-page .vx-meta{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
    .vx-history-page .m{
      border-radius:12px;
      border:1px solid rgba(255,255,255,.12);
      background:rgba(15,23,42,.65);
      padding:8px 10px;
      min-width:0;
    }
    .vx-history-page .m .k{ font-size:.82rem; color:rgba(226,232,240,.70); }
    .vx-history-page .m .v{ font-weight:1000; font-size:.98rem; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .vx-history-page .m .s{ margin-top:3px; font-size:.82rem; color:rgba(226,232,240,.62); }

    .vx-history-page .prog{
      margin-top:10px;
      height:10px;
      border-radius:999px;
      background:rgba(148,163,184,.16);
      overflow:hidden;
    }
    .vx-history-page .bar{
      height:100%;
      background:linear-gradient(90deg, var(--vx-accent), var(--vx-accent-2));
      width:0%;
    }
    .vx-history-page .times{
      display:flex; justify-content:space-between; gap:8px;
      margin-top:6px;
      font-size:.80rem;
      color:rgba(226,232,240,.66);
    }

    .vx-history-page .foot{
      margin-top:10px;
      display:flex; gap:10px; flex-wrap:wrap;
      align-items:center; justify-content:space-between;
    }
    .vx-history-page .target{
      display:flex; align-items:flex-start; gap:8px;
      color:rgba(226,232,240,.86);
      font-size:.90rem;
      min-width:0;
    }
    .vx-history-page .target b{ color:#fff; }
    .vx-history-page .hint{
      color:rgba(226,232,240,.60);
      font-size:.84rem;
    }
    .vx-history-page .linkbtn{
      text-decoration:none;
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px; border-radius:999px;
      border:1px solid rgba(148,163,184,.20);
      background:rgba(2,6,23,.45);
      color:#e5e7eb; font-weight:1000; font-size:.88rem;
    }
    .vx-history-page .linkbtn:hover{ border-color:rgba(0,255,224,.40); box-shadow:0 10px 26px rgba(0,255,224,.08); }

    /* Points table */
    .vx-history-page .tablewrap{
      border-radius:16px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(15,23,42,.55);
      overflow:auto;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.03);
    }
    .vx-history-page table{ width:100%; border-collapse:collapse; min-width:720px; }
    .vx-history-page th, .vx-history-page td{
      padding:10px 12px;
      border-bottom:1px solid rgba(148,163,184,.14);
      vertical-align:middle;
      font-size:.92rem;
      color:#e5e7eb;
    }
    .vx-history-page th{
      position:sticky; top:0;
      background:rgba(5,7,18,.88);
      z-index:2;
      font-size:.78rem;
      text-transform:uppercase;
      letter-spacing:.05em;
      color:rgba(226,232,240,.86);
    }
    .vx-history-page .delta.pos{ color:#86efac; font-weight:1000; }
    .vx-history-page .delta.neg{ color:#fecaca; font-weight:1000; }
    .vx-history-page .pill{
      display:inline-flex; align-items:center; gap:6px;
      padding:6px 10px; border-radius:999px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(2,6,23,.50);
      font-weight:900; font-size:.84rem;
      color:#e5e7eb;
    }
    .vx-history-page .empty{
      margin-top:10px;
      padding:14px;
      border-radius:16px;
      border:1px dashed rgba(255,255,255,.18);
      background:rgba(15,23,42,.42);
      color:rgba(226,232,240,.72);
      text-align:center;
    }
  </style>

  <div class="vx-shell">
    <!-- HERO -->
    <section class="vx-hero">
      <div class="vx-hero-top">
        <div>
          <h1 class="vx-title">
            <i class="fa-solid fa-clock-rotate-left" style="color:var(--vx-accent)"></i>
            Seeds & Points History
          </h1>
          <p class="vx-sub">
            Track every vault you’ve activated and every points movement. VX values shown are <b>targets</b> (not final)
            until TGE/listing. Target rate: <b>100 pts → 1 VX</b> (≈ <b>$<?= number_format($VX_USD, 2, '.', ''); ?>/VX</b>).
          </p>
        </div>
        <a class="vx-back" href="/user/dashboard"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>
      </div>

      <div class="vx-sum">
        <div class="sum-card">
          <div class="sum-k"><i class="fa-solid fa-layer-group"></i> Total vaults</div>
          <div class="sum-v"><?= number_format($totalVaults); ?></div>
          <div class="sum-s"><?= number_format($activeVaults); ?> active • <?= number_format($finishedVaults); ?> finished</div>
        </div>
        <div class="sum-card">
          <div class="sum-k"><i class="fa-solid fa-arrow-trend-up"></i> Est. daily yield</div>
          <div class="sum-v"><?= fmtMoney($estDailyAll); ?></div>
          <div class="sum-s">Across all your vault entries</div>
        </div>
        <div class="sum-card">
          <div class="sum-k"><i class="fa-solid fa-star"></i> Points</div>
          <div class="sum-v"><?= number_format((int)$estPtsForVX); ?> pts</div>
          <div class="sum-s">
            <?= ($pointsSpendable !== null) ? 'Spendable balance (server)' : 'Estimated from ledger' ?>
          </div>
        </div>
        <div class="sum-card">
          <div class="sum-k"><i class="fa-solid fa-bolt"></i> Target VX value</div>
          <div class="sum-v"><?= number_format($estVX, 0); ?> VX</div>
          <div class="sum-s">≈ $<?= number_format($estVXVal, 2, '.', ''); ?> (targets only)</div>
        </div>
      </div>
    </section>

    <!-- TABS -->
    <section class="vx-tabs" id="vxTabs">
      <div class="vx-tabbar">
        <button class="vx-tabbtn active" type="button" data-tab="vaults"><i class="fa-solid fa-vault"></i> Seeds</button>
        <button class="vx-tabbtn" type="button" data-tab="points"><i class="fa-solid fa-star"></i> Points</button>
      </div>

      <!-- VAULTS PANE -->
      <div class="vx-pane active" data-pane="vaults">
        <div class="vx-tools">
          <div class="vx-filters" id="vxVaultFilters">
            <button class="chip active" type="button" data-filter="all">All</button>
            <button class="chip" type="button" data-filter="active">Active</button>
            <button class="chip" type="button" data-filter="finished">Finished</button>
            <button class="chip" type="button" data-filter="pending">Pending</button>
          </div>
          <div class="vx-search">
            <input id="vxVaultSearch" type="search" placeholder="Search vaults by title or tier…">
          </div>
        </div>

        <?php if (!$vaults): ?>
          <div class="empty"><i class="fa-regular fa-face-smile"></i> No vault history yet. Activate your first vault to see it here.</div>
        <?php else: ?>
          <div class="vx-grid" id="vxVaultGrid">
            <?php foreach ($vaults as $r):
              $id      = safeInt($r['id']);
              $tarif   = safeInt($r['tarif']);
              // Prefer real plan name from db_tarif; fall back to stored title.
              $title   = trim((string)($r['plan_title'] ?? ''));
              if ($title === '') { $title = trim((string)($r['store_title'] ?? '')); }
              $title   = $title !== '' ? $title : ('Seed plan #'.$tarif);

              $speed   = (float)$r['speed'];           // % daily
              // Entry/principal: prefer plan price; fall back to stored hashpower.
              $hp      = (float)($r['price'] ?? 0);
              if ($hp <= 0) { $hp = (float)($r['hashpower'] ?? 0); }
              $tsStart = (int)$r['add'];
              $tsEnd   = (int)$r['end'];
              $status  = (int)$r['status'];            // 0=pending,1=active,2=finished

              $dailyEarn = ($hp * $speed / 100.0);
              $daysTotal = max(0, (int)round(($tsEnd - $tsStart) / 86400));
              $estTotal  = $dailyEarn * $daysTotal;

              $progress = 0;
              if ($tsEnd > $tsStart) {
                $progress = max(0, min(100, (($now - $tsStart) / max(1, ($tsEnd - $tsStart))) * 100));
              }
              $progressInt = (int)round($progress);

              $sText='Unknown'; $sClass='err'; $sKey='pending';
              if ($status === 1) { $sText='Active';   $sClass='ok';   $sKey='active'; }
              elseif ($status === 2){ $sText='Finished'; $sClass='info'; $sKey='finished'; }
              elseif ($status === 0){ $sText='Pending';  $sClass='wait'; $sKey='pending'; }

              // Target points for this vault entry (targets only)
              $targetPts = (int)($r['points_award'] ?? 0);
              if ($targetPts <= 0) { $targetPts = (int)round($hp * 1000); }
              $targetVX  = $targetPts / $VX_CONV;
              $targetUSD = $targetVX * $VX_USD;

              $searchKey = strtolower($title.' tier '.$tarif.' #'.$id);
            ?>
            <article class="vx-card"
              data-status="<?= h($sKey); ?>"
              data-search="<?= h($searchKey); ?>">
              <div class="vx-card-hd">
                <div class="vx-thumb">
                  <img loading="lazy" decoding="async" src="/img/item/<?= (int)$tarif; ?>.png" alt="Tier <?= (int)$tarif; ?>" onerror="this.onerror=null;this.src='/img/item/1.png';">
                </div>
                <div class="vx-titlewrap">
                  <h3 class="vx-name"><?= h($title); ?></h3>
                  <div class="vx-mini">Tier <?= (int)$tarif; ?> • Farm ID #<?= (int)$id; ?></div>
                </div>
                <span class="badge <?= h($sClass); ?>"><i class="fa-solid fa-circle"></i> <?= h($sText); ?></span>
              </div>

              <div class="vx-card-bd">
                <div class="vx-meta">
                  <div class="m">
                    <div class="k">Entry</div>
                    <div class="v"><?= fmtMoney($hp); ?> <span class="s">{!VAL!}</span></div>
                  </div>
                  <div class="m">
                    <div class="k">Daily yield</div>
                    <div class="v"><?= fmtMoney($dailyEarn); ?> <span class="s">{!VAL!}</span></div>
                    <div class="s"><?= fmtPct($speed); ?> / day</div>
                  </div>
                  <div class="m">
                    <div class="k">Duration</div>
                    <div class="v"><?= (int)$daysTotal; ?> days</div>
                    <div class="s">Start: <?= h(fmtDate($tsStart)); ?></div>
                  </div>
                  <div class="m">
                    <div class="k">End</div>
                    <div class="v"><?= h(fmtDate($tsEnd)); ?></div>
                    <div class="s">Est. total: <?= fmtMoney($estTotal); ?></div>
                  </div>
                </div>

                <div class="prog" aria-label="progress">
                  <div class="bar" style="width: <?= (int)$progressInt; ?>%"></div>
                </div>
                <div class="times">
                  <span><?= h(fmtDate($tsStart)); ?></span>
                  <span><?= (int)$progressInt; ?>%</span>
                  <span><?= h(fmtDate($tsEnd)); ?></span>
                </div>

                <div class="foot">
                  <div class="target">
                    <i class="fa-solid fa-star" style="color:var(--vx-warn); margin-top:2px;"></i>
                    <div>
                      <div><b><?= number_format($targetPts); ?> pts</b> <span class="hint">from this entry (targets)</span></div>
                      <div class="hint">≈ <?= number_format($targetVX, 0); ?> VX • ≈ $<?= number_format($targetUSD, 0); ?> (target rate)</div>
                    </div>
                  </div>

                  <a class="linkbtn" href="/user/points">
                    <i class="fa-regular fa-rectangle-list"></i> Open Points Ledger
                  </a>
                </div>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- POINTS PANE -->
      <div class="vx-pane" data-pane="points">
        <div class="vx-tools">
          <div class="vx-filters" id="vxPointFilters">
            <button class="chip active" type="button" data-filter="all">All</button>
            <button class="chip" type="button" data-filter="buy_vault">Purchases</button>
            <button class="chip" type="button" data-filter="ref_vault">Referrals</button>
            <button class="chip" type="button" data-filter="admin">Admin</button>
          </div>
          <div class="vx-search">
            <input id="vxPointSearch" type="search" placeholder="Search points by context, note, vault/tier id…">
          </div>
        </div>

        <div class="sum-card" style="margin-bottom:10px;">
          <div class="sum-k"><i class="fa-solid fa-circle-info"></i> Target conversion notice</div>
          <div class="sum-s" style="margin-top:6px;">
            Points → VX amounts shown here are <b>targets only</b>. Final tokenomics, listing price, and conversion mechanics are announced before TGE.
          </div>
        </div>

        <?php if (!$pointsRows): ?>
          <div class="empty"><i class="fa-regular fa-face-meh"></i> No points history found yet.</div>
        <?php else: ?>
          <div class="tablewrap">
            <table id="vxPointsTable">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Change</th>
                  <th>Context</th>
                  <th>Related</th>
                  <th>Target VX</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($pointsRows as $p):
                $pid  = (int)($p['id'] ?? 0);
                $dlt  = (int)($p['delta'] ?? 0);
                $pos  = $dlt >= 0;
                $ctx  = ($ledgerMode === 'ledger') ? (string)($p['ctx'] ?? '') : (string)($p['type'] ?? '');
                $ctxKey = $ctx ?: 'other';

                $ref  = (int)($p['ref_uid'] ?? 0);
                $tid  = (int)($p['tarif_id'] ?? 0);
                $usd  = ($ledgerMode === 'ledger') ? (float)($p['usd_value'] ?? 0) : 0.0;
                $note = ($ledgerMode === 'log') ? (string)($p['note'] ?? '') : '';

                $ts   = (int)($p['created_at'] ?? time());
                if ($ledgerMode === 'log' && isset($p['created_at'])) $ts = (int)$p['created_at'];

                $vxT  = abs($dlt) / $VX_CONV;
                $usdT = $vxT * $VX_USD;

                $search = strtolower(
                  trim($ctx.' '.$note.' ref '.$ref.' tier '.$tid.' id '.$pid)
                );
              ?>
                <tr data-filter="<?= h($ctxKey); ?>" data-search="<?= h($search); ?>">
                  <td>#<?= $pid; ?></td>
                  <td class="delta <?= $pos ? 'pos':'neg'; ?>">
                    <?= $pos ? '+':''; ?><?= number_format($dlt); ?> pts
                  </td>
                  <td>
                    <span class="pill">
                      <i class="fa-solid fa-tag" style="opacity:.85"></i>
                      <?= h($ledgerMode === 'ledger' ? ctxLabel($ctx) : ($ctx ?: '—')); ?>
                    </span>
                    <?php if ($note): ?>
                      <div style="margin-top:6px; color:rgba(226,232,240,.68); font-size:.86rem;">
                        <?= h($note); ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($tid): ?>
                      <div class="pill"><i class="fa-solid fa-vault"></i> Tier <?= (int)$tid; ?></div>
                    <?php else: ?>
                      <span style="color:rgba(226,232,240,.62)">—</span>
                    <?php endif; ?>
                    <?php if ($ref): ?>
                      <div style="margin-top:6px; color:rgba(226,232,240,.68); font-size:.86rem;">
                        Ref UID: <b><?= (int)$ref; ?></b>
                      </div>
                    <?php endif; ?>
                    <?php if ($usd > 0): ?>
                      <div style="margin-top:6px; color:rgba(226,232,240,.68); font-size:.86rem;">
                        USD ctx: <b>$<?= number_format($usd, 2, '.', ''); ?></b>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <b><?= number_format($vxT, 2); ?> VX</b>
                    <div style="color:rgba(226,232,240,.66); font-size:.86rem;">
                      ≈ $<?= number_format($usdT, 2, '.', ''); ?> (target)
                    </div>
                  </td>
                  <td><?= h(fmtDate($ts)); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <script>
  (function(){
    function qs(sel, root){ return (root||document).querySelector(sel); }
    function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

    // Tabs
    var tabsRoot = document.getElementById('vxTabs');
    if (tabsRoot){
      var btns = qsa('.vx-tabbtn', tabsRoot);
      var panes = qsa('.vx-pane', tabsRoot);
      btns.forEach(function(b){
        b.addEventListener('click', function(){
          var t = b.getAttribute('data-tab');
          btns.forEach(x=>x.classList.toggle('active', x===b));
          panes.forEach(function(p){
            p.classList.toggle('active', p.getAttribute('data-pane') === t);
          });
        });
      });
    }

    // Seed filters + search
    var vFilters = qs('#vxVaultFilters');
    var vSearch  = qs('#vxVaultSearch');
    var vCards   = qsa('#vxVaultGrid .vx-card');

    function applyVault(){
      if (!vCards.length) return;
      var activeChip = qs('#vxVaultFilters .chip.active');
      var filter = activeChip ? activeChip.getAttribute('data-filter') : 'all';
      var q = (vSearch && vSearch.value ? vSearch.value : '').trim().toLowerCase();

      vCards.forEach(function(c){
        var st = c.getAttribute('data-status') || '';
        var s  = (c.getAttribute('data-search') || '').toLowerCase();
        var okStatus = (filter === 'all') || (st === filter);
        var okQuery  = !q || (s.indexOf(q) !== -1);
        c.style.display = (okStatus && okQuery) ? '' : 'none';
      });
    }

    if (vFilters){
      qsa('.chip', vFilters).forEach(function(b){
        b.addEventListener('click', function(){
          qsa('.chip', vFilters).forEach(x=>x.classList.remove('active'));
          b.classList.add('active');
          applyVault();
        });
      });
    }
    if (vSearch) vSearch.addEventListener('input', applyVault);

    // Points filters + search
    var pFilters = qs('#vxPointFilters');
    var pSearch  = qs('#vxPointSearch');
    var pRows    = qsa('#vxPointsTable tbody tr');

    function applyPoints(){
      if (!pRows.length) return;
      var activeChip = qs('#vxPointFilters .chip.active');
      var filter = activeChip ? activeChip.getAttribute('data-filter') : 'all';
      var q = (pSearch && pSearch.value ? pSearch.value : '').trim().toLowerCase();

      pRows.forEach(function(r){
        var f = (r.getAttribute('data-filter') || '').toLowerCase();
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        var okF = (filter === 'all') || (f === filter);
        var okQ = !q || (s.indexOf(q) !== -1);
        r.style.display = (okF && okQ) ? '' : 'none';
      });
    }

    if (pFilters){
      qsa('.chip', pFilters).forEach(function(b){
        b.addEventListener('click', function(){
          qsa('.chip', pFilters).forEach(x=>x.classList.remove('active'));
          b.classList.add('active');
          applyPoints();
        });
      });
    }
    if (pSearch) pSearch.addEventListener('input', applyPoints);
  })();
  </script>
</div>

<script defer src="/assets/js/lazy_video.js"></script>
