<?php
declare(strict_types=1);
if (!defined('FastCore')) { exit('Opss!'); }

// Admin dashboard MUST never fatal (a fatal here makes the whole admin look broken).
$now = time();

// Stats (legacy table db_stats is not guaranteed to exist)
$stats = ['users'=>0,'inserts'=>0,'payments'=>0];
try {
  $row = $db->query("SELECT * FROM db_stats WHERE id = '1' LIMIT 1")->fetchArray();
  if (is_array($row) && !empty($row)) $stats = array_merge($stats, $row);
} catch (Throwable $e) {
  // ignore: table might not exist
}

// Quick user activity
$since24h = $now - 86400;
$since30m = $now - 1800;
try {
  $users24 = (int)$db->query("SELECT COUNT(*) AS c FROM db_users WHERE reg > ?", $since24h)->fetchArray()['c'];
} catch (Throwable $e) { $users24 = 0; }

try {
  $online30m = (int)$db->query("SELECT COUNT(*) AS c FROM db_users WHERE auth > ?", $since30m)->fetchArray()['c'];
} catch (Throwable $e) { $online30m = 0; }

try {
  $online24h = (int)$db->query("SELECT COUNT(*) AS c FROM db_users WHERE auth > ?", $since24h)->fetchArray()['c'];
} catch (Throwable $e) { $online24h = 0; }

// Money columns may be 0 in your current build (still show them)
try { $moneyb = (float)($db->query("SELECT SUM(money_b) AS s FROM db_users")->fetchArray()['s'] ?? 0); } catch (Throwable $e) { $moneyb = 0; }
try { $moneyp = (float)($db->query("SELECT SUM(money_p) AS s FROM db_users")->fetchArray()['s'] ?? 0); } catch (Throwable $e) { $moneyp = 0; }

$totalUsers = (int)($stats['users'] ?? 0);
if ($totalUsers <= 0) {
  try { $totalUsers = (int)$db->query("SELECT COUNT(*) AS c FROM db_users")->fetchArray()['c']; } catch (Throwable $e) {}
}

$totalDeposits = (int)($stats['inserts'] ?? 0);
$totalPayouts  = (int)($stats['payments'] ?? 0);
$net = $totalDeposits - $totalPayouts;

function vx_adm_num($n): string {
  if (!is_numeric($n)) return '0';
  return number_format((float)$n, 0, '.', ',');
}
?>

