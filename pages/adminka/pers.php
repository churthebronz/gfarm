<?php
if (!defined('FastCore')) { exit('Opss!'); }

global $db, $pg, $adm;

require_once __DIR__ . '/../../core/schema_helpers.php';
// Mutant Produce artwork storage (kept on legacy vx_guardian_art backend for compatibility)
require_once __DIR__ . '/../../core/vx_guardian_art.php';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/* -----------------------------
   Helpers
------------------------------*/
function vxh($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function vx_is_post(): bool {
  return ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}
function vx_csrf_token(): string {
  if (empty($_SESSION['_adm_csrf'])) $_SESSION['_adm_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['_adm_csrf'];
}
function vx_csrf_ok(): bool {
  $t = (string)($_POST['_csrf'] ?? '');
  return $t !== '' && isset($_SESSION['_adm_csrf']) && hash_equals((string)$_SESSION['_adm_csrf'], $t);
}
function vx_fnum($v): float {
  $v = trim((string)$v);
  $v = str_replace(',', '.', $v);
  if ($v === '' || !is_numeric($v)) return 0.0;
  return (float)$v;
}
function vx_fint($v): int {
  $v = trim((string)$v);
  if ($v === '' || !is_numeric($v)) return 0;
  return (int)$v;
}
function vx_clean_img_key(string $v): string {
  $v = trim($v);
  $v = str_ireplace(['.png','.jpg','.jpeg','.webp'], '', $v);
  $v = str_replace(['/', '\\'], '', $v);
  $v = preg_replace('~[^a-zA-Z0-9_\-]~', '', $v);
  return substr((string)$v, 0, 64);
}

function vx_save_uploaded_img_to_items(array $file, string $baseKey): string {
  $baseKey = vx_clean_img_key($baseKey);
  // Default image base key for new uploads
  if ($baseKey === '') $baseKey = 'mutant';
  $sz = (int)($file['size'] ?? 0);
  if ($sz <= 0 || $sz > (2 * 1024 * 1024)) return '';
  $orig = (string)($file['name'] ?? '');
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) return '';
  $ext = ($ext === 'jpeg') ? 'jpg' : $ext;
  $baseKey = substr($baseKey, 0, 42);
  $stamp = date('YmdHis');
  $fn = $baseKey . '_' . $stamp . '.' . $ext;
  $targetDir = __DIR__ . '/../../img/items';
  if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
  $target = $targetDir . '/' . $fn;
  if (@move_uploaded_file((string)($file['tmp_name'] ?? ''), $target)) {
    @chmod($target, 0664);
    return vx_clean_img_key(pathinfo($fn, PATHINFO_FILENAME));
  }
  return '';
}

/* -----------------------------
   Config: paid status for sold stats
------------------------------*/
$PAID_STATUS = 1;

/* -----------------------------
   Routing
------------------------------*/
$seg2 = (string)($pg->segment[2] ?? '');
$action = 'list';
if ($seg2 === 'add')  $action = 'add';
if ($seg2 === 'edit') $action = 'edit';

$edit_id = 0;
if ($action === 'edit') {
  $edit_id = (int)($pg->segment[3] ?? 0);
  if ($edit_id <= 0) $action = 'list';
}

$msg = '';
$err = '';
$csrf = vx_csrf_token();

/* -----------------------------
   Image picker scan
------------------------------*/
$img_files = [];
try {
  $dir = __DIR__ . '/../../img/items';
  if (is_dir($dir)) {
    $paths = array_merge(
      glob($dir.'/*.png') ?: [],
      glob($dir.'/*.webp') ?: [],
      glob($dir.'/*.jpg') ?: [],
      glob($dir.'/*.jpeg') ?: []
    );
    foreach ($paths as $p) {
      $bn = basename($p);
      $key = preg_replace('~\.(png|webp|jpe?g)$~i', '', $bn);
      $key = vx_clean_img_key((string)$key);
      if ($key !== '') $img_files[$key] = '/img/items/'.$bn;
    }
    ksort($img_files);
  }
} catch (Throwable $e) {
  $img_files = [];
}

/* -----------------------------
   Current season + caps + sold
------------------------------*/
$curSeason = [];
$curSeasonId = 0;
$curSeasonNo = 0;
$caps = [];
$sold = [];

try {
  if (function_exists('vx_table_exists') && vx_table_exists($db, 'vx_seasons')) {
    $now = time();
    $curSeason = $db->query(
      'SELECT * FROM vx_seasons WHERE starts_at <= ? AND ends_at > ? ORDER BY id DESC LIMIT 1',
      $now, $now
    )->fetchArray();
    $curSeasonId = (int)($curSeason['id'] ?? 0);
    $curSeasonNo = (int)($curSeason['season_no'] ?? 0);
  }
} catch (Throwable $e) {}

try {
  if ($curSeasonId > 0 && function_exists('vx_table_exists') && vx_table_exists($db, 'vx_season_caps')) {
    $rows = $db->query('SELECT tarif_id, cap FROM vx_season_caps WHERE season_id=?', $curSeasonId)->fetchAll();
    foreach ($rows as $r) $caps[(int)$r['tarif_id']] = (int)$r['cap'];
  }
} catch (Throwable $e) {}

try {
  if (
    $curSeasonId > 0 &&
    function_exists('vx_table_exists') && vx_table_exists($db, 'db_store') &&
    function_exists('vx_column_exists') && vx_column_exists($db, 'db_store', 'season_id')
  ) {
    $rows = $db->query(
      'SELECT tarif AS tarif_id, COUNT(*) AS c
       FROM db_store
       WHERE season_id=? AND status=?
       GROUP BY tarif',
      $curSeasonId, $PAID_STATUS
    )->fetchAll();
    foreach ($rows as $r) $sold[(int)$r['tarif_id']] = (int)($r['c'] ?? 0);
  }
} catch (Throwable $e) {}

/* -----------------------------
   POST actions
------------------------------*/
try {
  if (vx_is_post()) {
    if (!vx_csrf_ok()) throw new Exception('Bad token. Refresh and try again.');

    if (isset($_POST['do_move'])) {
      $id = (int)($_POST['id'] ?? 0);
      $dir = (string)($_POST['dir'] ?? '');
      if ($id <= 0) throw new Exception('Invalid ID.');

      $me = $db->query('SELECT id, sort_order FROM db_tarif WHERE id=? LIMIT 1', $id)->fetchArray();
      if (!$me) throw new Exception('Plan not found.');
      $mySort = (int)($me['sort_order'] ?? 0);

      $other = null;
      if ($dir === 'up') {
        $other = $db->query(
          'SELECT id, sort_order FROM db_tarif WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1',
          $mySort
        )->fetchArray();
      } else {
        $other = $db->query(
          'SELECT id, sort_order FROM db_tarif WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1',
          $mySort
        )->fetchArray();
      }

      if ($other) {
        $oid = (int)$other['id'];
        $os  = (int)$other['sort_order'];
        $db->query('UPDATE db_tarif SET sort_order=? WHERE id=? LIMIT 1', $os, $id);
        $db->query('UPDATE db_tarif SET sort_order=? WHERE id=? LIMIT 1', $mySort, $oid);
        $msg = 'Order updated.';
      } else {
        $msg = 'Already at the edge.';
      }
    }

    if (isset($_POST['do_toggle'])) {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid ID.');
      $row = $db->query('SELECT is_active FROM db_tarif WHERE id=? LIMIT 1', $id)->fetchArray();
      if (!$row) throw new Exception('Plan not found.');
      $new = ((int)($row['is_active'] ?? 1)) ? 0 : 1;
      $db->query('UPDATE db_tarif SET is_active=? WHERE id=? LIMIT 1', $new, $id);
      $msg = $new ? 'Plan enabled.' : 'Plan disabled.';
    }

    if (isset($_POST['do_delete'])) {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid ID.');
      $db->query('DELETE FROM db_tarif WHERE id=? LIMIT 1', $id);
      $msg = 'Plan deleted.';
    }

    if (isset($_POST['do_duplicate'])) {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid ID.');
      $src = $db->query('SELECT * FROM db_tarif WHERE id=? LIMIT 1', $id)->fetchArray();
      if (!$src) throw new Exception('Plan not found.');

      $title = trim((string)($src['title'] ?? 'Plan'));
      $speed = (string)($src['speed'] ?? '');
      $price = (string)($src['price'] ?? '0');
      $img   = (string)($src['img'] ?? '');
      $per   = (string)($src['period'] ?? '0');
      $act   = (int)($src['is_active'] ?? 1);

      $newTitle = $title !== '' ? ($title.' (Copy)') : 'Plan (Copy)';
      $sort = (int)($src['sort_order'] ?? 0) + 1;

      $db->query('UPDATE db_tarif SET sort_order = sort_order + 1 WHERE sort_order >= ?', $sort);

      $db->query(
        'INSERT INTO db_tarif (title, speed, price, img, period, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)',
        $newTitle, $speed, $price, $img, $per, $sort, $act
      );

      $msg = 'Plan duplicated.';
    }

    if (isset($_POST['do_bulk'])) {
      $ids = $_POST['ids'] ?? [];
      if (!is_array($ids) || !$ids) throw new Exception('No plans selected.');

      $norm = [];
      foreach ($ids as $x) { $xi = (int)$x; if ($xi > 0) $norm[] = $xi; }
      if (!$norm) throw new Exception('No valid IDs selected.');

      $ph = implode(',', array_fill(0, count($norm), '?'));
      $bulk = (string)($_POST['bulk_action'] ?? '');

      if ($bulk === 'enable') {
        $db->query('UPDATE db_tarif SET is_active=1 WHERE id IN ('.$ph.')', ...$norm);
        $msg = 'Selected plans enabled.';
      } elseif ($bulk === 'disable') {
        $db->query('UPDATE db_tarif SET is_active=0 WHERE id IN ('.$ph.')', ...$norm);
        $msg = 'Selected plans disabled.';
      } elseif ($bulk === 'delete') {
        $db->query('DELETE FROM db_tarif WHERE id IN ('.$ph.')', ...$norm);
        $msg = 'Selected plans deleted.';
      } elseif ($bulk === 'set_period') {
        $p = vx_fint($_POST['bulk_period'] ?? 0);
        $db->query('UPDATE db_tarif SET period=? WHERE id IN ('.$ph.')', $p, ...$norm);
        $msg = 'Period updated for selected plans.';
      } elseif ($bulk === 'set_img') {
        $k = vx_clean_img_key((string)($_POST['bulk_img'] ?? ''));
        $db->query('UPDATE db_tarif SET img=? WHERE id IN ('.$ph.')', $k, ...$norm);
        $msg = 'Image key updated for selected plans.';
      } else {
        throw new Exception('Pick a valid bulk action.');
      }
    }

    if (isset($_POST['do_add']) || isset($_POST['do_save'])) {
      $title = trim((string)($_POST['title'] ?? ''));
      $speed = trim((string)($_POST['speed'] ?? ''));
      $price = vx_fnum($_POST['price'] ?? '0');
      $per   = vx_fint($_POST['period'] ?? 0);
      $img   = vx_clean_img_key((string)($_POST['img'] ?? ''));
      // Optional image upload (stores into /img/items and saves the key into db_tarif.img)
      if (!empty($_FILES['plan_image']) && !empty($_FILES['plan_image']['tmp_name']) && is_uploaded_file($_FILES['plan_image']['tmp_name'])) {
        $base = ($img !== '') ? $img : preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($title));
        $k = vx_save_uploaded_img_to_items($_FILES['plan_image'], (string)$base);
        if ($k !== '') $img = $k;
      }

      $act   = !empty($_POST['is_active']) ? 1 : 0;

      if ($title === '' || strlen($title) < 2) throw new Exception('Title too short.');
      if (strlen($title) > 80) $title = substr($title, 0, 80);
      if (strlen($speed) > 64) $speed = substr($speed, 0, 64);
      if ($price < 0) $price = 0;
      if ($per < 0) $per = 0;

      $savedPlanId = 0;
      if (isset($_POST['do_add'])) {
        $m = $db->query('SELECT MAX(sort_order) AS m FROM db_tarif')->fetchArray();
        $sort = (int)($m['m'] ?? 0) + 1;

        $db->query(
          'INSERT INTO db_tarif (title, speed, price, img, period, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)',
          $title, $speed, $price, $img, $per, $sort, $act
        );
        $savedPlanId = (int)$db->lastInsert();
        $msg = 'Plan added: '.$title;
        $_POST = [];
      } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid ID.');
        $db->query(
          'UPDATE db_tarif SET title=?, speed=?, price=?, img=?, period=?, is_active=? WHERE id=? LIMIT 1',
          $title, $speed, $price, $img, $per, $act, $id
        );
        $savedPlanId = $id;
        $msg = 'Saved.';
      }

      // Evolution art (E0..E5): allow manual keys and/or per-level uploads.
      // Stored in vx_guardian_art (tarif_id, evolve_level, img_key)
      if ($savedPlanId > 0) {
        try { if (function_exists('vx_guardian_art_schema_ensure')) vx_guardian_art_schema_ensure($db); } catch (Throwable $e) {}

        // Manual keys
        $evoKeys = isset($_POST['evo_img']) && is_array($_POST['evo_img']) ? $_POST['evo_img'] : [];

        // Upload keys (wins over manual for that level)
        for ($lvl = 0; $lvl <= 5; $lvl++) {
          $fileKey = 'evo_file_' . $lvl;
          if (!empty($_FILES[$fileKey]) && !empty($_FILES[$fileKey]['tmp_name']) && is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
            $base = ((string)$savedPlanId) . '_e' . ((string)$lvl);
            $upKey = vx_save_uploaded_img_to_items($_FILES[$fileKey], $base);
            if ($upKey !== '') $evoKeys[(string)$lvl] = $upKey;
          }
        }

        foreach ($evoKeys as $lvlRaw => $keyRaw) {
          $lvl = (int)$lvlRaw;
          if ($lvl < 0 || $lvl > 5) continue;
          $k = vx_clean_img_key((string)$keyRaw);
          // Empty means: don't modify (admin can clear via DB if needed)
          if ($k === '') continue;
          try { vx_guardian_art_set($db, $savedPlanId, $lvl, $k); } catch (Throwable $e) {}
        }
      }
    }
  }
} catch (Throwable $e) {
  $err = 'Error: '.$e->getMessage();
}

