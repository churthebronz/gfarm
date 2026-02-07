<?php
// pages/adminka/stats.php (GreenFarm Admin • Deposits / Withdrawals • ops-grade)
declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm, $pg;

// Ensure session is available for CSRF + admin uid
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Route is /{adm}/st/{inserts|payouts}. When regex routes are used, the router
// still keeps full path segments, but we also fallback to params capture.
$seg2 = (string)($pg->segment[2] ?? ($pg->params[1] ?? ''));
$seg2 = strtolower(trim($seg2));
$mode = ($seg2 === 'payouts') ? 'payouts' : 'inserts';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function vx_admin_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['_adm_csrf'])) {
        $_SESSION['_adm_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['_adm_csrf'];
}
function vx_admin_csrf_ok(string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    return hash_equals((string)($_SESSION['_adm_csrf'] ?? ''), (string)$token);
}

function vx_admin_log($db, int $adminUid, string $event, array $meta = []): void {
    try {
        $db->query(
            "INSERT INTO events_log (uid, event, meta, created_at) VALUES (?, ?, ?, ?)",
            $adminUid,
            $event,
            json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            time()
        );
    } catch (Throwable $e) {
        // ignore
    }
}

function vx_money(float $n): string {
    return number_format($n, 2, '.', '');
}

function vx_status_pill(int $status, string $kind): string {
    // kind: inserts|payouts
    if ($kind === 'inserts') {
        if ($status === 1) return '<span class="vx-pill ok">confirmed</span>';
        if ($status === 0) return '<span class="vx-pill wait">pending</span>';
        return '<span class="vx-pill bad">status '.$status.'</span>';
    }
    // payouts
    if ($status === 3) return '<span class="vx-pill ok">paid</span>';
    if ($status === 1) return '<span class="vx-pill wait">pending</span>';
    if ($status === 2) return '<span class="vx-pill bad">canceled</span>';
    return '<span class="vx-pill">status '.$status.'</span>';
}

function vx_pairs_rate($db, string $currency): float {
    try {
        $ps = $db->query('SELECT * FROM db_paysystem WHERE currency = ? LIMIT 1', strtoupper($currency))->fetchArray();
        if ($ps && isset($ps['pairs'])) {
            $r = (float)$ps['pairs'];
            return $r > 0 ? $r : 1.0;
        }
    } catch (Throwable $e) {}
    return 1.0;
}

$uidAdmin = (int)($_SESSION['uid'] ?? 0);
$csrf = vx_admin_csrf_token();

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$status = isset($_GET['status']) ? (int)$_GET['status'] : -1;
$sys = strtoupper(trim((string)($_GET['sys'] ?? '')));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 200;
$offset = ($page - 1) * $limit;

// Actions
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = (string)($_POST['csrf'] ?? '');
    if (!vx_admin_csrf_ok($postCsrf)) {
        $flash = 'Invalid CSRF token.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        if ($mode === 'inserts' && $act === 'manual_confirm') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $row = $db->query('SELECT * FROM db_insert WHERE id=? LIMIT 1', $id)->fetchArray();
                    if (!$row) {
                        $flash = 'Deposit not found.';
                    } else if ((int)($row['status'] ?? 0) !== 0) {
                        $flash = 'Deposit already processed.';
                    } else {
                        $db->beginTransaction();
                        $lock = $db->query('SELECT id, uid, sum, sys, status FROM db_insert WHERE id=? LIMIT 1 FOR UPDATE', $id)->fetchArray();
                        if (!$lock || (int)($lock['status'] ?? 0) !== 0) {
                            $db->rollBack();
                            $flash = 'Deposit already processed (race).';
                        } else {
                            $amount = (float)($lock['sum'] ?? 0);
                            $currency = (string)($lock['sys'] ?? '');
                            $rate = vx_pairs_rate($db, $currency);
                            $sumUSD = round($amount * $rate, 2);
                            $timeNow = time();
                            $db->query('UPDATE db_insert SET status=1, sum=?, sum_x=?, `end`=? WHERE id=? AND status=0', $sumUSD, $sumUSD, $timeNow, $id);
                            $rc = (int)($db->query('SELECT ROW_COUNT() AS rc')->fetchArray()['rc'] ?? 0);
                            if ($rc <= 0) {
                                $db->commit();
                                $flash = 'Deposit already processed.';
                            } else {
                                $u = (int)($lock['uid'] ?? 0);
                                $db->query('UPDATE db_users SET sum_in=sum_in+?, money_p=money_p+? WHERE id=?', $sumUSD, $sumUSD, $u);
                                $db->query('UPDATE db_stats SET inserts=inserts+? WHERE id=1', $sumUSD);
                                // Referral payouts are already idempotent on db_insert.ref_credited in paykassa.php.
                                // We won't call it here to avoid double-ref if merchant triggers webhook later.
                                $db->commit();
                                vx_admin_log($db, $uidAdmin, 'admin_manual_confirm_deposit', ['deposit_id'=>$id,'uid'=>$u,'sum_usd'=>$sumUSD,'sys'=>$currency]);
                                $flash = 'Deposit manually confirmed + credited ('.$sumUSD.' USD).';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    try { $db->rollBack(); } catch (Throwable $e2) {}
                    $flash = 'Manual confirm failed.';
                }
            }
        }

        if ($mode === 'payouts' && $act === 'mark_paid') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $row = $db->query('SELECT id,status,uid,sum,sys FROM db_payout WHERE id=? LIMIT 1', $id)->fetchArray();
                    if (!$row) {
                        $flash = 'Withdrawal not found.';
                    } else {
                        $db->query('UPDATE db_payout SET status=3 WHERE id=? LIMIT 1', $id);
                        vx_admin_log($db, $uidAdmin, 'admin_mark_withdraw_paid', ['payout_id'=>$id,'uid'=>(int)$row['uid'],'sum'=>(float)$row['sum'],'sys'=>(string)$row['sys']]);
                        $flash = 'Withdrawal marked as PAID.';
                    }
                } catch (Throwable $e) {
                    $flash = 'Failed to update withdrawal.';
                }
            }
        }

        if ($mode === 'payouts' && $act === 'cancel_refund') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $row = $db->query('SELECT * FROM db_payout WHERE id=? LIMIT 1', $id)->fetchArray();
                    if (!$row) {
                        $flash = 'Withdrawal not found.';
                    } else if ((int)($row['status'] ?? 0) === 3) {
                        $flash = 'Cannot cancel: already paid.';
                    } else {
                        $db->beginTransaction();
                        $lock = $db->query('SELECT id,uid,sum,status FROM db_payout WHERE id=? LIMIT 1 FOR UPDATE', $id)->fetchArray();
                        if (!$lock || (int)($lock['status'] ?? 0) === 3) {
                            $db->rollBack();
                            $flash = 'Cannot cancel: already paid.';
                        } else {
                            $u = (int)($lock['uid'] ?? 0);
                            $sum = (float)($lock['sum'] ?? 0);
                            $db->query('UPDATE db_payout SET status=2 WHERE id=? LIMIT 1', $id);
                            // Refund to spendable balance
                            $db->query('UPDATE db_users SET money_p = money_p + ? WHERE id=?', $sum, $u);
                            $db->commit();
                            vx_admin_log($db, $uidAdmin, 'admin_cancel_withdraw_refund', ['payout_id'=>$id,'uid'=>$u,'sum'=>$sum]);
                            $flash = 'Withdrawal canceled + refunded.';
                        }
                    }
                } catch (Throwable $e) {
                    try { $db->rollBack(); } catch (Throwable $e2) {}
                    $flash = 'Cancel/refund failed.';
                }
            }
        }
    }
}

