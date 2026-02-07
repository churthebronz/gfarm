<?php
// pages/adminka/audit.php (GreenFarm Admin • Audit log) — Premium UI
declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm;

require_once __DIR__ . '/inc/admin_ops.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$opt['title'] = 'Audit log';

$event = trim((string)($_GET['event'] ?? ''));
$uid   = (int)($_GET['uid'] ?? 0);
$q     = trim((string)($_GET['q'] ?? ''));
$page  = max(1, (int)($_GET['p'] ?? 1));
$limit = 50;
$off   = ($page-1) * $limit;

// Best-effort schema detection (expected: uid, event, meta, created_at)
$has = function(string $col) use ($db): bool {
  try {
    $r = $db->query(
      "SELECT COLUMN_NAME
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events_log' AND COLUMN_NAME=?
       LIMIT 1",
      $col
    )->fetchArray();
    return !empty($r);
  } catch (Throwable $e) { return false; }
};

$schemaOk = $has('event') && $has('meta') && $has('created_at'); // uid often exists in your setup

// Pull rows
$rows = [];
$errMsg = '';
try {
  $where = [];
  $args = [];
  if ($event !== '') { $where[] = 'event = ?'; $args[] = $event; }
  if ($uid > 0) { $where[] = 'uid = ?'; $args[] = $uid; }
  if ($q !== '') { $where[] = 'meta LIKE ?'; $args[] = '%'.$q.'%'; }
  $w = $where ? ('WHERE '.implode(' AND ', $where)) : '';
  $sql = "SELECT id, uid, event, meta, created_at
          FROM events_log
          $w
          ORDER BY id DESC
          LIMIT $limit OFFSET $off";
  $qq = $db->query($sql, ...$args);
  while ($r = $qq->fetchArray()) { $rows[] = $r; }
} catch (Throwable $e) {
  $errMsg = 'Query failed: '.$e->getMessage();
}

// UI helpers
$filtersActive = ($event !== '' || $uid > 0 || $q !== '');
$chip = function(string $label, string $value, string $kind='') {
  $cls = 'vx-pill';
  if ($kind === 'ok') $cls .= ' ok';
  else if ($kind === 'bad') $cls .= ' bad';
  else if ($kind === 'wait') $cls .= ' wait';
  return '<span class="'.$cls.'"><span style="opacity:.85">'.$label.'</span> <b>'.$value.'</b></span>';
};

function event_badge(string $ev): string {
  $evl = strtolower($ev);
  $cls = 'vx-ev';
  if (strpos($evl, 'admin') === 0) $cls .= ' ev-admin';
  else if (strpos($evl, 'risk') !== false || strpos($evl, 'abuse') !== false) $cls .= ' ev-risk';
  else if (strpos($evl, 'payout') !== false || strpos($evl, 'withdraw') !== false) $cls .= ' ev-pay';
  else if (strpos($evl, 'deposit') !== false || strpos($evl, 'pay') !== false) $cls .= ' ev-dep';
  else if (strpos($evl, 'ref') !== false) $cls .= ' ev-ref';
  else $cls .= ' ev-sys';
  return '<span class="'.$cls.'">'.h($ev).'</span>';
}

function meta_preview(string $meta): string {
  $m = trim($meta);
  if ($m === '') return '';
  // Make a compact one-line preview
  $one = preg_replace('/\s+/', ' ', $m);
  if (strlen($one) > 140) $one = substr($one, 0, 140).'…';
  return $one;
}

?>
<style>
/* ===== GreenFarm Audit UI Skin (matches Risk) ===== */
:root{
  --vx-bg1:#0b1020;
  --vx-bg2:#0f1730;
  --vx-text:#e7edf7;
  --vx-muted: rgba(231,237,247,.66);
  --vx-muted2: rgba(231,237,247,.45);
  --vx-shadow: 0 18px 60px rgba(0,0,0,.55);
}

.vx-audit-wrap{
  max-width: 1300px;
  margin: 0 auto;
  background:
    radial-gradient(1200px 700px at 12% 8%, rgba(59,130,246,.18), transparent 60%),
    radial-gradient(1000px 600px at 92% 12%, rgba(245,158,11,.16), transparent 62%),
    radial-gradient(900px 600px at 60% 110%, rgba(34,197,94,.10), transparent 55%),
    linear-gradient(180deg, var(--vx-bg2), var(--vx-bg1));
  border: 1px solid rgba(255,255,255,.06);
  border-radius: 18px;
  box-shadow: var(--vx-shadow);
  padding: 14px;
}