/* -----------------------------
   Load edit row
------------------------------*/
$edit = null;
if ($action === 'edit' && $edit_id > 0) {
  try {
    $edit = $db->query('SELECT * FROM db_tarif WHERE id=? LIMIT 1', $edit_id)->fetchArray();
    if (!$edit) { $action = 'list'; $err = $err ?: 'Plan not found.'; }
  } catch (Throwable $e) {
    $action = 'list';
    $err = $err ?: ('Error: '.$e->getMessage());
  }
}

/* -----------------------------
   List + search
------------------------------*/
$q = trim((string)($_GET['q'] ?? ''));
$show = (string)($_GET['show'] ?? 'all'); // all | active | inactive
$limit = 200;

$list = [];
try {
  $where = [];
  $params = [];

  if ($q !== '') {
    $like = '%'.$q.'%';
    $where[] = '(title LIKE ? OR CAST(id AS CHAR) LIKE ? OR CAST(price AS CHAR) LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
  }

  if ($show === 'active') $where[] = 'is_active = 1';
  if ($show === 'inactive') $where[] = 'is_active = 0';

  $sql = 'SELECT * FROM db_tarif';
  if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
  $sql .= ' ORDER BY sort_order ASC, id DESC LIMIT '.$limit;

  $list = $db->query($sql, ...$params)->fetchAll();
} catch (Throwable $e) {
  $list = [];
  $err = $err ?: ('Error: '.$e->getMessage());
}

