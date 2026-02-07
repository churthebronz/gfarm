<?php
declare(strict_types=1);
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/vx_retention.php';
require_once __DIR__ . '/../../core/vx_legacy.php';
require_once __DIR__ . '/../../core/vx_lp.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . "/../../core/vx_rarity.php";
require_once __DIR__ . '/../../core/vx_guardian_art.php';

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $opt;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

$opt['title'] = 'Mutant Crop Mutant Index';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  require_once __DIR__ . '/../../api/require_tg_session.php';
  $uid = (int)($GLOBALS['UID'] ?? 0);
}
if ($uid <= 0) { header('Location: /'); exit; }

// --- Touch chains (keeps matured/codex states correct) ---
try { if (function_exists('vx_guardians_touch_user')) vx_guardians_touch_user($db, $uid, time()); } catch (Throwable $e) {}

// Pagination
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;
$per = 30;
$offset = ($page - 1) * $per;

// Guardians (source of truth: db_tarif)
$catalog = [];
try {
  // Show all guardians that belong in the Mutant Index (plans + achievements), active or not.
  // Ordering uses sort_order -> guardian_no -> id (so you control collection order from Adminka).
  $catalog = $db->query(
    "SELECT id AS tarif_id, kind, guardian_no, guardian_code, title AS display_name, type_primary, type_secondary, rarity, max_evolve, forms_total, blur_in_codex, unlock_method, img
     FROM db_tarif
     WHERE kind IN ('plan','achievement','special')
     ORDER BY COALESCE(sort_order, 999999) ASC, COALESCE(guardian_no, 999999) ASC, id ASC
     LIMIT ? OFFSET ?",
    $per, $offset
  )->fetchAll();
} catch (Throwable $e) { $catalog = []; }

// Prefetch db_tarif image keys for displayed slice (already included as img above but keep mapping)
$tarifImg = [];
try {
  if (!empty($catalog)) {
    foreach ($catalog as $r) {
      $tid = (int)($r['tarif_id'] ?? 0);
      if ($tid > 0) $tarifImg[$tid] = (string)($r['img'] ?? '');
    }
  }
} catch (Throwable $e) { $tarifImg = []; }

$tarifImg = [];
try {
  if (!empty($catalog)) {
    $ids = [];
    foreach ($catalog as $r) { $tid = (int)($r['tarif_id'] ?? 0); if ($tid > 0) $ids[$tid] = $tid; }
    if ($ids) {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $q = $db->query('SELECT id, img FROM db_tarif WHERE id IN ('.$ph.')', ...array_values($ids));
      if ($q) {
        while ($rr = $q->fetchArray()) {
          $tid = (int)($rr['id'] ?? 0);
          if ($tid > 0) $tarifImg[$tid] = (string)($rr['img'] ?? '');
        }
      }
    }
  }
} catch (Throwable $e) { $tarifImg = []; }

// Total count (for paging)
$totalCatalog = 0;
try {
  $r = $db->query("SELECT COUNT(1) AS c FROM db_tarif WHERE kind IN ('plan','achievement','special')")->fetchArray();
  $totalCatalog = (int)($r['c'] ?? 0);
} catch (Throwable $e) { $totalCatalog = 0; }

// Owned map: tarif => [lvl, shiny, max]
$owned = [];
try {
  $q = $db->query("SELECT tarif, MAX(crossbreed_level) AS lvl, MAX(shiny) AS shiny, MAX(max_evolve) AS max_evolve, MAX(rarity) AS rarity FROM vx_guardian_chains WHERE uid=? GROUP BY tarif", $uid);
  if ($q) {
    while ($r = $q->fetchArray()) {
      $t = (int)($r['tarif'] ?? 0);
      if ($t <= 0) continue;
      $owned[$t] = [
        'lvl' => (int)($r['lvl'] ?? 0),
        'shiny' => (int)($r['shiny'] ?? 0),
        'max' => (int)($r['max_evolve'] ?? 5),
        'rarity' => (string)($r['rarity'] ?? 'common'),
      ];
    }
  }
} catch (Throwable $e) { $owned = []; }


