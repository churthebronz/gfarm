<?php
// /core/tg_webapp_auth.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'err'=>'method_not_allowed']);
  exit;
}

define('FastCore', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/referrals.php';
require_once __DIR__ . '/vx_app_settings.php';
require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/vx_points.php';

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}


function out(array $arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function tg_log(string $msg): void {
  if (($_GET['debug'] ?? '') !== '1') return;
  $dir = __DIR__ . '/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($dir.'/tg_auth_debug.log', '['.date('c')."] ".$msg."\n", FILE_APPEND);
}

/**
 * Telegram spec:
 * secret = HMAC_SHA256(data=bot_token, key="WebAppData")  (raw bytes)
 * calc   = HMAC_SHA256(data=data_check_string, key=secret)
 */
function tg_verify_initdata(string $initData, string $botToken, bool $dbg=false): array {
  $data = [];
  parse_str($initData, $data);

  if (empty($data['hash'])) return ['ok'=>false,'err'=>'no_hash'];
  $hash = $data['hash'];
  unset($data['hash']);

  ksort($data, SORT_STRING);
  $pairs = [];
  foreach ($data as $k => $v) {
    $pairs[] = $k.'='.$v; // parse_str already urldecoded
  }
  $check = implode("\n", $pairs);

  $secret = hash_hmac('sha256', $botToken, 'WebAppData', true); // data=token, key="WebAppData"
  $calc   = hash_hmac('sha256', $check, $secret);

  if (!hash_equals($hash, $calc)) {
    if ($dbg) tg_log('BAD_HASH fields='.implode(',', array_keys($data)).' check_len='.strlen($check));
    return ['ok'=>false,'err'=>'bad_hash'];
  }

  $auth_date = isset($data['auth_date']) ? (int)$data['auth_date'] : 0;
  if ($auth_date && (time() - $auth_date) > 86400) return ['ok'=>false,'err'=>'stale'];

  if ($dbg) tg_log('OK fields='.implode(',', array_keys($data)).' check_len='.strlen($check));
  return ['ok'=>true,'data'=>$data];
}

// Read body
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$initData = '';
if (is_array($body) && array_key_exists('initData', $body)) {
  $initData = (string)$body['initData'];
} elseif (isset($_POST['initData'])) {
  $initData = (string)$_POST['initData'];
}
if ($initData === '') out(['ok'=>false,'err'=>'no_initdata'], 400);

// DB + token
global $db, $config;
if (!isset($db) || !($db instanceof db)) out(['ok'=>false,'err'=>'db_down'], 500);

$botToken = trim((string)($config->bot_token ?? $config->telegram_token ?? ''));
if ($botToken === '') out(['ok'=>false,'err'=>'no_token'], 500);

$debug = (($_GET['debug'] ?? '') === '1');
if ($debug) tg_log('initData_len='.strlen($initData));

// Verify
$ver = tg_verify_initdata($initData, $botToken, $debug);
if (!$ver['ok']) out($ver, 401);
$data = $ver['data'];

// Extract TG user JSON
$tgUser = [];
if (!empty($data['user'])) {
  $tmp = json_decode((string)$data['user'], true);
  if (is_array($tmp)) $tgUser = $tmp;
}
$tg_id   = isset($tgUser['id']) ? (int)$tgUser['id'] : 0;
if ($tg_id <= 0) out(['ok'=>false,'err'=>'no_tg_user'], 400);

// Start session early so we can safely handle Telegram account switches inside the same WebView.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// If the current WebView still has a PHP session bound to a DIFFERENT Telegram user,
// hard-reset it so the new account can bind cleanly.
$switched = false;
$prevTgId = (int)($_SESSION['tg_id'] ?? 0);
if ($prevTgId > 0 && $prevTgId !== $tg_id) {
  $switched = true;
  $_SESSION = [];

  // Clear app session cookie (tg_sess) so require_tg_session can't keep old identity.
  if (isset($_COOKIE['tg_sess'])) {
    setcookie('tg_sess', '', [
      'expires'  => time() - 3600,
      'path'     => '/',
      'secure'   => true,
      'httponly' => true,
      'samesite' => 'None',
    ]);
  }

  // Rotate PHP session id and drop old session data.
  session_regenerate_id(true);
}

// Always bind the session to the current Telegram user id.
$_SESSION['tg_id'] = $tg_id;

// Optional fields
$tg_username  = (string)($tgUser['username']    ?? '');
$tg_firstname = (string)($tgUser['first_name']  ?? '');
$tg_lastname  = (string)($tgUser['last_name']   ?? '');
$tg_lang      = (string)($tgUser['language_code'] ?? '');
$tg_photo     = (string)($tgUser['photo_url']   ?? '');
$tg_premium   = !empty($tgUser['is_premium']) ? 1 : 0;

// Resolve referrer (Telegram start_param -> ref_code or uid_). Fallback to cookie 'rid'.
// Supported formats:
//  - "<REFCODE>" (matches db_users.ref_code)
//  - "rc_<REFCODE>" (legacy/landing helper)
//  - "uid_<USERID>" (internal)
// Ignore non-ref start params like "webauth", "launch", "test".
$rid = 0;
if (!empty($data['start_param'])) {
  $sp = trim((string)$data['start_param']);
  $sp_l = strtolower($sp);
  if (!in_array($sp_l, ['webauth','launch','test'], true)) {
    if (str_starts_with($sp_l, 'rc_')) {
      $sp = substr($sp, 3);
    }
    if (str_starts_with($sp_l, 'uid_') && ctype_digit(substr($sp, 4))) {
      $rid = (int)substr($sp, 4);
    } else {
      // Prefer ref_code lookup; numeric-only start params are treated as ref_code (not uid) to avoid spoofing.
      $r = $db->query('SELECT id FROM db_users WHERE ref_code = ? LIMIT 1', $sp)->fetchArray();
      if ($r && !empty($r['id'])) $rid = (int)$r['id'];
    }
  }
}
if (!$rid && !empty($_COOKIE['rid']) && ctype_digit((string)$_COOKIE['rid'])) {
  $rid = (int)$_COOKIE['rid'];
}
// Prevent self-ref after user is resolved (handled later for existing user too).

// Sessions table (idempotent)
$db->query("CREATE TABLE IF NOT EXISTS db_tg_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  token CHAR(64) NOT NULL,
  ip VARBINARY(16) NULL,
  ua VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_token (token),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Upsert user by telegram_id
$now = time();
$user = $db->query('SELECT * FROM db_users WHERE telegram_id = ? LIMIT 1', $tg_id)->fetchArray();

if (!$user) {
  $login = $tg_username ? ('tg_'.$tg_username) : ('tg_'.$tg_id);
  // generate ref_code immediately for new users (for sharing)
  do {
    $code = strtoupper(bin2hex(random_bytes(4)));
    $exists = $db->query('SELECT id FROM db_users WHERE ref_code = ? LIMIT 1', $code)->fetchArray();
  } while ($exists);

  $db->query(
    "INSERT INTO db_users (login, username, email, pass, reg, auth, telegram_id, tg_firstname, tg_lastname, tg_username, tg_lang, tg_photo, tg_is_premium, ref_code, rid, tg_updated_at)
     VALUES (?, ?, '', '0', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    $login, $tg_username, $now, $now, $tg_id, $tg_firstname, $tg_lastname, $tg_username, $tg_lang, $tg_photo, $tg_premium, $code, 0, $now
  );

  $user = $db->query('SELECT * FROM db_users WHERE telegram_id = ? LIMIT 1', $tg_id)->fetchArray();
} else {
  // update tg fields
  $db->query(
    "UPDATE db_users SET username = ?, tg_firstname = ?, tg_lastname = ?, tg_username = ?, tg_lang = ?, tg_photo = ?, tg_is_premium = ?, tg_updated_at = ?
     WHERE id = ?",
    $tg_username, $tg_firstname, $tg_lastname, $tg_username, $tg_lang, $tg_photo, $tg_premium, $now, (int)$user['id']
  );
}

// Create session
$token = bin2hex(random_bytes(32));
$nowDT = date('Y-m-d H:i:s');
$expDT = date('Y-m-d H:i:s', time() + 60*60*24*30);
$ipStr = $_SERVER['REMOTE_ADDR'] ?? '';
$ipBin = $ipStr ? @inet_pton($ipStr) : null;
$uaStr = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$db->query(
  "INSERT INTO db_tg_sessions (user_id, token, ip, ua, created_at, expires_at)
   VALUES (?, ?, ?, ?, ?, ?)",
  (int)$user['id'], $token, $ipBin, $uaStr, $nowDT, $expDT
);

// Bind PHP session + cookie
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION['uid']   = (int)$user['id'];
$_SESSION['login'] = $user['login'] ?? ('tg_'.$tg_id);

// --- Onboarding bonus (first login only) + Season Pass badge
$firstBonus = false;
$bonusPts = 0;
try {
  $bonusEnabled = (bool)vx_app_setting('onboarding_bonus_enabled', true);
  $bonusPts = (int)vx_app_setting('onboarding_bonus_points', 250);
  if ($bonusEnabled && $bonusPts > 0) {
    $already = vx_meta_get($db, (int)$user['id'], 'first_login_bonus');
    if (!$already) {
      if (vx_points_add($db, (int)$user['id'], $bonusPts, 'Onboarding bonus', ['src'=>'tg_webapp_auth'])) {
        vx_meta_set($db, (int)$user['id'], 'first_login_bonus', '1');
        vx_meta_set($db, (int)$user['id'], 'first_login_bonus_pts', (string)$bonusPts);
        vx_meta_set($db, (int)$user['id'], 'first_login_bonus_at', (string)time());
        $firstBonus = true;
      }
    }
  }

  $genEnabled = (bool)vx_app_setting('season pass_badge_enabled', true);
  if ($genEnabled) {
    $g = vx_meta_get($db, (int)$user['id'], 'season pass_badge');
    if (!$g) {
      $label = (string)vx_app_setting('season pass_badge_label', 'Season Pass Holder');
      vx_meta_set($db, (int)$user['id'], 'season pass_badge', $label);
      vx_meta_set($db, (int)$user['id'], 'season pass_badge_at', (string)time());
    }
  }
} catch (Throwable $e) {
  // never break login
}

// --- Founders window: auto-grant Founders badge + Founders Guardian (VP only)
try {
  require_once __DIR__ . '/vx_founders.php';
  vx_founders_on_login($db, (int)$user['id'], time());
} catch (Throwable $e) {
  // never break login
}

// Apply referral anti-abuse guard (first-touch lock, rate limits, no self-ref, etc.)
// NOTE: Telegram's initData start_param is not always present.
// When a user enters the WebApp via our bot WebApp button using a URL like:
//   https://greenfarm.lol/?startapp=ref_<CODE>
// the payload is captured server-side into the vx_start_param cookie/session.
// Use that as a fallback so referral attribution works consistently.
require_once __DIR__ . '/referral_guard.php';

$startParam = (string)($data['start_param'] ?? '');
if ($startParam === '') {
  $startParam = (string)($_SESSION['vx_start_param'] ?? ($_COOKIE['vx_start_param'] ?? ''));
}
// Normalize common prefix used in Telegram /start deep links.
if ($startParam !== '' && strncasecmp($startParam, 'ref_', 4) === 0) {
  $startParam = substr($startParam, 4);
}
try {
  [$rok, $rreason, $rrid] = vx_apply_referral_guard($db, (int)$user['id'], $startParam);
  vx_log_event($db, (int)$user['id'], 'ref_apply', ['ok'=>$rok,'reason'=>$rreason,'rid'=>$rrid,'start_param'=>$startParam]);
} catch (Throwable $e) {
  // never break login for referral issues
}

setcookie('tg_sess', $token, [
  'expires'  => time() + 60*60*24*30,
  'path'     => '/',
  'secure'   => true,
  'httponly' => true,
  'samesite' => 'None',
]);

out([
  'ok' => true,
  'switched' => $switched,
  'tg_id' => $tg_id,
  'uid' => (int)($user['id'] ?? 0),
  'first_login_bonus' => $firstBonus,
  'first_login_bonus_points' => $firstBonus ? $bonusPts : 0,
]);