.vx-card{
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
  box-shadow: 0 12px 40px rgba(0,0,0,.40);
  overflow: hidden;
}

.vx-card-hd{
  display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;
  padding: 14px 14px 12px;
  border-bottom: 1px solid rgba(255,255,255,.10);
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.015));
}

.vx-card-bd{ padding: 12px 14px; }

.vx-kicker{
  display:inline-flex; align-items:center; gap:8px;
  border-radius: 999px;
  padding: 6px 12px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  box-shadow: 0 10px 30px rgba(0,0,0,.35);
}
.vx-title{ margin:0; letter-spacing:.2px; font-weight:1000; color: var(--vx-text); }
.vx-sub{ margin-top:6px; color: var(--vx-muted); }

.vx-pill{
  display:inline-flex; align-items:center; gap:8px;
  border-radius: 999px;
  padding: 6px 10px;
  font-size: 12px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
}
.vx-pill.ok{ border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); color: rgba(201,253,216,1); }
.vx-pill.bad{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); color: rgba(255,208,208,1); }
.vx-pill.wait{ border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); color: rgba(255,226,179,1); }

.vx-btn{
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 9px 12px;
  font-weight: 900;
  cursor: pointer;
  display:inline-flex; align-items:center; gap:8px;
  transition: transform .12s ease, border-color .12s ease, background .12s ease, opacity .12s ease;
  text-decoration:none;
}
.vx-btn:hover{
  transform: translateY(-1px);
  border-color: rgba(59,130,246,.35);
  background: rgba(59,130,246,.10);
}
.vx-btn:active{ transform: translateY(0); }

.vx-in{
  width:100%;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 10px 12px;
  outline:none;
}
.vx-in::placeholder{ color: var(--vx-muted2); }
.vx-in:focus{
  border-color: rgba(59,130,246,.45);
  box-shadow: 0 0 0 3px rgba(59,130,246,.18);
}
.vx-lab{ display:block; color: var(--vx-muted); font-size: 12px; font-weight: 900; margin:0 0 6px; }

.vx-alert{
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.04);
  color: var(--vx-text);
  padding: 10px 12px;
}
.vx-alert.bad{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); color: rgba(255,208,208,1); }

.vx-table{
  width:100%;
  border-collapse: separate;
  border-spacing: 0;
}
.vx-table thead th{
  position: sticky;
  top: 0;
  z-index: 2;
  background: rgba(15,23,48,.92);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(255,255,255,.12);
  color: var(--vx-muted);
  text-transform: uppercase;
  letter-spacing: .35px;
  font-size: 11px;
  padding: 10px 10px;
}
.vx-table td{
  border-bottom: 1px solid rgba(255,255,255,.08);
  padding: 10px 10px;
  color: var(--vx-text);
  vertical-align: top;
}
.vx-table tbody tr{ background: rgba(255,255,255,.015); }
.vx-table tbody tr:hover{ background: rgba(59,130,246,.06); }

.vx-mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
.vx-mini{ color: var(--vx-muted); font-size: 12px; }

.vx-meta-preview{
  opacity:.85;
  color: var(--vx-text);
  font-size: 13px;
  margin-bottom: 8px;
}
.vx-details summary{
  cursor:pointer;
  user-select:none;
  display:inline-flex; align-items:center; gap:8px;
  padding: 6px 10px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.03);
  color: var(--vx-text);
}
.vx-details summary:hover{
  border-color: rgba(59,130,246,.35);
  background: rgba(59,130,246,.08);
}
.vx-details pre{
  margin:10px 0 0;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(0,0,0,.22);
  white-space: pre-wrap;
  word-break: break-word;
  opacity:.92;
}

.vx-ev{
  display:inline-flex;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 900;
  border: 1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.03);
}
.ev-admin{ border-color: rgba(59,130,246,.35); background: rgba(59,130,246,.10); }
.ev-risk{ border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); }
.ev-pay { border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }
.ev-dep { border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); }
.ev-ref { border-color: rgba(168,85,247,.35); background: rgba(168,85,247,.10); }
.ev-sys { border-color: rgba(255,255,255,.12); background: rgba(255,255,255,.04); }