?>
<style>
/* DARK / LOW WHITE SPACE (premium admin) */
.vx-root{
  --bg0:#070a12;
  --bg1:#0b1020;
  --bg2:#0f1730;
  --card:#0c142b;
  --card2:#0a1226;
  --line:rgba(255,255,255,.08);
  --text:#e7edf7;
  --muted:rgba(231,237,247,.65);
  --muted2:rgba(231,237,247,.45);
  --green:#22c55e;
  --red:#ef4444;
  --amber:#f59e0b;
  --blue:#3b82f6;
  --shadow: 0 18px 50px rgba(0,0,0,.45);
}

.vx-root .vx-hero{
  background: radial-gradient(1200px 600px at 10% 10%, rgba(59,130,246,.18), transparent 60%),
              radial-gradient(900px 500px at 90% 20%, rgba(245,158,11,.14), transparent 60%),
              linear-gradient(180deg, var(--bg2), var(--bg1));
  border:1px solid var(--line);
  border-radius:18px;
  padding:16px;
  box-shadow: var(--shadow);
  color: var(--text);
}

.vx-root h3{ margin:0; font-weight:900; letter-spacing:.2px; }
.vx-sub{ color: var(--muted); font-size:12px; margin-top:6px; }

.vx-root .vx-card{
  border-radius:18px;
  overflow:hidden;
  border:1px solid var(--line);
  background: linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));
  box-shadow: 0 14px 40px rgba(0,0,0,.35);
}

