<?php
declare(strict_types=1);
define('FastCore', true);
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_helpers.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="refs_export.csv"');

if (!isset($_SESSION)) session_start();
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo 'unauthorized'; exit; }

function table_exists($db, string $name): bool {
  $row = $db->query('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', $name)->fetchArray();
  return (int)($row['c'] ?? 0) > 0;
}
function column_exists($db, string $table, string $col): bool {
  $row = $db->query('SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', $table, $col)->fetchArray();
  return (int)($row['c'] ?? 0) > 0;
}

$has_insert = table_exists($db, 'db_insert');


$has_ref_earn = table_exists($db, 'db_ref_earn');
$refmap = $has_ref_earn ? vx_refearn_schema($db) : ['ref_col'=>null,'user_col'=>null,'reward_col'=>null,'usd_col'=>null,'rate_col'=>null];
$use_reward = $has_ref_earn && !empty($refmap['reward_col']);
$use_usd_rate = $has_ref_earn && !empty($refmap['usd_col']) && !empty($refmap['rate_col']);



// Determine deposit amount/status columns dynamically
$dep_amount_col = 'amount';
if ($has_insert && !column_exists($db, 'db_insert', 'amount') && column_exists($db, 'db_insert', 'sum')) {
  $dep_amount_col = 'sum';
}
$dep_status_col = 'status';
$has_status = $has_insert && column_exists($db, 'db_insert', $dep_status_col);

$out = fopen('php://output', 'w');
fputcsv($out, ['id','login','telegram_id','add_date','total_deposits_usd','vault_points','upline_cash_from_this_ref']);

// Fetch downlines
$rows = $db->query('SELECT id, login, telegram_id, add_date FROM db_users WHERE rid = ? ORDER BY id DESC', $uid)->fetchAll();
foreach ($rows as $r) {
  $rid = (int)$r['id'];

  // Aggregate deposits
  $total_deposits = 0.0;
  if ($has_insert) {
    if ($has_status) {
      // consider common confirmed states incl. numeric 1
      $row = $db->query("
        SELECT COALESCE(SUM($dep_amount_col),0) AS s
        FROM db_insert
        WHERE user_id = ? AND (
          LOWER($dep_status_col) IN ('confirmed','paid','success','completed')
          OR $dep_status_col = 1
        )
      ", $rid)->fetchArray();
    } else {
      // if no status column, sum all rows for the user
      $row = $db->query("SELECT COALESCE(SUM($dep_amount_col),0) AS s FROM db_insert WHERE user_id = ?", $rid)->fetchArray();
    }
    $total_deposits = (float)($row['s'] ?? 0.0);
  }


  // Upline cash earned from this referral (flexible ledger schema)
  $upline_cash_from_ref = 0.0;
  if ($has_ref_earn && $refmap['ref_col'] && $refmap['user_col']) {
    if ($use_reward) {
      $sqlc = 'SELECT COALESCE(SUM(' . $refmap['reward_col'] . '),0) AS s FROM db_ref_earn WHERE ' . $refmap['ref_col'] . ' = ? AND ' . $refmap['user_col'] . ' = ?';
      $rowc = $db->query($sqlc, $uid, $rid)->fetchArray();
      $upline_cash_from_ref = (float)($rowc['s'] ?? 0.0);
    } elseif ($use_usd_rate) {
      $sqlc = 'SELECT COALESCE(SUM(' . $refmap['usd_col'] . ' * (' . $refmap['rate_col'] . '/100.0)),0) AS s FROM db_ref_earn WHERE ' . $refmap['ref_col'] . ' = ? AND ' . $refmap['user_col'] . ' = ?';
      $rowc = $db->query($sqlc, $uid, $rid)->fetchArray();
      $upline_cash_from_ref = (float)($rowc['s'] ?? 0.0);
    }
  } elseif ($use_usd_rate) {
      $rowc = $db->query('SELECT COALESCE(SUM(usd * (rate/100.0)),0) AS s FROM db_ref_earn WHERE ref_id = ? AND user_id = ?', $uid, $rid)->fetchArray();
      $upline_cash_from_ref = (float)($rowc['s'] ?? 0.0);
    }
// Aggregate buyer vault points
  $vault_points = 0;
  if ($has_points) {
    $rowp = $db->query("SELECT COALESCE(SUM(delta),0) AS s FROM db_points_ledger WHERE uid = ? AND ctx = 'buy_vault'", $rid)->fetchArray();
    $vault_points = (int)($rowp['s'] ?? 0);
  }

  fputcsv($out, [
    $r['id'],
    $r['login'],
    $r['telegram_id'],
    date('Y-m-d H:i', (int)$r['add_date']),
    number_format($total_deposits, 2, '.', ''),
    $vault_points,
    number_format($upline_cash_from_ref, 2, '.', '')
  ]);
}
fclose($out);
