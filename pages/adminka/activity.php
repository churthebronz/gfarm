<?php
// pages/adminka/activity.php (GreenFarm Admin • Public Activity feed source)
declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm;

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/vx_activity.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$opt['title'] = 'Activity log';

try { vx_activity_schema_ensure($db); } catch (Throwable $e) {}

$type = trim((string)($_GET['type'] ?? ''));
$uid  = (int)($_GET['uid'] ?? 0);
$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 80;
$off = ($page - 1) * $limit;

$rows = [];
$err = '';
try {
  $where = [];
  $args = [];
  if ($type !== '') { $where[] = 'type = ?'; $args[] = $type; }
  if ($uid > 0) { $where[] = 'uid = ?'; $args[] = $uid; }
  if ($q !== '') { $where[] = 'meta_json LIKE ?'; $args[] = '%'.$q.'%'; }
  $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  $sql = "SELECT id, uid, type, amount, meta_json, created_at FROM vx_activity_log $w ORDER BY id DESC LIMIT $limit OFFSET $off";
  $qq = $db->query($sql, ...$args);
  while ($r = $qq->fetchArray()) { $rows[] = $r; }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

// quick uid->name
$uMap = [];
try {
  $uids = [];
  foreach ($rows as $r) { $uids[(int)($r['uid'] ?? 0)] = true; }
  $ids = array_keys($uids);
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $qq = $db->query("SELECT id, login, tg_username, tg_name FROM db_users WHERE id IN ($in)", ...$ids);
    while ($u = $qq->fetchArray()) {
      $id = (int)($u['id'] ?? 0);
      $tg = trim((string)($u['tg_username'] ?? ''));
      $name = $tg !== '' ? ('@'.ltrim($tg,'@')) : (trim((string)($u['tg_name'] ?? '')) ?: (trim((string)($u['login'] ?? '')) ?: ('User#'.$id)));
      $uMap[$id] = $name;
    }
  }
} catch (Throwable $e) {}

include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/menu.php';
?>

<div class="vx-admin-card">
  <div class="vx-admin-card-h">
    <div>
      <h2 style="margin:0">Activity log</h2>
      <div class="vx-admin-sub">This powers the public tickers (homepage + dashboard). Useful for monitoring virality, crossbreed pressure and season momentum.</div>
    </div>
  </div>

  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:12px 0 14px">
    <div style="min-width:200px">
      <div class="vx-admin-label">Type</div>
      <input class="vx-admin-in" name="type" value="<?= h($type); ?>" placeholder="seed_buy, harvest, crossbreed, index, rank_up...">
    </div>
    <div style="min-width:160px">
      <div class="vx-admin-label">UID</div>
      <input class="vx-admin-in" name="uid" value="<?= $uid > 0 ? (int)$uid : ''; ?>" placeholder="123">
    </div>
    <div style="flex:1;min-width:240px">
      <div class="vx-admin-label">Meta contains</div>
      <input class="vx-admin-in" name="q" value="<?= h($q); ?>" placeholder="rarity, season_id, title...">
    </div>
    <div>
      <button class="vx-admin-btn" type="submit"><i class="fa fa-search"></i> Filter</button>
    </div>
  </form>

  <?php if ($err): ?>
    <div class="vx-admin-alert bad">Query failed: <?= h($err); ?></div>
  <?php endif; ?>

  <div style="overflow:auto">
    <table class="vx-admin-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Time</th>
          <th>User</th>
          <th>Type</th>
          <th>Amount</th>
          <th>Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="6" style="opacity:.8">No rows</td></tr>
        <?php else: foreach ($rows as $r):
          $id = (int)($r['id'] ?? 0);
          $ruid = (int)($r['uid'] ?? 0);
          $nm = $uMap[$ruid] ?? ('User#'.$ruid);
          $t = (string)($r['type'] ?? '');
          $amt = (float)($r['amount'] ?? 0);
          $mj = (string)($r['meta_json'] ?? '');
          $ts = (int)($r['created_at'] ?? 0);
        ?>
          <tr>
            <td><?= $id; ?></td>
            <td><?= $ts>0? h(date('Y-m-d H:i:s', $ts)) : '—'; ?></td>
            <td><b><?= h($nm); ?></b> <span style="opacity:.7">(#<?= $ruid; ?>)</span></td>
            <td><span class="vx-pill" style="padding:4px 8px"><?= h($t); ?></span></td>
            <td><?= $amt ? h(number_format($amt, 6, '.', '')) : '0'; ?></td>
            <td style="max-width:560px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              <code style="opacity:.85"><?= h($mj); ?></code>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
    <div style="opacity:.8">Page <?= (int)$page; ?></div>
    <div style="display:flex;gap:8px">
      <?php if ($page > 1): ?>
        <a class="vx-admin-btn secondary" href="?type=<?= urlencode($type); ?>&uid=<?= (int)$uid; ?>&q=<?= urlencode($q); ?>&p=<?= (int)($page-1); ?>">← Prev</a>
      <?php endif; ?>
      <?php if (count($rows) >= $limit): ?>
        <a class="vx-admin-btn secondary" href="?type=<?= urlencode($type); ?>&uid=<?= (int)$uid; ?>&q=<?= urlencode($q); ?>&p=<?= (int)($page+1); ?>">Next →</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/inc/foot.php';
?>
