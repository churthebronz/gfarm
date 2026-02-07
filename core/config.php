<?php
// File: /public_html/core/config.php
// GreenFarm config loader (prod-safe, idempotent, PHP 7+ compatible)

if (!defined('FastCore')) { define('FastCore', true); }

if (!class_exists('config')) {
    class config {
        public $start_time = '1748752621';
        public $sitename   = 'GreenFarm';
        public $siteurl    = 'greenfarm.lol';
        public $email      = 'support@greenfarm.lol';

        public $telegram = '@greenfarmboss';
        public $telegram_group = 'greenfarmofficial';

        // Telegram bot username (without @). Used for deep links + WebApp auth launch.
        public $telegram_bot   = 'greenfarmappbot';
        public $telegram_token = '8501525972:AAEB-Kz5XqxMilS6W8UQJhHhroHUxZaHkb0';
        public $bot_token      = '8501525972:AAEB-Kz5XqxMilS6W8UQJhHhroHUxZaHkb0';

        // Telegram webhook secret token (recommended if using /api/tg/webhook.php)
        public $tg_webhook_secret = 'e70a37e5b9cd8a3d92adc922dbcd5da23fc6e48141d359d59d619c9acfd32908';

        public $bonus_tg  = '5';
        public $bonus     = '0';
        public $valuta    = 'USD';
        public $percent   = '0';

        public $adm_dir  = 'adminka';
        public $adm_name = 'Maidz';
        public $adm_pass = '';

        public $pkm_id   = '28159';
        public $pkm_pass = '';
        public $pka_id   = '30748';
        public $pka_pass = '';

        public $db_host = '';
        public $db_user = '';
        public $db_pass = '';
        public $db_name = '';

        public $env = 'prod';
        public $is_prod = true;

        public $asset_ver = '';
    }
}

if (defined('CONFIG_ALREADY_LOADED')) {
    if (!class_exists('db')) { require_once __DIR__ . '/classes/db.php'; }

    if (!isset($GLOBALS['config']) || !is_object($GLOBALS['config'])) {
        $GLOBALS['config'] = new config();
    }

    if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof db)) {
        if (defined('dbHost') && defined('dbUser') && defined('dbPass') && defined('dbName')) {
            $GLOBALS['db'] = new db(dbHost, dbUser, dbPass, dbName);
        }
    }
    return;
}
define('CONFIG_ALREADY_LOADED', 1);

if (!isset($GLOBALS['config']) || !($GLOBALS['config'] instanceof config)) {
    $GLOBALS['config'] = new config();
}
$config = $GLOBALS['config'];

if (!defined('dbHost')) define('dbHost', 'localhost');
if (!defined('dbUser')) define('dbUser', 'vaulbmln_core');
if (!defined('dbPass')) define('dbPass', 'kQhx{Ved6hH6');
if (!defined('dbName')) define('dbName', 'vaulbmln_core');

$__boot = __DIR__ . '/config_boot.php';
if (is_file($__boot)) {
    require_once $__boot;
}

if (!property_exists($config, 'env') || $config->env === null || $config->env === '') {
    $config->env = (string)(getenv('GREENFARM_ENV') ?: 'prod');
}
$config->is_prod = (strtolower((string)$config->env) !== 'dev');