// Seen / Owned / Maxed stats for completion rings
$seenTarifs = [];
try {
  $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_seen (
    uid INT NOT NULL,
    tarif_id INT NOT NULL,
    seen_at INT NOT NULL,
    PRIMARY KEY (uid, tarif_id),
    KEY seen_at (seen_at)
  )");
  $q = $db->query("SELECT tarif_id FROM vx_guardian_seen WHERE uid=".$uid);
  if ($q) { while ($r = $q->fetchArray()) { $t = (int)($r['tarif_id'] ?? 0); if ($t>0) $seenTarifs[$t]=1; } }
} catch (Throwable $e) { $seenTarifs = []; }

$ownedCount = count($owned);
$maxedCount = 0;
if (!empty($owned)) {
  foreach ($owned as $t => $st) {
    $lvl = (int)($st['lvl'] ?? 0);
    $mx = (int)($st['max'] ?? 5);
    if ($mx < 1) $mx = 5;
    if ($lvl >= $mx) $maxedCount++;
  }
}
// Seen count: intersection of seenTarifs with catalog tarif_ids (best effort)
$seenCount = 0;
try {
  if (!empty($seenTarifs)) {
    // count seen that exist in catalog
    $ph = implode(',', array_fill(0, count($seenTarifs), '?'));
    $q = $db->query("SELECT COUNT(*) AS c FROM vx_guardian_catalog WHERE tarif_id IN (".$ph.")", ...array_keys($seenTarifs));
    $seenCount = (int)(($q ? ($q->fetchArray()['c'] ?? 0) : 0));
  }
} catch (Throwable $e) { $seenCount = count($seenTarifs); }

$totalCount = max(0, (int)$totalCatalog);
if ($totalCount < 1) $totalCount = max($ownedCount, 1);

$seenPct = (int)round(min(100, ($seenCount / max(1, $totalCount)) * 100));
$ownedPct = (int)round(min(100, ($ownedCount / max(1, $totalCount)) * 100));
$maxedPct = (int)round(min(100, ($maxedCount / max(1, $totalCount)) * 100));
$vx_codex_reward = '';
$vx_codex_reward_class = '';
try {
  // Reward tiers based on OWNED completion (simple + motivating)
  if ($ownedPct >= 100) { $vx_codex_reward='Archivist'; $vx_codex_reward_class='r100'; }
  else if ($ownedPct >= 75) { $vx_codex_reward='Collector'; $vx_codex_reward_class='r75'; }
  else if ($ownedPct >= 50) { $vx_codex_reward='Seeker'; $vx_codex_reward_class='r50'; }
  else if ($ownedPct >= 25) { $vx_codex_reward='Initiate'; $vx_codex_reward_class='r25'; }
} catch (Throwable $e) { $vx_codex_reward=''; $vx_codex_reward_class=''; }


