<?php
// pages/adminka/health.php
declare(strict_types=1);
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/vx_ref_boost.php';

global $db, $config, $opt;

$opt['title'] = 'Health';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (function_exists('vx_schema_ensure')) { vx_schema_ensure($db); }

$checks = [];
$ok = true;

$add = function(string $name, bool $pass, string $detail = '') use (&$checks, &$ok) {
  $checks[] = ['name'=>$name, 'pass'=>$pass, 'detail'=>$detail];
  if (!$pass) $ok = false;
};

$warn = function(string $name, bool $pass, string $detail = '') use (&$checks) {
  // Warn-only row: does NOT affect overall OK status (shared hosts often block loopback HTTP).
  $checks[] = ['name'=>$name, 'pass'=>$pass, 'detail'=>$detail];
};

$env = (string)(getenv('GREENFARM_ENV') ?: 'prod');
$now = time();

/* -------------------------------------------
   Helpers
--------------------------------------------*/
function has_func(string $fn): bool { return function_exists($fn); }

function vx_http_get_json(string $url, int $timeout = 8): array {
  // Always return diagnostics fields so health can explain failures.
  $out = ['ok'=>false,'_http_code'=>0,'_raw'=>'','_is_json'=>false,'_url'=>$url];

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'GreenFarmHealth/1.0',
      CURLOPT_HEADER => false,
    ]);
    $body = (string)curl_exec($ch);
    $errno = (int)curl_errno($ch);
    $err = (string)curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $out['_http_code'] = $code;
    $out['_raw'] = $body;

    if ($errno !== 0) {
      $out['err'] = "curl:$errno:$err";
      return $out;
    }

    $json = json_decode($body, true);
    if (is_array($json)) {
      $json['_http_code'] = $code;
      $json['_raw'] = $body;
      $json['_is_json'] = true;
      return $json;
    }

    $out['err'] = 'bad_json';
    return $out;
  }

  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => $timeout,
      'header' => "User-Agent: GreenFarmHealth/1.0\r\n",
    ]
  ]);
  $body = @file_get_contents($url, false, $ctx);
  $out['_raw'] = (string)($body ?: '');

  if ($body === false) {
    $out['err'] = 'fopen_failed';
    return $out;
  }

  $json = json_decode((string)$body, true);
  if (is_array($json)) {
    $json['_raw'] = (string)$body;
    $json['_is_json'] = true;
    return $json;
  }

  $out['err'] = 'bad_json';
  return $out;
}

function vx_snip(string $s, int $n = 160): string {
  $s = trim(preg_replace('/\s+/', ' ', $s));
  if ($s === '') return '';
  if (strlen($s) <= $n) return $s;
  return substr($s, 0, $n) . '…';
}

function vx_is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
  if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
    $v = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
    if (is_array($v) && (($v['scheme'] ?? '') === 'https')) return true;
  }
  return false;
}

function vx_fmt_eta(int $sec): string {
  if ($sec < 0) $sec = 0;
  $d = intdiv($sec, 86400); $sec %= 86400;
  $h = intdiv($sec, 3600);  $sec %= 3600;
  $m = intdiv($sec, 60);    $sec %= 60;
  return ($d>0?($d.'d '):'') . sprintf('%02dh %02dm %02ds', $h, $m, $sec);
}

function vx_base_url(): string {
  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  $scheme = vx_is_https() ? 'https' : 'http';
  return $scheme.'://'.$host;
}

/* -------------------------------------------
   Core environment / runtime checks
--------------------------------------------*/
$https = vx_is_https();
$add('PHP version', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);
$add('HTTPS', $https, $https ? 'on' : 'off (cookies may break in TG)');

$add('ext: json', extension_loaded('json'), extension_loaded('json') ? 'loaded' : 'missing');
$add('ext: openssl', extension_loaded('openssl'), extension_loaded('openssl') ? 'loaded' : 'missing');
$add('ext: curl', extension_loaded('curl'), extension_loaded('curl') ? 'loaded' : 'missing (TG API checks may skip)');

$cookieDomain   = (string)ini_get('session.cookie_domain');
$cookieSecure   = (string)ini_get('session.cookie_secure');
$cookieSamesite = (string)ini_get('session.cookie_samesite');

