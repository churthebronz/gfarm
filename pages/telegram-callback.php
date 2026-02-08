<?php
// Legacy Telegram callback endpoint is deprecated.
// Canonical Telegram entry points:
// - /api/auth/telegram.php (WebApp auth)
// - /api/tg/bot.php and /api/tg/webhook.php (bot updates/payments)
declare(strict_types=1);
if (!defined('FastCore')) { define('FastCore', true); }

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'deprecated_endpoint',
    'message' => 'Use /api/auth/telegram.php and /api/tg/* endpoints only.'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
