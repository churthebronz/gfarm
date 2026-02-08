<?php
declare(strict_types=1);

define('FastCore', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/vx_rarity.php';
require_once __DIR__ . '/../../core/vx_guardian_art.php';
require_once __DIR__ . '/../../core/vx_nicknames.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { try { session_start(); } catch (Throwable $e) {} }
global $db;

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'AUTH'], JSON_UNESCAPED_SLASHES);
  exit;
}

$tarif = (int)($_GET['tarif'] ?? 0);
if ($tarif <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'BAD_TARIF'], JSON_UNESCAPED_SLASHES);
  exit;
}

try { if (function_exists('vx_guardians_schema_ensure')) vx_guardians_schema_ensure($db); } catch (Throwable $e) {}

try {
  // Mark as seen for Mutant Index completion rings
  $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_seen (
    uid INT NOT NULL,
    tarif_id INT NOT NULL,
    seen_at INT NOT NULL,
    PRIMARY KEY (uid, tarif_id),
    KEY seen_at (seen_at)
  )");
  $db->query("INSERT INTO vx_guardian_seen (uid, tarif_id, seen_at)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at)", $uid, $tarif, time());
} catch (Throwable $e) {}


$plan = ['id' => $tarif, 'title' => 'Mutant Crop', 'img' => '', 'price' => 0];
try {
  $p = $db->query('SELECT id, title, img, price FROM db_tarif WHERE id=? LIMIT 1', $tarif)->fetchArray();
  if ($p) {
    $plan['title'] = (string)($p['title'] ?? $plan['title']);
    $plan['img'] = (string)($p['img'] ?? '');
    $plan['price'] = (float)($p['price'] ?? 0);
  }
} catch (Throwable $e) {}

$chain = null;
try {
  $q = $db->query(
    'SELECT * FROM vx_guardian_chains WHERE uid=? AND tarif=? ORDER BY updated_at DESC, id DESC LIMIT 1',
    $uid,
    $tarif
  );
  if ($q) $chain = $q->fetchArray();
} catch (Throwable $e) { $chain = null; }

$owned = is_array($chain);
$lvl = $owned ? (int)($chain['crossbreed_level'] ?? 0) : 0;
$maxE = $owned ? (int)($chain['max_evolve'] ?? 5) : 5;
if ($maxE < 1) $maxE = 1;
if ($maxE > 10) $maxE = 10;

$rarity = $owned ? (string)($chain['rarity'] ?? 'common') : '';
if ($rarity === '') {
  $rr = vx_rarity_for_tarif($db, $tarif, (string)$plan['title'], (float)$plan['price']);
  $rarity = (string)($rr['rarity'] ?? 'common');
}
$rarity = vx_rarity_normalize($rarity);

$kind = $owned ? (string)($chain['kind'] ?? 'plan') : 'plan';
$isShiny = $owned ? ((int)($chain['shiny'] ?? 0) === 1) : false;

// 6-star only for non-plan special guardians (founder/affiliate/achievement). Normal plans max at 5.
$isSpecial = ($kind !== 'plan' && $kind !== '');
$starsMax = $isSpecial ? 6 : 5;
$stars = $isSpecial ? 6 : max(1, min(5, $lvl + 1));

// Art URL for current evolve stage
$fallbackKey = (string)($plan['img'] ?? '');
$artKey = '';
try { $artKey = vx_guardian_art_key_for_level($db, $tarif, $lvl, $fallbackKey); } catch (Throwable $e) { $artKey = $fallbackKey; }

$artUrl = '/assets/img/guardians/placeholder.png';
$ak = trim((string)$artKey);
if ($ak !== '' && (strpos($ak, 'http') === 0 || strpos($ak, '/') === 0)) {
  $artUrl = $ak;
} else {
  $artUrl = function_exists('vx_img_items_url_from_key')
    ? vx_img_items_url_from_key((string)$ak)
    : ('/img/items/' . preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$ak) . '.png');
}

// Preload all stage arts for a smooth swipe/preview in the sheet
$artStages = [];
try {
  for ($i = 0; $i <= $maxE; $i++) {
    $k = vx_guardian_art_key_for_level($db, $tarif, $i, $fallbackKey);
    $kk = trim((string)$k);
    if ($kk !== '' && (strpos($kk, 'http') === 0 || strpos($kk, '/') === 0)) {
      $artStages[(string)$i] = $kk;
    } else {
      $artStages[(string)$i] = function_exists('vx_img_items_url_from_key')
        ? vx_img_items_url_from_key((string)$kk)
        : ('/img/items/' . preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$kk) . '.png');
    }
  }
} catch (Throwable $e) {
  $artStages = [];
}

// Mark as Seen (for Mutant Index completion rings)
try {
  $db->query("CREATE TABLE IF NOT EXISTS vx_guardian_seen (
    uid INT NOT NULL,
    tarif_id INT NOT NULL,
    seen_at INT NOT NULL,
    PRIMARY KEY (uid, tarif_id),
    KEY seen_at (seen_at)
  )");
  if ($uid > 0 && $tarif > 0) {
    $db->query("INSERT INTO vx_guardian_seen (uid, tarif_id, seen_at)
      VALUES (".$uid.", ".$tarif.", ".time().")
      ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at)");
  }
} catch (Throwable $e) {}

$nickname='';
try { $nickname = vx_nickname_get($db, $uid, $tarif); } catch (Throwable $e) { $nickname=''; }

$out = [
  'ok' => true,
  'tarif' => $tarif,
  'title' => (string)$plan['title'],
  'nickname' => (string)$nickname,
  'rarity' => $rarity,
  'rarity_label' => vx_rarity_label($rarity),
  'rarity_class' => vx_rarity_css_class($rarity),
  'price' => (float)$plan['price'],
  'owned' => $owned,
  'kind' => $kind,
  'is_shiny' => $isShiny,
  'level' => $lvl,
  'max_evolve' => $maxE,
  'stars' => $stars,
  'stars_max' => $starsMax,
  'status' => $owned ? (string)($chain['status'] ?? 'active') : 'unowned',
  'vp_total' => $owned ? (int)($chain['vp_total'] ?? 0) : 0,
  'lp_total' => $owned ? (int)($chain['lp_total'] ?? 0) : 0,
  'created_at' => $owned ? (int)($chain['created_at'] ?? 0) : 0,
  'matured_at' => $owned ? (int)($chain['matured_at'] ?? 0) : 0,
  'term_end' => $owned ? (int)($chain['term_end'] ?? 0) : 0,
  'crossbreed_deadline' => $owned ? (int)($chain['crossbreed_deadline'] ?? 0) : 0,
  'art_url' => $artUrl,
  'art_stages' => $artStages,
  'urls' => [
    'plan' => '/user/plans' . ($tarif > 0 ? ('?focus=' . $tarif) : ''),
    'evolve' => $owned ? ('/user/crossbreed?tarif=' . $tarif) : '',
    'codex' => '/user/codex',
  ],
];

echo json_encode($out, JSON_UNESCAPED_SLASHES);