$cookieSecureOk = ($cookieSecure === '1' || $cookieSecure === 'On' || $cookieSecure === 'true');
$cookieSecureDetail = 'php.ini='.$cookieSecure;
if ($https && !$cookieSecureOk) {
  $cookieSecureDetail .= ' • fix: set session.cookie_secure=1 (MultiPHP INI or .htaccess php_value)';
}
$add('Session cookie_secure', (!$https) ? true : $cookieSecureOk, $cookieSecureDetail);

$ssLower = strtolower($cookieSamesite);
$add(
  'Session cookie_samesite',
  ($cookieSamesite === '' || in_array($ssLower, ['lax','none','strict'], true)),
  'php.ini='.$cookieSamesite.($cookieSamesite===''?' • recommend None for TG WebView':'' )
);

$add('Session cookie_domain', true, $cookieDomain !== '' ? $cookieDomain : '(default)');

/* -------------------------------------------
   Session persistence test (killer test #1)
--------------------------------------------*/
try {
  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $prev = (int)($_SESSION['vx_health_ping'] ?? 0);
  $_SESSION['vx_health_ping'] = $now;
  // if session was already active, prev should exist on refresh
  $detail = $prev > 0 ? ('persist ok (prev '.date('H:i:s', $prev).')') : 'set (refresh once to confirm)';
  // We mark it OK always, but on the *second refresh* it should show prev
  $add('Session persistence', true, $detail);
} catch (Throwable $e) {
  $add('Session persistence', false, 'session error: '.$e->getMessage());
}

/* -------------------------------------------
   Current session identity (server-side)
--------------------------------------------*/
$sessUid = (int)($_SESSION['uid'] ?? 0);
$sessTg  = (int)($_SESSION['tg_uid'] ?? 0);
$add('Current session UID', $sessUid > 0, $sessUid > 0 ? ('uid='.$sessUid) : 'not logged in');
$add('Current session tg_id', $sessTg > 0, $sessTg > 0 ? ('tg_id='.$sessTg) : 'not bound');

/* -------------------------------------------
   Telegram (CONFIG FIRST, env only as fallback)
--------------------------------------------*/
$botFromConfig = (string)($config->telegram_token ?? ($config->bot_token ?? ''));
$botFromEnv = (string)(getenv('TG_BOT_TOKEN') ?: '');
$bot = $botFromConfig !== '' ? $botFromConfig : $botFromEnv;

$whs = (string)($config->tg_webhook_secret ?? '');
$whsEnv = (string)(getenv('TG_WEBHOOK_SECRET') ?: '');
$whsFinal = $whs !== '' ? $whs : $whsEnv;
$whsSrc = $whs !== '' ? 'config' : (($whsEnv !== '') ? 'env' : 'missing');

$add('TG bot token', $bot !== '', $bot !== '' ? 'set' : 'missing');
$add('TG_WEBHOOK_SECRET', $whsFinal !== '', $whsFinal !== '' ? ('set (' . $whsSrc . ')') : 'missing');

$tgUsername = '';
$tgBotOk = false;
$tgWebhookUrl = '';
$tgPending = null;
$tgLastErr = '';

if ($bot !== '' && extension_loaded('openssl')) {
  $me = vx_http_get_json('https://api.telegram.org/bot'.$bot.'/getMe', 8);
  $tgBotOk = (bool)($me['ok'] ?? false);
  $tgUsername = $tgBotOk ? ('@'.(string)($me['result']['username'] ?? '')) : '';
  $add('TG getMe', $tgBotOk, $tgBotOk ? ($tgUsername ?: 'OK') : ((string)($me['description'] ?? ($me['err'] ?? 'fail'))));

  $wh = vx_http_get_json('https://api.telegram.org/bot'.$bot.'/getWebhookInfo', 8);
  $whOk = (bool)($wh['ok'] ?? false);
  if ($whOk) {
    $tgWebhookUrl = (string)($wh['result']['url'] ?? '');
    $tgPending = $wh['result']['pending_update_count'] ?? null;
    $tgLastErr = (string)($wh['result']['last_error_message'] ?? '');
  }
  $add('TG webhook URL', $whOk && $tgWebhookUrl !== '', $whOk ? ($tgWebhookUrl ?: 'missing') : ((string)($wh['description'] ?? ($wh['err'] ?? 'fail'))));
  $add('TG pending updates', $whOk, $whOk ? ('pending='.(string)($tgPending ?? 'n/a')) : 'fail');
  $add('TG last error', $whOk && $tgLastErr === '', $whOk ? ($tgLastErr === '' ? 'none' : $tgLastErr) : 'fail');
} else {
  $add('TG getMe', false, 'skipped (missing bot token or openssl)');
  $add('TG webhook URL', false, 'skipped');
  $add('TG pending updates', false, 'skipped');
  $add('TG last error', false, 'skipped');
}

