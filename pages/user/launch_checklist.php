<?php
declare(strict_types=1);
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_helpers.php';
require_once __DIR__ . '/../../core/seasons.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  require_once __DIR__ . '/../../api/require_tg_session.php';
  $uid = (int)($GLOBALS['UID'] ?? 0);
}
if ($uid <= 0) { header('Location: /'); exit; }

// Admin gate: uid==1 OR role>=9
$isAdmin = ($uid === 1);
try {
  $u = $db->query('SELECT id, role, login FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
  $role = (int)($u['role'] ?? 0);
  if ($role >= 9) $isAdmin = true;
} catch (Throwable $e) { /* ignore */ }

if (!$isAdmin) { header('Location: /user'); exit; }

$opt['title'] = 'Launch Checklist';

function vx_mask(string $s, int $keep=4): string {
  $s = trim($s);
  if ($s === '') return '';
  if (strlen($s) <= $keep) return str_repeat('•', strlen($s));
  return str_repeat('•', max(0, strlen($s)-$keep)) . substr($s, -$keep);
}

function vx_check(bool $ok, string $okText='OK', string $badText='FIX'): string {
  $cls = $ok ? 'ok' : 'bad';
  $txt = $ok ? $okText : $badText;
  return '<span class="vxchk '.$cls.'">'.$txt.'</span>';
}

// HTTPS check
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
  || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Bot token (best effort: common config places)

$botToken = '';
// Prefer project helper if present (reads getenv('TG_BOT_TOKEN') or config)
if (function_exists('vx_get_bot_token')) {
  try { $botToken = (string)vx_get_bot_token(); } catch (Throwable $e) { $botToken = ''; }
}
if ($botToken === '') {
  foreach (['TG_BOT_TOKEN','BOT_TOKEN','TELEGRAM_BOT_TOKEN'] as $k) {
    $v = getenv($k);
    if ($v !== false && $v !== '') { $botToken = (string)$v; break; }
  }
}
if ($botToken === '' && isset($config) && is_object($config)) {
  foreach (['tg_bot_token','bot_token','telegram_token','telegram_bot_token'] as $k) {
    if (!empty($config->$k)) { $botToken = (string)$config->$k; break; }
  }
}

// PayKassa config (best effort)

$pkMid = '';
$pkKey = '';
// PayKassa config (best effort). Your build commonly uses:
// - $config->pkm_id (merchant id)
// - $config->pka_pass (API password/key)
// - $config->pka_id (API id)
if (isset($config) && is_object($config)) {
  foreach (['pkm_id','paykassa_merchant_id','paykassa_mid','paykassa_id','merchant_id'] as $k) {
    if (!empty($config->$k)) { $pkMid = (string)$config->$k; break; }
  }
  foreach (['pka_pass','paykassa_password','paykassa_key','paykassa_secret','merchant_password'] as $k) {
    if (!empty($config->$k)) { $pkKey = (string)$config->$k; break; }
  }
}


$pkMissing = [];
if ($pkMid === '') $pkMissing[] = 'MID';
if ($pkKey === '') $pkMissing[] = 'KEY';

$seasonsEnsureErr = '';
$seasonErr = '';

// Seasons tables present?
// Ensure seasons schema exists before we check (otherwise this checklist shows FIX even though
// seasons.php would auto-create on first use).
try {
  if (function_exists('vx_seasons_ensure')) {
    vx_seasons_ensure($db);
    if (!empty($db->last_error)) { $seasonsEnsureErr = (string)$db->last_error; }
  }
} catch (Throwable $e) { $seasonsEnsureErr = $e->getMessage(); }

$hasPhases = vx_table_exists($db, 'vx_seasons');
$hasCaps    = vx_table_exists($db, 'vx_season_caps');

// Active season exists?

$season = [];
try {
  $season = vx_get_current_season($db);
  if (!empty($db->last_error)) { $seasonErr = (string)$db->last_error; }
} catch (Throwable $e) {
  $seasonErr = $e->getMessage();
  $season = [];
}
$hasActivePhase = is_array($season) && !empty($season['id'] ?? null);

// Re-check after attempting to create season (vx_get_current_season() creates tables + seeds caps).
$hasPhases = vx_table_exists($db, 'vx_seasons');
$hasCaps    = vx_table_exists($db, 'vx_season_caps');



// Caps seeded for active season?
$hasSeededCaps = false;
if ($hasActivePhase) {
  try {
    $row = $db->query('SELECT COUNT(*) AS c FROM vx_season_caps WHERE season_id = ? LIMIT 1', (int)$season['id'])->fetchArray();
    $hasSeededCaps = ((int)($row['c'] ?? 0)) > 0;
  } catch (Throwable $e) { $hasSeededCaps = false; }
}

if ($hasActivePhase && $hasCaps) {
  try {
    $row = $db->query('SELECT COUNT(*) AS c FROM vx_season_caps WHERE season_id=?', (int)$season['id'])->fetchArray();
    $hasSeededCaps = ((int)($row['c'] ?? 0) > 0);
  } catch (Throwable $e) { $hasSeededCaps = false; }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= htmlspecialchars($opt['title'], ENT_QUOTES); ?></title>
  <style>
    body{background:#0b0f1a;color:#e7ecff;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:18px;}
    .card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);border-radius:16px;padding:16px;max-width:920px;margin:0 auto;}
    h1{margin:0 0 10px 0;font-size:22px;}
    .sub{opacity:.8;margin:0 0 14px 0;}
    .row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
    .item{flex:1 1 280px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px;}
    .k{font-weight:700;margin-bottom:6px;}
    .v{opacity:.9}
    .hint{margin-top:6px;opacity:.72;font-size:12px;line-height:1.35}
    .vxchk{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:800;font-size:12px;letter-spacing:.4px}
    .vxchk.ok{background:rgba(34,197,94,.18);border:1px solid rgba(34,197,94,.35);color:#7CFFB2}
    .vxchk.bad{background:rgba(239,68,68,.18);border:1px solid rgba(239,68,68,.35);color:#ff9aa6}
    .btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    a.btn{display:inline-block;text-decoration:none;color:#0b0f1a;background:#ffd24d;padding:10px 14px;border-radius:12px;font-weight:800}
    a.btn.alt{background:rgba(255,255,255,.10);color:#e7ecff;border:1px solid rgba(255,255,255,.14)}
    code{background:rgba(255,255,255,.08);padding:2px 6px;border-radius:8px}
  </style>
</head>
<body>
  <div class="card">
    <h1>Launch Checklist</h1>
    <p class="sub">Fix anything marked <strong>FIX</strong> before you go live.</p>

    <div class="row">
      <div class="item">
        <div class="k">HTTPS</div>
        <div class="v"><?= vx_check($isHttps); ?> <span style="opacity:.85">Mini Apps should run on HTTPS (secure cookies).</span></div>
      </div>

      <div class="item">
        <div class="k">Telegram Bot Token</div>
        <div class="v"><?= vx_check($botToken !== ''); ?> <code><?= htmlspecialchars(vx_mask($botToken), ENT_QUOTES); ?></code></div>
      </div>

      <div class="item">
        <div class="k">PayKassa Credentials</div>
        <div class="v">
          <?= vx_check($pkMid !== '' && $pkKey !== ''); ?>
          <?php if ($pkMid !== '' && $pkKey !== ''): ?>
            MID <code><?= htmlspecialchars(vx_mask($pkMid), ENT_QUOTES); ?></code> / KEY <code><?= htmlspecialchars(vx_mask($pkKey), ENT_QUOTES); ?></code>
          <?php else: ?>
            Missing: <code><?= htmlspecialchars(implode(' / ', $pkMissing), ENT_QUOTES, 'UTF-8'); ?></code>
            <div class="hint">Set PayKassa MID/KEY in <code>/core/config.php</code> (or env vars) and reload.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="item">
        <div class="k">Seasons Tables</div>
        <div class="v">
          <?= vx_check($hasPhases && $hasCaps); ?>
          <?php if ($hasPhases && $hasCaps): ?>
            <span style="opacity:.85">Tables present.</span>
          <?php else: ?>
            <span style="opacity:.85">Needs <code>vx_seasons</code> and <code>vx_season_caps</code>.</span>
            <?php if (!empty($seasonsEnsureErr)): ?>
              <div class="hint"><?= htmlspecialchars($seasonsEnsureErr, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="item">
        <div class="k">Active Season</div>
        <div class="v">
          <?= vx_check($hasActivePhase); ?>
          <span style="opacity:.85">Auto-created if missing (if seasons.php is loaded).</span>
          <?php if (!$hasActivePhase && !empty($seasonErr)): ?>
            <div class="hint"><?= htmlspecialchars($seasonErr, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="item">
        <div class="k">Caps Seeded</div>
        <div class="v"><?= vx_check($hasSeededCaps); ?> <span style="opacity:.85">Ensure your top vault is capped (e.g. 4 slots).</span></div>
      </div>
    </div>

    <div class="btns">
      <a class="btn" href="/user/plans">Open Farms</a>
      <a class="btn alt" href="/user/seasons">Season History</a>
      <a class="btn alt" href="/user">Dashboard</a>
    </div>
  </div>
</body>
</html>
