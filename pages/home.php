<?php
if (!defined('FastCore')) { exit('Oops!'); }

global $db, $config;

@require_once __DIR__ . '/../core/vx_daily_shop.php';
@require_once __DIR__ . '/../core/vx_guardian_art.php';
@require_once __DIR__ . '/../core/vx_rarity.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$isAuth = !empty($_SESSION['uid']);
$uid    = (int)($_SESSION['uid'] ?? 0);

$bot = preg_replace('~[^A-Za-z0-9_]+~','',(string)($config->telegram_bot ?? 'GreenFarmBot'));
$loginUrl = 'https://t.me/'.$bot.'/launch';

$vx_daily_home = function_exists('vx_daily_shop_pick') ? vx_daily_shop_pick($db, 9) : [];
$vx_daily_plans = (array)($vx_daily_home['plans'] ?? []);
$vx_daily_featured_id = (int)($vx_daily_home['featured_id'] ?? 0);

/* ---------- First-time flow ---------- */
$hasMutant = false;
if ($isAuth && $db && method_exists($db,'query')) {
  try {
    $r = $db->query("SELECT id FROM db_user_guardians WHERE user_id=? LIMIT 1", $uid);
    $hasMutant = (bool)($r && $r->fetchArray());
  } catch(Throwable $e){}
}

/* ---------- Small helpers ---------- */
function gf_num($n, $d=2){
  $n = (float)$n;
  return rtrim(rtrim(number_format($n, $d), '0'), '.');
}

/* ---------- Shop refresh countdown (no cron) ---------- */
/*
  We show a real timer until the next "day boundary".
  If your vx_daily_shop_pick() is seeded by date, this will match the rotation feel (24h).
*/
$nowTs = time();
$nextRefreshTs = strtotime('tomorrow 00:00:00');
if (!$nextRefreshTs || $nextRefreshTs <= $nowTs) { $nextRefreshTs = $nowTs + 86400; }
$msUntilRefresh = max(0, ($nextRefreshTs - $nowTs) * 1000);
?>

<style>
/* ===================== GLOBAL ===================== */
body{
  background:
    radial-gradient(1200px 500px at 50% -10%, rgba(90,255,180,.14), transparent 60%),
    #06150d;
}

.gf-shell{max-width:1140px;margin:0 auto;padding:16px}
.gf-divider{height:1px;background:linear-gradient(90deg,transparent,rgba(120,255,200,.25),transparent);margin:32px 0}

/* ===================== HERO ===================== */
.gf-hero{text-align:center;padding:36px 10px 22px}
.gf-hero h1{font-size:clamp(34px,5vw,54px);font-weight:1100; letter-spacing:.2px;}
.gf-hero p{opacity:.78;max-width:540px;margin:12px auto 0}

/* ===================== META ===================== */
.gf-meta-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:24px}
.gf-meta{padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06)}
.gf-meta strong{display:block;font-size:14px}
.gf-meta span{font-size:12px;opacity:.7}
@media(max-width:820px){.gf-meta-strip{grid-template-columns:1fr}}

/* ===================== SHOP HEADER ===================== */
.gf-shophead{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:12px;
  margin:0 0 12px;
  padding:14px 14px;
  border-radius:18px;
  background: linear-gradient(135deg, rgba(0,255,150,.10), rgba(255,255,255,.03));
  border:1px solid rgba(255,255,255,.06);
}
.gf-shophead .l{
  display:flex;
  flex-direction:column;
  gap:6px;
  min-width: 0;
}
.gf-shophead .t{
  font-weight:1200;
  letter-spacing:.2px;
  font-size:1.02rem;
  display:flex;
  align-items:center;
  gap:8px;
}
.gf-shophead .s{
  opacity:.78;
  font-size:.90rem;
  line-height:1.25;
}
.gf-shophead .r{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
  justify-content:flex-end;
}
.gf-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:9px 12px;
  border-radius:999px;
  background: rgba(0,0,0,.28);
  border:1px solid rgba(255,255,255,.10);
  color:rgba(255,255,255,.92);
  font-weight:1100;
  letter-spacing:.2px;
  white-space:nowrap;
}
.gf-pill small{opacity:.75;font-weight:1000;letter-spacing:.12em;text-transform:uppercase}
.gf-pill .time{font-variant-numeric: tabular-nums;}
.gf-pill.urgent{
  background: rgba(251,191,36,.12);
  border-color: rgba(251,191,36,.28);
  box-shadow: 0 0 28px rgba(251,191,36,.18);
}
.gf-pill-btn{
  cursor:pointer;
  user-select:none;
  transition: transform .12s ease;
}
.gf-pill-btn:active{ transform: translateY(1px); }