/* -------------------------------------------
   Stars expected amount
--------------------------------------------*/
$expectedXtr = 2500;
try { if (function_exists('vx_season_pass_stars_xtr')) $expectedXtr = (int)vx_season_pass_stars_xtr(); } catch (Throwable $e) {}
$add('Stars price', $expectedXtr === 2500, 'expected 2500 XTR, got '.$expectedXtr);

/* -------------------------------------------
   Paykassa credentials (from config)
--------------------------------------------*/
$pkm_id = (string)($config->pkm_id ?? '');
$pkm_pass = (string)($config->pkm_pass ?? '');
$pka_id = (string)($config->pka_id ?? '');
$pka_pass = (string)($config->pka_pass ?? '');

$add('Paykassa merchant id', $pkm_id !== '' && $pkm_id !== '0', $pkm_id ? 'set' : 'missing');
$add('Paykassa merchant pass', $pkm_pass !== '' && $pkm_pass !== '0', $pkm_pass ? 'set' : 'missing');
$add('Paykassa api id', $pka_id !== '' && $pka_id !== '0', $pka_id ? 'set' : 'missing');
$add('Paykassa api pass', $pka_pass !== '' && $pka_pass !== '0', $pka_pass ? 'set' : 'missing');

/* -------------------------------------------
   DB read + write + schema checks
--------------------------------------------*/
try {
  $r = $db->query("SELECT 1 AS ok")->fetchArray();
  $add('DB read', !empty($r), !empty($r) ? 'ok' : 'failed');
} catch (Throwable $e) {
  $add('DB read', false, 'exception: '.$e->getMessage());
}

try {
  $k = 'health:'.bin2hex(random_bytes(8)).':'.$now;
  $st = vx_idempo_begin($db, $k, 60);
  $db->query("DELETE FROM vx_idempotency WHERE idem_key = ? LIMIT 1", $k);
  $add('DB write', is_array($st) && (bool)($st['ok'] ?? false), 'vx_idempotency insert');
} catch (Throwable $e) {
  $add('DB write', false, 'vx_idempotency write failed: '.$e->getMessage());
}

$tables = [
  'db_users','db_insert','db_payout','db_tarif','vx_idempotency',
  // Telegram auth/session tables
  'db_tg_sessions','db_store',
  'vx_seasons','vx_season_caps','events_log','reward_ledger',
  'vx_season_passes','vx_ref_earnings'
];

foreach ($tables as $t) {
  try {
    $row = $db->query(
      "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
      $t
    )->fetchArray();
    $add('Table '.$t, !empty($row), !empty($row) ? 'OK' : 'missing');
  } catch (Throwable $e) {
    $add('Table '.$t, false, 'exception');
  }
}

/* -------------------------------------------
   Active season check
--------------------------------------------*/
try {
  $row = $db->query("SELECT id, season_no, starts_at, ends_at, is_active FROM vx_seasons WHERE is_active=1 LIMIT 1")->fetchArray();
  if (!empty($row)) {
    $starts = (int)($row['starts_at'] ?? 0);
    $ends = (int)($row['ends_at'] ?? 0);
    $rem = $ends > 0 ? ($ends - $now) : 0;
    $state = ($starts <= $now && $ends >= $now) ? 'live' : 'not-live';
    $add('Active season', true, 'Season '.(string)($row['season_no'] ?? '?').' • '.$state.' • ends in '.vx_fmt_eta($rem));
  } else {
    $add('Active season', false, 'none active');
  }
} catch (Throwable $e) {
  $add('Active season', false, 'query failed: '.$e->getMessage());
}

/* -------------------------------------------
   Referral anti-abuse schema checks
--------------------------------------------*/
$refCols = ['rid_set_at','rid_lock','ref_start_param','ref_ip_hash','ref_ua_hash'];
try {
  foreach ($refCols as $c) {
    $col = $db->query(
      "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='db_users' AND COLUMN_NAME=? LIMIT 1",
      $c
    )->fetchArray();
    $add('db_users.'.$c, !empty($col), !empty($col) ? 'OK' : 'missing (run DB additions)');
  }
} catch (Throwable $e) {
  $add('Referral columns', false, 'exception');
}