<style>
.vx-adm-h{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.vx-adm-h h3{margin:0;font-weight:900;letter-spacing:.2px}
.vx-adm-sub{opacity:.75;font-size:12px}
.vx-adm-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
.vx-adm-card{grid-column:span 12;border-radius:16px;border:1px solid rgba(148,163,184,.14);background:rgba(15,23,42,.25);padding:12px;box-shadow:0 12px 32px rgba(0,0,0,.25)}
@media (min-width: 768px){.vx-adm-card{grid-column:span 6}}
@media (min-width: 1100px){.vx-adm-card{grid-column:span 3}}
.vx-adm-k{opacity:.72;font-size:12px;margin-top:2px}
.vx-adm-v{font-weight:1000;font-size:20px;margin-top:6px}
.vx-adm-i{opacity:.9;margin-right:8px}
</style>

<div class="vx-adm-h">
  <div>
    <h3>Admin Overview</h3>
    <div class="vx-adm-sub">Live system stats • <?= htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?></div>
  </div>
</div>

<div class="vx-adm-grid">
  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-users vx-adm-i"></i>Total users</div><div class="vx-adm-v"><?= vx_adm_num($totalUsers); ?></div></div>
  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-user-plus vx-adm-i"></i>New users (24h)</div><div class="vx-adm-v"><?= vx_adm_num($users24); ?></div></div>
  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-bolt vx-adm-i"></i>Active (30m)</div><div class="vx-adm-v"><?= vx_adm_num($online30m); ?></div></div>
  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-clock-o vx-adm-i"></i>Active (24h)</div><div class="vx-adm-v"><?= vx_adm_num($online24h); ?></div></div>

  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-plus vx-adm-i"></i>Deposits (count)</div><div class="vx-adm-v"><?= vx_adm_num($totalDeposits); ?></div></div>
  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-minus vx-adm-i"></i>Payouts (count)</div><div class="vx-adm-v"><?= vx_adm_num($totalPayouts); ?></div></div>
  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-university vx-adm-i"></i>Net (deposits - payouts)</div><div class="vx-adm-v"><?= vx_adm_num($net); ?></div></div>
  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-credit-card vx-adm-i"></i>User balance (money_b)</div><div class="vx-adm-v"><?= vx_adm_num($moneyb); ?></div></div>

  <div class="vx-adm-card"><div class="vx-adm-k"><i class="fa fa-bank vx-adm-i"></i>Withdraw balance (money_p)</div><div class="vx-adm-v"><?= vx_adm_num($moneyp); ?></div></div>
</div>


<?php
// --- Growth Snapshot (share/click/conversion) ---
$g = [
  'shares_today'=>0,'clicks_today'=>0,'conv_today'=>0,
  'shares_7d'=>0,'clicks_7d'=>0,'conv_7d'=>0,
  'top_ref'=>'—','top_conv'=>0,
  'cr_7d'=>0.0,
];
try {
  $t0 = strtotime(date('Y-m-d 00:00:00'));
  $t7 = $now - 7*86400;

  // Ensure table exists (fail-soft)
  $db->query("CREATE TABLE IF NOT EXISTS vx_share_events (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    ts INTEGER NOT NULL,\n    event TEXT NOT NULL,\n    ctx TEXT,\n    ref_code TEXT,\n    ip TEXT,\n    ua TEXT,\n    user_id INTEGER DEFAULT 0\n  )");

  $g['shares_today'] = (int)($db->query("SELECT COUNT(*) AS c FROM vx_share_events WHERE ts >= ? AND event='share'", $t0)->fetchArray()['c'] ?? 0);
  $g['clicks_today'] = (int)($db->query("SELECT COUNT(*) AS c FROM vx_share_events WHERE ts >= ? AND event='click'", $t0)->fetchArray()['c'] ?? 0);
  $g['conv_today']   = (int)($db->query("SELECT COUNT(*) AS c FROM vx_share_events WHERE ts >= ? AND event='conversion'", $t0)->fetchArray()['c'] ?? 0);

  $g['shares_7d'] = (int)($db->query("SELECT COUNT(*) AS c FROM vx_share_events WHERE ts >= ? AND event='share'", $t7)->fetchArray()['c'] ?? 0);
  $g['clicks_7d'] = (int)($db->query("SELECT COUNT(*) AS c FROM vx_share_events WHERE ts >= ? AND event='click'", $t7)->fetchArray()['c'] ?? 0);
  $g['conv_7d']   = (int)($db->query("SELECT COUNT(*) AS c FROM vx_share_events WHERE ts >= ? AND event='conversion'", $t7)->fetchArray()['c'] ?? 0);

  $cr = 0.0;
  if ($g['clicks_7d'] > 0) $cr = ($g['conv_7d'] / $g['clicks_7d']) * 100.0;
  $g['cr_7d'] = $cr;

  $top = $db->query("SELECT ref_code, COUNT(*) AS c FROM vx_share_events WHERE ts >= ? AND event='conversion' AND ref_code IS NOT NULL AND ref_code != '' GROUP BY ref_code ORDER BY c DESC LIMIT 1", $t7)->fetchArray();
  if (is_array($top) && !empty($top)) {
    $g['top_ref'] = (string)($top['ref_code'] ?? '—');
    $g['top_conv'] = (int)($top['c'] ?? 0);
  }
} catch (Throwable $e) {
  // ignore
}
?>

<style>
.vx-adm-grow{margin-top:14px}
.vx-adm-grow h4{margin:0 0 10px 0;font-weight:950}
.vx-adm-mini{opacity:.75;font-size:12px;margin-top:2px}
.vx-adm-row{display:flex;gap:10px;flex-wrap:wrap;align-items:stretch}
.vx-adm-pill{border-radius:14px;border:1px solid rgba(148,163,184,.14);background:rgba(15,23,42,.25);padding:10px 12px;min-width:220px;flex:1 1 220px}
.vx-adm-pill .k{opacity:.7;font-size:12px}
.vx-adm-pill .v{font-weight:1000;font-size:18px;margin-top:6px}
.vx-adm-pill .v small{font-weight:800;opacity:.8}
.vx-adm-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.vx-adm-btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:12px;padding:10px 12px;border:1px solid rgba(148,163,184,.18);background:rgba(2,132,199,.12);color:#eaf0ff;font-weight:900}
.vx-adm-btn:hover{filter:brightness(1.05)}
</style>

<div class="vx-adm-grow">
  <div class="vx-adm-h" style="margin-bottom:8px">
    <div>
      <h4>Growth Snapshot</h4>
      <div class="vx-adm-sub">Shares • clicks • conversions (tracked)</div>
    </div>
  </div>

  <div class="vx-adm-row">
    <div class="vx-adm-pill"><div class="k">Today • Shares</div><div class="v"><?= vx_adm_num($g['shares_today']); ?></div><div class="vx-adm-mini">People tapped “Share”</div></div>
    <div class="vx-adm-pill"><div class="k">Today • Clicks</div><div class="v"><?= vx_adm_num($g['clicks_today']); ?></div><div class="vx-adm-mini">Landing page opens</div></div>
    <div class="vx-adm-pill"><div class="k">Today • Conversions</div><div class="v"><?= vx_adm_num($g['conv_today']); ?></div><div class="vx-adm-mini">Claim completed (attributed)</div></div>

    <div class="vx-adm-pill"><div class="k">Last 7d • Click → Conversion</div><div class="v"><?= number_format((float)$g['cr_7d'], 1); ?><small>%</small></div><div class="vx-adm-mini">Conversions / clicks</div></div>
    <div class="vx-adm-pill"><div class="k">Last 7d • Top ref</div><div class="v"><?= htmlspecialchars($g['top_ref'], ENT_QUOTES, 'UTF-8'); ?></div><div class="vx-adm-mini"><?= vx_adm_num($g['top_conv']); ?> conversions</div></div>
  </div>

  <div class="vx-adm-actions">
    <a class="vx-adm-btn" href="/adminka/growth"><i class="fa fa-line-chart"></i>Open Growth Dashboard</a>
    <a class="vx-adm-btn" href="/adminka/js-errors"><i class="fa fa-bug"></i>View JS Errors</a>
  </div>
</div>

