<?php
// Legacy shim for old code paths that do require('core/db.php')
if (!defined('FastCore')) define('FastCore', true);

// Load normalized config (idempotent; on re-entry it guarantees $db below)
require_once __DIR__ . '/config.php';

// Ensure db class is available even if config returned early due to guard
if (!class_exists('db')) {
    require_once __DIR__ . '/classes/db.php';
}

// Ensure $db exists for callers that expect it after including this file
if (!isset($db) || !($db instanceof db)) {
    if (defined('dbHost') && defined('dbUser') && defined('dbPass') && defined('dbName')) {
        $db = new db(dbHost, dbUser, dbPass, dbName);
    } else {
        // Failsafe: attempt defaults to avoid fatal
        $db = new db('localhost', 'root', '', '');
    }
}
// no closing tag