/* -------------------------------------------
   Referral debug preview
--------------------------------------------*/
try {
  $rows = [];
  $q = $db->query("SELECT uid, meta, created_at FROM events_log WHERE event='ref_apply' ORDER BY id DESC LIMIT 10");
  while ($r = $q->fetchArray()) { $rows[] = $r; }

  if (!empty($rows)) {
    $lines = [];
    foreach ($rows as $r) {
      $meta = json_decode((string)($r['meta'] ?? ''), true);
      $reason = is_array($meta) ? (string)($meta['reason'] ?? '') : '';
      $rid    = is_array($meta) ? (string)($meta['rid'] ?? '') : '';
      $sp     = is_array($meta) ? (string)($meta['start_param'] ?? '') : '';
      $lines[] = 'uid='.(int)($r['uid'] ?? 0).' rid='.$rid.' reason='.$reason.' start='.$sp.' @'.date('m-d H:i', (int)($r['created_at'] ?? 0));
    }
    $add('Referral debug', true, implode(' | ', $lines));
  } else {
    $add('Referral debug', true, 'no ref_apply events yet');
  }
} catch (Throwable $e) {
  $add('Referral debug', false, 'events_log read failed');
}

/* -------------------------------------------
   Routing / endpoints checks
--------------------------------------------*/
$add('/api/health.php exists', is_file(__DIR__.'/../../api/health.php'), is_file(__DIR__.'/../../api/health.php') ? 'OK' : 'missing');
$add('/api/selftest.php exists', is_file(__DIR__.'/../../api/selftest.php'), is_file(__DIR__.'/../../api/selftest.php') ? 'OK' : 'missing');
$add('/api/auth/telegram.php exists', is_file(__DIR__.'/../../api/auth/telegram.php'), is_file(__DIR__.'/../../api/auth/telegram.php') ? 'OK' : 'missing');

// TG webhook handler should exist (or Telegram can't deliver updates)
$add('/api/tg/webhook.php exists', is_file(__DIR__.'/../../api/tg/webhook.php'), is_file(__DIR__.'/../../api/tg/webhook.php') ? 'OK' : 'missing');

// WebApp init-data verifier should exist (login will break without it)
$add('Function tg_check_webapp', function_exists('tg_check_webapp'), function_exists('tg_check_webapp') ? 'OK' : 'missing');

// Referral boost ladder (controls % of referral points you earn)
$add('Function vx_ref_points_boost_mult', function_exists('vx_ref_points_boost_mult'), function_exists('vx_ref_points_boost_mult') ? 'OK' : 'missing');

// Webhook secret sanity
$secLen = strlen((string)$whsFinal);
$add('TG_WEBHOOK_SECRET length', $secLen >= 32, 'len='.$secLen.($secLen>=32?'':' (too short)'));

// Mini App assets sanity
// If you are running the Mini App as a pure PHP site (Telegram WebApp opens https://greenfarm.lol/),
// you may not have a /webapp folder at all. In that case this is not an error.
// If you DO use /webapp (common for static/SPA builds), make sure /webapp/index.html exists.
$webappDir = __DIR__.'/../../webapp';
$webappHas = is_dir($webappDir);
$webappOk = $webappHas && (is_file($webappDir.'/index.html') || is_file($webappDir.'/index.php'));

$phpHomeOk = is_file(__DIR__.'/../home.php') || is_file(__DIR__.'/../home/index.php') || is_file(__DIR__.'/../index.php');
if ($webappHas) {
  $warn('WebApp assets /webapp', $webappOk, $webappOk ? 'OK' : 'missing (no webapp/index.* found)');
} else {
  // No /webapp dir – treat as OK if the PHP homepage exists.
  $add('WebApp assets /webapp', $phpHomeOk, $phpHomeOk ? 'not used (PHP mini app)' : 'missing (no /webapp and no PHP home detected)');
}

$base = vx_base_url();

