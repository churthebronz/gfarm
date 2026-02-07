<?php
declare(strict_types=1);
// File: /core/schema_ensure.php
// Purpose: Launch-safe schema migrations for GreenFarm (idempotent, fail-soft).

if (!defined('FastCore')) { define('FastCore', true); }

function vx_schema_index_exists($db, string $table, string $keyName): bool {
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
            $table, $keyName
        )->fetchArray();
        return !!$r;
    } catch (Throwable $e) {
        return false;
    }
}

function vx_schema_ensure($db): void {
    if (!$db) return;

    // vx_idempotency
    try {
        $db->query("CREATE TABLE IF NOT EXISTS vx_idempotency (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            idem_key VARCHAR(190) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'inflight',
            response_json MEDIUMTEXT NULL,
            created_at INT NOT NULL,
            expires_at INT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_idem_key (idem_key),
            KEY ix_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) {}

    // vx_season_passes
    try {
        $db->query("CREATE TABLE IF NOT EXISTS vx_season_passes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid INT NOT NULL,
            season_id INT NOT NULL,
            purchased_via VARCHAR(24) NOT NULL DEFAULT 'balance',
            created_at INT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_uid_season (uid, season_id),
            KEY ix_season (season_id),
            KEY ix_uid (uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) {}

    // Referral earnings log
    try {
        $db->query("CREATE TABLE IF NOT EXISTS vx_ref_earnings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rid INT NOT NULL,
            buyer_id INT NOT NULL,
            deposit_id BIGINT UNSIGNED NOT NULL,
            deposit_usd DECIMAL(18,2) NOT NULL DEFAULT 0,
            reward_usd DECIMAL(18,6) NOT NULL DEFAULT 0,
            pct_used DECIMAL(8,2) NOT NULL DEFAULT 0,
            receipt TEXT NULL,
            created_at INT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_dep_rid (deposit_id, rid),
            KEY ix_rid_created (rid, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) {}

    // Yield ledger (for user earnings audit)
    try {
        $db->query("CREATE TABLE IF NOT EXISTS vx_yield_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid INT NOT NULL,
            guardian_id BIGINT UNSIGNED NULL,
            amount_usd DECIMAL(18,6) NOT NULL DEFAULT 0,
            ctx VARCHAR(32) NOT NULL DEFAULT 'claim',
            meta_json TEXT NULL,
            created_at INT NOT NULL,
            PRIMARY KEY (id),
            KEY ix_uid_created (uid, created_at),
            KEY ix_guardian_created (guardian_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) {}

    // db_users: onboarding flag
    try {
        $row = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'db_users' AND COLUMN_NAME = 'vx_onboarded' LIMIT 1")->fetchArray();
        if (!$row) {
            $db->query("ALTER TABLE db_users ADD COLUMN vx_onboarded TINYINT(1) NOT NULL DEFAULT 0");
            $db->query("ALTER TABLE db_users ADD KEY ix_vx_onboarded (vx_onboarded)");
        }
        $row2 = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'db_users' AND COLUMN_NAME = 'vx_onboarded_at' LIMIT 1")->fetchArray();
        if (!$row2) {
            $db->query("ALTER TABLE db_users ADD COLUMN vx_onboarded_at INT NULL");
            $db->query("ALTER TABLE db_users ADD KEY ix_vx_onboarded_at (vx_onboarded_at)");
        }
    } catch (Throwable $e) {}

    // db_payout: idempotency columns
    try {
        $row = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'db_payout' AND COLUMN_NAME = 'txid' LIMIT 1")->fetchArray();
        if (!$row) { $db->query("ALTER TABLE db_payout ADD COLUMN txid VARCHAR(190) NULL"); }
        $row = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'db_payout' AND COLUMN_NAME = 'proof' LIMIT 1")->fetchArray();
        if (!$row) { $db->query("ALTER TABLE db_payout ADD COLUMN proof TEXT NULL"); }
        $row = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'db_payout' AND COLUMN_NAME = 'paid_at' LIMIT 1")->fetchArray();
        if (!$row) { $db->query("ALTER TABLE db_payout ADD COLUMN paid_at INT NULL"); }
        if (!vx_schema_index_exists($db, 'db_payout', 'ix_paid_at')) {
            try { $db->query("ALTER TABLE db_payout ADD KEY ix_paid_at (paid_at)"); } catch (Throwable $e) {}
        }

        $row = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'db_payout' AND COLUMN_NAME = 'request_id' LIMIT 1")->fetchArray();
        if (!$row) { $db->query("ALTER TABLE db_payout ADD COLUMN request_id VARCHAR(190) NULL"); }
        if (!vx_schema_index_exists($db, 'db_payout', 'ux_request_id')) {
            try { $db->query("ALTER TABLE db_payout ADD UNIQUE KEY ux_request_id (request_id)"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}

    // prune expired idempotency rows
    try {
        $now = time();
        $db->query("DELETE FROM vx_idempotency WHERE expires_at < ?", $now - 60);
    } catch (Throwable $e) {}
}
