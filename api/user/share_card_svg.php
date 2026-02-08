<?php
declare(strict_types=1);
define('FastCore', true);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';
require_once __DIR__ . '/../require_tg_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
global $db;

$uid = (int)($GLOBALS['UID'] ?? ($_SESSION['uid'] ?? 0));
if ($uid <= 0) { http_response_code(401); echo '<svg xmlns="http://www.w3.org/2000/svg"></svg>'; exit; }

$u = $db->query('SELECT login, tg_name, tg_username, points_total FROM db_users WHERE id=? LIMIT 1', $uid)->fetchArray();
$name = (string)($u['tg_name'] ?? '');
if ($name === '') $name = (string)($u['tg_username'] ?? '');
if ($name === '') $name = (string)($u['login'] ?? 'User');
$pts = (int)($u['points_total'] ?? 0);

$season = vx_get_current_season($db);
$sid = (int)($season['id'] ?? 0);
$has = vx_season_pass_active($db, $uid, $sid);

$title = 'GreenFarm';
$sub = 'Season #'.(int)($season['season_no'] ?? 0).' • '.date('M j', (int)($season['ends_at'] ?? time()+86400)).' ends';

$badge = $has ? 'SEASON PASS' : 'LIVE';
$accent = $has ? '#ffae00' : '#00ffe0';

$w=880; $h=460;

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $w ?>" height="<?= $h ?>" viewBox="0 0 <?= $w ?> <?= $h ?>">
  <defs>
    <linearGradient id="bg" x1="0" x2="1">
      <stop offset="0" stop-color="#050712"/>
      <stop offset="1" stop-color="#0b1220"/>
    </linearGradient>
    <linearGradient id="beam" x1="0" x2="1">
      <stop offset="0" stop-color="<?= $accent ?>" stop-opacity="0"/>
      <stop offset="0.5" stop-color="<?= $accent ?>" stop-opacity="0.9"/>
      <stop offset="1" stop-color="<?= $accent ?>" stop-opacity="0"/>
    </linearGradient>
    <filter id="soft" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="10" result="b"/>
      <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
    </filter>
  </defs>

  <rect x="0" y="0" width="<?= $w ?>" height="<?= $h ?>" rx="26" fill="url(#bg)"/>
  <rect x="24" y="24" width="<?= $w-48 ?>" height="<?= $h-48 ?>" rx="22" fill="rgba(5,7,18,0.66)" stroke="rgba(255,255,255,0.12)"/>
  <rect x="24" y="24" width="<?= $w-48 ?>" height="6" rx="3" fill="url(#beam)" filter="url(#soft)"/>

  <text x="54" y="92" fill="#f8fafc" font-size="34" font-family="ui-sans-serif,system-ui" font-weight="800"><?= htmlspecialchars($title) ?></text>
  <text x="54" y="128" fill="rgba(226,232,240,0.82)" font-size="16" font-family="ui-sans-serif,system-ui" font-weight="700"><?= htmlspecialchars($sub) ?></text>

  <g>
    <rect x="54" y="156" rx="999" ry="999" width="210" height="44" fill="rgba(2,6,23,0.55)" stroke="rgba(148,163,184,0.16)"/>
    <text x="74" y="184" fill="<?= $accent ?>" font-size="14" font-family="ui-sans-serif,system-ui" font-weight="900"><?= htmlspecialchars($badge) ?></text>
  </g>

  <text x="54" y="258" fill="rgba(226,232,240,0.85)" font-size="18" font-family="ui-sans-serif,system-ui" font-weight="800">Operator</text>
  <text x="54" y="292" fill="#fef9f3" font-size="30" font-family="ui-sans-serif,system-ui" font-weight="950"><?= htmlspecialchars($name) ?></text>

  <g>
    <rect x="54" y="320" rx="18" ry="18" width="320" height="92" fill="rgba(2,6,23,0.55)" stroke="rgba(148,163,184,0.16)"/>
    <text x="80" y="356" fill="rgba(226,232,240,0.78)" font-size="14" font-family="ui-sans-serif,system-ui" font-weight="800">TOTAL POINTS</text>
    <text x="80" y="394" fill="#fff7d6" font-size="34" font-family="ui-sans-serif,system-ui" font-weight="950"><?= number_format($pts) ?></text>
  </g>

  <text x="<?= $w-54 ?>" y="<?= $h-56 ?>" text-anchor="end" fill="rgba(226,232,240,0.60)" font-size="14" font-family="ui-sans-serif,system-ui" font-weight="800">⚡ greenfarm.lol</text>
</svg>
