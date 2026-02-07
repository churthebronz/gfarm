<?php
declare(strict_types=1);
define('FastCore', true);
require_once __DIR__ . '/../../core/config.php';
header('Content-Type: application/json; charset=utf-8');

function table_exists($db, string $table): bool {
    $row = $db->query('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', $table)->fetchArray();
    return (int)($row['c'] ?? 0) > 0;
}
function column_exists($db, string $table, string $col): bool {
    $row = $db->query('SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', $table, $col)->fetchArray();
    return (int)($row['c'] ?? 0) > 0;
}

$checks = [
    'tables' => [
        'db_points_ledger' => table_exists($db, 'db_points_ledger'),
        'db_tarif_points'  => table_exists($db, 'db_tarif_points'),
        'db_insert'        => table_exists($db, 'db_insert'),
        'db_users'         => table_exists($db, 'db_users'),
        'vx_guardian_chains' => table_exists($db, 'vx_guardian_chains'),
        'vault_definitions'  => table_exists($db, 'vault_definitions'),
        'vx_lp_ledger'       => table_exists($db, 'vx_lp_ledger'),
    ],
    'db_users.columns' => [
        'money_p'          => column_exists($db, 'db_users', 'money_p'),
        'income'           => column_exists($db, 'db_users', 'income'),
        'ref_to'           => column_exists($db, 'db_users', 'ref_to'),
        'rid'              => column_exists($db, 'db_users', 'rid'),
        'points_total'     => column_exists($db, 'db_users', 'points_total'),
        'points_spendable' => column_exists($db, 'db_users', 'points_spendable'),
    ],
    // Optional (the app will fall back to user_meta if these are missing)
    'optional.db_users.columns' => [
        'lp_total'         => column_exists($db, 'db_users', 'lp_total'),
        'lp_spendable'     => column_exists($db, 'db_users', 'lp_spendable'),
    ],
    'db_insert.columns' => [
        'ref_credited'     => column_exists($db, 'db_insert', 'ref_credited'),
    ],
];

$missing = [];
foreach ($checks as $group => $arr) {
    $isOptional = (strpos($group, 'optional.') === 0);
    foreach ($arr as $k => $ok) {
        if (!$ok && !$isOptional) $missing[] = "$group.$k";
    }
}
echo json_encode(['ok' => count($missing) === 0, 'missing' => $missing, 'checks' => $checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