.vx-toprow{
  display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between;
  margin-top:10px;
}
.vx-chips{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
</style>

<div class="vx-audit-wrap">
  <div class="vx-card">
    <div class="vx-card-hd">
      <div>
        <div class="vx-kicker"><i class="fa fa-list"></i> Audit</div>
        <h1 class="vx-title">Audit log</h1>
        <div class="vx-sub">Last actions & system events. Use this while testing referrals, deposits, withdrawals and admin actions.</div>

        <div class="vx-toprow">
          <div class="vx-chips">
            <?= $chip('schema', $schemaOk ? 'OK' : 'CHECK', $schemaOk ? 'ok' : 'wait'); ?>
            <?= $chip('filters', $filtersActive ? 'ON' : 'OFF', $filtersActive ? 'wait' : 'ok'); ?>
            <?= $chip('page', (string)$page, 'ok'); ?>
            <?= $chip('rows', (string)count($rows), count($rows) ? 'ok' : 'wait'); ?>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="vx-btn" href="/<?= h($adm); ?>/audit"><i class="fa fa-undo"></i> Reset</a>
        <a class="vx-btn" href="/<?= h($adm); ?>/risk"><i class="fa fa-shield"></i> Risk</a>
      </div>
    </div>

    <div class="vx-card-bd">
      <?php if (!$schemaOk): ?>
        <div class="vx-alert" style="border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08);margin-bottom:12px">
          Your <span class="vx-mono">events_log</span> schema may not match expected columns
          (<span class="vx-mono">uid,event,meta,created_at</span>). If empty, fix your table or align your logger.
        </div>
      <?php endif; ?>

      <?php if ($errMsg !== ''): ?>
        <div class="vx-alert bad" style="margin-bottom:12px"><?= h($errMsg); ?></div>
      <?php endif; ?>

      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px">
        <div style="min-width:240px;flex:0">
          <label class="vx-lab">Event</label>
          <input class="vx-in" name="event" value="<?= h($event); ?>" placeholder="admin.deposit_confirm, ref_apply, ...">
        </div>

        <div style="width:160px;flex:0">
          <label class="vx-lab">UID</label>
          <input class="vx-in" type="number" name="uid" value="<?= $uid ? (int)$uid : ''; ?>" placeholder="e.g. 1">
        </div>

        <div style="min-width:260px;flex:1">
          <label class="vx-lab">Search meta</label>
          <input class="vx-in" name="q" value="<?= h($q); ?>" placeholder="rid, start_param, ip, txid...">
        </div>

        <button class="vx-btn" type="submit"><i class="fa fa-search"></i> Filter</button>
      </form>

      <div style="overflow:auto;border-radius:16px;border:1px solid rgba(255,255,255,.08)">
        <table class="vx-table">
          <thead>
            <tr>
              <th style="width:80px">ID</th>
              <th style="width:110px">UID</th>
              <th style="width:260px">Event</th>
              <th>Meta</th>
              <th style="width:190px;text-align:right">Time</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="opacity:.8">No rows found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <?php
              $id  = (int)($r['id'] ?? 0);
              $ruid = (int)($r['uid'] ?? 0);
              $ev  = (string)($r['event'] ?? '');
              $meta = (string)($r['meta'] ?? '');

              $pretty = '';
              $arr = json_decode($meta, true);
              if (is_array($arr)) {
                $pretty = json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
              }
              $preview = meta_preview($meta);
              $ts = (int)($r['created_at'] ?? 0);
            ?>
            <tr>
              <td class="vx-mono"><?= $id; ?></td>
              <td>
                <a href="/<?= h($adm); ?>/users?uid=<?= $ruid; ?>" class="vx-mono" style="text-decoration:none">
                  #<?= $ruid; ?>
                </a>
              </td>
              <td class="vx-mono"><?= event_badge($ev); ?></td>
              <td style="max-width:760px">
                <?php if ($preview !== ''): ?>
                  <div class="vx-meta-preview"><?= h($preview); ?></div>
                <?php endif; ?>
                <details class="vx-details">
                  <summary><i class="fa fa-code"></i> View full meta</summary>
                  <pre class="vx-mono"><?= h($pretty !== '' ? $pretty : $meta); ?></pre>
                </details>
              </td>
              <td class="vx-mono" style="text-align:right"><?= $ts > 0 ? date('Y-m-d H:i:s', $ts) : ''; ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px">
        <div class="vx-mini">Page <?= (int)$page; ?> • showing <?= (int)count($rows); ?> rows</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($page > 1): ?>
            <a class="vx-btn" href="?<?= h(http_build_query(array_merge($_GET, ['p'=>$page-1]))); ?>"><i class="fa fa-chevron-left"></i> Prev</a>
          <?php endif; ?>
          <?php if (count($rows) === $limit): ?>
            <a class="vx-btn" href="?<?= h(http_build_query(array_merge($_GET, ['p'=>$page+1]))); ?>">Next <i class="fa fa-chevron-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php /* footer rendered by wrapper */ ?>
