<?php
if (!defined('FastCore')) { exit('Opss!'); }
header('Content-Type: application/json; charset=utf-8');

global $db, $config;

/* ---------- read update ---------- */
$raw    = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!$update || !isset($update['message'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Bad update']);
    exit;
}

$msg     = $update['message'];
$chat_id = (int)($msg['chat']['id'] ?? 0);
$text    = trim((string)($msg['text'] ?? ''));
$from    = (array)($msg['from'] ?? []);
$from_id = (int)($from['id'] ?? 0);

/* ---------- anti replay (60s window) ---------- */
$msgDate = (int)($msg['date'] ?? 0);
if (!$msgDate || (time() - $msgDate) > 60) {
    echo json_encode(['ok'=>true,'result'=>'stale']);
    exit;
}

/* ---------- config ---------- */
$bot_token   = (string)($config->bot_token ?? $config->telegram_token ?? '');
$channelSlug = ltrim((string)($config->telegram ?? ''), '@'); // channel username without @
$bonusUSD    = (float)($config->bonus_tg ?? 0.0);

if ($bot_token === '' || $channelSlug === '') {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Bot token or channel missing']);
    exit;
}

/* ---------- tg api helpers ---------- */
function tg_api(string $method, array $params, string $bot_token) {
    $url  = "https://api.telegram.org/bot{$bot_token}/{$method}";
    $opts = ['http'=>[
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($params, '', '&'),
        'timeout' => 10
    ]];
    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);
    return $res ? json_decode($res, true) : null;
}
function send_text(int $chat_id, string $text, ?array $kb, string $bot_token) {
    $p = ['chat_id'=>$chat_id, 'text'=>$text];
    if ($kb) $p['reply_markup'] = json_encode($kb);
    return tg_api('sendMessage', $p, $bot_token);
}

/* ---------- parse command ---------- */
$parts   = explode(' ', $text, 2);
$cmdRaw  = $parts[0] ?? '';
$payload = $parts[1] ?? '';

/* accept /start and /start@BotName */
$cmd = strtolower(preg_replace('~^/start(@[A-Za-z0-9_]+)?$~i', '/start', $cmdRaw));

/* ---------- SUBSCRIBED FLOW ---------- */
if ($cmdRaw === 'Subscribed' || strtolower($cmdRaw) === '/subscribed') {

    // check membership
    $cm = tg_api('getChatMember', ['chat_id'=>'@'.$channelSlug, 'user_id'=>$from_id], $bot_token);
    $status  = $cm['result']['status'] ?? 'left';
    $is_sub  = in_array($status, ['member','administrator','creator'], true);

    if (!$is_sub) {
        send_text(
            $chat_id,
            "You're not subscribed to the channel, subscribe and click the button again!",
            ['keyboard'=>[[['text'=>'Subscribed']]], 'resize_keyboard'=>true, 'one_time_keyboard'=>true],
            $bot_token
        );
        echo json_encode(['ok'=>true]); exit;
    }

    // find pending claim for this chat
    $row = $db->query(
        'SELECT * FROM db_bonus_tg WHERE tgid = ? AND status = ? ORDER BY id DESC LIMIT 1',
        $chat_id, 0
    )->fetchArray();

    if (!$row) {
        // no pending entry: either already claimed or user never started the flow
        $claimed = $db->query(
            'SELECT id FROM db_bonus_tg WHERE tgid = ? AND status = 1 LIMIT 1',
            $chat_id
        )->numRows();
        if ($claimed) {
            send_text($chat_id, 'You already received your bonus! Bonus can be received 1 time.', null, $bot_token);
        } else {
            send_text($chat_id, 'No pending bonus found. Open the bonus page and press Start again.', null, $bot_token);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    $uid   = (int)$row['uid'];
    $bonus = max(0, $bonusUSD); // don’t allow negative

    // user data (best-effort)
    $user = $db->query('SELECT id, login FROM db_users WHERE id = ? LIMIT 1', $uid)->fetchArray();
    $loginName = $user['login'] ?? ('tg_'.$from_id);
    $tg_username = (string)($from['username'] ?? '');

    // credit
    if ($bonus > 0) {
        $db->query('UPDATE db_users SET money_p = money_p + ?, username = ?, tg_username = ? WHERE id = ?',
                   $bonus, $tg_username, $tg_username, $uid);
    }
    $db->query('UPDATE db_bonus_tg SET login = ?, status = 1, amount = ?, date_add = ? WHERE id = ?',
               $loginName, $bonus, time(), (int)$row['id']);

    send_text($chat_id, 'Bonus '.$bonus.' USD has been credited. You can check it in your account.', null, $bot_token);
    echo json_encode(['ok'=>true]); exit;
}

/* ---------- /start FLOW (with payload) ---------- */
if ($cmd === '/start' && $payload !== '') {
    // expect "<uid>_<md5(uid.secret)>"
    $tmp = explode('_', $payload, 2);
    $id  = (isset($tmp[0]) && ctype_digit($tmp[0])) ? (int)$tmp[0] : 0;
    $sig = $tmp[1] ?? '';

    $secret = 'fvfhtrdcmt45'; // move to $config (recommended)
    $okSig  = ($id > 0 && $sig === md5($id.$secret));

    if (!$okSig) {
        send_text($chat_id, 'Error', null, $bot_token);
        echo json_encode(['ok'=>true]); exit;
    }

    // already credited for this uid+tg?
    $has = $db->query(
        'SELECT id FROM db_bonus_tg WHERE uid = ? AND tgid = ? AND status = 1 LIMIT 1',
        $id, $chat_id
    )->numRows();
    if ($has) {
        send_text($chat_id, 'You already received your bonus! Bonus can be received 1 time.', null, $bot_token);
        echo json_encode(['ok'=>true]); exit;
    }

    // clear non-success for this chat so we only track the latest
    $db->query('DELETE FROM db_bonus_tg WHERE tgid = ? AND status != 1', $chat_id);

    // create pending
    $db->query(
        'INSERT INTO db_bonus_tg (uid, tgid, status, date_add, amount) VALUES (?, ?, 0, ?, 0)',
        $id, $chat_id, time()
    );

    send_text(
        $chat_id,
        "Hello! To get the bonus, subscribe to our channel @{$channelSlug}, then come back and press the button below:",
        ['keyboard'=>[[['text'=>'Subscribed']]], 'resize_keyboard'=>true, 'one_time_keyboard'=>true],
        $bot_token
    );
    echo json_encode(['ok'=>true]); exit;
}

/* ---------- fallbacks ---------- */
if (strtolower($cmdRaw) === '/start') {
    send_text($chat_id, 'Your query was not found. Go to the bonus page and press Start again.', null, $bot_token);
} else {
    send_text($chat_id, "I don't understand you.", null, $bot_token);
}
echo json_encode(['ok'=>true]);
