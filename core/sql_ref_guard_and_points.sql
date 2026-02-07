-- Run in phpMyAdmin (safe to run multiple times)

ALTER TABLE `db_insert`
  ADD COLUMN IF NOT EXISTS `ref_credited` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
  ADD INDEX IF NOT EXISTS `idx_refcredited` (`ref_credited`);

CREATE TABLE IF NOT EXISTS `db_points_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL,
  `delta` INT NOT NULL,
  `ctx` VARCHAR(32) NOT NULL,
  `ref_uid` BIGINT UNSIGNED NULL,
  `tarif_id` BIGINT UNSIGNED NULL,
  `usd_value` DECIMAL(18,6) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_ref` (`ref_uid`),
  KEY `idx_ctx_time` (`ctx`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `db_tarif_points` (
  `tarif_id` BIGINT UNSIGNED NOT NULL,
  `points_award` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`tarif_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `db_users`
  ADD COLUMN IF NOT EXISTS `points_total` INT NOT NULL DEFAULT 0 AFTER `income`,
  ADD COLUMN IF NOT EXISTS `points_spendable` INT NOT NULL DEFAULT 0 AFTER `points_total`,
  ADD COLUMN IF NOT EXISTS `rid` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `telegram_id`,
  ADD COLUMN IF NOT EXISTS `money_p` DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER `balance`,
  ADD COLUMN IF NOT EXISTS `income`  DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER `money_p`,
  ADD COLUMN IF NOT EXISTS `ref_to`  DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER `income`,
  ADD INDEX IF NOT EXISTS `idx_rid` (`rid`);

-- Optional seed
INSERT IGNORE INTO `db_tarif_points` (`tarif_id`, `points_award`) VALUES (1,500),(2,1500),(3,3000);