try {
  $apiH = vx_http_get_json($base.'/api/health.php', 8);
  $code = (int)($apiH['_http_code'] ?? 0);
  $isJson = (bool)($apiH['_is_json'] ?? false);

  // Your /api/health.php is intentionally ADMIN-ONLY (403 unless logged in as diag_allow_uid).
  // Server-side loopback requests won't carry browser cookies, so 403 is EXPECTED.
  $isExpected403 = ($code === 403) && (strpos((string)($apiH['_raw'] ?? ''), 'forbidden') !== false);

  $pass = ($isJson && (bool)($apiH['ok'] ?? false)) || $isExpected403;

  $detail = 'http='.$code.' • ';
  if ($isExpected403) {
    $detail .= 'protected (403 expected without browser session)';
  } elseif ($pass) {
    $detail .= 'ok';
  } else {
    $detail .= (string)($apiH['err'] ?? ($apiH['description'] ?? 'fail'));
    $raw = (string)($apiH['_raw'] ?? '');
    if ($raw !== '') $detail .= ' • raw: '.vx_snip($raw, 160);
  }

  $warn('Reach /api/health.php', $pass, $detail);
} catch (Throwable $e) {
  $warn('Reach /api/health.php', false, 'exception: '.$e->getMessage().' (warn-only)');
}

try {
  $apiS = vx_http_get_json($base.'/api/selftest.php', 8);
  $code = (int)($apiS['_http_code'] ?? 0);
  $isJson = (bool)($apiS['_is_json'] ?? false);

  $isExpected403 = ($code === 403) && (strpos((string)($apiS['_raw'] ?? ''), 'forbidden') !== false);
  $pass = ($isJson && (bool)($apiS['ok'] ?? false)) || $isExpected403;

  $detail = 'http='.$code.' • ';
  if ($isExpected403) {
    $detail .= 'protected (403 expected without browser session)';
  } elseif ($pass) {
    $detail .= 'ok';
  } else {
    $detail .= (string)($apiS['err'] ?? ($apiS['description'] ?? 'fail'));
    $raw = (string)($apiS['_raw'] ?? '');
    if ($raw !== '') $detail .= ' • raw: '.vx_snip($raw, 160);
  }

  $warn('Reach /api/selftest.php', $pass, $detail);
} catch (Throwable $e) {
  $warn('Reach /api/selftest.php', false, 'exception: '.$e->getMessage().' (warn-only)');
}

$cssPath = __DIR__.'/../../assets/css/style.css';
if (is_file($cssPath)) {
  $readable = is_readable($cssPath);
  $add('Asset readable: style.css', $readable, $readable ? 'OK' : 'not readable (permissions)');
} else {
  $add('Asset readable: style.css', false, 'missing');
}

$allowUid = isset($config->diag_allow_uid) ? (int)$config->diag_allow_uid : 1;
$add('diag_allow_uid', $allowUid > 0, 'uid='.$allowUid);

?>
<style>
.vx-health-wrap{max-width:1120px;margin:0 auto}
.vx-health-card{
  border-radius:22px;
  border:1px solid rgba(148,163,184,.14);
  background:radial-gradient(900px 360px at 12% 0%, rgba(251,191,36,.10), transparent 60%),
             radial-gradient(900px 360px at 98% 0%, rgba(0,255,224,.08), transparent 58%),
             linear-gradient(180deg, rgba(10,16,32,.62), rgba(2,6,23,.52));
  box-shadow:0 18px 45px rgba(0,0,0,.45);
  overflow:hidden;
}
.vx-health-hd{padding:14px 14px 10px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap}
.vx-health-title{font-weight:1200;font-size:1.12rem;margin-top:4px}
.vx-health-sub{opacity:.78;font-size:.9rem;margin-top:2px;line-height:1.4}
.vx-health-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px 14px;border-top:1px solid rgba(148,163,184,.10);flex-wrap:wrap}
.vx-health-k{font-weight:1000}
.vx-health-d{opacity:.75;font-size:.86rem;margin-top:2px}
.vx-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(148,163,184,.14);background:rgba(15,23,42,.45);font-weight:900;font-size:.78rem}
.vx-pill.ok{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.08)}
.vx-pill.bad{border-color:rgba(248,113,113,.22);background:rgba(248,113,113,.08)}
.vx-pill.wait{border-color:rgba(251,191,36,.22);background:rgba(251,191,36,.08)}
.vx-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.vx-actions .vx-btn{border-radius:999px}
.vx-mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono','Courier New', monospace}
</style>

