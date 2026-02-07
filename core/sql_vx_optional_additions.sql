-- GreenFarm TG Mini App: Optional DB Additions (safe to run)
-- Purpose: lightweight analytics / anti-abuse / UX tuning. Does NOT change existing tables.

-- 1) events_log: track key in-app events (share, checkin, vault_start) for debugging + abuse detection.
CREATE TABLE IF NOT EXISTS `events_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `ctx` VARCHAR(64) NULL,
  `ip` VARCHAR(64) NULL,
  `ua` VARCHAR(191) NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_type_time` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

