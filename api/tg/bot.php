<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/config.php';

// Optional secret token validation (recommended)
$secret = (string)(getenv('TG_WEBHOOK_SECRET') ?: ($GLOBALS['config']->tg_webhook_secret ?? ''));
if ($secret !== '') {
  $hdr = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
  if (!hash_equals($secret, $hdr)) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
  }
}

$raw = file_get_contents('php://input');
$upd = json_decode($raw ?: 'null', true);
if (!is_array($upd)) {
  echo json_encode(['ok' => true]);
  exit;
}

// 1) Delegate Telegram Stars payments to the existing handler
if (!empty($upd['message']['successful_payment'])) {
  // webhook.php is already idempotent and safe; it exits with JSON
  require __DIR__ . '/webhook.php';
  exit;
}

// 2) Handle /start payloads
$msg = $upd['message'] ?? $upd['edited_message'] ?? null;
if (!is_array($msg)) {
  echo json_encode(['ok' => true]);
  exit;
}

$text = trim((string)($msg['text'] ?? ''));
$chat_id = (int)($msg['chat']['id'] ?? 0);
if ($chat_id <= 0) {
  echo json_encode(['ok' => true]);
  exit;
}

// Parse "/start" payload
$parts = preg_split('~\s+~', $text, 2);
$cmdRaw = strtolower(trim((string)($parts[0] ?? '')));
$payload = trim((string)($parts[1] ?? ''));

// normalize /start@BotName
if (preg_match('~^/start(@[A-Za-z0-9_]+)?$~i', $cmdRaw)) {
  $cmdRaw = '/start';
}

if ($cmdRaw !== '/start') {
  echo json_encode(['ok' => true]);
  exit;
}

// If no payload, give a generic open button
$base = 'https://greenfarm.lol/';
$url = $base;
if ($payload !== '') {
  // Accept both "ref_CODE" and plain CODE
  $url = $base . '?startapp=' . rawurlencode($payload);
}

$bot_token = (string)($GLOBALS['config']->bot_token ?? $GLOBALS['config']->telegram_token ?? '');
if ($bot_token === '') {
  // fail-soft: avoid Telegram retries
  echo json_encode(['ok' => true]);
  exit;
}

// Send a WebApp button
$api = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
$reply_markup = [
  'inline_keyboard' => [[
    [
      'text' => 'Open GreenFarm',
      'web_app' => ['url' => $url]
    ]
  ]]
];

$params = [
  'chat_id' => $chat_id,
  // Polished copy: show a conversion-focused message when the user arrives via a referral payload.
  'text' => (
    ($payload !== '')
      ? "🎉 You’ve been invited to GreenFarm\n\nA Founders Guardian is waiting for you."
      : "✨ GreenFarm is ready\n\nTap below to enter the app."
  ),
  'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_SLASHES)
];

$opts = [
  'http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query($params, '', '&'),
    'timeout' => 8,
  ]
];

@file_get_contents($api, false, stream_context_create($opts));

echo json_encode(['ok' => true]);