// Auto-map catalog.tarif_id if missing (best-effort; keeps #01.. aligned with your plan order)
try {
  // Only do this once-ish: if there are any NULL tarif_id rows.
  $need = $db->query("SELECT COUNT(*) AS c FROM vx_guardian_catalog WHERE kind='plan' AND (tarif_id IS NULL OR tarif_id=0)")->fetchArray();
  if ((int)($need['c'] ?? 0) > 0) {
    // Map guardian_no -> db_tarif.id by ascending id (1st plan => #01)
    // Uses a derived table to avoid user variables.
    $db->query("UPDATE vx_guardian_catalog gc
      JOIN (
        SELECT id AS tarif_id, (@rn:=@rn+1) AS guardian_no
        FROM (SELECT id FROM db_tarif ORDER BY id ASC LIMIT 200) t, (SELECT @rn:=0) vars
      ) m ON m.guardian_no = gc.guardian_no
      SET gc.tarif_id = m.tarif_id
      WHERE gc.kind='plan' AND gc.guardian_no BETWEEN 1 AND 200 AND (gc.tarif_id IS NULL OR gc.tarif_id=0)");
  }
} catch (Throwable $e) {}

$legacy = ['total'=>0,'spendable'=>0];
try { $legacy = vx_legacy_get($db, $uid); } catch (Throwable $e) {}

$lp = ['total'=>0,'spendable'=>0];
try { $lp = vx_lp_get($db, $uid); } catch (Throwable $e) {}

function vx_codex_type_chip(string $t): string {
  $t = trim($t);
  if ($t === '') return '';
  return '<span class="vx-chip">'.htmlspecialchars($t, ENT_QUOTES).'</span>';
}

?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/vx_shell.css">
<link rel="stylesheet" href="/assets/css/vx_rarity.css">

<style>
:root{
  --vx-bg:#060816;
  --vx-surface:#0b1024;
  --vx-surface-2:#0e1430;
  --vx-border:rgba(255,255,255,.10);
  --vx-text:#eaf0ff;
  --vx-muted:#a8b2d1;
  --vx-accent:#00ffe0;
  --vx-accent-2:#58a6ff;
  --vx-glow:0 24px 80px rgba(0,255,224,.08), 0 8px 26px rgba(88,166,255,.08);
}

.vx-shell{max-width:1120px;margin:18px auto 34px;padding:0 14px;}
.vx-card{
  background:linear-gradient(180deg, rgba(11,16,36,.86), rgba(11,16,36,.94));
  border:1px solid var(--vx-border);
  border-radius:22px;
  box-shadow:var(--vx-glow);
  backdrop-filter:blur(14px);
}
.vx-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:18px 18px 10px;}
.vx-title{margin:0;font-weight:1000;letter-spacing:.02em;font-size:1.25rem;color:var(--vx-text);}
.vx-sub{color:rgba(226,232,240,.84);margin-top:6px;line-height:1.4;}
.vx-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid rgba(148,163,184,.18);background:rgba(2,6,23,.35);color:rgba(255,255,255,.92);font-weight:900;font-size:.92rem;}
.vx-pill b{color:var(--vx-accent);}

.vx-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px 18px 18px;}
@media(max-width:900px){.vx-grid{grid-template-columns:1fr;}}

/* --- Card Frames (Top 1% feel) --- */
.gcard{
  border-radius:18px;
  border:1px solid rgba(148,163,184,.16);
  background:radial-gradient(900px 260px at 10% 0%, rgba(0,255,224,.12), transparent 55%), rgba(2,6,23,.36);
  padding:14px;
  position:relative;
  overflow:hidden;
  transform:translateZ(0);
}
.gcard::before{
  content:"";
  position:absolute;
  inset:-1px;
  border-radius:18px;
  padding:1px;
  background:linear-gradient(135deg, rgba(0,255,224,.34), rgba(88,166,255,.22), rgba(255,209,102,.16));
  -webkit-mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite:xor;
  mask-composite:exclude;
  opacity:.58;
}
.gcard::after{
  content:"";
  position:absolute;
  inset:-120px;
  background:radial-gradient(circle at 20% 10%, rgba(0,255,224,.14), transparent 40%),
             radial-gradient(circle at 80% 0%, rgba(88,166,255,.12), transparent 44%),
             radial-gradient(circle at 60% 90%, rgba(255,209,102,.10), transparent 46%);
  filter:blur(18px);
  opacity:.45;
  z-index:0;
  animation:vxAura 6.8s ease-in-out infinite;
}
@keyframes vxAura{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(0,-12px,0) scale(1.02)}}

.gcard>*{position:relative;z-index:1;}
.gname{font-weight:1000;font-size:1.08rem;margin:0 0 8px;display:flex;gap:10px;align-items:center;}
.gart{
  width:100%;
  height:160px;
  border-radius:16px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(0,0,0,.18);
  overflow:hidden;
  position:relative;
  box-shadow:0 18px 50px rgba(0,0,0,.45);
}
.gart img{width:100%;height:100%;object-fit:cover;display:block;transform:scale(1.02);filter:saturate(1.05) contrast(1.05);}
.gart::after{
  content:"";
  position:absolute;
  inset:0;
  pointer-events:none;
  background:linear-gradient(180deg, rgba(0,0,0,.05), rgba(0,0,0,.55));
}
.gart .cap{
  position:absolute;
  left:12px;
  bottom:12px;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
  z-index:2;
}
.cap .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(2,6,23,.45);font-weight:1000;font-size:.78rem;color:rgba(234,240,255,.92);backdrop-filter:blur(10px)}
.gbadge{position:absolute;top:12px;right:12px;z-index:2}
.gbadge span{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.20);font-weight:1000;font-size:.78rem;}

