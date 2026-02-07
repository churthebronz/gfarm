<?php
// File: /core/rate_limit.php
// Backward-compat shim: older endpoints include core/rate_limit.php,
// while the real implementation lives in core/rate_limiter.php.

if (!defined('FastCore')) { define('FastCore', true); }

require_once __DIR__ . '/rate_limiter.php';

// no closing tag
