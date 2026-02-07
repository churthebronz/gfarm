<?php
// core/vx_tg_notify.php
// Minimal Telegram push notification helper (fail-soft).

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vx_retention.php';

/**
 * Check if user has opted out of Telegram push.
 */
function vx_tg_push_enabled_for_user($db, int $uid): bool {
  try {
    $glob = (int)vx_app_setting('tg_push_enabled', 1);
    if ($glob !== 1) return false;
    $off = (string)vx_meta_get($db, $uid, 'tg_push_off', '0');
    return $off !== '1';
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Send a Telegram message to a numeric telegram_id.
 */
function vx_tg_send_to_chat(int $chatId, string $text, array $opts = []): bool {
  $token = (string)vx_get_bot_token();
  if ($token === '' || $chatId <= 0) return false;
  $text = trim($text);
  if ($text === '') return false;

  $payload = [
    'chat_id' => $chatId,
    'text' => $text,
    'disable_web_page_preview' => true,
  ];
  if (!empty($opts['parse_mode'])) $payload['parse_mode'] = (string)$opts['parse_mode'];
  if (isset($opts['reply_markup']) && is_array($opts['reply_markup'])) $payload['reply_markup'] = $opts['reply_markup'];

  $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
  try {
    $ctx = stream_context_create([
      'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'timeout' => 4,
      ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return false;
    $j = json_decode($res, true);
    return is_array($j) && !empty($j['ok']);
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Send message to a GreenFarm user (by uid) using db_users.telegram_id.
 */
function vx_tg_send_to_user($db, int $uid, string $text, array $opts = []): bool {
  if (!$db || $uid <= 0) return false;
  if (!vx_tg_push_enabled_for_user($db, $uid)) return false;
  try {
    $r = $db->query('SELECT telegram_id FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
    $chatId = (int)($r['telegram_id'] ?? 0);
    if ($chatId <= 0) return false;
    return vx_tg_send_to_chat($chatId, $text, $opts);
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * One-time Telegram message by unique key.
 */
function vx_tg_send_once($db, int $uid, string $uniqKey, string $text): bool {
  $k = 'tg_once_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $uniqKey);
  $sent = (string)vx_meta_get($db, $uid, $k, '0');
  if ($sent === '1') return true;
  $ok = vx_tg_send_to_user($db, $uid, $text);
  if ($ok) { vx_meta_set($db, $uid, $k, '1'); }
  return $ok;
}
