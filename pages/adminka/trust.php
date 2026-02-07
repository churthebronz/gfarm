<?php
// pages/adminka/trust.php (GreenFarm Admin • Trust / Referral Qualification)
declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $config, $adm;

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/vx_refqual.php';

$opt['title'] = 'Trust';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_post(): bool { return (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'); }

vx_refqual_schema_ensure($db);

$csrf = vx_admin_csrf_token();
$msg = '';

if (is_post()) {
  if (!vx_admin_csrf_ok($_POST['_csrf'] ?? null)) {
    $msg = 'Invalid CSRF token.';
  } else {
    $act = (string)($_POST['action'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    try {
      if ($id > 0 && $act === 'approve') {
        $db->query("UPDATE vx_ref_qualifications SET status='pending', reason=NULL WHERE id=? AND status='flagged' LIMIT 1", $id);
        vx_admin_audit($db, 'trust_ref_approve', ['id'=>$id]);
        $msg = 'Referral set to pending (will qualify after cooldown).' ;
      } elseif ($id > 0 && $act === 'block') {
        $db->query("UPDATE vx_ref_qualifications SET status='blocked', reason='admin_block' WHERE id=? LIMIT 1", $id);
        vx_admin_audit($db, 'trust_ref_block', ['id'=>$id]);
        $msg = 'Referral blocked.';
      } elseif ($id > 0 && $act === 'unblock') {
        $db->query("UPDATE vx_ref_qualifications SET status='pending', reason=NULL WHERE id=? AND status='blocked' LIMIT 1", $id);
        vx_admin_audit($db, 'trust_ref_unblock', ['id'=>$id]);
        $msg = 'Referral unblocked (pending).' ;
      }
    } catch (Throwable $e) {
      $msg = 'Action failed: ' . $e->getMessage();
    }
  }
}

$status = (string)($_GET['status'] ?? 'flagged');
$allowed = ['flagged','blocked','pending','qualified'];
if (!in_array($status, $allowed, true)) $status = 'flagged';

$items = [];
try {
  $q = $db->query(
    "SELECT q.*, 
            ur.login AS ref_login, ur.tg_username AS ref_tg,
            ub.login AS buy_login, ub.tg_username AS buy_tg
     FROM vx_ref_qualifications q
     LEFT JOIN db_users ur ON ur.id=q.referrer_uid
     LEFT JOIN db_users ub ON ub.id=q.buyer_uid
     WHERE q.status=?
     ORDER BY q.id DESC
     LIMIT 200",
    $status
  );
  while ($q && ($r = $q->fetchArray())) $items[] = $r;
} catch (Throwable $e) {}

?>

<div class="vx-admin-wrap" style="max-width:1200px;margin:0 auto;padding:14px">
  <div class="vx-admin-head" style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:4px 0 12px">
    <div>
      <div style="font-size:22px;font-weight:950">Trust</div>
      <div style="opacity:.72;line-height:1.35">Referral qualification review (cooldown + min paid + anti-abuse flags).</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <?php foreach ($allowed as $st): ?>
        <a href="/<?php echo h((string)$adm); ?>/trust?status=<?php echo h($st); ?>" class="btn btn-sm <?php echo ($st===$status)?'btn-warning':'btn-outline-secondary'; ?>">
          <?php echo strtoupper(h($st)); ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="alert alert-info"><?php echo h($msg); ?></div>
  <?php endif; ?>

  <div class="card" style="background:rgba(15,23,42,.55);border:1px solid rgba(255,255,255,.10)">
    <div class="card-body" style="padding:12px">
      <?php if (!$items): ?>
        <div class="text-muted">No rows.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle" style="color:#eaf0ff">
            <thead style="opacity:.8">
              <tr>
                <th>ID</th>
                <th>Referrer</th>
                <th>Buyer</th>
                <th>USD</th>
                <th>Hold PTS</th>
                <th>Due</th>
                <th>Reason</th>
                <th>IP/UA hash</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $r):
                $id = (int)($r['id'] ?? 0);
                $due = (int)($r['qualify_after'] ?? 0);
                $dueTxt = $due>0 ? date('Y-m-d H:i', $due) : '-';
                $refLbl = (string)($r['ref_tg'] ?? '');
                if ($refLbl !== '') $refLbl = '@'.ltrim($refLbl,'@'); else $refLbl = (string)($r['ref_login'] ?? '');
                $buyLbl = (string)($r['buy_tg'] ?? '');
                if ($buyLbl !== '') $buyLbl = '@'.ltrim($buyLbl,'@'); else $buyLbl = (string)($r['buy_login'] ?? '');
              ?>
              <tr>
                <td>#<?php echo (int)$id; ?></td>
                <td><?php echo h($refLbl); ?> <span style="opacity:.6">(<?php echo (int)($r['referrer_uid'] ?? 0); ?>)</span></td>
                <td><?php echo h($buyLbl); ?> <span style="opacity:.6">(<?php echo (int)($r['buyer_uid'] ?? 0); ?>)</span></td>
                <td>$<?php echo h(number_format((float)($r['usd_value'] ?? 0), 2)); ?></td>
                <td><?php echo (int)($r['hold_points'] ?? 0); ?></td>
                <td><?php echo h($dueTxt); ?></td>
                <td><?php echo h((string)($r['reason'] ?? '')); ?></td>
                <td><span style="opacity:.7"><?php echo h(substr((string)($r['ip_hash'] ?? ''),0,10)); ?>…</span><br><span style="opacity:.7"><?php echo h(substr((string)($r['ua_hash'] ?? ''),0,10)); ?>…</span></td>
                <td>
                  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="hidden" name="_csrf" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <?php if ($status === 'flagged'): ?>
                      <button class="btn btn-sm btn-success" name="action" value="approve">Approve</button>
                      <button class="btn btn-sm btn-danger" name="action" value="block">Block</button>
                    <?php elseif ($status === 'blocked'): ?>
                      <button class="btn btn-sm btn-warning" name="action" value="unblock">Unblock</button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-danger" name="action" value="block">Block</button>
                    <?php endif; ?>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
