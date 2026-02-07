<?php
declare(strict_types=1);

/**
 * FastCore v0.8 – Safe index bootstrap
 */

/* ---------- PHP 7 compatibility ---------- */
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ($needle === '') return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}
/* ---------------------------------------- */

define('GenTime', microtime(true));
$CSP_NONCE = base64_encode(random_bytes(16));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
ob_start();

if (!defined('FastCore')) {
    define('FastCore', true);
}

if (empty($_COOKIE['rsite'])) {
    $http_referer = $_SERVER['HTTP_REFERER'] ?? '';
    setcookie('rsite', $http_referer, time() + 1209600, '/');
}

$opt = ['title' => '', 'description' => ''];

/* ---------------- CORE ---------------- */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/session_bridge.php';
require_once __DIR__ . '/core/rate_limiter.php';
require_once __DIR__ . '/core/error_boundary.php';

global $db, $config;

if (!isset($db) || !($db instanceof db)) {
    require_once __DIR__ . '/core/classes/db.php';
    $db = new db(
        $config->db_host ?? 'localhost',
        $config->db_user ?? '',
        $config->db_pass ?? '',
        $config->db_name ?? ''
    );
}

spl_autoload_register(static function (string $c): void {
    $p = __DIR__ . '/core/' . $c . '.php';
    if (is_file($p)) require $p;
});

/* ---------------- ROUTING ---------------- */

$adm = $config->adm_dir ?? 'adminka';
$tgCallbackUrl = 'telegram-callback';

require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/core/router.php';

$pg = new Router();
$routed_file = $pg->classname ?: 'home.php';

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$bucket = (strpos($uri, '/api/') === 0) ? 'api' : 'page';
vx_rate_limit_or_429($bucket, $bucket === 'api' ? 120 : 240, 60, vx_is_api_request());

$uid  = (int)($_SESSION['uid'] ?? 0);
$seg0 = $pg->segment[0] ?? '';

/* ---------------- DISPATCHER ---------------- */

$head = __DIR__ . '/inc/head.php';
$foot = __DIR__ . '/inc/foot.php';

if ($seg0 === 'user') {

    if ($uid <= 0) {
        header('Location: /auth', true, 302);
        exit;
    }

    if (is_file($head)) include $head;
    echo '<div class="content"><div class="content-user p-1 px-lg-2">';

    $userPage = __DIR__ . '/pages/user/' . ltrim($routed_file, '/');
    if (is_file($userPage)) {
        vx_with_error_boundary('User page error', function () use ($userPage) {
            include $userPage;
        });
    } else {
        include __DIR__ . '/inc/404.php';
    }

    echo '</div></div>';
    if (is_file($foot)) include $foot;

} elseif ($seg0 === ($adm ?? 'adminka')) {

    if (!empty($_SESSION['admin'])) {
        require __DIR__ . "/pages/$adm/inc/head.php";
        require __DIR__ . "/pages/$adm/inc/menu.php";
        $p = __DIR__ . "/pages/$adm/" . ltrim($routed_file, '/');
        require is_file($p) ? $p : __DIR__ . '/pages/404.php';
        require __DIR__ . "/pages/$adm/inc/foot.php";
    } else {
        require __DIR__ . "/pages/$adm/inc/head.php";
        require __DIR__ . "/pages/$adm/login.php";
    }

} elseif ($seg0 === $tgCallbackUrl) {

    $cb = __DIR__ . "/pages/$tgCallbackUrl.php";
    require is_file($cb) ? $cb : __DIR__ . '/inc/404.php';

} else {

    if (is_file($head)) include $head;

    $page = __DIR__ . '/pages/' . ltrim($routed_file, '/');
    $target = is_file($page) ? $page : __DIR__ . '/inc/404.php';

    vx_with_error_boundary('Page error', function () use ($target) {
        include $target;
    });

    if (is_file($foot)) include $foot;
}

/* ---------------- OUTPUT ---------------- */

$content = ob_get_clean();
$content = str_replace('{!TITLE!}', $opt['title'], $content);
$content = str_replace('{!DESCRIPTION!}', $opt['description'], $content);
$content = str_replace('{!GEN_PAGE!}', sprintf('%.5f', microtime(true) - GenTime), $content);
$content = str_replace('{!VAL!}', $config->valuta ?? 'USD', $content);

echo $content;
