<?php
// pages/adminka/js_errors.php
declare(strict_types=1);
if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $opt, $adm;
$opt['title'] = 'JS Errors';

require_once __DIR__ . '/../../core/events_log.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$days = (int)($_GET['days'] ?? 3);
if ($days < 1) $days = 1;
if ($days > 30) $days = 30;
$since = time() - ($days * 86400);

vx_events_log_ensure($db);

$rows = [];
try {
  $rows = $db->query('SELECT id, uid, meta, created_at FROM events_log WHERE event=? AND created_at>=? ORDER BY id DESC LIMIT 250', 'js_error', $since)->fetchAll();
} catch (Throwable $e) {
  $rows = [];
}

// Aggregate quick stats
$byPage = [];
$byMsg = [];
foreach ($rows as $r) {
  $meta = (string)($r['meta'] ?? '');
  $j = json_decode($meta, true);
  if (!is_array($j)) $j = [];
  $page = (string)($j['page'] ?? '');
  $msg  = (string)($j['msg'] ?? '');
  if ($page !== '') $byPage[$page] = ($byPage[$page] ?? 0) + 1;
  if ($msg !== '')  $byMsg[$msg]   = ($byMsg[$msg] ?? 0) + 1;
}
arsort($byPage);
arsort($byMsg);

include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/menu.php';
?>

<div class="vx-root">
  <div class="vx-hero" style="margin-bottom:14px;">
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <h3 style="margin:0;">JS Errors</h3>
        <div class="vx-sub">Client-side error stream captured from Telegram WebView + desktop browser. (Max 6/min per session; stored fail-soft.)</div>
      </div>
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <label style="margin:0;color:var(--muted);font-size:12px;font-weight:800">Range</label>
        <select name="days" class="form-control" style="width:140px">
          <?php foreach ([1,3,7,14,30] as $d): ?>
            <option value="<?= (int)$d; ?>" <?= $days===$d?'selected':''; ?>>Last <?= (int)$d; ?> day<?= $d===1?'':'s'; ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Apply</button>
      </form>
    </div>
  </div>

  <div class="row" style="display:flex;gap:14px;flex-wrap:wrap;margin:0 0 14px;">
    <div class="vx-card" style="flex:1;min-width:320px;">
      <div class="vx-head"><strong>Top pages</strong><span class="vx-pill"><?= count($rows); ?> events</span></div>
      <div class="vx-body">
        <?php if (!$byPage): ?>
          <div class="vx-sub">No data.</div>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:8px">
            <?php $i=0; foreach ($byPage as $k=>$v): if(++$i>8) break; ?>
              <div style="display:flex;justify-content:space-between;gap:10px;">
                <div style="color:var(--text);font-weight:800;max-width:78%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($k); ?></div>
                <div class="vx-pill" style="font-weight:900"><?= (int)$v; ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="vx-card" style="flex:1;min-width:320px;">
      <div class="vx-head"><strong>Top messages</strong><span class="vx-pill">Grouped</span></div>
      <div class="vx-body">
        <?php if (!$byMsg): ?>
          <div class="vx-sub">No data.</div>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:8px">
            <?php $i=0; foreach ($byMsg as $k=>$v): if(++$i>8) break; ?>
              <div style="display:flex;justify-content:space-between;gap:10px;">
                <div style="color:var(--text);font-weight:800;max-width:78%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($k); ?></div>
                <div class="vx-pill" style="font-weight:900"><?= (int)$v; ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="vx-card">
    <div class="vx-head"><strong>Latest events</strong><span class="vx-pill">last 250</span></div>
    <div class="vx-body">
      <?php if (!$rows): ?>
        <div class="vx-sub">No errors captured in this time range.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-dark table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>#</th><th>Time</th><th>UID</th><th>Page</th><th>Message</th><th>Source</th><th>Stack</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $meta = json_decode((string)($r['meta'] ?? ''), true);
                if (!is_array($meta)) $meta = [];
                $stack = (string)($meta['stack'] ?? '');
                if (strlen($stack) > 180) $stack = substr($stack, 0, 180) . '…';
              ?>
                <tr>
                  <td class="vx-mono"><?= (int)($r['id'] ?? 0); ?></td>
                  <td class="vx-mono"><?= date('m-d H:i:s', (int)($r['created_at'] ?? 0)); ?></td>
                  <td class="vx-mono"><?= (int)($r['uid'] ?? 0); ?></td>
                  <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h((string)($meta['page'] ?? '')); ?></td>
                  <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h((string)($meta['msg'] ?? '')); ?></td>
                  <td class="vx-mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h((string)($meta['src'] ?? '')); ?><?= !empty($meta['line']) ? (':'.(int)$meta['line']) : ''; ?><?= !empty($meta['col']) ? (':'.(int)$meta['col']) : ''; ?></td>
                  <td class="vx-mono" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($stack); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include __DIR__ . '/inc/foot.php'; ?>