<div class="vx-health-wrap">
  <div class="vx-actions">
    <a class="vx-btn" href="/<?= h($config->adm_dir ?? 'adminka'); ?>/launch-checklist"><i class="fa-solid fa-list-check"></i> Launch checklist</a>
    <a class="vx-btn" href="/<?= h($config->adm_dir ?? 'adminka'); ?>/growth"><i class="fa-solid fa-chart-line"></i> Growth settings</a>
    <a class="vx-btn" href="/api/health.php" target="_blank" rel="noopener"><i class="fa-solid fa-heart-pulse"></i> /api/health.php</a>
    <a class="vx-btn" href="/api/selftest.php" target="_blank" rel="noopener"><i class="fa-solid fa-flask"></i> /api/selftest.php</a>
  </div>

  <div class="vx-health-card">
    <div class="vx-health-hd">
      <div>
        <div class="vx-chip"><i class="fa-solid fa-shield-check"></i> Diagnostics</div>
        <div class="vx-health-title">System health</div>
        <div class="vx-health-sub">
          Environment: <span class="vx-mono"><?= h($env); ?></span>
          • Server time: <span class="vx-mono"><?= date('Y-m-d H:i:s'); ?></span>
        </div>
      </div>
      <span class="vx-pill <?= $ok ? 'ok' : 'bad'; ?>">
        <i class="fa-solid fa-circle"></i> <?= $ok ? 'OK' : 'Issues'; ?>
      </span>
    </div>

    <?php foreach ($checks as $c): ?>
      <div class="vx-health-row">
        <div style="min-width:0">
          <div class="vx-health-k"><?= h($c['name']); ?></div>
          <?php if (!empty($c['detail'])): ?>
            <div class="vx-health-d"><?= h($c['detail']); ?></div>
          <?php endif; ?>
        </div>
        <span class="vx-pill <?= $c['pass'] ? 'ok' : 'bad'; ?>">
          <i class="fa-solid fa-circle"></i> <?= $c['pass'] ? 'OK' : 'FAIL'; ?>
        </span>
      </div>
    <?php endforeach; ?>

    <!-- Multi-account (client-side) -->
    <div class="vx-health-row" id="vx-multiacct-row" data-server-tg="<?= (int)($sessTg ?? 0); ?>" data-server-uid="<?= (int)($sessUid ?? 0); ?>">
      <div style="min-width:0">
        <div class="vx-health-k">Client sessionStorage tg_id</div>
        <div class="vx-health-d">client=<span class="vx-mono" id="vx-client-tg">(reading...)</span> • server=<span class="vx-mono" id="vx-server-tg"><?= (int)($sessTg ?? 0); ?></span></div>
      </div>
      <span class="vx-pill wait" id="vx-client-pill"><i class="fa-solid fa-circle"></i> WAIT</span>
    </div>

    <script>
    (function(){
      try {
        var row = document.getElementById('vx-multiacct-row');
        if (!row) return;
        var serverTg = parseInt(row.getAttribute('data-server-tg')||'0',10)||0;
        var client = 0;
        // try a few keys (we can change these later without breaking)
        var raw = sessionStorage.getItem('vx_tg_id') || sessionStorage.getItem('vx_tg_uid') || sessionStorage.getItem('tg_uid') || '';
        if (raw) client = parseInt(raw,10)||0;
        var el = document.getElementById('vx-client-tg');
        if (el) el.textContent = client ? String(client) : 'none';

        var pill = document.getElementById('vx-client-pill');
        if (!pill) return;

        // Status logic
        if (serverTg <= 0) {
          pill.classList.remove('ok','bad','wait');
          pill.classList.add('bad');
          pill.innerHTML = '<i class="fa-solid fa-circle"></i> FAIL';
          return;
        }

        if (client <= 0) {
          pill.classList.remove('ok','bad','wait');
          pill.classList.add('wait');
          pill.innerHTML = '<i class="fa-solid fa-circle"></i> WAIT';
          return;
        }

        if (client === serverTg) {
          pill.classList.remove('ok','bad','wait');
          pill.classList.add('ok');
          pill.innerHTML = '<i class="fa-solid fa-circle"></i> OK';
        } else {
          pill.classList.remove('ok','bad','wait');
          pill.classList.add('bad');
          pill.innerHTML = '<i class="fa-solid fa-circle"></i> MISMATCH';
        }
      } catch(e){}
    })();
    </script>
  </div>
</div>