// Build WHERE
$where = [];
$args = [];

// Hide "system" rows by default (seed/demo rows often have uid=0).
// You can show them with ?system=1
$showSystem = ((int)($_GET['system'] ?? 0) === 1);

// Date range helper (expects YYYY-MM-DD)
$parseDate = function(string $d, bool $end): int {
    $ts = strtotime($d . ($end ? ' 23:59:59' : ' 00:00:00'));
    return $ts ? $ts : 0;
};

if ($status !== -1) { $where[] = 'status = ?'; $args[] = $status; }
if ($sys !== '') {
    $where[] = 'UPPER(sys) = ?';
    $args[] = $sys;
}
if ($from !== '') {
    $ts = $parseDate($from, false);
    if ($ts > 0) { $where[] = '`add` >= ?'; $args[] = $ts; }
}
if ($to !== '') {
    $ts = $parseDate($to, true);
    if ($ts > 0) { $where[] = '`add` <= ?'; $args[] = $ts; }
}
if ($q !== '') {
    // search by id, uid, login, wallet, txid
    if (ctype_digit($q)) {
        $where[] = '(id = ? OR uid = ?)';
        $args[] = (int)$q;
        $args[] = (int)$q;
    } else {
        $where[] = '(login LIKE ? OR purse LIKE ? OR txid LIKE ?)';
        $args[] = '%'.$q.'%';
        $args[] = '%'.$q.'%';
        $args[] = '%'.$q.'%';
    }
}

