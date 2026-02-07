<?php
declare(strict_types=1);

if (!defined('FastCore')) { define('FastCore', true); }

global $db, $config, $opt;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

// Admin guard (same convention as other adminka pages)
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['adminka']) && !isset($_SESSION['admin']) && !isset($_SESSION['adm'])) {
  header('Location: /'.($config->adm_dir ?? 'adminka').'/login');
  exit;
}

require_once __DIR__ . '/../../core/vx_affiliate.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';

$opt['title'] = 'Affiliate Dashboard';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Pull top referrers by qualified paid referrals
$limit = 50;
$rows = [];
try {
  $q = $db->query(
    "SELECT u.id, u.login, u.tg_username, u.ref_code,
            (SELECT COUNT(DISTINCT uu.id)
             FROM db_users uu
             JOIN vx_guardian_chains c ON c.uid=uu.id
             JOIN db_tarif t ON t.id=c.tarif
             WHERE uu.rid=u.id AND c.kind='plan' AND t.price>0 AND c.status IN ('active','matured','codex')
            ) AS qualified_paid,
            (SELECT COUNT(*) FROM db_users uu WHERE uu.rid=u.id) AS total_refs
     FROM db_users u
     ORDER BY qualified_paid DESC, total_refs DESC, u.id ASC
     LIMIT ?",
    $limit
  );
  if ($q) { while ($r = $q->fetchArray()) { $rows[] = $r; } }
} catch (Throwable $e) { $rows = []; }

?>

<style>
  .vx-wrap{max-width:1100px;margin:18px auto;padding:0 12px}
  .vx-card{border-radius:18px;border:1px solid rgba(148,163,184,.16);background:rgba(2,6,23,.55);box-shadow:0 18px 52px rgba(0,0,0,.45)}
  .vx-hd{padding:14px 16px;border-bottom:1px solid rgba(148,163,184,.12)}
  .vx-bd{padding:14px 16px}
  code{background:rgba(255,255,255,.07);padding:2px 6px;border-radius:8px}
</style>

<div class="vx-wrap">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h3 class="m-0">Affiliate dashboard</h3>
      <div class="text-secondary">Who is driving qualified paid activations + tier progress.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="/<?= h($config->adm_dir ?? 'adminka'); ?>/growth">Growth settings</a>
      <a class="btn btn-outline-secondary" href="/<?= h($config->adm_dir ?? 'adminka'); ?>">Back to admin</a>
    </div>
  </div>

  <div class="vx-card">
    <div class="vx-hd"><strong>Top affiliates</strong></div>
    <div class="vx-bd">
      <div class="table-responsive">
        <table class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>UID</th>
              <th>User</th>
              <th>Ref code</th>
              <th>Total refs</th>
              <th>Qualified paid</th>
              <th>Tier</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" style="opacity:.7">No data yet.</td></tr>
          <?php else: foreach ($rows as $r):
            $uid = (int)($r['id'] ?? 0);
            $qualified = (int)($r['qualified_paid'] ?? 0);
            $tier = vx_affiliate_tier_from_qualified($qualified);
            $label = trim((string)($r['tg_username'] ?? ''));
            if ($label !== '') $label = '@'.ltrim($label,'@');
            else $label = (string)($r['login'] ?? 'User');
          ?>
            <tr>
              <td><?= $uid; ?></td>
              <td><?= h($label); ?></td>
              <td><code><?= h((string)($r['ref_code'] ?? '')); ?></code></td>
              <td><?= (int)($r['total_refs'] ?? 0); ?></td>
              <td><b><?= $qualified; ?></b></td>
              <td><?= $tier; ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