/* Evolve badge */
.evo-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(2,6,23,.35);font-weight:1000;font-size:.78rem;color:rgba(234,240,255,.92);}
.evo-badge b{color:var(--vx-accent)}

/* Type chips */
.vx-chip{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;border:1px solid rgba(148,163,184,.16);background:rgba(2,6,23,.30);font-weight:900;font-size:.78rem;color:rgba(226,232,240,.90)}

/* Evolve tree */
.evo-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px;}
.evo-dot{width:14px;height:14px;border-radius:999px;border:1px solid rgba(148,163,184,.22);background:rgba(2,6,23,.35);position:relative;}
.evo-dot.on{border-color:rgba(0,255,224,.45);box-shadow:0 0 0 2px rgba(0,255,224,.12);background:radial-gradient(circle at 30% 30%, rgba(0,255,224,.65), rgba(2,6,23,.32));}
.evo-dot.max{width:auto;height:auto;padding:5px 10px;border-radius:999px;font-weight:900;font-size:.78rem;}

/* Locked/unowned */
.gcard.locked{opacity:.80;}
.gcard.locked .gbody{filter:blur(10px) saturate(.6);opacity:.55;}
.gcard.locked .lock-overlay{display:flex;}
.lock-overlay{display:none;position:absolute;inset:0;z-index:3;align-items:center;justify-content:center;flex-direction:column;gap:10px;background:linear-gradient(180deg, rgba(0,0,0,.10), rgba(0,0,0,.32));}
.lock-overlay .lk{width:56px;height:56px;border-radius:16px;border:1px solid rgba(255,255,255,.14);background:rgba(2,6,23,.55);display:flex;align-items:center;justify-content:center;font-size:22px;color:rgba(234,240,255,.92);box-shadow:0 20px 60px rgba(0,0,0,.35)}
.lock-overlay .txt{font-weight:1000;color:rgba(234,240,255,.90)}

/* Shiny */
.gcard.shiny::before{opacity:.85;background:linear-gradient(135deg, rgba(255,255,255,.45), rgba(0,255,224,.28), rgba(124,92,255,.22), rgba(255,209,102,.22));}
.gcard.shiny .gbadge span{border-color:rgba(255,255,255,.22)}

/* Hover micro-motion (desktop) */
@media(hover:hover){
  .gcard{transition:transform .18s ease, filter .18s ease;}
  .gcard:hover{transform:translateY(-2px);filter:brightness(1.05);}
}

.vx-pager{display:flex;justify-content:space-between;align-items:center;padding:0 18px 18px;gap:10px;}
.vx-pager a{display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:var(--vx-text);padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(2,6,23,.26);} 
.vx-pager a:hover{filter:brightness(1.08)}
.vx-pager .stat{color:rgba(226,232,240,.78);font-weight:900}
.vx-reward{border-color:rgba(255,255,255,.16); background:rgba(255,255,255,.06)}
.vx-reward.r25{border-color:rgba(0,243,255,.22); background:rgba(0,243,255,.07)}
.vx-reward.r50{border-color:rgba(124,92,255,.22); background:rgba(124,92,255,.08)}
.vx-reward.r75{border-color:rgba(255,204,102,.22); background:rgba(255,204,102,.08)}
.vx-reward.r100{border-color:rgba(34,197,94,.22); background:rgba(34,197,94,.08)}
</style>