// In withdrawals mode, exclude uid=0 unless explicitly requested.
if ($mode !== 'inserts' && !$showSystem) {
    $where[] = 'uid > 0';
}

$whereSql = !empty($where) ? ('WHERE '.implode(' AND ', $where)) : '';

// Summaries
$sum = ['count'=>0,'total'=>0.0,'pending'=>0,'pending_total'=>0.0,'ok'=>0,'ok_total'=>0.0];
try {
    if ($mode === 'inserts') {
        $sum['count'] = (int)($db->query("SELECT COUNT(*) AS c FROM db_insert")->fetchArray()['c'] ?? 0);
        $sum['total'] = (float)($db->query("SELECT IFNULL(SUM(sum),0) AS s FROM db_insert WHERE status=1")->fetchArray()['s'] ?? 0);
        $sum['pending'] = (int)($db->query("SELECT COUNT(*) AS c FROM db_insert WHERE status=0")->fetchArray()['c'] ?? 0);
        $sum['pending_total'] = (float)($db->query("SELECT IFNULL(SUM(sum),0) AS s FROM db_insert WHERE status=0")->fetchArray()['s'] ?? 0);
        $sum['ok'] = (int)($db->query("SELECT COUNT(*) AS c FROM db_insert WHERE status=1")->fetchArray()['c'] ?? 0);
        $sum['ok_total'] = (float)($db->query("SELECT IFNULL(SUM(sum),0) AS s FROM db_insert WHERE status=1")->fetchArray()['s'] ?? 0);
    } else {
        // Keep summaries aligned with the list filter (hide uid=0 by default).
        $w = $showSystem ? '' : 'WHERE uid > 0';
        $w3 = $showSystem ? 'WHERE status=3' : 'WHERE uid > 0 AND status=3';
        $w1 = $showSystem ? 'WHERE status=1' : 'WHERE uid > 0 AND status=1';

        $sum['count'] = (int)($db->query("SELECT COUNT(*) AS c FROM db_payout $w")->fetchArray()['c'] ?? 0);
        $sum['total'] = (float)($db->query("SELECT IFNULL(SUM(sum),0) AS s FROM db_payout $w3")->fetchArray()['s'] ?? 0);
        $sum['pending'] = (int)($db->query("SELECT COUNT(*) AS c FROM db_payout $w1")->fetchArray()['c'] ?? 0);
        $sum['pending_total'] = (float)($db->query("SELECT IFNULL(SUM(sum),0) AS s FROM db_payout $w1")->fetchArray()['s'] ?? 0);
        $sum['ok'] = (int)($db->query("SELECT COUNT(*) AS c FROM db_payout $w3")->fetchArray()['c'] ?? 0);
        $sum['ok_total'] = (float)($db->query("SELECT IFNULL(SUM(sum),0) AS s FROM db_payout $w3")->fetchArray()['s'] ?? 0);
    }
} catch (Throwable $e) {}

