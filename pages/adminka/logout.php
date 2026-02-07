<?php
// pages/adminka/logout.php
if (!defined('FastCore')) { exit('Opss!'); }

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Remove admin + user session data
unset($_SESSION['admin']);
unset($_SESSION['admin_uid']);
unset($_SESSION['impersonating']);
unset($_SESSION['uid']);
unset($_SESSION['login']);

// Remove CSRF tokens if present
unset($_SESSION['_csrf']);
unset($_SESSION['_csrf_admin']);
unset($_SESSION['_csrf_pay']);

// Kill session cookie properly
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        !empty($params['secure']),
        !empty($params['httponly'])
    );
}

@session_destroy();

// Redirect to main site homepage
header('Location: /', true, 302);
exit;