if ($config->is_prod) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    @ini_set('error_log', $logDir . '/php-error.log');

    error_reporting(E_ALL);
} else {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

if (!property_exists($config, 'asset_ver') || !$config->asset_ver) {
    $config->asset_ver = (string)@filemtime(__FILE__);
}

if (!function_exists('vx_log_exception')) {
    function vx_log_exception(Throwable $e): void {
        $msg = '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() .
               ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
        error_log($msg);
    }
}
if (!function_exists('vx_exception_handler')) {
    function vx_exception_handler(Throwable $e): void {
        vx_log_exception($e);
        http_response_code(500);
        if (headers_sent()) { return; }
        $isProd = (isset($GLOBALS['config']) && is_object($GLOBALS['config']) && !empty($GLOBALS['config']->is_prod));
        if ($isProd) {
            echo 'Something went wrong.';
        } else {
            echo '<pre style="white-space:pre-wrap">' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</pre>';
        }
    }
}
set_exception_handler('vx_exception_handler');

// Token source of truth: this config file (do not override from env)
if (!empty($config->telegram_token) && empty($config->bot_token)) {
    $config->bot_token = $config->telegram_token;
}

$config->telegram_bot   = ltrim((string)$config->telegram_bot, '@');
$config->bot_token      = trim((string)$config->bot_token);
$config->telegram_token = trim((string)$config->telegram_token);

// ---- Webhook + secret aliases (so admin UI can read them consistently) ----
try {
    // Secret token aliases
    if (property_exists($config, 'tg_webhook_secret') && !empty($config->tg_webhook_secret)) {
        if (!property_exists($config, 'telegram_secret') || empty($config->telegram_secret)) {
            $config->telegram_secret = (string)$config->tg_webhook_secret;
        }
        if (!property_exists($config, 'tg_secret') || empty($config->tg_secret)) {
            $config->tg_secret = (string)$config->tg_webhook_secret;
        }
    }

    // Webhook URL (derive if not stored)
    $hasUrl = (property_exists($config, 'telegram_webhook_url') && !empty($config->telegram_webhook_url))
           || (property_exists($config, 'tg_webhook_url') && !empty($config->tg_webhook_url))
           || (property_exists($config, 'webhook_url') && !empty($config->webhook_url));
    if (!$hasUrl) {
        $host = '';
        if (!empty($_SERVER['HTTP_HOST'])) $host = (string)$_SERVER['HTTP_HOST'];
        if ($host === '' && !empty($config->siteurl)) $host = (string)$config->siteurl;
        $host = preg_replace('~[^a-z0-9\.:\-]~i', '', $host);

        $isHttps = false;
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') $isHttps = true;
        if (!$isHttps && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') $isHttps = true;
        if (!$isHttps && isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) $isHttps = true;

        $scheme = $isHttps ? 'https' : 'http';
        if ($host !== '') {
            $url = $scheme . '://' . $host . '/api/tg/webhook.php';
            $config->telegram_webhook_url = $url;
            $config->tg_webhook_url = $url;
            $config->webhook_url = $url;
        }
    }
} catch (Throwable $e) {
    // ignore - config must never hard-fail
}


if (empty($config->db_host)) $config->db_host = dbHost;
if (empty($config->db_user)) $config->db_user = dbUser;
if (empty($config->db_pass)) $config->db_pass = dbPass;
if (empty($config->db_name)) $config->db_name = dbName;

require_once __DIR__ . '/classes/db.php';
if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof db)) {
    $GLOBALS['db'] = new db($config->db_host, $config->db_user, $config->db_pass, $config->db_name);
}
$db = $GLOBALS['db'];

// GreenFarm: ensure seasons tables + active season exist (launch-checklist hard requirement)
// Safe on PHP 7.x and cheap once created.
try {
    require_once __DIR__ . '/schema_helpers.php';
    require_once __DIR__ . '/seasons.php';
    if (function_exists('vx_current_season')) {
        vx_current_season($db);
    }
} catch (Throwable $e) {
    // keep app running even if seasons bootstrap fails
}

if (!function_exists('vx_get_bot_token')) {
    function vx_get_bot_token(){
        return (string)(getenv('TG_BOT_TOKEN') ?: ($GLOBALS['config']->bot_token ?? ''));
    }
}

/* Telegram WebApp initData check (PHP 7 compatible: no arrow functions) */
if (!function_exists('tg_check_webapp')) {
    function tg_check_webapp(string $initData, string $botToken): array {
        parse_str($initData, $arr);
        if (!isset($arr['hash'])) return ['ok'=>false,'error'=>'missing_hash'];

        $hash = $arr['hash'];
        unset($arr['hash']);
        ksort($arr);

        $pairs = [];
        foreach ($arr as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $data_check_string = implode("\n", $pairs);

        $secret_key = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calc = bin2hex(hash_hmac('sha256', $data_check_string, $secret_key, true));

        if (!hash_equals($calc, $hash)) return ['ok'=>false,'error'=>'bad_signature'];

        $user = json_decode($arr['user'] ?? 'null', true);
        if (!$user || !isset($user['id'])) return ['ok'=>false,'error'=>'no_user'];

        return [
            'ok' => true,
            'user' => $user,
            'query_id' => $arr['query_id'] ?? null,
            'auth_date' => intval($arr['auth_date'] ?? 0)
        ];
    }
}


// Auto-ensure launch-critical tables (fail-soft)
try { if (isset($GLOBALS['db'])) { vx_schema_ensure($GLOBALS['db']); } } catch (Throwable $e) {}
