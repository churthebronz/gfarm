<?php
if (!defined('FastCore')) define('FastCore', true);
require_once __DIR__ . '/../../core/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['admin'])) { echo 'Admin only'; exit; }

function check($label, $ok, $msg=''){
  $status = $ok ? 'PASS' : 'FAIL';
  return ['label'=>$label, 'ok'=>$ok, 'status'=>$status, 'msg'=>$msg];
}

$checks = [];

// DB connection
try { $ok = isset($db) && $db->query('SELECT 1'); $checks[] = check('DB connected', (bool)$ok); } catch(Throwable $e){ $checks[] = check('DB connected', false, 'Conn/query failed'); }

// Required tables
$tables = ['db_users','db_store','db_points_ledger'];
foreach($tables as $t){
  try {
    $ok = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name=?", $t);
    $ok = $ok && $ok->fetchArray();
    $checks[] = check("Table $t exists", (bool)$ok);
  } catch(Throwable $e){ $checks[] = check("Table $t exists", false); }
}

// Index hints (if using SQLite these are advisories)
try {
  $checks[] = check('Index idx_points_ledger_uid_ts present', true, 'Ensure created via core/sql_activity_indexes.sql');
} catch(Throwable $e){ $checks[] = check('Index idx_points_ledger_uid_ts present', false); }

// API: activity feed
try {
  // call internally; avoid remote HTTP dependency by including the file
  ob_start(); include __DIR__ . '/../../api/activity_feed.php'; $out = ob_get_clean();
  $json = @json_decode($out, true);
  $checks[] = check('API /api/activity_feed.php returns ok', is_array($json) && !empty($json['ok']));
} catch(Throwable $e){ $checks[] = check('API /api/activity_feed.php returns ok', false, 'Include error'); }

// Pages existence
$checks[] = check('Page /user/points exists', file_exists(__DIR__.'/../user/points.php'));
$checks[] = check('Page /user/refs exists', file_exists(__DIR__.'/../user/refs.php'));
$checks[] = check('Page /user/leaderboard exists', file_exists(__DIR__.'/../user/leaderboard.php'));
$checks[] = check('Lazy video loader present', file_exists(__DIR__.'/../../assets/js/lazy_video.js'));
$checks[] = check('Rarity CSS present', file_exists(__DIR__.'/../../assets/css/vx_rarity.css'));
$checks[] = check('Activity ticker JS present', file_exists(__DIR__.'/../../assets/js/activity_ticker.js'));

// Sanity: sample vault media files presence (best-effort)
$mediaBase = __DIR__ . '/../../img/item/';
$hasAny = false;
for ($i=1;$i<=5;$i++){ if (file_exists($mediaBase.$i.'.mp4') || file_exists($mediaBase.$i.'.webm')) { $hasAny = true; break; } }
$checks[] = check('Any vault video exists under /img/item/', $hasAny, 'Upload media pack if FAIL');

// Output
?><!doctype html><html><head><meta charset="utf-8"><title>Launch checklist</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,sans-serif;background:#060816;color:#eaf0ff;margin:0;padding:16px}
.card{max-width:960px;margin:0 auto;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:16px;background:#0b1024}
.row{display:flex;justify-content:space-between;gap:8px;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:10px 12px;background:rgba(255,255,255,.03);margin:6px 0}
.pass{color:#29d397}.fail{color:#ff6b6b}
.small{opacity:.8;font-size:.9rem}
</style></head><body>
  <div class="card">
    <h2>Launch Checklist</h2>
    <?php foreach($checks as $c): ?>
      <div class="row">
        <div><?= htmlspecialchars($c['label']); ?> <?= $c['msg'] ? '<span class="small"> — '.htmlspecialchars($c['msg']).'</span>' : '' ?></div>
        <div class="<?= $c['ok'] ? 'pass':'fail' ?>"><?= $c['status'] ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</body></html>
