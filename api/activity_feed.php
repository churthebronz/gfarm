<?php
declare(strict_types=1);

/**
 * /api/activity_feed.php
 * Public-safe activity feed for tickers (homepage + dashboard).
 *
 * IMPORTANT:
 * - No sensitive data.
 * - Prefer Telegram usernames if available.
 * - Uses vx_activity_log as source of truth (no placeholders).
 */

define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
  require_once __DIR__ . '/../core/config.php';
  require_once __DIR__ . '/../core/vx_activity.php';
  require_once __DIR__ . '/../core/vx_guardians.php';
  require_once __DIR__ . '/../core/seasons.php';
  require_once __DIR__ . '/../core/vx_season_points.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'Bootstrap failed']);
  exit;
}

global $db;

// Fail-soft session (only used when scope=me)
if (session_status() !== PHP_SESSION_ACTIVE) { try { session_start(); } catch (Throwable $e) {} }

function vx_rows_all($stmt): array {
  $out = [];
  if (!$stmt) return $out;
  try { while ($r = $stmt->fetchArray()) { $out[] = $r; } } catch (Throwable $e) {}
  return $out;
}

function vx_user_label($db, int $uid): string {
  try {
    $r = $db->query('SELECT login, tg_username, tg_name FROM db_users WHERE id=? LIMIT 1', $uid);
    $u = $r ? ($r->fetchArray() ?: []) : [];
    $tg = trim((string)($u['tg_username'] ?? ''));
    if ($tg !== '') return '@' . ltrim($tg, '@');
    $nm = trim((string)($u['tg_name'] ?? ''));
    if ($nm !== '') return $nm;
    $lg = trim((string)($u['login'] ?? ''));
    if ($lg !== '') return $lg;
  } catch (Throwable $e) {}
  return 'User#' . $uid;
}

function vx_emoji_for(string $type, array $meta = []): string {
  $t = strtolower($type);
  if ($t === 'vault_buy') return '🔒';
  if ($t === 'claim') return '✅';
  if ($t === 'crossbreed') return '🔁';
  if ($t === 'codex') return '💎';
  if ($t === 'rank_up') return '🏆';
  if ($t === 'ref') return '🧲';
  if ($t === 'points') return '⭐';
  if ($t === 'lp') return '🌙';
  return '⚡';
}

function vx_msg_for(string $type, float $amount, array $meta = []): string {
  $t = strtolower($type);
  if ($t === 'vault_buy') {
    $title = trim((string)($meta['title'] ?? 'Mutant Crop'));
    $rar = trim((string)($meta['rarity'] ?? ''));
    $rarTxt = $rar !== '' ? (ucfirst($rar) . ' ') : '';
    return 'activated ' . $rarTxt . $title;
  }
  if ($t === 'claim') {
    $usd = $amount;
    return 'claimed $' . number_format($usd, 2, '.', '') . ' yield';
  }
  if ($t === 'crossbreed') {
    $lvl = (int)($meta['crossbreed_level'] ?? 0);
    $title = trim((string)($meta['title'] ?? 'Guardian'));
    return 'crossbreeded ' . $title . ' (Level ' . $lvl . ')';
  }
  if ($t === 'codex') {
    $title = trim((string)($meta['title'] ?? 'Guardian'));
    $reason = (string)($meta['reason'] ?? '');
    $badge = ($reason === 'max') ? 'MAX REBIRTH' : (($reason === 'missed') ? 'MISSED WINDOW' : 'CODEX');
    return 'completed ' . $title . ' — ' . $badge;
  }
  if ($t === 'rank_up') {
    $rank = (int)($meta['rank'] ?? 0);
    return $rank > 0 ? ('entered Top ' . $rank . ' this Season') : 'climbed the Season leaderboard';
  }
  if ($t === 'ref') {
    return 'earned referral VP';
  }
  if ($t === 'lp') {
    $n = (int)round($amount);
    return 'earned ' . number_format($n) . ' LP';
  }
  if ($t === 'points') {
    $n = (int)round($amount);
    return 'earned ' . number_format($n) . ' VP';
  }
  return 'activity recorded';
}

try {
  vx_activity_schema_ensure($db);

  $limit = max(10, min(60, (int)($_GET['limit'] ?? 24)));
  $since = (int)($_GET['since'] ?? 0);

  $scope = strtolower((string)($_GET['scope'] ?? 'all'));
  $uidFilter = 0;
  if ($scope === 'me') {
    $uidFilter = (int)($_SESSION['uid'] ?? 0);
  } elseif (isset($_GET['uid'])) {
    $uidFilter = max(0, (int)($_GET['uid'] ?? 0));
  }

  // Pull latest activity rows.
  $sql = "SELECT id, uid, type, amount, meta_json, created_at
          FROM vx_activity_log
          WHERE created_at > ?";
  $params = [$since];
  if ($uidFilter > 0) { $sql .= " AND uid = ?"; $params[] = $uidFilter; }
  $sql .= " ORDER BY created_at DESC, id DESC LIMIT ?";
  $params[] = $limit;

  $q = $db->query($sql, ...$params);
  $rows = vx_rows_all($q);

  $items = [];
  foreach ($rows as $r) {
    $uid = (int)($r['uid'] ?? 0);
    $type = (string)($r['type'] ?? '');
    $meta = [];
    try {
      $mj = (string)($r['meta_json'] ?? '');
      if ($mj !== '') {
        $meta = json_decode($mj, true);
        if (!is_array($meta)) $meta = [];
      }
    } catch (Throwable $e) { $meta = []; }

    $items[] = [
      'ts'    => (int)($r['created_at'] ?? 0),
      'type'  => $type,
      'uid'   => $uid,
      'user'  => vx_user_label($db, $uid),
      'emoji' => vx_emoji_for($type, $meta),
      'msg'   => vx_msg_for($type, (float)($r['amount'] ?? 0), $meta),
      'meta'  => $meta, // safe fields only; meta_json is controlled by our server
    ];
  }

  echo json_encode([
    'ok'    => true,
    'scope' => ($uidFilter > 0 ? 'filtered' : 'all'),
    'items' => $items,
    'now'   => time(),
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'activity feed error']);
}