@media(max-width:720px){
  .gf-shophead{flex-direction:column; align-items:stretch;}
  .gf-shophead .r{justify-content:flex-start}
}

/* ===================== SLIDER (SEAMLESS — no box) ===================== */
.gf-slider{margin-top:14px; position:relative;}
/* No “container box” — just a soft edge fade so it feels infinite */
.gf-track-wrap{
  overflow-x:auto;
  overflow-y:visible;
  -webkit-overflow-scrolling: touch;
  scroll-snap-type: x mandatory;
  scroll-padding: 16px;
  padding: 6px 2px 18px;
  scrollbar-width: none;
  mask-image: linear-gradient(90deg, transparent 0%, #000 6%, #000 94%, transparent 100%);
  -webkit-mask-image: linear-gradient(90deg, transparent 0%, #000 6%, #000 94%, transparent 100%);
}
.gf-track-wrap::-webkit-scrollbar{ display:none; }
.gf-track{
  display:flex;
  gap:14px;
  padding: 2px 14px;
  align-items:stretch;
}

/* Hint under slider */
.gf-swipehint{
  display:flex;align-items:center;gap:8px;
  margin: 8px 6px 0;
  opacity:.72;
  font-weight:1000;
  font-size:.90rem;
}
.gf-swipehint .dot{
  width:8px;height:8px;border-radius:50%;
  background: rgba(0,255,150,.75);
  box-shadow: 0 0 18px rgba(0,255,150,.35);
}

/* ===================== CARD BASE (TALLER + RICHER) ===================== */
.gf-gcard{
  flex:0 0 calc(100% - 10px);
  min-height: 560px;
  border-radius:28px;
  position:relative;
  overflow:hidden;
  scroll-snap-align:center;
  background:
    radial-gradient(140% 70% at 30% 0%, rgba(0,255,170,.12), transparent 60%),
    linear-gradient(180deg, #0e3326, #061b14);
  border:2px solid rgba(0,255,170,.18);
  box-shadow:0 34px 120px rgba(0,0,0,.72);
  cursor:pointer;
  transform: translateZ(0);
}
@media(min-width:860px){
  .gf-gcard{
    flex-basis: calc(33.333% - 12px);
    min-height: 620px;
  }
}

/* tap feedback */
.gf-gcard:active{ transform: translateY(1px) translateZ(0); }

/* inner glass edge (adds richness without “box inside box”) */
.gf-gcard:before{
  content:"";
  position:absolute; inset:0;
  pointer-events:none;
  border-radius:28px;
  box-shadow:
    inset 0 0 0 1px rgba(255,255,255,.06),
    inset 0 0 0 10px rgba(0,0,0,.06);
  opacity:.9;
}

/* ===================== RARITY BORDERS (STRONGER READ) ===================== */
.gf-gcard[data-rarity="common"]{
  border-color: rgba(0,255,170,.18);
}
.gf-gcard[data-rarity="rare"]{
  border-color:#22d3ee;
  box-shadow:
    0 0 0 1px rgba(34,211,238,.40),
    0 0 34px rgba(34,211,238,.38),
    0 34px 120px rgba(0,0,0,.72);
}
.gf-gcard[data-rarity="epic"]{
  border-color:#c084fc;
  box-shadow:
    0 0 0 1px rgba(192,132,252,.46),
    0 0 44px rgba(192,132,252,.42),
    0 34px 120px rgba(0,0,0,.72);
}
.gf-gcard[data-rarity="mythic"]{
  border-color:#22d3ee;
  box-shadow:
    0 0 0 1px rgba(34,211,238,.52),
    0 0 58px rgba(34,211,238,.46),
    0 34px 130px rgba(0,0,0,.78);
}
.gf-gcard[data-rarity="legendary"]{
  border-color:#fbbf24;
  box-shadow:
    0 0 0 1px rgba(251,191,36,.70),
    0 0 70px rgba(251,191,36,.62),
    0 34px 140px rgba(0,0,0,.82);
  animation: legendaryPulse 3.5s ease-in-out infinite;
}
@keyframes legendaryPulse{
  0%,100%{
    box-shadow:
      0 0 0 1px rgba(251,191,36,.65),
      0 0 60px rgba(251,191,36,.58),
      0 34px 140px rgba(0,0,0,.82);
  }
  50%{
    box-shadow:
      0 0 0 2px rgba(251,191,36,.92),
      0 0 92px rgba(251,191,36,.80),
      0 34px 160px rgba(0,0,0,.88);
  }
}

/* ===================== CARD CONTENT ===================== */
.gf-ghead{
  padding:16px 16px 12px;
  border-bottom:1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,0));
  position:relative;
  z-index:2;
}
.gf-gname{font-weight:1100; font-size:1.08rem; letter-spacing:.2px;}
.gf-gsubrow{display:flex;justify-content:space-between;align-items:center;margin-top:6px}
.gf-gstars{color:#ffd166; letter-spacing:2px; font-size:.92rem; opacity:.95}

/* Artwork */
.gf-gmedia{
  position:relative;
  height:260px;
  background:#02140f;
  z-index:1;
}
@media(max-width:420px){ .gf-gmedia{height:240px;} }
.gf-gmedia img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
  transform: scale(1.02);
  filter: saturate(1.08) contrast(1.06);
}
.gf-gmedia:after{
  content:"";
  position:absolute; inset:0;
  background:
    radial-gradient(85% 60% at 50% 0%, rgba(255,255,255,.10), transparent 55%),
    linear-gradient(180deg, rgba(0,0,0,.00), rgba(0,0,0,.28));
  pointer-events:none;
}

.gf-feature-pill{
  position:absolute;top:12px;right:12px;
  padding:7px 10px;border-radius:999px;
  background:linear-gradient(135deg,#fbbf24,#22d3ee);
  font-weight:1000;
  letter-spacing:.08em;
  color:#03110c;
  z-index:3;
  border:1px solid rgba(255,255,255,.16);
  backdrop-filter: blur(10px);
}

.gf-grarity{
  position:absolute;bottom:12px;right:12px;
  padding:7px 10px;border-radius:999px;
  background:rgba(0,0,0,.50);
  font-size:.72rem;
  letter-spacing:.14em;
  border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.92);
  z-index:3;
}

/* FX → rare/legendary sparkle only (lightweight) */
.gf-spark{
  position:absolute; inset:-20%;
  pointer-events:none;
  background:
    radial-gradient(8px 8px at 18% 22%, rgba(255,255,255,.22), transparent 60%),
    radial-gradient(6px 6px at 62% 18%, rgba(255,255,255,.18), transparent 60%),
    radial-gradient(10px 10px at 78% 52%, rgba(255,255,255,.16), transparent 60%),
    radial-gradient(7px 7px at 36% 68%, rgba(255,255,255,.14), transparent 60%);
  opacity:0;
  transform: translateZ(0);
  animation: gfSpark 4.2s ease-in-out infinite;
}
.gf-gcard[data-rarity="rare"] .gf-spark,
.gf-gcard[data-rarity="epic"] .gf-spark,
.gf-gcard[data-rarity="mythic"] .gf-spark,
.gf-gcard[data-rarity="legendary"] .gf-spark{ opacity:.55; }
.gf-gcard[data-rarity="legendary"] .gf-spark{ opacity:.75; }

@keyframes gfSpark{
  0%,100%{ transform: translate3d(0,0,0); filter: blur(0px); }
  50%{ transform: translate3d(6px,-4px,0); filter: blur(.2px); }
}

/* Body */
.gf-gbody{padding:14px 16px 16px; position:relative; z-index:2;}
.gf-gquick{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.gf-qchip{
  padding:7px 10px;border-radius:999px;
  font-size:.82rem;
  font-weight:1000;
  background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.12);
  color:rgba(255,255,255,.92);
}
.gf-qchip.is-a{ border-color: rgba(34,211,238,.22); background: rgba(34,211,238,.07); }
.gf-qchip.is-b{ border-color: rgba(16,185,129,.22); background: rgba(16,185,129,.07); }
.gf-qchip.is-c{ border-color: rgba(251,191,36,.20); background: rgba(251,191,36,.06); }

/* Rich plan info grid */
.gf-ginfo{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
  margin-top: 6px;
}
@media(max-width:420px){ .gf-ginfo{ grid-template-columns:1fr; } }
.gf-info{
  padding:10px 12px;
  border-radius:16px;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.10);
}
.gf-info .k{
  font-size:.70rem;
  letter-spacing:.12em;
  text-transform:uppercase;
  opacity:.70;
  font-weight:1000;
}
.gf-info .v{
  margin-top:6px;
  font-weight:1100;
  letter-spacing:.2px;
  color: rgba(255,255,255,.94);
}
.gf-info.is-yield{ border-color: rgba(52,211,153,.20); background: rgba(52,211,153,.06); }
.gf-info.is-total{ border-color: rgba(251,191,36,.18); background: rgba(251,191,36,.05); }
.gf-info.is-earn{ border-color: rgba(34,211,238,.20); background: rgba(34,211,238,.06); }
.gf-info.is-meta{ border-color: rgba(192,132,252,.18); background: rgba(192,132,252,.05); }

