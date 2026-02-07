<?php
declare(strict_types=1);

/**
 * Legacy entrypoint kept for backward compatibility.
 *
 * Some deployments (and older bookmarks) point to /inc/login.php or /webapp/index.html.
 * This file redirects to the canonical auth route while preserving the query string.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '/auth' . ($qs !== '' ? ('?' . $qs) : '');
header('Location: ' . $target, true, 302);
exit;
?>