.vx-root .vx-card .vx-head{
  padding:12px 14px;
  background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));
  border-bottom:1px solid var(--line);
  color: var(--text);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}

.vx-root .vx-body{ padding:12px 14px; }

.vx-pill{
  display:inline-flex; align-items:center; gap:6px;
  border-radius:999px; padding:4px 10px;
  font-size:12px; font-weight:800;
  border:1px solid var(--line);
  background: rgba(255,255,255,.03);
  color: var(--text);
}
.vx-on{ border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); color: #b7f7cc; }
.vx-off{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); color: #ffd0d0; }
.vx-warn{ border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); color: #ffe2b3; }

.vx-root .form-control,
.vx-root .custom-select,
.vx-root select{
  background: rgba(255,255,255,.04) !important;
  color: var(--text) !important;
  border: 1px solid var(--line) !important;
  border-radius: 12px !important;
}
.vx-root .form-control::placeholder{ color: var(--muted2); }
.vx-root label{ color: var(--muted); font-size:12px; font-weight:700; }

.vx-root .btn{
  border-radius:12px;
  font-weight:800;
}
.vx-root .btn-outline-light{
  color: var(--text);
  border-color: rgba(255,255,255,.20);
}
.vx-root .btn-outline-light:hover{ background: rgba(255,255,255,.06); }