/* Footer */
.gf-gfoot{
  margin-top:14px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
}
.gf-gprice{
  display:flex;
  flex-direction:column;
  gap:2px;
}
.gf-gprice .k{
  font-size:.72rem;
  letter-spacing:.12em;
  text-transform:uppercase;
  opacity:.70;
  font-weight:1000;
}
.gf-gprice .v{
  font-weight:1200;
  font-size:1.10rem;
}
.gf-gbtn{
  padding:11px 14px;border-radius:18px;
  background:linear-gradient(135deg,#22d3ee,#34d399);
  color:#03110c;
  font-weight:1100;
  text-decoration:none;
  white-space:nowrap;
  box-shadow: 0 14px 34px rgba(34,211,238,.10);
  border: 1px solid rgba(255,255,255,.12);
}
.gf-gbtn:active{ transform: translateY(1px); }

/* ===================== PATH ===================== */
.gf-path{
  margin:34px 0 10px;
  padding:18px;
  border-radius:18px;
  background:linear-gradient(135deg,#0f2a1f,#091814);
  border:1px solid rgba(255,255,255,.06);
}
.gf-path h3{ margin:0 0 10px; font-weight:1100; letter-spacing:.2px; }
.gf-step{
  display:flex;gap:14px;
  padding:12px 14px;
  border-radius:16px;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.08);
  margin-top:10px;
  color:#dfffe9;
  text-decoration:none;
}
.gf-step:hover{ border-color: rgba(0,255,150,.22); background: rgba(0,255,150,.06); }
.gf-step span{
  width:34px;height:34px;border-radius:50%;
  display:grid;place-items:center;
  background:rgba(0,255,150,.95);
  color:#032;
  font-weight:1100;
}
.gf-step.active{ outline:2px solid rgba(0,255,150,.28); }
</style>

<div class="gf-shell">

<section class="gf-hero">
  <h1>Grow. Evolve. Discover.</h1>
  <p>A living mutant collection game inside Telegram. Every choice shapes the Codex.</p>

  <div class="gf-meta-strip">
    <div class="gf-meta"><strong>📚 Living Codex</strong><span>Every discovery matters</span></div>
    <div class="gf-meta"><strong>🛒 Daily Drops</strong><span>Limited mutants rotate daily</span></div>
    <div class="gf-meta"><strong>🧬 Seasons</strong><span>Progress resets, value evolves</span></div>
  </div>
</section>

<div class="gf-divider"></div>

<?php if ($vx_daily_plans): ?>
  <div class="gf-shophead" aria-label="Daily shop info">
    <div class="l">
      <div class="t">🛒 Daily Seed Shop</div>
      <div class="s">🌱 Cards rotate every <b>24 hours</b>. Limited supply — grab the best drops before they refresh.</div>
    </div>
    <div class="r">
      <div class="gf-pill" id="gfTimerPill" aria-live="polite">
        <small id="gfTimerEmoji">⏳</small>
        <span>Refresh in</span>
        <span class="time" id="gfTimerText">--:--:--</span>
      </div>
      <div class="gf-pill gf-pill-btn" id="gfSoundToggle" role="button" tabindex="0" aria-label="Toggle tap sound">
        <span id="gfSoundIcon">🔈</span>
        <span id="gfSoundText">Tap Sound Off</span>
      </div>
    </div>
  </div>

  <div class="gf-slider" aria-label="Today's Seed Drop">
    <div class="gf-track-wrap" id="gfShopTrackWrap">
      <div class="gf-track">

<?php foreach ($vx_daily_plans as $p):
  $id     = (int)($p['id'] ?? 0);
  $title  = (string)($p['title'] ?? ('Mutant '.$id));
  $price  = (float)($p['price'] ?? 0);
  $speed  = (float)($p['speed'] ?? 0);     // interpreted as % per day
  $period = (int)($p['period'] ?? 0);      // days
  $min    = (float)($p['min'] ?? ($p['min_sum'] ?? 0));
  $max    = (float)($p['max'] ?? ($p['max_sum'] ?? 0));
  $vp     = (float)($p['bonus_vp'] ?? 0);
  $lp     = (float)($p['bonus_lp'] ?? 0);
  $evo    = (int)($p['max_evolve'] ?? 0);
  $forms  = (int)($p['forms_total'] ?? 0);
  $type   = (string)($p['type_primary'] ?? '');

  $rar='common';
  try { $rx = vx_rarity_for_tarif($db, $id, $title, $price); $rar = (string)($rx['rarity'] ?? 'common'); } catch(Throwable $e){}
  $rar = function_exists('vx_rarity_normalize') ? vx_rarity_normalize($rar) : strtolower($rar);

  $stars = str_repeat('★', ['common'=>1,'rare'=>2,'epic'=>3,'mythic'=>4,'legendary'=>5][$rar] ?? 1);

  $art = '/assets/img/guardians/placeholder.png';
  if (!empty($p['img'])) $art = (string)$p['img'];

  // Derived value props
  $totalPct = ($speed > 0 && $period > 0) ? ($speed * $period) : 0;
  $earnDay  = ($price > 0 && $speed > 0)  ? ($price * ($speed/100.0)) : 0;
  $earnCyc  = ($price > 0 && $totalPct>0) ? ($price * ($totalPct/100.0)) : 0;

  $depositTxt = '—';
  if ($min > 0 || $max > 0){
    if ($min > 0 && $max > 0) $depositTxt = '$'.number_format($min,2).' – $'.number_format($max,2);
    else if ($min > 0) $depositTxt = 'Min $'.number_format($min,2);
    else $depositTxt = 'Max $'.number_format($max,2);
  }

  $benefits = [];
  if ($vp > 0 || $lp > 0) $benefits[] = gf_num($vp,0).' VP • '.gf_num($lp,0).' LP';
  if ($evo > 0) $benefits[] = 'Evolve ×'.$evo;
  if ($forms > 0) $benefits[] = $forms.' Forms';
  if ($type !== '') $benefits[] = $type.' Type';
  $benefitsTxt = $benefits ? implode(' • ', $benefits) : '—';

  // route (mutants naming) — keep auth gating
  $inspectHref = $isAuth ? '/user/mutants' : $loginUrl;
?>

<article class="gf-gcard" data-rarity="<?= htmlspecialchars($rar, ENT_QUOTES); ?>" role="group" aria-label="<?= htmlspecialchars($title, ENT_QUOTES); ?>">
  <div class="gf-spark" aria-hidden="true"></div>

  <div class="gf-ghead">
    <div class="gf-gname"><?= htmlspecialchars($title, ENT_QUOTES); ?></div>
    <div class="gf-gsubrow">
      <div>🧬 #<?= str_pad((string)$id, 3, '0', STR_PAD_LEFT); ?></div>
      <div class="gf-gstars" aria-label="Rarity stars"><?= $stars; ?></div>
    </div>
  </div>

  <div class="gf-gmedia">
    <?php if ($id === (int)$vx_daily_featured_id): ?>
      <div class="gf-feature-pill">Featured</div>
    <?php endif; ?>
    <img src="<?= htmlspecialchars($art, ENT_QUOTES); ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES); ?>">
    <div class="gf-grarity"><?= strtoupper(htmlspecialchars($rar, ENT_QUOTES)); ?></div>
  </div>

  <div class="gf-gbody">
    <div class="gf-gquick" aria-label="Key highlights">
      <span class="gf-qchip is-a">⚡ <?= ($speed>0 ? gf_num($speed,2).'%' : '—'); ?> / day</span>
      <span class="gf-qchip is-b">🗓️ <?= ($period>0 ? (int)$period.' days' : '—'); ?> cycle</span>
      <span class="gf-qchip is-c">💎 <?= ($totalPct>0 ? gf_num($totalPct,2).'%' : '—'); ?> total</span>
    </div>

    <div class="gf-ginfo" aria-label="Plan details">
      <div class="gf-info">
        <div class="k">Deposit Range</div>
        <div class="v"><?= htmlspecialchars($depositTxt, ENT_QUOTES); ?></div>
      </div>
      <div class="gf-info is-yield">
        <div class="k">Daily Yield</div>
        <div class="v"><?= ($speed>0 ? gf_num($speed,2).'%' : '—'); ?></div>
      </div>
      <div class="gf-info is-total">
        <div class="k">Total / Cycle</div>
        <div class="v"><?= ($totalPct>0 ? gf_num($totalPct,2).'%' : '—'); ?></div>
      </div>
      <div class="gf-info is-earn">
        <div class="k">Est Earn / Day</div>
        <div class="v"><?= ($earnDay>0 ? '$'.number_format($earnDay,2).' USD' : '—'); ?></div>
      </div>
      <div class="gf-info is-earn">
        <div class="k">Est Earn / Cycle</div>
        <div class="v"><?= ($earnCyc>0 ? '$'.number_format($earnCyc,2).' USD' : '—'); ?></div>
      </div>
      <div class="gf-info is-meta">
        <div class="k">Benefits</div>
        <div class="v"><?= htmlspecialchars($benefitsTxt, ENT_QUOTES); ?></div>
      </div>
    </div>

    <div class="gf-gfoot">
      <div class="gf-gprice">
        <div class="k">Price</div>
        <div class="v">💵 $<?= number_format($price, 2); ?> USD</div>
      </div>
      <a class="gf-gbtn gf-cardtap" href="<?= htmlspecialchars($inspectHref, ENT_QUOTES); ?>">
        <?= $isAuth ? 'Inspect' : 'Open'; ?> 🚀
      </a>
    </div>
  </div>
</article>

<?php endforeach; ?>

      </div>
    </div>

    <div class="gf-swipehint" aria-hidden="true">
      <span class="dot"></span>
      <span>👆 Swipe to explore today’s drops</span>
    </div>
  </div>
<?php endif; ?>

<section class="gf-path">
  <h3>🧭 Your Mutant Path</h3>
  <a class="gf-step <?= !$hasMutant?'active':'' ?>" href="/user/mutants"><span>1</span> Unlock your first Mutant ✨</a>
  <a class="gf-step <?= $hasMutant?'active':'' ?>" href="/user/dashboard"><span>2</span> Evolve a Mutant ⚗️</a>
  <a class="gf-step" href="/user/codex"><span>3</span> Discover the Codex 📖</a>
  <a class="gf-step" href="/user/referrals"><span>4</span> Invite & Amplify 🤝</a>
</section>

</div>

<script>
(function(){
  // -------- Countdown (server computed) --------
  var msLeft = <?=(int)$msUntilRefresh?>;
  var pill = document.getElementById('gfTimerPill');
  var txt  = document.getElementById('gfTimerText');
  var emo  = document.getElementById('gfTimerEmoji');

  function pad(n){ return (n<10?'0':'') + n; }

  function tick(){
    msLeft = Math.max(0, msLeft - 1000);
    var s = Math.floor(msLeft/1000);
    var h = Math.floor(s/3600); s -= h*3600;
    var m = Math.floor(s/60);   s -= m*60;

    txt.textContent = pad(h)+":"+pad(m)+":"+pad(s);

    // last 6h: urgent style + fire emoji
    if (pill){
      if (msLeft <= 6*3600*1000){
        pill.classList.add('urgent');
        if (emo) emo.textContent = "🔥";
      } else {
        pill.classList.remove('urgent');
        if (emo) emo.textContent = "⏳";
      }
    }
  }
  tick();
  setInterval(tick, 1000);

  // -------- Ultra-light optional tap sound --------
  var toggle = document.getElementById('gfSoundToggle');
  var icon = document.getElementById('gfSoundIcon');
  var label = document.getElementById('gfSoundText');
  var soundKey = 'gf_sound_enabled_v1';

  function getEnabled(){
    try { return localStorage.getItem(soundKey) === '1'; } catch(e){ return false; }
  }
  function setEnabled(v){
    try { localStorage.setItem(soundKey, v ? '1' : '0'); } catch(e){}
    renderToggle();
  }
  function renderToggle(){
    var on = getEnabled();
    if (icon) icon.textContent = on ? '🔊' : '🔈';
    if (label) label.textContent = on ? 'Tap Sound On' : 'Tap Sound Off';
  }

  // tiny blip via WebAudio (no files)
  var audioCtx = null;
  function blip(){
    if (!getEnabled()) return;
    try{
      if (!audioCtx){
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        audioCtx = new AC();
      }
      if (audioCtx.state === 'suspended') audioCtx.resume();

      var o = audioCtx.createOscillator();
      var g = audioCtx.createGain();

      o.type = 'sine';
      o.frequency.value = 880; // high, subtle
      g.gain.value = 0.0001;

      o.connect(g);
      g.connect(audioCtx.destination);

      var now = audioCtx.currentTime;
      g.gain.setValueAtTime(0.0001, now);
      g.gain.exponentialRampToValueAtTime(0.025, now + 0.01);
      g.gain.exponentialRampToValueAtTime(0.0001, now + 0.06);

      o.start(now);
      o.stop(now + 0.07);
    } catch(e){}
  }

  function onToggle(){
    setEnabled(!getEnabled());
    blip();
  }

  if (toggle){
    toggle.addEventListener('click', onToggle);
    toggle.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' '){
        e.preventDefault();
        onToggle();
      }
    });
  }
  renderToggle();

  // Play blip on card taps (button + card area)
  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t) return;

    // If user taps inside a card (or the inspect button), play sound
    var card = t.closest ? t.closest('.gf-gcard') : null;
    var btn  = t.closest ? t.closest('.gf-cardtap') : null;

    if (btn || card){
      blip();
    }
  }, {passive:true});
})();
</script>