<div class="vx-shell">
  <div class="vx-card">
    <div class="vx-head">
      <div>
        <h1 class="vx-title">Mutant Crop Mutant Index</h1>
        <div class="vx-sub">Swipe your collection mindset: build sets, evolve stages, and flex shinies. Unowned Guardians are blurred — names stay visible.</div>
      </div>
      <?php if ($vx_codex_reward !== ''): ?>
      <div class="vx-pill vx-reward <?= htmlspecialchars($vx_codex_reward_class, ENT_QUOTES); ?>">🏅 <?= htmlspecialchars($vx_codex_reward, ENT_QUOTES); ?></div>
      <?php endif; ?>
      <div class="vx-pill">Legacy VP: <b><?= number_format((int)($legacy['spendable'] ?? 0)); ?></b> • LP: <b><?= number_format((int)($lp['spendable'] ?? 0)); ?></b></div>

    <div class="vx-rings">
      <div class="vx-ring" style="--p:<?= (int)$seenPct ?>;">
        <div class="vx-ring-inner">
          <div class="vx-ring-num"><?= (int)$seenPct ?>%</div>
          <div class="vx-ring-lbl">Seen</div>
          <div class="vx-ring-sub"><?= number_format((int)$seenCount) ?> / <?= number_format((int)$totalCount) ?></div>
        </div>
      </div>
      <div class="vx-ring" style="--p:<?= (int)$ownedPct ?>;">
        <div class="vx-ring-inner">
          <div class="vx-ring-num"><?= (int)$ownedPct ?>%</div>
          <div class="vx-ring-lbl">Owned</div>
          <div class="vx-ring-sub"><?= number_format((int)$ownedCount) ?> / <?= number_format((int)$totalCount) ?></div>
        </div>
      </div>
      <div class="vx-ring vx-ring-gold" style="--p:<?= (int)$maxedPct ?>;">
        <div class="vx-ring-inner">
          <div class="vx-ring-num"><?= (int)$maxedPct ?>%</div>
          <div class="vx-ring-lbl">Maxed</div>
          <div class="vx-ring-sub"><?= number_format((int)$maxedCount) ?> / <?= number_format((int)$totalCount) ?></div>
        </div>
      </div>
    </div>

    </div>

    <?php if (empty($catalog)): ?>
      <div class="vx-empty" style="margin:14px 0 6px;">
  <h3>📘 Mutant Index not seeded</h3>
  <p>Your guardian catalog hasn’t been imported yet. Once you seed the catalog, undiscovered Guardians will appear here as silhouettes.</p>
  <div class="vx-empty-actions">
    <a class="vx-btn vx-btn-primary" href="/user/plans">Open Plans</a>
    <a class="vx-btn" href="/user/dashboard">Back to Farm</a>
  </div>
