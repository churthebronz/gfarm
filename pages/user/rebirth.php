<?php
declare(strict_types=1);
if (!defined('FastCore')) { define('FastCore', true); }

global $db, $opt;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  header('Location: /');
  exit;
}

require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';

$opt['title'] = 'Crossbreed Progression';

$s = function_exists('vx_guardians_settings') ? vx_guardians_settings() : [];
$vpMult = (array)($s['vp_crossbreed_mult'] ?? [1.0,1.15,1.35,1.60,1.90,2.25]);
$lpMult = (array)($s['lp_crossbreed_mult'] ?? [0.0,1.0,1.10,1.25,1.45,1.70]);

$maxLevel = 5;
$currentMax = 0;
$active = 0;
try {
  if ($db) {
    $r = $db->query("SELECT MAX(crossbreed_level) AS m, SUM(status='active') AS a FROM vx_guardian_chains WHERE uid=?", [$uid])->fetchArray();
    $currentMax = (int)($r['m'] ?? 0);
    $active = (int)($r['a'] ?? 0);
  }
} catch (Throwable $e) {}
if ($currentMax < 0) $currentMax = 0;
if ($currentMax > $maxLevel) $currentMax = $maxLevel;

?><div class="vx-page" style="max-width:980px;margin:0 auto;padding:18px 14px 90px;">
  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <div style="font-weight:1100;font-size:1.35rem;">Crossbreed Progression</div>
      <div style="margin-top:6px;color:rgba(226,232,240,.82);line-height:1.45;">
        Crossbreed increases your <strong>VP scaling</strong> and unlocks/boosts <strong>LP</strong>. Reactivate within the 24h window after maturity to level up.
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
      <div class="vx-metric" style="min-width:160px;">
        <div class="k">Active guardians</div>
        <div class="v"><?= number_format($active); ?></div>
      </div>
      <div class="vx-metric" style="min-width:160px;">
        <div class="k">My highest crossbreed</div>
        <div class="v">Level <?= (int)$currentMax; ?></div>
      </div>
    </div>
  </div>

  <div style="margin-top:16px;display:grid;grid-template-columns:1fr;gap:12px;">
    <?php for ($lvl=0; $lvl<= $maxLevel; $lvl++):
      $locked = ($lvl > $currentMax);
      $vp = (float)($vpMult[$lvl] ?? end($vpMult));
      $lp = (float)($lpMult[$lvl] ?? end($lpMult));
      $lpLabel = ($lvl === 0) ? 'LP locked' : ('LP ×'.rtrim(rtrim(number_format($lp,2,'.',''), '0'), '.'));
      $vpLabel = 'VP ×'.rtrim(rtrim(number_format($vp,2,'.',''), '0'), '.');

      // Benefit copy tuned to your game loop
      $benefit = '';
      if ($lvl === 0) $benefit = 'Start here. Earn VP daily while active.';
      if ($lvl === 1) $benefit = 'LP unlocks. Your loop becomes “VP + LP” daily.';
      if ($lvl === 2) $benefit = 'Faster rank climbing. Better compounding into future seasons.';
      if ($lvl === 3) $benefit = 'Serious scaling. Crossbreed discipline starts to matter.';
      if ($lvl === 4) $benefit = 'Elite tier. High VP multiplier + stronger LP boost.';
      if ($lvl === 5) $benefit = 'Max tier. After this, guardians auto-archive on maturity.';
    ?>
      <div style="border:1px solid rgba(255,255,255,.12);border-radius:18px;background:linear-gradient(135deg, rgba(2,6,23,.92), rgba(15,23,42,.86));padding:14px;box-shadow:0 18px 55px rgba(0,0,0,.45);opacity:<?= $locked ? '.55' : '1'; ?>;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <div style="font-weight:1100;font-size:1.05rem;">Level <?= $lvl; ?><?= $lvl === $currentMax ? ' • Current' : ''; ?></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <span style="padding:6px 10px;border-radius:999px;border:1px solid rgba(34,211,238,.22);background:rgba(34,211,238,.08);font-weight:1000;"><?= htmlspecialchars($vpLabel, ENT_QUOTES); ?></span>
            <span style="padding:6px 10px;border-radius:999px;border:1px solid rgba(148,163,184,.18);background:rgba(255,255,255,.05);font-weight:1000;"><?= htmlspecialchars($lpLabel, ENT_QUOTES); ?></span>
          </div>
        </div>
        <div style="margin-top:10px;color:rgba(226,232,240,.84);line-height:1.45;">
          <?= htmlspecialchars($benefit, ENT_QUOTES); ?>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <div style="margin-top:16px;color:rgba(226,232,240,.76);font-size:.92rem;line-height:1.45;">
    Tip: If you miss a crossbreed, the guardian goes into collection mode after the grace window. You can keep playing by activating a new guardian.
  </div>
</div>