// Data query
$rows = [];
try {
    if ($mode === 'inserts') {
        $sql = "SELECT * FROM db_insert $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset";
        $q1 = $db->query($sql, ...$args);
        while ($r = $q1->fetchArray()) { $rows[] = $r; }
    } else {
        $sql = "SELECT * FROM db_payout $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset";
        $q1 = $db->query($sql, ...$args);
        while ($r = $q1->fetchArray()) { $rows[] = $r; }
    }
} catch (Throwable $e) {
    $flash = $flash ?: 'Query failed.';
}

$title = ($mode === 'inserts') ? 'Deposits' : 'Withdrawals';
?>

<style>
.vx-ops-wrap{max-width:1200px;margin:0 auto}
.vx-ops-card{border-radius:18px;border:1px solid rgba(148,163,184,.16);background:linear-gradient(180deg,rgba(10,16,32,.60),rgba(2,6,23,.52));box-shadow:0 14px 40px rgba(0,0,0,.45);overflow:hidden}
.vx-ops-hd{padding:14px 14px 10px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
.vx-ops-title{font-weight:900;font-size:18px;margin:0}
.vx-ops-sub{opacity:.78;margin-top:2px}
.vx-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:0 14px 14px}
@media(max-width:980px){.vx-grid{grid-template-columns:1fr}}
.vx-stat{border:1px solid rgba(148,163,184,.14);border-radius:14px;padding:10px 12px;background:rgba(15,23,42,.35)}
.vx-stat .k{opacity:.7;font-size:12px}
.vx-stat .v{font-weight:900;font-size:18px;margin-top:2px}
.vx-filters{padding:0 14px 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:end}
.vx-filters label{display:block;font-size:12px;opacity:.75;margin:0 0 4px}
.vx-filters input,.vx-filters select{width:100%;min-width:180px;padding:8px 10px;border-radius:12px;border:1px solid rgba(148,163,184,.18);background:rgba(2,6,23,.35);color:#e5e7eb}
.vx-filters .btn{border-radius:999px}
.vx-table{width:100%;border-collapse:separate;border-spacing:0}
.vx-table th,.vx-table td{padding:10px 10px;border-top:1px solid rgba(148,163,184,.10);vertical-align:top}
.vx-table th{opacity:.75;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.vx-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:999px;border:1px solid rgba(148,163,184,.16);background:rgba(15,23,42,.35);font-weight:800;font-size:12px}
.vx-pill.ok{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.10)}
.vx-pill.bad{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.10)}
.vx-pill.wait{border-color:rgba(251,191,36,.25);background:rgba(251,191,36,.10)}
.vx-actions{display:flex;gap:6px;flex-wrap:wrap}
.vx-mini{font-size:12px;opacity:.78}
.vx-flash{margin:0 0 10px;padding:10px 12px;border-radius:14px;border:1px solid rgba(251,191,36,.22);background:rgba(251,191,36,.08)}
.vx-mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono','Courier New', monospace}
</style>