.vx-root .table{
  color: var(--text);
  background: transparent;
  margin-bottom:0;
}
.vx-root .table thead th{
  background: rgba(255,255,255,.04);
  border-color: var(--line);
  color: var(--muted);
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.4px;
  padding:10px;
}
.vx-root .table td{
  border-color: var(--line);
  padding:10px;
}
.vx-root .table-striped tbody tr:nth-of-type(odd){
  background: rgba(255,255,255,.02);
}
.vx-root .table-hover tbody tr:hover{
  background: rgba(59,130,246,.08);
}

.vx-img{
  width:52px;height:52px;object-fit:contain;
  border:1px solid var(--line);
  border-radius:14px;
  padding:5px;
  background: rgba(255,255,255,.03);
}

.vx-cap{
  display:inline-flex; flex-direction:column; gap:4px;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.03);
  min-width:170px;
  text-align:left;
}
.vx-cap .r{ display:flex; justify-content:space-between; gap:10px; font-size:12px; color: var(--muted); }
.vx-cap .r b{ color: var(--text); font-weight:900; }
.vx-cap .ok{ color: #b7f7cc; }
.vx-cap .bad{ color: #ffd0d0; }

.vx-picker{
  border:1px solid var(--line);
  background: rgba(255,255,255,.02);
  border-radius:16px;
  padding:12px;
}
.vx-grid{
  display:flex; flex-wrap:wrap; gap:10px;
  max-height: 320px; overflow:auto; padding-right:4px;
}
.vx-grid::-webkit-scrollbar{ width: 8px; }
.vx-grid::-webkit-scrollbar-thumb{ background: rgba(255,255,255,.12); border-radius: 10px; }

.vx-thumb{
  width:58px;height:58px;
  border-radius:14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.03);
  padding:6px;
  object-fit:contain;
  transition: transform .12s ease, border-color .12s ease;
}
.vx-grid a:hover .vx-thumb{
  transform: translateY(-2px);
  border-color: rgba(59,130,246,.45);
}

.vx-small{ font-size:12px; color: var(--muted); }
.vx-quiet{ color: var(--muted2); font-size:12px; }

.vx-alert{
  border-radius:14px;
  border:1px solid var(--line);
  padding:10px 12px;
  margin-bottom:12px;
}
.vx-alert.ok{ background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.25); color:#c9fdd8; }
.vx-alert.bad{ background: rgba(239,68,68,.10); border-color: rgba(239,68,68,.25); color:#ffd0d0; }
</style>

<div class="vx-root">

  <div class="vx-hero mb-3 d-flex align-items-center justify-content-between flex-wrap" style="gap:10px;">
    <div>
      <h3>Mutant Crops (Plans)</h3>
      <div class="vx-sub">
        Manage <b>db_tarif</b> • Sort • Enable/Disable • Bulk • Image picker • Release new crops weekly
        <?php if ($curSeasonId > 0): ?>
          • Current Season <span class="vx-pill vx-warn">#<?= (int)$curSeasonNo; ?> · ID <?= (int)$curSeasonId; ?></span>
        <?php else: ?>
          • Current Season <span class="vx-pill">Not detected</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="btn-group">
      <a class="btn btn-outline-light" href="/<?=vxh($adm);?>/pers">All</a>
      <a class="btn btn-success" href="/<?=vxh($adm);?>/pers/add">Add</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="vx-alert ok text-center"><?=vxh($msg);?></div><?php endif; ?>
  <?php if ($err): ?><div class="vx-alert bad text-center"><?=vxh($err);?></div><?php endif; ?>

  <?php if ($action === 'add' || ($action === 'edit' && $edit)): ?>
    <?php
      $isEdit = ($action === 'edit' && $edit);
      $row = $isEdit ? $edit : [];
      $titleV = (string)($_POST['title'] ?? ($row['title'] ?? ''));
      $speedV = (string)($_POST['speed'] ?? ($row['speed'] ?? ''));
      $priceV = (string)($_POST['price'] ?? ($row['price'] ?? ''));
      $periodV= (string)($_POST['period'] ?? ($row['period'] ?? ''));
      $imgV   = (string)($_POST['img'] ?? ($row['img'] ?? ''));
      $actV   = (int)($_POST['is_active'] ?? ($row['is_active'] ?? 1));

      $evoMap = [];
      if ($isEdit) {
        try { $evoMap = vx_guardian_art_get_all($db, (int)$row['id']); } catch (Throwable $e) { $evoMap = []; }
      }
    ?>
    <div class="vx-card mb-3">
      <div class="vx-head">
        <div>
          <b><?= $isEdit ? 'Edit Plan' : 'Add Plan'; ?></b>
          <?php if ($isEdit): ?><span class="vx-quiet">#<?= (int)$row['id']; ?></span><?php endif; ?>
        </div>
        <span class="vx-pill <?= $actV ? 'vx-on' : 'vx-off'; ?>"><?= $actV ? 'ACTIVE' : 'DISABLED'; ?></span>
      </div>

      <div class="vx-body">
        <form method="post" enctype="multipart/form-data" class="m-0">
          <input type="hidden" name="_csrf" value="<?=vxh($csrf);?>" />
          <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$row['id']; ?>" /><?php endif; ?>

          <div class="row">
            <div class="col-md-6 mb-2">
              <label>Crop name</label>
              <input class="form-control" name="title" value="<?=vxh($titleV);?>" placeholder="e.g. Nuclear Carrot" required />
            </div>
            <div class="col-md-3 mb-2">
              <label>Income (speed)</label>
              <input class="form-control" name="speed" value="<?=vxh($speedV);?>" placeholder="e.g. 0.05" />
            </div>
            <div class="col-md-3 mb-2">
              <label>Price</label>
              <input class="form-control" name="price" value="<?=vxh($priceV);?>" placeholder="e.g. 100" />
            </div>

            <div class="col-md-3 mb-2">
              <label>Period</label>
              <input type="number" min="0" class="form-control" name="period" value="<?=vxh($periodV);?>" placeholder="e.g. 30" />
            </div>

            <div class="col-md-6 mb-2">
              <label>Image key</label>
              <div class="input-group">
                <input class="form-control" name="img" id="img_key" value="<?=vxh($imgV);?>" placeholder="e.g. 1 or vip_pack" />
                <div class="input-group-append">
                  <button class="btn btn-outline-light" type="button" onclick="vxTogglePicker()">Pick</button>
                </div>
              </div>
              <div class="vx-small mt-1">Will render: <span class="vx-quiet">/img/items/&lt;key&gt;.png</span></div>
            </div>

            <div class="col-md-6 mb-2">
              <label>Upload plan image</label>
              <input class="form-control" type="file" name="plan_image" accept="image/png,image/jpeg,image/webp" />
              <div class="vx-small mt-1">PNG/JPG/WebP • max 2MB • saved to <span class="vx-quiet">/img/items/</span> and the key auto-updates.</div>
            </div>

            <div class="col-12 mt-2">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <div>
                  <b>Mutation Art (Stage 0..5)</b>
                  <div class="vx-small">Upload or set a key for each mutation stage. Users will automatically see the correct art for their current crossbreed stage.</div>
                </div>
                <span class="vx-pill" style="opacity:.9;">Tip: name files like <span class="vx-quiet"><?= $isEdit ? ((int)$row['id']) : 'PLANID'; ?>_s0</span>, <span class="vx-quiet">..._s1</span></span>
              </div>

              <div class="row" style="margin-top:10px;">
                <?php for ($lvl = 0; $lvl <= 5; $lvl++):
                  $posted = isset($_POST['evo_img']) && is_array($_POST['evo_img']) ? (string)($_POST['evo_img'][(string)$lvl] ?? '') : '';
                  $val = $posted !== '' ? $posted : (string)($evoMap[$lvl] ?? '');
                  $val = vx_clean_img_key($val);
                  $preview = $val !== '' ? (function_exists('vx_img_items_url_from_key') ? vx_img_items_url_from_key($val) : ('/img/items/'.$val.'.png')) : '';
                ?>
                <div class="col-12 col-md-6 col-lg-4 mb-2">
                  <div style="border:1px solid rgba(255,255,255,.10); border-radius:16px; padding:12px; background:rgba(255,255,255,.02);">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                      <div style="font-weight:900;">Stage <?= (int)$lvl; ?> <span class="vx-small" style="margin-left:6px;">(mutation <?= (int)$lvl; ?>)</span></div>
                      <?php if ($preview): ?>
                        <img loading="lazy" decoding="async" src="<?= vxh($preview); ?>" alt="E<?= (int)$lvl; ?>" style="width:56px;height:40px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.10);" onerror="this.style.display='none';">
                      <?php else: ?>
                        <span class="vx-small" style="opacity:.7;">No art set</span>
                      <?php endif; ?>
                    </div>
                    <div style="margin-top:10px;">
                      <label class="vx-small">Image key</label>
                      <input class="form-control" name="evo_img[<?= (int)$lvl; ?>]" value="<?= vxh($val); ?>" placeholder="e.g. <?= $isEdit ? ((int)$row['id']) : 'plan'; ?>_s<?= (int)$lvl; ?>" />
                      <div class="vx-small mt-1">Renders from <span class="vx-quiet">/img/items/&lt;key&gt;.(webp/png/jpg)</span></div>
                    </div>
                    <div style="margin-top:10px;">
                      <label class="vx-small">Upload</label>
                      <input class="form-control" type="file" name="evo_file_<?= (int)$lvl; ?>" accept="image/png,image/jpeg,image/webp" />
                    </div>
                  </div>
                </div>
                <?php endfor; ?>
              </div>
            </div>

            <div class="col-md-3 mb-2 d-flex align-items-end">
              <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="is_active_switch" name="is_active" value="1" <?= $actV ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="is_active_switch"><b>Active</b></label>
              </div>
            </div>

            <div class="col-md-3 mb-2 d-flex align-items-end">
              <button class="btn btn-success w-100" type="submit" name="<?= $isEdit ? 'do_save' : 'do_add'; ?>">
                <?= $isEdit ? 'Save' : 'Add'; ?>
              </button>
            </div>
          </div>

          <div id="vxPicker" style="display:none;margin-top:12px;">
            <div class="vx-picker">
              <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:10px;">
                <div><b>Image Picker</b> <span class="vx-small">(click to set key)</span></div>
                <button class="btn btn-sm btn-outline-light" type="button" onclick="vxTogglePicker()">Close</button>
              </div>

              <div class="vx-grid mt-2">
                <?php if ($img_files): ?>
                  <?php foreach ($img_files as $k => $src): ?>
                    <a href="javascript:void(0)" onclick="vxSetImg('<?=vxh($k);?>')" title="<?=vxh($k);?>">
                      <img loading="lazy" decoding="async" class="vx-thumb" src="<?=vxh($src);?>" alt="<?=vxh($k);?>">
                    </a>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="vx-small">No images found in /img/items</span>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="vx-card mb-3">
    <div class="vx-head">
      <b>Plans List</b>

      <form method="get" class="m-0 d-flex flex-wrap" style="gap:8px;align-items:center;">
        <input class="form-control form-control-sm" name="q" value="<?=vxh($q);?>" placeholder="Search title/id/price..." style="min-width:220px;" />
        <select class="form-control form-control-sm" name="show">
          <option value="all" <?= $show==='all'?'selected':''; ?>>All</option>
          <option value="active" <?= $show==='active'?'selected':''; ?>>Active</option>
          <option value="inactive" <?= $show==='inactive'?'selected':''; ?>>Disabled</option>
        </select>
        <button class="btn btn-sm btn-outline-light" type="submit">Filter</button>
        <a class="btn btn-sm btn-outline-light" href="/<?=vxh($adm);?>/pers">Reset</a>
      </form>
    </div>

    <div class="vx-body">
      <form method="post" class="m-0">
        <input type="hidden" name="_csrf" value="<?=vxh($csrf);?>" />

        <div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap:10px;">
          <div class="d-flex flex-wrap" style="gap:8px;align-items:center;">
            <select class="form-control form-control-sm" name="bulk_action" style="min-width:210px;">
              <option value="">Bulk action...</option>
              <option value="enable">Enable selected</option>
              <option value="disable">Disable selected</option>
              <option value="set_period">Set period for selected</option>
              <option value="set_img">Set image key for selected</option>
              <option value="delete">Delete selected</option>
            </select>

            <input class="form-control form-control-sm" name="bulk_period" type="number" min="0" placeholder="Period" style="width:120px;" />
            <input class="form-control form-control-sm" name="bulk_img" placeholder="Image key" style="width:160px;" />

            <button class="btn btn-sm btn-primary" type="submit" name="do_bulk" onclick="return vxConfirmBulk();">Apply</button>
          </div>

          <div class="vx-small">Showing <b><?= (int)count($list); ?></b> (limit <?= (int)$limit; ?>)</div>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover">
            <thead>
              <tr>
                <th class="text-center" style="width:38px;"><input type="checkbox" onclick="vxToggleAll(this)"></th>
                <th class="text-center" style="width:96px;">Order</th>
                <th class="text-center" style="width:70px;">ID</th>
                <th>Title</th>
                <th class="text-center" style="width:120px;">Active</th>
                <th class="text-center" style="width:120px;">Income</th>
                <th class="text-center" style="width:120px;">Price</th>
                <th class="text-center" style="width:110px;">Period</th>
                <th class="text-center" style="width:120px;">Image</th>
                <th class="text-center" style="width:220px;">Season Caps</th>
                <th class="text-center" style="width:340px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$list): ?>
              <tr><td colspan="11"><div class="vx-alert bad text-center">No plans found.</div></td></tr>
            <?php else: ?>
              <?php foreach ($list as $r):
                $rid = (int)($r['id'] ?? 0);
                $imgk = vx_clean_img_key((string)($r['img'] ?? ''));
                $is_active = (int)($r['is_active'] ?? 1);

                $cap = (int)($caps[$rid] ?? 0);
                $soldN = (int)($sold[$rid] ?? 0);
                $exceeded = ($cap > 0 && $soldN > $cap);
                $remain = ($cap > 0) ? max(0, $cap - $soldN) : -1;
              ?>
                <tr>
                  <td class="text-center"><input type="checkbox" name="ids[]" value="<?=$rid;?>" class="vxRowCheck"></td>

                  <td class="text-center">
                    <div class="d-inline-flex" style="gap:6px;">
                      <button class="btn btn-sm btn-outline-light" type="submit" name="do_move"
                        onclick="this.form.id.value='<?=$rid;?>'; this.form.dir.value='up';">▲</button>
                      <button class="btn btn-sm btn-outline-light" type="submit" name="do_move"
                        onclick="this.form.id.value='<?=$rid;?>'; this.form.dir.value='down';">▼</button>
                    </div>
                    <div class="vx-quiet mt-1">sort: <b><?= (int)($r['sort_order'] ?? 0); ?></b></div>
                  </td>

                  <td class="text-center"><b><?=$rid;?></b></td>

                  <td>
                    <div style="font-weight:900; color: var(--text);"><?=vxh((string)($r['title'] ?? ''));?></div>
                    <?php if ($exceeded): ?>
                      <span class="vx-pill vx-off mt-1">CAP EXCEEDED</span>
                    <?php endif; ?>
                  </td>

                  <td class="text-center">
                    <span class="vx-pill <?= $is_active ? 'vx-on' : 'vx-off'; ?>">
                      <?= $is_active ? 'ACTIVE' : 'DISABLED'; ?>
                    </span>
                  </td>

                  <td class="text-center"><?=vxh((string)($r['speed'] ?? ''));?></td>
                  <td class="text-center">$<?= number_format((float)($r['price'] ?? 0), 2); ?></td>
                  <td class="text-center"><?= (int)($r['period'] ?? 0); ?></td>

                  <td class="text-center">
                    <?php if ($imgk !== ''): ?>
                      <img loading="lazy" decoding="async" class="vx-img" src="/img/items/<?=vxh($imgk);?>.png" onerror="this.style.display='none';" alt="<?=vxh($imgk);?>">
                      <div class="vx-quiet mt-1"><?=vxh($imgk);?></div>
                    <?php else: ?>
                      <span class="vx-quiet">—</span>
                    <?php endif; ?>
                  </td>

                  <td class="text-center">
                    <?php if ($curSeasonId <= 0): ?>
                      <span class="vx-quiet">No season</span>
                    <?php else: ?>
                      <div class="vx-cap">
                        <div class="r"><span>Sold</span><b><?= (int)$soldN; ?></b></div>
                        <div class="r"><span>Cap</span><b><?= ($cap === 0 ? '∞' : (int)$cap); ?></b></div>
                        <div class="r">
                          <span>Left</span>
                          <?php if ($cap === 0): ?>
                            <b class="vx-quiet">∞</b>
                          <?php else: ?>
                            <b class="<?= $exceeded ? 'bad' : 'ok'; ?>"><?= (int)$remain; ?></b>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </td>

                  <td class="text-center">
                    <div class="d-flex justify-content-center flex-wrap" style="gap:8px;">
                      <a class="btn btn-sm btn-success" href="/<?=vxh($adm);?>/pers/edit/<?=$rid;?>">Edit</a>

                      <button class="btn btn-sm btn-outline-light" type="submit" name="do_toggle"
                        onclick="this.form.id.value='<?=$rid;?>'; return true;">
                        <?= $is_active ? 'Disable' : 'Enable'; ?>
                      </button>

                      <button class="btn btn-sm btn-outline-light" type="submit" name="do_duplicate"
                        onclick="this.form.id.value='<?=$rid;?>'; return confirm('Duplicate plan #<?=$rid;?>?');">Duplicate</button>

                      <button class="btn btn-sm btn-danger" type="submit" name="do_delete"
                        onclick="this.form.id.value='<?=$rid;?>'; return confirm('Delete plan #<?=$rid;?>?');">Delete</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <input type="hidden" name="id" value="0">
        <input type="hidden" name="dir" value="">
      </form>
    </div>
  </div>

</div>

<script>
function vxToggleAll(el){
  document.querySelectorAll('.vxRowCheck').forEach(cb => cb.checked = !!el.checked);
}
function vxConfirmBulk(){
  const sel = document.querySelector('select[name="bulk_action"]');
  const a = sel ? sel.value : '';
  if (!a) { alert('Pick a bulk action first.'); return false; }
  const checked = document.querySelectorAll('.vxRowCheck:checked').length;
  if (!checked) { alert('Select at least one plan.'); return false; }
  if (a === 'delete') return confirm('Delete selected plans? This cannot be undone.');
  return true;
}
function vxTogglePicker(){
  const el = document.getElementById('vxPicker');
  if (!el) return;
  el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
}
function vxSetImg(k){
  const inp = document.getElementById('img_key');
  if (inp) inp.value = k;
  vxTogglePicker();
}
</script>