</div>
    <?php else: ?>
      <div class="vx-grid" id="vxMutant IndexGrid">
        <?php foreach ($catalog as $row):
          $tarifId = (int)($row['tarif_id'] ?? 0);
          $display = (string)($row['display_name'] ?? '');
          $base = (string)($row['base_name'] ?? '');
          $type1 = (string)($row['type_primary'] ?? 'Mystic');
          $type2 = (string)($row['type_secondary'] ?? '');
          $rarity = (string)($row['rarity'] ?? 'common');
          $maxE = (int)($row['max_evolve'] ?? 5);
          if ($maxE < 1) $maxE = 1;
          if ($maxE > 10) $maxE = 10;

          $own = ($tarifId > 0 && isset($owned[$tarifId]));
          $lvl = $own ? (int)($owned[$tarifId]['lvl'] ?? 0) : 0;
          $isShiny = $own ? ((int)($owned[$tarifId]['shiny'] ?? 0) === 1) : false;

          $rClass = vx_rarity_css_class($rarity);
          $badge = $isShiny ? '✨ Shiny' : vx_rarity_label($rarity);

          $fallbackKey = ($tarifId > 0) ? (string)($tarifImg[$tarifId] ?? '') : '';
          $artKey = '';
          try { $artKey = vx_guardian_art_key_for_level($db, $tarifId, $own ? $lvl : 0, $fallbackKey); } catch (Throwable $e) { $artKey = $fallbackKey; }
          $artUrl = '/assets/img/guardians/placeholder.png';
          $ak = trim((string)$artKey);
          if ($ak !== '' && (strpos($ak, 'http') === 0 || strpos($ak, '/') === 0)) {
            $artUrl = $ak;
          } else {
            $artUrl = function_exists('vx_img_items_url_from_key') ? vx_img_items_url_from_key((string)$ak) : ('/img/items/' . preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$ak) . '.png');
          }
        ?>
          <div class="gcard vx-vault-card <?= $rClass; ?> <?= $own?'owned':'locked'; ?> <?= $isShiny?'shiny':''; ?>" data-guardian-sheet="1" data-tarif="<?= (int)$tarifId; ?>" role="button" tabindex="0" aria-label="Open Guardian details">
            <div class="gbadge"><span><?= htmlspecialchars($badge, ENT_QUOTES); ?></span></div>
            <h3 class="gname">🛡️ <?= htmlspecialchars($display !== '' ? $display : ($base !== '' ? $base : 'Guardian'), ENT_QUOTES); ?></h3>

            <div class="gbody">
              <div class="gart" aria-label="Guardian art">
                <img loading="lazy" decoding="async" src="<?= htmlspecialchars($artUrl, ENT_QUOTES); ?>" alt="<?= htmlspecialchars($display !== '' ? $display : ($base !== '' ? $base : 'Guardian'), ENT_QUOTES); ?>" loading="lazy" onerror="this.src='/assets/img/guardians/placeholder.png'">
                <div class="cap">
                  <span class="chip"><i class="fa-solid fa-wand-magic-sparkles"></i> Evolve <?= (int)$lvl; ?></span>
                  <span class="chip"><i class="fa-solid fa-gem"></i> <?= htmlspecialchars(vx_rarity_label($rarity), ENT_QUOTES); ?></span>
                </div>
              </div>

              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <?= vx_codex_type_chip($type1); ?>
                <?= $type2 !== '' ? vx_codex_type_chip($type2) : ''; ?>
                <span class="evo-badge">Evolve <b><?= (int)$lvl; ?></b> / <?= (int)$maxE; ?></span>
              </div>

              <div class="evo-row" aria-label="evolve tree">
                <?php for ($i=0;$i<=$maxE;$i++): ?>
                  <span class="evo-dot <?= ($i <= $lvl ? 'on' : ''); ?>" title="Stage <?= (int)$i; ?>"></span>
                <?php endfor; ?>
                <span class="evo-dot max" title="Max evolve">Max <?= (int)$maxE; ?></span>
              </div>

              <div style="margin-top:10px;color:rgba(226,232,240,.78);font-size:.92rem;line-height:1.4">
                <?= $own ? 'Owned. Keep evolving during the 24h window after maturity to climb stages.' : 'Unowned. Start this Guardian by buying its plan.'; ?>
              </div>

              <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
                <a href="/user/plans<?= $tarifId>0?('?focus='.(int)$tarifId):''; ?>" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:var(--vx-text);padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(2,6,23,.26);">
                  <i class="fa-solid fa-store"></i> View plan
                </a>
                <?php if ($own && $tarifId>0): ?>
                  <a href="/user/crossbreed?tarif=<?= (int)$tarifId; ?>" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:var(--vx-text);padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(2,6,23,.26);">
                    <i class="fa-solid fa-up-long"></i> Evolve
                  </a>
                <?php endif; ?>
              </div>
            </div>

            <div class="lock-overlay">
              <div class="lk"><i class="fa-solid fa-lock"></i></div>
              <div class="txt">Locked</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php
        $totalPages = $totalCatalog > 0 ? (int)ceil($totalCatalog / $per) : $page;
        if ($totalPages < 1) $totalPages = 1;
        $prev = max(1, $page-1);
        $next = min($totalPages, $page+1);
      ?>
      <div class="vx-pager">
        <a href="/user/codex?p=<?= (int)$prev; ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a>
        <div class="stat">Page <?= (int)$page; ?> / <?= (int)$totalPages; ?></div>
        <a href="/user/codex?p=<?= (int)$next; ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
      </div>

      <script>
      (function(){
        // Simple swipe between pages (mobile)
        const grid = document.getElementById('vxMutant IndexGrid');
        if (!grid) return;
        let sx = 0, dx = 0;
        grid.addEventListener('touchstart', (e)=>{ sx = e.touches[0].clientX; dx = 0; }, {passive:true});
        grid.addEventListener('touchmove', (e)=>{ dx = e.touches[0].clientX - sx; }, {passive:true});
        grid.addEventListener('touchend', ()=>{
          if (Math.abs(dx) < 90) return;
          const go = (dx < 0) ? document.querySelector('.vx-pager a:last-child') : document.querySelector('.vx-pager a:first-child');
          if (go && go.href) window.location.href = go.href;
        });
      })();
      </script>

      <!-- Guardian detail sheet -->
      <script src="/assets/js/vx_guardian_sheet.js"></script>

    <?php endif; ?>

  </div>
</div>