<div class="vx-ops-wrap">
  <?php if ($flash !== ''): ?>
    <div class="vx-flash"><?= h($flash); ?></div>
  <?php endif; ?>

  <div class="vx-ops-card">
    <div class="vx-ops-hd">
      <div>
        <h2 class="vx-ops-title"><?= h($title); ?></h2>
        <div class="vx-ops-sub vx-mini">Latest first • Limit <?= (int)$limit; ?> per page</div>
      </div>
      <div class="vx-actions">
        <a class="btn btn-sm btn-dark" href="/<?= h($adm); ?>/st/<?= $mode === 'inserts' ? 'inserts' : 'payouts'; ?>">Reset filters</a>
      </div>
    </div>

    <div class="vx-grid">
      <div class="vx-stat"><div class="k">All records</div><div class="v"><?= (int)$sum['count']; ?></div></div>
      <div class="vx-stat"><div class="k">Pending</div><div class="v"><?= (int)$sum['pending']; ?> <span class="vx-mini">(<?= vx_money((float)$sum['pending_total']); ?>)</span></div></div>
      <div class="vx-stat"><div class="k"><?= $mode === 'inserts' ? 'Confirmed' : 'Paid'; ?></div><div class="v"><?= (int)$sum['ok']; ?> <span class="vx-mini">(<?= vx_money((float)$sum['ok_total']); ?>)</span></div></div>
    </div>

    <form class="vx-filters" method="get" action="">
      <input type="hidden" name="pg" value="<?= h((string)($_GET['pg'] ?? '')); ?>">
      <div style="min-width:220px;flex:1">
        <label>Search (id/uid/login/txid/wallet)</label>
        <input name="q" value="<?= h($q); ?>" placeholder="e.g. 123 or @username or txid">
      </div>
      <div style="min-width:180px">
        <label>Status</label>
        <select name="status">
          <option value="-1"<?= $status===-1?' selected':''; ?>>All</option>
          <?php if ($mode === 'inserts'): ?>
            <option value="0"<?= $status===0?' selected':''; ?>>Pending</option>
            <option value="1"<?= $status===1?' selected':''; ?>>Confirmed</option>
          <?php else: ?>
            <option value="1"<?= $status===1?' selected':''; ?>>Pending</option>
            <option value="3"<?= $status===3?' selected':''; ?>>Paid</option>
            <option value="2"<?= $status===2?' selected':''; ?>>Canceled</option>
          <?php endif; ?>
        </select>
      </div>
      <div style="min-width:160px">
        <label>Currency</label>
        <input name="sys" value="<?= h($sys); ?>" placeholder="USDT, BTC, ...">
      </div>
      <div style="min-width:160px">
        <label>From (YYYY-MM-DD)</label>
        <input name="from" value="<?= h($from); ?>" placeholder="2026-01-01">
      </div>
      <div style="min-width:160px">
        <label>To (YYYY-MM-DD)</label>
        <input name="to" value="<?= h($to); ?>" placeholder="2026-01-05">
      </div>
      <div>
        <button class="btn btn-primary" type="submit">Apply</button>
      </div>
    </form>

    <div style="overflow:auto">
      <table class="vx-table">
        <thead>
          <?php if ($mode === 'inserts'): ?>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Amount</th>
              <th>Currency</th>
              <th>Status</th>
              <th>Created</th>
              <th>Completed</th>
              <th>Actions</th>
            </tr>
          <?php else: ?>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Amount</th>
              <th>Currency</th>
              <th>Status</th>
              <th>Wallet</th>
              <th>TxID</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          <?php endif; ?>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="<?= $mode==='inserts'?8:9; ?>" class="vx-mini" style="padding:14px">No records.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php if ($mode === 'inserts'):
            $id = (int)($r['id'] ?? 0);
            $u = (int)($r['uid'] ?? 0);
            $login = (string)($r['login'] ?? '');
            $sumv = (float)($r['sum'] ?? 0);
            $sumx = (float)($r['sum_x'] ?? $sumv);
            $sysv = (string)($r['sys'] ?? '');
            $st = (int)($r['status'] ?? 0);
            $addt = (int)($r['add'] ?? 0);
            $endt = (int)($r['end'] ?? 0);
          ?>
            <tr>
              <td class="vx-mono">#<?= $id; ?></td>
              <td>
                <div><a href="/<?= h($adm); ?>/users/info/<?= $u; ?>"><?= h($login ?: ('UID '.$u)); ?></a></div>
                <div class="vx-mini">uid: <?= $u; ?></div>
              </td>
              <td>
                <div><b><?= vx_money($sumx); ?></b></div>
                <?php if ($sumx !== $sumv): ?><div class="vx-mini">raw: <?= vx_money($sumv); ?></div><?php endif; ?>
              </td>
              <td class="vx-mono"><?= h($sysv); ?></td>
              <td><?= vx_status_pill($st, 'inserts'); ?></td>
              <td class="vx-mini"><?= $addt ? date('Y-m-d H:i', $addt) : '-'; ?></td>
              <td class="vx-mini"><?= $endt ? date('Y-m-d H:i', $endt) : '-'; ?></td>
              <td>
                <div class="vx-actions">
                  <?php if ($st === 0): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Manually confirm + credit this deposit? Use ONLY if webhook failed.');">
                      <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                      <input type="hidden" name="action" value="manual_confirm">
                      <input type="hidden" name="id" value="<?= $id; ?>">
                      <button class="btn btn-xs btn-warning" type="submit">Manual confirm</button>
                    </form>
                  <?php endif; ?>
                  <a class="btn btn-xs btn-default" href="/<?= h($adm); ?>/users/info/<?= $u; ?>">User</a>
                </div>
              </td>
            </tr>
          <?php else:
            $id = (int)($r['id'] ?? 0);
            $u = (int)($r['uid'] ?? 0);
            $login = (string)($r['login'] ?? '');
            $sumv = (float)($r['sum'] ?? 0);
            $sysv = (string)($r['sys'] ?? '');
            $st = (int)($r['status'] ?? 0);
            $wallet = (string)($r['purse'] ?? '');
            $txid = (string)($r['txid'] ?? '');
            $addt = (int)($r['add'] ?? 0);
          ?>
            <tr>
              <td class="vx-mono">#<?= $id; ?></td>
              <td>
                <div><a href="/<?= h($adm); ?>/users/info/<?= $u; ?>"><?= h($login ?: ('UID '.$u)); ?></a></div>
                <div class="vx-mini">uid: <?= $u; ?></div>
              </td>
              <td><b><?= vx_money($sumv); ?></b></td>
              <td class="vx-mono"><?= h($sysv); ?></td>
              <td><?= vx_status_pill($st, 'payouts'); ?></td>
              <td class="vx-mini" style="max-width:240px;word-break:break-all"><?= h($wallet); ?></td>
              <td class="vx-mini" style="max-width:220px;word-break:break-all"><?= h($txid); ?></td>
              <td class="vx-mini"><?= $addt ? date('Y-m-d H:i', $addt) : '-'; ?></td>
              <td>
                <div class="vx-actions">
                  <?php if ($st !== 3): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Mark this withdrawal as PAID?');">
                      <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                      <input type="hidden" name="action" value="mark_paid">
                      <input type="hidden" name="id" value="<?= $id; ?>">
                      <button class="btn btn-xs btn-success" type="submit">Mark paid</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Cancel + refund this withdrawal back to user money_p?');">
                      <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                      <input type="hidden" name="action" value="cancel_refund">
                      <input type="hidden" name="id" value="<?= $id; ?>">
                      <button class="btn btn-xs btn-danger" type="submit">Cancel+refund</button>
                    </form>
                  <?php endif; ?>
                  <a class="btn btn-xs btn-default" href="/<?= h($adm); ?>/users/info/<?= $u; ?>">User</a>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div style="padding:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div class="vx-mini">Page <?= (int)$page; ?> • showing <?= count($rows); ?> rows</div>
      <div class="vx-actions">
        <?php if ($page > 1): ?>
          <a class="btn btn-sm btn-dark" href="?<?= h(http_build_query(array_merge($_GET, ['p'=>$page-1]))); ?>">Prev</a>
        <?php endif; ?>
        <?php if (count($rows) === $limit): ?>
          <a class="btn btn-sm btn-dark" href="?<?= h(http_build_query(array_merge($_GET, ['p'=>$page+1]))); ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
