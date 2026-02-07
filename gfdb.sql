-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 06, 2026 at 06:42 PM
-- Server version: 11.4.9-MariaDB-cll-lve-log
-- PHP Version: 8.3.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vaulbmln_core`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_diag_schema` (IN `in_schema` VARCHAR(64))   BEGIN
  SELECT 'NO_PK' AS issue, t.table_name
  FROM information_schema.tables t
  LEFT JOIN information_schema.table_constraints c
    ON c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.constraint_type='PRIMARY KEY'
  WHERE t.table_schema=in_schema AND c.table_name IS NULL
  UNION ALL
  SELECT 'ID_NOT_AI_BIGINT', c.table_name
  FROM information_schema.columns c
  WHERE c.table_schema=in_schema AND c.column_name='id'
    AND (c.extra NOT LIKE '%auto_increment%' OR c.data_type <> 'bigint' OR c.column_type NOT LIKE '%unsigned%')
  UNION ALL
  SELECT 'NON_UTF8MB4', t.table_name
  FROM information_schema.tables t
  WHERE t.table_schema=in_schema AND (t.table_collation IS NULL OR t.table_collation NOT LIKE 'utf8mb4\_%')
  UNION ALL
  SELECT 'MISSING_CREATED_OR_UPDATED', t.table_name
  FROM information_schema.tables t
  LEFT JOIN information_schema.columns c1
    ON c1.table_schema=t.table_schema AND c1.table_name=t.table_name AND c1.column_name='created_at'
  LEFT JOIN information_schema.columns c2
    ON c2.table_schema=t.table_schema AND c2.table_name=t.table_name AND c2.column_name='updated_at'
  WHERE t.table_schema=in_schema AND (c1.column_name IS NULL OR c2.column_name IS NULL)
  ORDER BY issue, table_name;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_harden_schema` (IN `in_schema` VARCHAR(64))   BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_table VARCHAR(255);

  DECLARE cur CURSOR FOR
    SELECT t.table_name
    FROM information_schema.tables t
    WHERE t.table_schema = in_schema
      AND t.table_type='BASE TABLE'
    ORDER BY t.table_name;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  SET @old_sql_mode := @@SESSION.sql_mode;
  SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_table;
    IF done = 1 THEN LEAVE read_loop; END IF;

    /* 1) Primary key on `id` */
    SET @has_pk := (
      SELECT COUNT(*)
      FROM information_schema.table_constraints
      WHERE table_schema=in_schema AND table_name=v_table AND constraint_type='PRIMARY KEY'
    );

    IF @has_pk = 0 THEN
      SET @has_id := (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema=in_schema AND table_name=v_table AND column_name='id'
      );

      IF @has_id = 0 THEN
        SET @sql := CONCAT('ALTER TABLE `', in_schema, '`.`', v_table, '` ',
                           'ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST, ',
                           'ADD PRIMARY KEY (`id`)');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
      ELSE
        SET @sql := CONCAT('ALTER TABLE `', in_schema, '`.`', v_table, '` ',
                           'MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

        SET @sql := CONCAT('ALTER TABLE `', in_schema, '`.`', v_table, '` ADD PRIMARY KEY (`id`)');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
      END IF;
    ELSE
      /* Ensure `id` is AUTO_INCREMENT if present */
      SET @has_id2 := (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema=in_schema AND table_name=v_table AND column_name='id'
      );
      IF @has_id2 = 1 THEN
        SET @is_ai2 := (
          SELECT CASE WHEN extra LIKE '%auto_increment%' THEN 1 ELSE 0 END
          FROM information_schema.columns
          WHERE table_schema=in_schema AND table_name=v_table AND column_name='id' LIMIT 1
        );
        IF @is_ai2 = 0 THEN
          SET @sql := CONCAT('ALTER TABLE `', in_schema, '`.`', v_table, '` ',
                             'MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
          PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;
      END IF;
    END IF;

    /* 2) created_at */
    SET @has_created := (
      SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema=in_schema AND table_name=v_table AND column_name='created_at'
    );
    IF @has_created = 0 THEN
      SET @sql := CONCAT('ALTER TABLE `', in_schema, '`.`', v_table, '` ',
                         'ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
      PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    /* 3) updated_at */
    SET @has_updated := (
      SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema=in_schema AND table_name=v_table AND column_name='updated_at'
    );
    IF @has_updated = 0 THEN
      SET @sql := CONCAT('ALTER TABLE `', in_schema, '`.`', v_table, '` ',
                         'ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ',
                         'ON UPDATE CURRENT_TIMESTAMP');
      PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    /* 4) Collation normalization */
    SET @tbl_coll := (SELECT table_collation
                      FROM information_schema.tables
                      WHERE table_schema=in_schema AND table_name=v_table LIMIT 1);
    IF @tbl_coll IS NULL OR @tbl_coll NOT LIKE 'utf8mb4\_%' THEN
      SET @sql := CONCAT('ALTER TABLE `', in_schema, '`.`', v_table, '` ',
                         'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

  END LOOP;
  CLOSE cur;

  SET SESSION sql_mode = @old_sql_mode;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `vx_add_points` (IN `p_uid` INT, IN `p_points` INT, IN `p_tarif` INT)   BEGIN
  INSERT INTO db_points_ledger (uid, points, tarif_id, created_at)
  VALUES (p_uid, p_points, p_tarif, UNIX_TIMESTAMP());

  INSERT INTO db_user_points (uid, points, updated_at)
  VALUES (p_uid, p_points, UNIX_TIMESTAMP())
  ON DUPLICATE KEY UPDATE
    points = points + p_points,
    updated_at = UNIX_TIMESTAMP();

  INSERT INTO vx_daily_scores (day_key, user_id, points)
  VALUES (CURDATE(), p_uid, p_points)
  ON DUPLICATE KEY UPDATE
    points = points + p_points;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `vx_refresh_leaderboard` (IN `p_scope` VARCHAR(16))   BEGIN
  DELETE FROM leaderboard_cache WHERE scope = p_scope;

  INSERT INTO leaderboard_cache (scope, bucket, data, expires_at)
  SELECT
    p_scope,
    'global',
    JSON_ARRAYAGG(
      JSON_OBJECT(
        'uid', user_id,
        'points', points
      )
    ),
    UNIX_TIMESTAMP() + 60
  FROM (
    SELECT user_id, points
    FROM db_user_points
    ORDER BY points DESC
    LIMIT 100
  ) t;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

CREATE TABLE `admin_logs` (
  `id` bigint(20) NOT NULL,
  `action` varchar(100) NOT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `community_pool`
--

CREATE TABLE `community_pool` (
  `season_id` int(10) UNSIGNED NOT NULL,
  `points_total` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_aff_conf`
--

CREATE TABLE `db_aff_conf` (
  `id` tinyint(4) NOT NULL,
  `cash_ref_pct` decimal(5,2) NOT NULL,
  `points_ref_pct` decimal(5,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `db_aff_conf`
--

INSERT INTO `db_aff_conf` (`id`, `cash_ref_pct`, `points_ref_pct`, `created_at`, `updated_at`) VALUES
(1, 5.00, 10.00, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_bonus`
--

CREATE TABLE `db_bonus` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL DEFAULT 0,
  `login` varchar(80) NOT NULL,
  `sum` float(10,2) NOT NULL,
  `add` int(11) NOT NULL DEFAULT 0,
  `del` int(11) NOT NULL DEFAULT 0,
  `bonus_id` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_bonus2`
--

CREATE TABLE `db_bonus2` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL DEFAULT 0,
  `login` varchar(80) NOT NULL,
  `sum` float(10,2) NOT NULL,
  `add` int(11) NOT NULL DEFAULT 0,
  `del` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_bonus_tg`
--

CREATE TABLE `db_bonus_tg` (
  `id` int(11) NOT NULL,
  `tgid` varchar(30) NOT NULL DEFAULT '0',
  `uid` int(11) NOT NULL,
  `login` varchar(50) NOT NULL,
  `date_add` int(11) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 0,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_conf`
--

CREATE TABLE `db_conf` (
  `id` int(11) NOT NULL,
  `coint` int(11) NOT NULL,
  `bounty` int(11) NOT NULL,
  `p_sell` int(11) NOT NULL,
  `p_swap` int(11) NOT NULL,
  `min_s` float(10,2) NOT NULL,
  `acc_pay` int(11) NOT NULL,
  `maintenance` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_conf`
--

INSERT INTO `db_conf` (`id`, `coint`, `bounty`, `p_sell`, `p_swap`, `min_s`, `acc_pay`, `maintenance`, `created_at`, `updated_at`) VALUES
(1, 1, 100, 100, 1, 0.10, 20, 0, '2025-12-21 21:46:47', '2026-01-05 10:03:54');

-- --------------------------------------------------------

--
-- Table structure for table `db_contest_ref`
--

CREATE TABLE `db_contest_ref` (
  `id` int(11) NOT NULL,
  `1m` double NOT NULL DEFAULT 0,
  `2m` double NOT NULL DEFAULT 0,
  `3m` double NOT NULL DEFAULT 0,
  `4m` double NOT NULL DEFAULT 0,
  `5m` double NOT NULL DEFAULT 0,
  `user_1` varchar(30) NOT NULL DEFAULT '',
  `user_2` varchar(30) NOT NULL DEFAULT '',
  `user_3` varchar(30) NOT NULL DEFAULT '',
  `user_4` varchar(30) NOT NULL DEFAULT '',
  `user_5` varchar(30) NOT NULL DEFAULT '',
  `status` int(11) NOT NULL DEFAULT 0,
  `date_add` int(11) NOT NULL DEFAULT 0,
  `date_end` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_contest_ref_u`
--

CREATE TABLE `db_contest_ref_u` (
  `id` int(11) NOT NULL,
  `login` varchar(35) NOT NULL,
  `uid` int(11) NOT NULL,
  `points` double NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_earnings`
--

CREATE TABLE `db_earnings` (
  `uid` int(11) NOT NULL,
  `pending` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  `id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `db_earnings`
--

INSERT INTO `db_earnings` (`uid`, `pending`, `updated_at`, `id`, `created_at`) VALUES
(1, 0.00000000, 1770083213, NULL, '2026-01-19 00:29:25'),
(2, 0.00000000, 1768782768, NULL, '2026-01-19 00:32:48'),
(3, 0.00000000, 1769136455, NULL, '2026-01-19 00:53:23');

-- --------------------------------------------------------

--
-- Table structure for table `db_insert`
--

CREATE TABLE `db_insert` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `login` varchar(85) NOT NULL DEFAULT '',
  `sum` float(10,2) NOT NULL,
  `sum_x` float(10,2) NOT NULL DEFAULT 0.00,
  `sys` varchar(20) NOT NULL,
  `type` int(11) NOT NULL DEFAULT 1,
  `status` int(11) NOT NULL,
  `ref_credited` tinyint(1) NOT NULL DEFAULT 0,
  `role` int(11) NOT NULL DEFAULT 1,
  `add` int(11) NOT NULL DEFAULT 0,
  `end` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_liders`
--

CREATE TABLE `db_liders` (
  `id` int(11) NOT NULL,
  `1m` double NOT NULL DEFAULT 0,
  `2m` double NOT NULL DEFAULT 0,
  `3m` double NOT NULL DEFAULT 0,
  `4m` double NOT NULL DEFAULT 0,
  `5m` double NOT NULL DEFAULT 0,
  `u1` varchar(55) NOT NULL DEFAULT '',
  `u2` varchar(55) NOT NULL DEFAULT '',
  `u3` varchar(55) NOT NULL DEFAULT '',
  `u4` varchar(55) NOT NULL DEFAULT '',
  `u5` varchar(55) NOT NULL DEFAULT '',
  `bank` double NOT NULL DEFAULT 0,
  `date_add` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_loto_wins`
--

CREATE TABLE `db_loto_wins` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `login` varchar(30) NOT NULL,
  `num_bill` int(11) NOT NULL DEFAULT 0,
  `sum` decimal(10,2) NOT NULL,
  `add` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_news`
--

CREATE TABLE `db_news` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `text` text NOT NULL,
  `cat` int(11) NOT NULL DEFAULT 0,
  `count` int(11) NOT NULL DEFAULT 0,
  `add` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_news`
--

INSERT INTO `db_news` (`id`, `title`, `text`, `cat`, `count`, `add`, `created_at`, `updated_at`) VALUES
(1, 'CashFarm Official Launch', 'CashFarm Official Launch – Start Earning Today!\n\nWe’re excited to announce the official launch of CashFarm.Fun, your premier farming investment game With engaging gameplay and profitable farming rewards, we offer players daily accruals and steady returns on their investments. <P> <br>\n\n✅ Reliable & Transparent – Track your earnings in real time.<p>  \n✅ Profitable Plans – Choose from 4 flexible farm investment options, starting as low as $5 usd and capping at $100 usd, with a maximum of 10 purchases per plan.<p>  \n✅ Daily Payouts – Earn consistently with smooth withdrawals, starting at a $5 usd minimum withdrawal and a 5% withdrawal fee.<p><br>\n\nBegin with a minimum deposit of just $5 USD and dive into the fun—start farming and earning with CashFarm today!', 0, 0, 1748752621, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_payout`
--

CREATE TABLE `db_payout` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `login` varchar(80) NOT NULL,
  `purse` varchar(120) NOT NULL DEFAULT '0',
  `sum` double NOT NULL DEFAULT 0,
  `sum2` float(10,2) NOT NULL DEFAULT 0.00,
  `status` int(11) NOT NULL DEFAULT 0,
  `sys` varchar(21) NOT NULL DEFAULT '0',
  `psys` int(11) NOT NULL DEFAULT 0,
  `add` int(11) NOT NULL DEFAULT 0,
  `del` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `txid` varchar(190) DEFAULT NULL,
  `proof` text DEFAULT NULL,
  `paid_at` int(11) DEFAULT NULL,
  `request_id` varchar(190) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_paysystem`
--

CREATE TABLE `db_paysystem` (
  `id` int(11) NOT NULL,
  `title` varchar(20) NOT NULL,
  `name` varchar(20) NOT NULL,
  `currency` varchar(20) NOT NULL,
  `mindep` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `minpay` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `pairs` decimal(50,6) NOT NULL DEFAULT 0.000000,
  `pairs_updated_at` int(11) NOT NULL DEFAULT 0,
  `pairss` decimal(50,6) NOT NULL DEFAULT 0.000000,
  `pairss_updated_at` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_paysystem`
--

INSERT INTO `db_paysystem` (`id`, `title`, `name`, `currency`, `mindep`, `minpay`, `pairs`, `pairs_updated_at`, `pairss`, `pairss_updated_at`, `created_at`, `updated_at`) VALUES
(3, 'TRON', 'TRON', 'TRX', 10.000000, 1.000000, 0.292410, 1767654252, 3.419667, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(4, 'LITECOIN', 'LiteCoin', 'LTC', 0.020000, 1.000000, 83.988669, 1767654252, 0.011906, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(5, 'DOGECOIN', 'DogeCoin', 'DOGE', 10.000000, 1.000000, 0.152420, 1767654252, 6.560184, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(6, 'BITCOIN', 'Bitcoin', 'BTC', 0.000050, 10.000000, 94067.918940, 1767654252, 0.000011, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(7, 'BinanceCoin', 'BinanceCoin', 'BNB', 0.001000, 1.000000, 911.720013, 1767654252, 0.001097, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(8, 'ETHEREUM', 'Ethereum', 'ETH', 0.005000, 5.000000, 3234.419057, 1767654252, 0.000309, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(9, 'DASHCOIN', 'Dash', 'DASH', 0.100000, 1.000000, 44.179250, 1767654252, 0.022623, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(10, 'TETHER (TRC-20)', 'TRON_TRC20', 'USDT', 10.000000, 10.000000, 1.000000, 0, 1.000000, 0, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(11, 'BITCOIN CASH', 'BitcoinCash', 'BCH', 0.002000, 1.000000, 648.007916, 1767654252, 0.001543, 1767654257, '2025-12-21 21:46:47', '2026-01-05 23:04:17'),
(525, 'TON', 'TON', 'TON', 10.000000, 0.000000, 1.898175, 1767654252, 0.527116, 1767654257, '2026-01-05 10:17:13', '2026-01-05 23:04:17');

-- --------------------------------------------------------

--
-- Table structure for table `db_percent`
--

CREATE TABLE `db_percent` (
  `id` int(11) NOT NULL,
  `type` int(11) NOT NULL,
  `sum_a` float(10,2) NOT NULL,
  `sum_b` float(10,2) NOT NULL,
  `sum_x` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_percent`
--

INSERT INTO `db_percent` (`id`, `type`, `sum_a`, `sum_b`, `sum_x`, `created_at`, `updated_at`) VALUES
(1, 1, 0.00, 99.99, 0.00, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(2, 1, 100.00, 499.99, 0.00, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(3, 1, 500.00, 999.99, 0.00, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(4, 1, 1000.00, 4999.99, 0.00, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(5, 1, 5000.00, 99999.00, 0.00, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_points_ledger`
--

CREATE TABLE `db_points_ledger` (
  `id` bigint(20) NOT NULL,
  `uid` int(11) NOT NULL,
  `delta` bigint(20) NOT NULL,
  `ctx` varchar(32) NOT NULL,
  `meta_json` longtext DEFAULT NULL,
  `ref_uid` int(11) DEFAULT NULL,
  `tarif_id` int(11) DEFAULT NULL,
  `usd_value` decimal(10,2) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `db_points_ledger`
--

INSERT INTO `db_points_ledger` (`id`, `uid`, `delta`, `ctx`, `meta_json`, `ref_uid`, `tarif_id`, `usd_value`, `created_at`, `updated_at`) VALUES
(1, 1, 250, 'Onboarding bonus', '{\"src\":\"tg_webapp_auth\"}', NULL, NULL, NULL, 1768782560, '2026-01-19 00:29:20'),
(2, 2, 250, 'Onboarding bonus', '{\"src\":\"tg_webapp_auth\"}', NULL, NULL, NULL, 1768782758, '2026-01-19 00:32:38'),
(3, 3, 250, 'Onboarding bonus', '{\"src\":\"tg_webapp_auth\"}', NULL, NULL, NULL, 1768783996, '2026-01-19 00:53:16'),
(4, 1, 4, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768802708}', NULL, NULL, NULL, 1768802708, '2026-01-19 06:05:08'),
(5, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768807302}', NULL, NULL, NULL, 1768807302, '2026-01-19 07:21:42'),
(6, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768812195}', NULL, NULL, NULL, 1768812195, '2026-01-19 08:43:15'),
(7, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768820473}', NULL, NULL, NULL, 1768820473, '2026-01-19 11:01:13'),
(8, 1, 7, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768856120}', NULL, NULL, NULL, 1768856120, '2026-01-19 20:55:20'),
(9, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768856667}', NULL, NULL, NULL, 1768856667, '2026-01-19 21:04:27'),
(10, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768861580}', NULL, NULL, NULL, 1768861580, '2026-01-19 22:26:20'),
(11, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768866507}', NULL, NULL, NULL, 1768866507, '2026-01-19 23:48:27'),
(12, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768871487}', NULL, NULL, NULL, 1768871487, '2026-01-20 01:11:27'),
(13, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1768876407}', NULL, NULL, NULL, 1768876407, '2026-01-20 02:33:27'),
(14, 1, 30, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769029014}', NULL, NULL, NULL, 1769029014, '2026-01-21 20:56:54'),
(15, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769029459}', NULL, NULL, NULL, 1769029459, '2026-01-21 21:04:19'),
(16, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769034362}', NULL, NULL, NULL, 1769034362, '2026-01-21 22:26:02'),
(17, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769039299}', NULL, NULL, NULL, 1769039299, '2026-01-21 23:48:19'),
(18, 1, 2, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769053633}', NULL, NULL, NULL, 1769053633, '2026-01-22 03:47:13'),
(19, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769054104}', NULL, NULL, NULL, 1769054104, '2026-01-22 03:55:04'),
(20, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769059098}', NULL, NULL, NULL, 1769059099, '2026-01-22 05:18:19'),
(21, 1, 2, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769072859}', NULL, NULL, NULL, 1769072859, '2026-01-22 09:07:39'),
(22, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769074170}', NULL, NULL, NULL, 1769074170, '2026-01-22 09:29:30'),
(23, 1, 9, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769118938}', NULL, NULL, NULL, 1769118938, '2026-01-22 21:55:38'),
(24, 1, 7, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769157203}', NULL, NULL, NULL, 1769157203, '2026-01-23 08:33:23'),
(25, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769157785}', NULL, NULL, NULL, 1769157785, '2026-01-23 08:43:05'),
(26, 1, 12, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769221233}', NULL, NULL, NULL, 1769221233, '2026-01-24 02:20:33'),
(27, 1, 6, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769246991}', NULL, NULL, NULL, 1769246991, '2026-01-24 09:29:51'),
(28, 1, 11, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769304676}', NULL, NULL, NULL, 1769304676, '2026-01-25 01:31:16'),
(29, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769306690}', NULL, NULL, NULL, 1769306690, '2026-01-25 02:04:50'),
(30, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769312693}', NULL, NULL, NULL, 1769312693, '2026-01-25 03:44:53'),
(31, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769315784}', NULL, NULL, NULL, 1769315784, '2026-01-25 04:36:24'),
(32, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769320806}', NULL, NULL, NULL, 1769320806, '2026-01-25 06:00:06'),
(33, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769326842}', NULL, NULL, NULL, 1769326842, '2026-01-25 07:40:42'),
(34, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769330638}', NULL, NULL, NULL, 1769330638, '2026-01-25 08:43:58'),
(35, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769335520}', NULL, NULL, NULL, 1769335520, '2026-01-25 10:05:20'),
(36, 1, 6, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769369981}', NULL, NULL, NULL, 1769369981, '2026-01-25 19:39:41'),
(37, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769370081}', NULL, NULL, NULL, 1769370081, '2026-01-25 19:41:21'),
(38, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769375060}', NULL, NULL, NULL, 1769375060, '2026-01-25 21:04:20'),
(39, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769379980}', NULL, NULL, NULL, 1769379980, '2026-01-25 22:26:20'),
(40, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769384900}', NULL, NULL, NULL, 1769384900, '2026-01-25 23:48:20'),
(41, 1, 1, 'vp_daily', '{\"season_id\":2,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769389879}', NULL, NULL, NULL, 1769389879, '2026-01-26 01:11:19'),
(42, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769394800}', NULL, NULL, NULL, 1769394800, '2026-01-26 02:33:20'),
(43, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769399720}', NULL, NULL, NULL, 1769399720, '2026-01-26 03:55:20'),
(44, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769404640}', NULL, NULL, NULL, 1769404640, '2026-01-26 05:17:20'),
(45, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769409620}', NULL, NULL, NULL, 1769409620, '2026-01-26 06:40:20'),
(46, 1, 53, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769672229}', NULL, NULL, NULL, 1769672229, '2026-01-29 07:37:09'),
(47, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769679528}', NULL, NULL, NULL, 1769679528, '2026-01-29 09:38:48'),
(48, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769682694}', NULL, NULL, NULL, 1769682694, '2026-01-29 10:31:34'),
(49, 1, 10, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769733316}', NULL, NULL, NULL, 1769733316, '2026-01-30 00:35:16'),
(50, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769735452}', NULL, NULL, NULL, 1769735452, '2026-01-30 01:10:52'),
(51, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769740372}', NULL, NULL, NULL, 1769740372, '2026-01-30 02:32:52'),
(52, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769745352}', NULL, NULL, NULL, 1769745352, '2026-01-30 03:55:52'),
(53, 1, 32, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769905687}', NULL, NULL, NULL, 1769905687, '2026-02-01 00:28:07'),
(54, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769908250}', NULL, NULL, NULL, 1769908250, '2026-02-01 01:10:50'),
(55, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769913169}', NULL, NULL, NULL, 1769913169, '2026-02-01 02:32:49'),
(56, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769918149}', NULL, NULL, NULL, 1769918149, '2026-02-01 03:55:49'),
(57, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769923070}', NULL, NULL, NULL, 1769923070, '2026-02-01 05:17:50'),
(58, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769927989}', NULL, NULL, NULL, 1769927989, '2026-02-01 06:39:49'),
(59, 1, 1, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1769932969}', NULL, NULL, NULL, 1769932969, '2026-02-01 08:02:49'),
(60, 1, 30, 'vp_daily', '{\"season_id\":3,\"tarif_id\":10001,\"chain_id\":1,\"crossbreed_level\":0,\"rarity\":\"legendary\",\"ts\":1770082557}', NULL, NULL, NULL, 1770082557, '2026-02-03 01:35:57');

-- --------------------------------------------------------

--
-- Table structure for table `db_points_log`
--

CREATE TABLE `db_points_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` int(10) UNSIGNED NOT NULL,
  `type` enum('purchase','referral','admin') NOT NULL,
  `delta` int(11) NOT NULL,
  `tarif_id` int(10) UNSIGNED DEFAULT NULL,
  `ref_uid` int(10) UNSIGNED DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` int(10) UNSIGNED NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_purse`
--

CREATE TABLE `db_purse` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL DEFAULT 0,
  `name` varchar(20) NOT NULL DEFAULT '0',
  `purse` varchar(60) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_ref_earn`
--

CREATE TABLE `db_ref_earn` (
  `id` int(11) NOT NULL,
  `ref_uid` int(11) NOT NULL,
  `user_uid` int(11) NOT NULL,
  `usd` decimal(10,2) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `ctx` varchar(32) NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_reviews`
--

CREATE TABLE `db_reviews` (
  `id` int(11) NOT NULL,
  `login` varchar(90) NOT NULL DEFAULT '0',
  `uid` int(11) NOT NULL,
  `text` text NOT NULL,
  `adm_text` text NOT NULL,
  `img` int(11) NOT NULL DEFAULT 0,
  `reward` int(11) NOT NULL DEFAULT 0,
  `hide` int(11) NOT NULL DEFAULT 0,
  `date` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_stats`
--

CREATE TABLE `db_stats` (
  `id` int(11) NOT NULL,
  `users` int(11) NOT NULL DEFAULT 0,
  `inserts` float(10,2) NOT NULL DEFAULT 0.00,
  `payments` float(10,2) NOT NULL DEFAULT 0.00,
  `views` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_stats`
--

INSERT INTO `db_stats` (`id`, `users`, `inserts`, `payments`, `views`, `created_at`, `updated_at`) VALUES
(1, 9, 122.42, 9.20, 0, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_store`
--

CREATE TABLE `db_store` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `title` varchar(20) NOT NULL,
  `tarif` int(11) NOT NULL,
  `hashpower` int(11) NOT NULL DEFAULT 0,
  `speed` decimal(10,6) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 1,
  `add` int(11) NOT NULL,
  `end` int(11) NOT NULL,
  `last` int(11) NOT NULL,
  `season_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_store`
--

INSERT INTO `db_store` (`id`, `uid`, `title`, `tarif`, `hashpower`, `speed`, `status`, `add`, `end`, `last`, `season_id`, `created_at`, `updated_at`) VALUES
(1, 1, 'Carrot Farm', 1, 5, 25.000000, 2, 1762505032, 1765097032, 1762505032, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_surf`
--

CREATE TABLE `db_surf` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `title` varchar(70) NOT NULL,
  `url` varchar(255) NOT NULL,
  `country` varchar(555) NOT NULL DEFAULT 'XX|',
  `crev` int(11) NOT NULL DEFAULT 0,
  `price_click` float(10,6) NOT NULL,
  `per_click` float(10,6) NOT NULL,
  `views` int(11) NOT NULL DEFAULT 0,
  `balance` float(10,6) NOT NULL DEFAULT 0.000000,
  `timer` int(11) NOT NULL DEFAULT 10,
  `reply` int(11) NOT NULL DEFAULT 24,
  `wind` int(11) NOT NULL DEFAULT 0,
  `vip` int(11) NOT NULL DEFAULT 0,
  `date_add` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_surf_config`
--

CREATE TABLE `db_surf_config` (
  `id` int(11) NOT NULL,
  `timer` int(11) NOT NULL,
  `price_click` float(10,6) NOT NULL,
  `per_click` float(10,6) NOT NULL,
  `timer_pay` float(10,6) NOT NULL,
  `wind` float(10,6) NOT NULL,
  `vip` float(10,6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_surf_config`
--

INSERT INTO `db_surf_config` (`id`, `timer`, `price_click`, `per_click`, `timer_pay`, `wind`, `vip`, `created_at`, `updated_at`) VALUES
(1, 10, 0.001000, 0.001000, 0.001000, 0.000000, 0.005000, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_surf_views`
--

CREATE TABLE `db_surf_views` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `link` int(11) NOT NULL,
  `time_end` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_surf_views`
--

INSERT INTO `db_surf_views` (`id`, `uid`, `link`, `time_end`, `created_at`, `updated_at`) VALUES
(1, 10, 2, 1739916200, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_tarif`
--

CREATE TABLE `db_tarif` (
  `id` int(11) NOT NULL,
  `title` varchar(96) NOT NULL,
  `display_name` varchar(140) NOT NULL DEFAULT '',
  `guardian_no` int(11) NOT NULL DEFAULT 0,
  `guardian_code` varchar(24) NOT NULL DEFAULT '',
  `kind` varchar(16) NOT NULL DEFAULT 'plan',
  `type_primary` varchar(24) NOT NULL DEFAULT 'Mystic',
  `type_secondary` varchar(24) DEFAULT NULL,
  `rarity` varchar(24) NOT NULL DEFAULT 'common',
  `max_evolve` tinyint(4) NOT NULL DEFAULT 5,
  `forms_total` tinyint(4) NOT NULL DEFAULT 6,
  `blur_in_codex` tinyint(4) NOT NULL DEFAULT 1,
  `unlock_method` varchar(32) NOT NULL DEFAULT 'purchase',
  `img` int(11) NOT NULL,
  `speed` decimal(10,6) NOT NULL,
  `profit_speed` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `price` int(11) NOT NULL,
  `period` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_tarif`
--

INSERT INTO `db_tarif` (`id`, `title`, `display_name`, `guardian_no`, `guardian_code`, `kind`, `type_primary`, `type_secondary`, `rarity`, `max_evolve`, `forms_total`, `blur_in_codex`, `unlock_method`, `img`, `speed`, `profit_speed`, `price`, `period`, `created_at`, `updated_at`, `sort_order`, `is_active`) VALUES
(1, 'Neon Carrot', '#001 Neon Carrot', 1, 'G001', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.200000, 0.000000, 25, 15, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 1, 1),
(2, 'Neon Corn', '#002 Neon Corn', 2, 'G002', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.350000, 0.000000, 50, 20, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 2, 1),
(3, 'Neon Tomato', '#003 Neon Tomato', 3, 'G003', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.550000, 0.000000, 100, 25, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 3, 1),
(4, 'Neon Eggplant', '#004 Neon Eggplant', 4, 'G004', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.850000, 0.000000, 250, 30, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 4, 1),
(5, 'Neon Melon', '#005 Neon Melon', 5, 'G005', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 2.200000, 0.000000, 500, 35, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 5, 1),
(6, 'Neon Potato', '#006 Neon Potato', 6, 'G006', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 2.600000, 0.000000, 1000, 40, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 6, 1),
(7, 'Neon Onion', '#007 Neon Onion', 7, 'G007', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 3.100000, 0.000000, 2500, 45, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 7, 1),
(8, 'Neon Strawberry', '#008 Neon Strawberry', 8, 'G008', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 3.600000, 0.000000, 5000, 50, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 8, 1),
(9, 'Neon Pumpkin', '#009 Neon Pumpkin', 9, 'G009', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 4.200000, 0.000000, 10000, 60, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 9, 1),
(10, 'Neon Lettuce', '#010 Neon Lettuce', 10, 'G010', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10, 0),
(11, 'Neon Cabbage', '#011 Neon Cabbage', 11, 'G011', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 11, 0),
(12, 'Neon Broccoli', '#012 Neon Broccoli', 12, 'G012', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 12, 0),
(13, 'Neon Garlic', '#013 Neon Garlic', 13, 'G013', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 13, 0),
(14, 'Neon Beet', '#014 Neon Beet', 14, 'G014', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 14, 0),
(15, 'Neon Radish', '#015 Neon Radish', 15, 'G015', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 15, 0),
(16, 'Neon Pepper', '#016 Neon Pepper', 16, 'G016', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 16, 0),
(17, 'Neon Chili', '#017 Neon Chili', 17, 'G017', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 17, 0),
(18, 'Neon Apple', '#018 Neon Apple', 18, 'G018', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 18, 0),
(19, 'Neon Pear', '#019 Neon Pear', 19, 'G019', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 19, 0),
(20, 'Neon Peach', '#020 Neon Peach', 20, 'G020', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 20, 0),
(21, 'Neon Grape', '#021 Neon Grape', 21, 'G021', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 21, 0),
(22, 'Neon Blueberry', '#022 Neon Blueberry', 22, 'G022', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 22, 0),
(23, 'Neon Raspberry', '#023 Neon Raspberry', 23, 'G023', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 23, 0),
(24, 'Neon Mango', '#024 Neon Mango', 24, 'G024', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 24, 0),
(25, 'Neon Pineapple', '#025 Neon Pineapple', 25, 'G025', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 25, 0),
(26, 'Neon Kiwi', '#026 Neon Kiwi', 26, 'G026', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 26, 0),
(27, 'Neon Banana', '#027 Neon Banana', 27, 'G027', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 27, 0),
(28, 'Neon Coconut', '#028 Neon Coconut', 28, 'G028', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 28, 0),
(29, 'Neon Avocado', '#029 Neon Avocado', 29, 'G029', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 29, 0),
(30, 'Neon Cucumber', '#030 Neon Cucumber', 30, 'G030', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 30, 0),
(31, 'Neon Zucchini', '#031 Neon Zucchini', 31, 'G031', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 31, 0),
(32, 'Neon Squash', '#032 Neon Squash', 32, 'G032', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 32, 0),
(33, 'Neon Turnip', '#033 Neon Turnip', 33, 'G033', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 33, 0),
(34, 'Neon Parsnip', '#034 Neon Parsnip', 34, 'G034', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 34, 0),
(35, 'Neon Kale', '#035 Neon Kale', 35, 'G035', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 35, 0),
(36, 'Neon Spinach', '#036 Neon Spinach', 36, 'G036', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 36, 0),
(37, 'Neon Celery', '#037 Neon Celery', 37, 'G037', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 37, 0),
(38, 'Neon Mushroom', '#038 Neon Mushroom', 38, 'G038', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 38, 0),
(39, 'Neon Truffle', '#039 Neon Truffle', 39, 'G039', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 39, 0),
(40, 'Neon Wheat', '#040 Neon Wheat', 40, 'G040', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 40, 0),
(41, 'Neon Rice', '#041 Neon Rice', 41, 'G041', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 41, 0),
(42, 'Neon Barley', '#042 Neon Barley', 42, 'G042', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 42, 0),
(43, 'Neon Oats', '#043 Neon Oats', 43, 'G043', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 43, 0),
(44, 'Neon Sunflower', '#044 Neon Sunflower', 44, 'G044', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 44, 0),
(45, 'Neon Coffee', '#045 Neon Coffee', 45, 'G045', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 45, 0),
(46, 'Neon Cacao', '#046 Neon Cacao', 46, 'G046', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 46, 0),
(47, 'Neon Cherry', '#047 Neon Cherry', 47, 'G047', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 47, 0),
(48, 'Neon Lime', '#048 Neon Lime', 48, 'G048', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 48, 0),
(49, 'Neon Lemon', '#049 Neon Lemon', 49, 'G049', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 49, 0),
(50, 'Neon Fig', '#050 Neon Fig', 50, 'G050', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 50, 0),
(51, 'Toxic Carrot', '#051 Toxic Carrot', 51, 'G051', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 51, 0),
(52, 'Toxic Corn', '#052 Toxic Corn', 52, 'G052', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 52, 0),
(53, 'Toxic Tomato', '#053 Toxic Tomato', 53, 'G053', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 53, 0),
(54, 'Toxic Eggplant', '#054 Toxic Eggplant', 54, 'G054', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 54, 0),
(55, 'Toxic Melon', '#055 Toxic Melon', 55, 'G055', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 55, 0),
(56, 'Toxic Potato', '#056 Toxic Potato', 56, 'G056', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 56, 0),
(57, 'Toxic Onion', '#057 Toxic Onion', 57, 'G057', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 57, 0),
(58, 'Toxic Strawberry', '#058 Toxic Strawberry', 58, 'G058', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 58, 0),
(59, 'Toxic Pumpkin', '#059 Toxic Pumpkin', 59, 'G059', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 59, 0),
(60, 'Toxic Lettuce', '#060 Toxic Lettuce', 60, 'G060', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 60, 0),
(61, 'Toxic Cabbage', '#061 Toxic Cabbage', 61, 'G061', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 61, 0),
(62, 'Toxic Broccoli', '#062 Toxic Broccoli', 62, 'G062', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 62, 0),
(63, 'Toxic Garlic', '#063 Toxic Garlic', 63, 'G063', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 63, 0),
(64, 'Toxic Beet', '#064 Toxic Beet', 64, 'G064', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 64, 0),
(65, 'Toxic Radish', '#065 Toxic Radish', 65, 'G065', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 65, 0),
(66, 'Toxic Pepper', '#066 Toxic Pepper', 66, 'G066', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 66, 0),
(67, 'Toxic Chili', '#067 Toxic Chili', 67, 'G067', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 67, 0),
(68, 'Toxic Apple', '#068 Toxic Apple', 68, 'G068', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 68, 0),
(69, 'Toxic Pear', '#069 Toxic Pear', 69, 'G069', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 69, 0),
(70, 'Toxic Peach', '#070 Toxic Peach', 70, 'G070', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 70, 0),
(71, 'Toxic Grape', '#071 Toxic Grape', 71, 'G071', 'plan', 'Fire', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 71, 0),
(72, 'Toxic Blueberry', '#072 Toxic Blueberry', 72, 'G072', 'plan', 'Water', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 72, 0),
(73, 'Toxic Raspberry', '#073 Toxic Raspberry', 73, 'G073', 'plan', 'Electric', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 73, 0),
(74, 'Toxic Mango', '#074 Toxic Mango', 74, 'G074', 'plan', 'Earth', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 74, 0),
(75, 'Toxic Pineapple', '#075 Toxic Pineapple', 75, 'G075', 'plan', 'Wind', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 75, 0),
(76, 'Toxic Kiwi', '#076 Toxic Kiwi', 76, 'G076', 'plan', 'Ice', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 76, 0),
(77, 'Toxic Banana', '#077 Toxic Banana', 77, 'G077', 'plan', 'Shadow', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 77, 0),
(78, 'Toxic Coconut', '#078 Toxic Coconut', 78, 'G078', 'plan', 'Light', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 78, 0),
(79, 'Toxic Avocado', '#079 Toxic Avocado', 79, 'G079', 'plan', 'Steel', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 79, 0),
(80, 'Toxic Cucumber', '#080 Toxic Cucumber', 80, 'G080', 'plan', 'Mystic', NULL, 'common', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 80, 0),
(81, 'Toxic Zucchini', '#081 Toxic Zucchini', 81, 'G081', 'plan', 'Fire', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 81, 0),
(82, 'Toxic Squash', '#082 Toxic Squash', 82, 'G082', 'plan', 'Water', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 82, 0),
(83, 'Toxic Turnip', '#083 Toxic Turnip', 83, 'G083', 'plan', 'Electric', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 83, 0),
(84, 'Toxic Parsnip', '#084 Toxic Parsnip', 84, 'G084', 'plan', 'Earth', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 84, 0),
(85, 'Toxic Kale', '#085 Toxic Kale', 85, 'G085', 'plan', 'Wind', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 85, 0),
(86, 'Toxic Spinach', '#086 Toxic Spinach', 86, 'G086', 'plan', 'Ice', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 86, 0),
(87, 'Toxic Celery', '#087 Toxic Celery', 87, 'G087', 'plan', 'Shadow', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 87, 0),
(88, 'Toxic Mushroom', '#088 Toxic Mushroom', 88, 'G088', 'plan', 'Light', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 88, 0),
(89, 'Toxic Truffle', '#089 Toxic Truffle', 89, 'G089', 'plan', 'Steel', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 89, 0),
(90, 'Toxic Wheat', '#090 Toxic Wheat', 90, 'G090', 'plan', 'Mystic', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 90, 0),
(91, 'Toxic Rice', '#091 Toxic Rice', 91, 'G091', 'plan', 'Fire', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 91, 0),
(92, 'Toxic Barley', '#092 Toxic Barley', 92, 'G092', 'plan', 'Water', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 92, 0),
(93, 'Toxic Oats', '#093 Toxic Oats', 93, 'G093', 'plan', 'Electric', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 93, 0),
(94, 'Toxic Sunflower', '#094 Toxic Sunflower', 94, 'G094', 'plan', 'Earth', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 94, 0),
(95, 'Toxic Coffee', '#095 Toxic Coffee', 95, 'G095', 'plan', 'Wind', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 95, 0),
(96, 'Toxic Cacao', '#096 Toxic Cacao', 96, 'G096', 'plan', 'Ice', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 96, 0),
(97, 'Toxic Cherry', '#097 Toxic Cherry', 97, 'G097', 'plan', 'Shadow', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 97, 0),
(98, 'Toxic Lime', '#098 Toxic Lime', 98, 'G098', 'plan', 'Light', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 98, 0),
(99, 'Toxic Lemon', '#099 Toxic Lemon', 99, 'G099', 'plan', 'Steel', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 99, 0),
(100, 'Toxic Fig', '#100 Toxic Fig', 100, 'G100', 'plan', 'Mystic', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 100, 0),
(101, 'Radioactive Carrot', '#101 Radioactive Carrot', 101, 'G101', 'plan', 'Fire', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 101, 0),
(102, 'Radioactive Corn', '#102 Radioactive Corn', 102, 'G102', 'plan', 'Water', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 102, 0),
(103, 'Radioactive Tomato', '#103 Radioactive Tomato', 103, 'G103', 'plan', 'Electric', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 103, 0),
(104, 'Radioactive Eggplant', '#104 Radioactive Eggplant', 104, 'G104', 'plan', 'Earth', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 104, 0),
(105, 'Radioactive Melon', '#105 Radioactive Melon', 105, 'G105', 'plan', 'Wind', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 105, 0),
(106, 'Radioactive Potato', '#106 Radioactive Potato', 106, 'G106', 'plan', 'Ice', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 106, 0),
(107, 'Radioactive Onion', '#107 Radioactive Onion', 107, 'G107', 'plan', 'Shadow', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 107, 0),
(108, 'Radioactive Strawberry', '#108 Radioactive Strawberry', 108, 'G108', 'plan', 'Light', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 108, 0),
(109, 'Radioactive Pumpkin', '#109 Radioactive Pumpkin', 109, 'G109', 'plan', 'Steel', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 109, 0),
(110, 'Radioactive Lettuce', '#110 Radioactive Lettuce', 110, 'G110', 'plan', 'Mystic', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 110, 0),
(111, 'Radioactive Cabbage', '#111 Radioactive Cabbage', 111, 'G111', 'plan', 'Fire', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 111, 0),
(112, 'Radioactive Broccoli', '#112 Radioactive Broccoli', 112, 'G112', 'plan', 'Water', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 112, 0),
(113, 'Radioactive Garlic', '#113 Radioactive Garlic', 113, 'G113', 'plan', 'Electric', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 113, 0),
(114, 'Radioactive Beet', '#114 Radioactive Beet', 114, 'G114', 'plan', 'Earth', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 114, 0),
(115, 'Radioactive Radish', '#115 Radioactive Radish', 115, 'G115', 'plan', 'Wind', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 115, 0),
(116, 'Radioactive Pepper', '#116 Radioactive Pepper', 116, 'G116', 'plan', 'Ice', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 116, 0),
(117, 'Radioactive Chili', '#117 Radioactive Chili', 117, 'G117', 'plan', 'Shadow', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 117, 0),
(118, 'Radioactive Apple', '#118 Radioactive Apple', 118, 'G118', 'plan', 'Light', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 118, 0),
(119, 'Radioactive Pear', '#119 Radioactive Pear', 119, 'G119', 'plan', 'Steel', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 119, 0),
(120, 'Radioactive Peach', '#120 Radioactive Peach', 120, 'G120', 'plan', 'Mystic', NULL, 'rare', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 120, 0),
(121, 'Radioactive Grape', '#121 Radioactive Grape', 121, 'G121', 'plan', 'Fire', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 121, 0),
(122, 'Radioactive Blueberry', '#122 Radioactive Blueberry', 122, 'G122', 'plan', 'Water', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 122, 0),
(123, 'Radioactive Raspberry', '#123 Radioactive Raspberry', 123, 'G123', 'plan', 'Electric', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 123, 0),
(124, 'Radioactive Mango', '#124 Radioactive Mango', 124, 'G124', 'plan', 'Earth', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 124, 0),
(125, 'Radioactive Pineapple', '#125 Radioactive Pineapple', 125, 'G125', 'plan', 'Wind', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 125, 0),
(126, 'Radioactive Kiwi', '#126 Radioactive Kiwi', 126, 'G126', 'plan', 'Ice', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 126, 0),
(127, 'Radioactive Banana', '#127 Radioactive Banana', 127, 'G127', 'plan', 'Shadow', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 127, 0),
(128, 'Radioactive Coconut', '#128 Radioactive Coconut', 128, 'G128', 'plan', 'Light', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 128, 0),
(129, 'Radioactive Avocado', '#129 Radioactive Avocado', 129, 'G129', 'plan', 'Steel', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 129, 0),
(130, 'Radioactive Cucumber', '#130 Radioactive Cucumber', 130, 'G130', 'plan', 'Mystic', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 130, 0),
(131, 'Radioactive Zucchini', '#131 Radioactive Zucchini', 131, 'G131', 'plan', 'Fire', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 131, 0),
(132, 'Radioactive Squash', '#132 Radioactive Squash', 132, 'G132', 'plan', 'Water', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 132, 0),
(133, 'Radioactive Turnip', '#133 Radioactive Turnip', 133, 'G133', 'plan', 'Electric', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 133, 0),
(134, 'Radioactive Parsnip', '#134 Radioactive Parsnip', 134, 'G134', 'plan', 'Earth', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 134, 0),
(135, 'Radioactive Kale', '#135 Radioactive Kale', 135, 'G135', 'plan', 'Wind', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 135, 0),
(136, 'Radioactive Spinach', '#136 Radioactive Spinach', 136, 'G136', 'plan', 'Ice', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 136, 0),
(137, 'Radioactive Celery', '#137 Radioactive Celery', 137, 'G137', 'plan', 'Shadow', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 137, 0),
(138, 'Radioactive Mushroom', '#138 Radioactive Mushroom', 138, 'G138', 'plan', 'Light', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 138, 0),
(139, 'Radioactive Truffle', '#139 Radioactive Truffle', 139, 'G139', 'plan', 'Steel', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 139, 0),
(140, 'Radioactive Wheat', '#140 Radioactive Wheat', 140, 'G140', 'plan', 'Mystic', NULL, 'epic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 140, 0),
(141, 'Radioactive Rice', '#141 Radioactive Rice', 141, 'G141', 'plan', 'Fire', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 141, 0),
(142, 'Radioactive Barley', '#142 Radioactive Barley', 142, 'G142', 'plan', 'Water', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 142, 0),
(143, 'Radioactive Oats', '#143 Radioactive Oats', 143, 'G143', 'plan', 'Electric', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 143, 0),
(144, 'Radioactive Sunflower', '#144 Radioactive Sunflower', 144, 'G144', 'plan', 'Earth', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 144, 0),
(145, 'Radioactive Coffee', '#145 Radioactive Coffee', 145, 'G145', 'plan', 'Wind', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 145, 0),
(146, 'Radioactive Cacao', '#146 Radioactive Cacao', 146, 'G146', 'plan', 'Ice', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 146, 0),
(147, 'Radioactive Cherry', '#147 Radioactive Cherry', 147, 'G147', 'plan', 'Shadow', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 147, 0),
(148, 'Radioactive Lime', '#148 Radioactive Lime', 148, 'G148', 'plan', 'Light', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 148, 0),
(149, 'Radioactive Lemon', '#149 Radioactive Lemon', 149, 'G149', 'plan', 'Steel', NULL, 'legendary', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 149, 0),
(150, 'Radioactive Fig', '#150 Radioactive Fig', 150, 'G150', 'plan', 'Mystic', NULL, 'mythic', 5, 6, 1, 'purchase', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 150, 0),
(10001, 'FoundersEnex', '#STAR01 FoundersEnex', 1, 'STAR01', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10001, 0),
(10002, 'AffiliatesEnex', '#STAR02 AffiliatesEnex', 2, 'STAR02', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10002, 0),
(10003, 'ChampionsEnex', '#STAR03 ChampionsEnex', 3, 'STAR03', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10003, 0),
(10004, 'PioneersEnex', '#STAR04 PioneersEnex', 4, 'STAR04', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10004, 0),
(10005, 'LoyaltyEnex', '#STAR05 LoyaltyEnex', 5, 'STAR05', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10005, 0),
(10006, 'CreatorEnex', '#STAR06 CreatorEnex', 6, 'STAR06', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10006, 0),
(10007, 'SentinelEnex', '#STAR07 SentinelEnex', 7, 'STAR07', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10007, 0),
(10008, 'VanguardEnex', '#STAR08 VanguardEnex', 8, 'STAR08', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10008, 0),
(10009, 'OracleEnex', '#STAR09 OracleEnex', 9, 'STAR09', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10009, 0),
(10010, 'ApexEnex', '#STAR10 ApexEnex', 10, 'STAR10', 'achievement', 'Light', NULL, 'legendary', 5, 6, 0, 'achievement', 0, 1.000000, 0.000000, 0, 0, '2026-01-22 02:46:26', '2026-01-22 02:46:26', 10010, 0);

-- --------------------------------------------------------

--
-- Table structure for table `db_tarif_points`
--

CREATE TABLE `db_tarif_points` (
  `tarif_id` int(11) NOT NULL,
  `points_award` bigint(20) NOT NULL,
  `id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `db_tarif_points`
--

INSERT INTO `db_tarif_points` (`tarif_id`, `points_award`, `id`, `created_at`, `updated_at`) VALUES
(1, 100, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(2, 400, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(3, 1200, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(4, 5000, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(5, 9000, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(6, 40000, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(7, 70000, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47'),
(8, 150000, NULL, '2025-12-21 21:46:47', '2025-12-21 21:46:47');

-- --------------------------------------------------------

--
-- Table structure for table `db_tg_sessions`
--

CREATE TABLE `db_tg_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `tg_id` bigint(20) UNSIGNED DEFAULT NULL,
  `token` char(64) NOT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `ua` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `db_tg_sessions`
--

INSERT INTO `db_tg_sessions` (`id`, `user_id`, `tg_id`, `token`, `ip`, `ua`, `created_at`, `expires_at`, `updated_at`) VALUES
(1, 1, NULL, 'd19af9ca4cb347c27e914dc99216a741b913904ffe32336733327b53919e947a', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:20', '2026-02-18 05:29:20', '2026-01-19 00:29:20'),
(2, 1, NULL, '7bd9712e9fb8f9533f01272e4b7339e4da052356f52a41f730473f62bdac1204', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:20', '2026-02-18 05:29:20', '2026-01-19 00:29:20'),
(3, 1, NULL, '56458a3b417e56fb1bb25d4f874f38ddfa6fbfc076f7788c8691c513caa7f0f0', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:25', '2026-02-18 05:29:25', '2026-01-19 00:29:25'),
(4, 1, NULL, '283f4ef8f2af1bb36ace8b81a6fd76e9f10d46873e37efe61d3389ef4d1ef94f', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:27', '2026-02-18 05:29:27', '2026-01-19 00:29:27'),
(5, 1, NULL, '85c7259ce7fc940d7997b5ad670727ceaba227ee57e1d600e4f43d9badbd0f6e', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:30', '2026-02-18 05:29:30', '2026-01-19 00:29:30'),
(6, 1, NULL, '8ec63b743a63062f59b49dbd8fa01b7ba13657a1b48b1f4aae4c2a94c3c376ed', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:32', '2026-02-18 05:29:32', '2026-01-19 00:29:32'),
(7, 1, NULL, '0d56a8996192b7f83e8d450eb3e87f0f76954a6fdbac059a36e732d308a6d29f', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:34', '2026-02-18 05:29:34', '2026-01-19 00:29:34'),
(8, 1, NULL, '2a7165b93d18c6d7f0e916e777f2c8157e969cc38208cb926c3b25f2b6c84eee', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:37', '2026-02-18 05:29:37', '2026-01-19 00:29:37'),
(9, 1, NULL, 'd649155474bce1a26d312105cf8468b177843c9d667a56c15a22eb2a2080c6eb', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:38', '2026-02-18 05:29:38', '2026-01-19 00:29:38'),
(10, 1, NULL, 'f38cf9e1724fe034c614b8cb64463481e40c7acb57c135a445380a81a4305096', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:29:40', '2026-02-18 05:29:40', '2026-01-19 00:29:40'),
(11, 1, NULL, 'acb3af8445b2608cfb72ebe2843dc154d81c4f0988032d5455fc3546774ba2e7', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:30:16', '2026-02-18 05:30:16', '2026-01-19 00:30:16'),
(13, 1, NULL, 'fb898be8fb20eb8e92819a3d0b8f6c30372c07972383c8faef62c26ea83effcd', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:31:17', '2026-02-18 05:31:17', '2026-01-19 00:31:17'),
(14, 1, NULL, 'bdd868d2f055fcee7b64fe4bdaa5ae7fe2ce689f5edd4bb1d6cf92bf886c4160', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:31:17', '2026-02-18 05:31:17', '2026-01-19 00:31:17'),
(15, 1, NULL, 'ce1e5593a7c848bbdec26db7705103fd4ce6cb537c1b7f1048a0631ff8f4a0ac', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:31:18', '2026-02-18 05:31:18', '2026-01-19 00:31:18'),
(16, 1, NULL, '3e7717d4a481edd813665601ba10a130b46d35ada4d1e5ef94f905348cf4b04b', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:31:20', '2026-02-18 05:31:20', '2026-01-19 00:31:20'),
(17, 1, NULL, '849cf56312856fe9df79e21e0f33bcf896450d2ffc0829559841865f1e003dd0', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:31:28', '2026-02-18 05:31:28', '2026-01-19 00:31:28'),
(18, 2, NULL, '7737f9b3ee46006cc47e6b55d8e50ce03f8498709799fd79c0cdf708190402b3', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:32:38', '2026-02-18 05:32:38', '2026-01-19 00:32:38'),
(19, 2, NULL, '29f6f9553282718f99102bbfe46ca9e374b8e3ff3605319f21c8a77b8531626a', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:32:38', '2026-02-18 05:32:38', '2026-01-19 00:32:38'),
(20, 2, NULL, '65be29a43ceffb2cb8b72d44fbe760e8f748158aca2295c8657aaf413b37b5a4', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:32:50', '2026-02-18 05:32:50', '2026-01-19 00:32:50'),
(21, 2, NULL, '740467b9d0ac4796bfd6721e761f551d2e82da6f21f598fc0e5086970fbef9f6', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:33:18', '2026-02-18 05:33:18', '2026-01-19 00:33:18'),
(23, 2, NULL, 'b49719dcf50f4cc9018e10d7141b672c27954aec945d4fd09a05194e3137e2f8', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:35:22', '2026-02-18 05:35:22', '2026-01-19 00:35:22'),
(24, 2, NULL, '438fb5466f34595cfac4a8618577d372ec16dbb405cbe92cfd0d5a625a4a37ff', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:36:45', '2026-02-18 05:36:45', '2026-01-19 00:36:45'),
(25, 2, NULL, '80436ab025a25d88a62337bab7b81eeffa31b209abbcff136a9b1706a18f1e86', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:36:45', '2026-02-18 05:36:45', '2026-01-19 00:36:45'),
(26, 2, NULL, 'ec4349ad1fc681ccfefa0b1bd5629c2b228711fb52c42e5204e739a1c3ce9df3', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:36:56', '2026-02-18 05:36:56', '2026-01-19 00:36:56'),
(27, 2, NULL, 'abf25a4dcbc8d76e2e6004be8b4d76ba417d27fe3fcc815ac8d66e1573a2dea8', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:37:05', '2026-02-18 05:37:05', '2026-01-19 00:37:05'),
(28, 1, NULL, 'b8f92b4147b09df145fa9068aa194228c1e96594c3d3c928030ce7839d1a7129', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:52:28', '2026-02-18 05:52:28', '2026-01-19 00:52:28'),
(29, 1, NULL, 'fdbb87a491a56d6bfcd7a9d635f03ed4c7f8af72df82725cc8c17f68e59e99b0', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:52:28', '2026-02-18 05:52:28', '2026-01-19 00:52:28'),
(30, 1, NULL, '76dd495bae0b4004983357531d709633493218603e1f0384c746997411c91914', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:52:40', '2026-02-18 05:52:40', '2026-01-19 00:52:40'),
(31, 1, NULL, 'ba11a7ecddb63afc4a681bb693a6f9a77aa0c3d20540432acf7f1d37c9a957fa', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:52:42', '2026-02-18 05:52:42', '2026-01-19 00:52:42'),
(32, 1, NULL, '117a0c5591aa2e94285d4b431538bb5ac31999d3130cff5d143d44aa7d55ee10', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 05:52:51', '2026-02-18 05:52:51', '2026-01-19 00:52:51'),
(33, 3, NULL, '638f66d7995ea1dd78ad794569c4e2f5bb2a0e82b0784ddfd6721397c03ff9a7', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:53:16', '2026-02-18 05:53:16', '2026-01-19 00:53:16'),
(34, 3, NULL, '7e34cb35064fcb7e754b96cd336e33d4cea47ee46d619cf982704c01209e9d8f', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:53:16', '2026-02-18 05:53:16', '2026-01-19 00:53:16'),
(35, 3, NULL, '859a5580be495ff465cf4865338206b76814a083854771f1be3be4eaa1beceb3', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:53:25', '2026-02-18 05:53:25', '2026-01-19 00:53:25'),
(36, 3, NULL, '54f387b305f04e1e8e6e8bf622dc444c7a74d3ad23238568530402041851ccee', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 05:53:38', '2026-02-18 05:53:38', '2026-01-19 00:53:38'),
(38, 1, NULL, '7e3d6340f558f5ac28950415792782ef2073b673eab4978614fb2a3823b160b5', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 06:24:13', '2026-02-18 06:24:13', '2026-01-19 01:24:13'),
(39, 1, NULL, '2330b8c0b4514237d9a9686256302eefbb096eaddb0b3d32156dd3e2680cf13c', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 06:24:14', '2026-02-18 06:24:14', '2026-01-19 01:24:14'),
(40, 1, NULL, '8606a41256134387073c184d2bdb1f47efee62a6c809fdf83e30aa79c18c8af3', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 06:24:19', '2026-02-18 06:24:19', '2026-01-19 01:24:19'),
(41, 1, NULL, '12ff1346e172c5d29ccac80943f446b395c550baca1407150408c4624c5e4c99', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 06:24:21', '2026-02-18 06:24:21', '2026-01-19 01:24:21'),
(43, 3, NULL, '5d7825d78a42a60fd8342d2af6fd9b036137fcb6dc8aaf2f190d4f2cc97515cd', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 06:24:57', '2026-02-18 06:24:57', '2026-01-19 01:24:57'),
(44, 3, NULL, '16e2ffb9d992163bb71bf455fb7abd61ddc88a1d7d8aced5e3bdcc7a05cd0a5f', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 06:24:57', '2026-02-18 06:24:57', '2026-01-19 01:24:57'),
(45, 3, NULL, 'ca67398804b2c868aa95c688c980ff7e0efd93d81009f897d124101c96cfb21b', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 06:25:07', '2026-02-18 06:25:07', '2026-01-19 01:25:07'),
(47, 3, NULL, '44b5c83c8bc17a4f0e1be763f60bd2f3b3e9f560f152ff145275bf2e092348bf', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-19 06:25:27', '2026-02-18 06:25:27', '2026-01-19 01:25:27'),
(48, 1, NULL, '53cd7446c483a69991f3226aa956da94032f2756d9dd02c52ad46555832ac1d3', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-19 11:05:06', '2026-02-18 11:05:06', '2026-01-19 06:05:06'),
(51, 1, NULL, '599e4c5442b3a9e2fd02759415d9b066f90cf016e1b3a4142c996f6db53d2345', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:29:39', '2026-02-18 12:29:39', '2026-01-19 07:29:39'),
(52, 1, NULL, 'f1170da7f1b128b4134e75b596158d72fd0069b130ff27b39e6fe958993ba68d', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:29:39', '2026-02-18 12:29:39', '2026-01-19 07:29:39'),
(53, 1, NULL, '6a0d4b71db23fe69e51efd21b00370b65a765fec39b9aa59b6f96aa4723a5b56', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:29:40', '2026-02-18 12:29:40', '2026-01-19 07:29:40'),
(54, 1, NULL, 'da7e2b4a78be452d30bd05961cfbf08a202b211bda009b75f82b76a2fc7bfe8b', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:29:42', '2026-02-18 12:29:42', '2026-01-19 07:29:42'),
(55, 1, NULL, '704a45de01265de03d3776282d9b3ff88215e7f08bcd64df48705295273d077f', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:29:51', '2026-02-18 12:29:51', '2026-01-19 07:29:51'),
(57, 1, NULL, '7831e0fa37c02ec104290e2f0afecd2477f61f7072af7ebc344dc74e93fce167', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:31:22', '2026-02-18 12:31:22', '2026-01-19 07:31:22'),
(58, 1, NULL, '99ac6d74aa1bfb004a616c916fc0d63135c1f84700b4e2ed4547511e5bb909d2', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:31:22', '2026-02-18 12:31:22', '2026-01-19 07:31:22'),
(59, 1, NULL, '33fc25bae92ebdfc87f3622bca278b89460fd31c56b9079b9657d70df7609926', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:31:37', '2026-02-18 12:31:37', '2026-01-19 07:31:37'),
(60, 1, NULL, '19a278ce8ee4d1938cfcbcace40f3e74798f916a39ff852f3ee84bb37b643709', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:31:50', '2026-02-18 12:31:50', '2026-01-19 07:31:50'),
(61, 1, NULL, '1bd8979095a1061abe6c083499c770515784554db2c88738776f8acedf790427', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:31:51', '2026-02-18 12:31:51', '2026-01-19 07:31:51'),
(62, 1, NULL, 'fcc559b5c15c05a3bb42948329a6d6cc272c8b9df8f10a948ed983d33b6f65d8', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:32:56', '2026-02-18 12:32:56', '2026-01-19 07:32:56'),
(63, 1, NULL, 'fb73ae72c01d47041886e369f3f4da9a38983bf96c6f842bdaa4592932eb1e8d', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:33:03', '2026-02-18 12:33:03', '2026-01-19 07:33:03'),
(64, 1, NULL, 'aeaded8c2bbae4694062a21418ee2cc034f42289a9808d7af0604aa6b044a963', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:33:08', '2026-02-18 12:33:08', '2026-01-19 07:33:08'),
(65, 1, NULL, 'e789a9e884015e87027c5fa2ffdf58086f29389f390390bfd84be726c1b517eb', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:33:17', '2026-02-18 12:33:17', '2026-01-19 07:33:17'),
(66, 1, NULL, 'd5485500f51ab5041b9a8f65ed8d49a8501d307e17d313b96c45e5e1038d8388', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:33:33', '2026-02-18 12:33:33', '2026-01-19 07:33:33'),
(67, 1, NULL, '70c4caf5a4d0fdab77d91b3e104f91ebe5c5b82e4f86dea1f5c492810ddf628b', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:33:44', '2026-02-18 12:33:44', '2026-01-19 07:33:44'),
(68, 1, NULL, '955af5f3460c49ceb150a99550c3eb62aedcedf46f0073d5eecf47cf9134e992', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:34:30', '2026-02-18 12:34:30', '2026-01-19 07:34:30'),
(69, 1, NULL, '66897f07fb14d964dfcb635fa5e3cdb6f4296f0abfee9ae657313d0c4ce4a76f', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:34:37', '2026-02-18 12:34:37', '2026-01-19 07:34:37'),
(70, 1, NULL, 'd71f97c6d256992f90e589730dd400abfc26c7c5425cf34f9fdfa340bced0f58', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:34:56', '2026-02-18 12:34:56', '2026-01-19 07:34:56'),
(71, 1, NULL, '18f6359b07160afcefd8725f9d331aab457e78e5280d9360a3e837f0192072b6', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2026-01-19 12:35:04', '2026-02-18 12:35:04', '2026-01-19 07:35:04'),
(72, 1, NULL, 'ca2c01a3551b8c47af301e87d41cfc2ccc959af6aa3d21bfbc671be6baf9fc94', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-20 02:54:59', '2026-02-19 02:54:59', '2026-01-19 21:54:59'),
(78, 1, NULL, '2289cf916d9ae021090ce237d5c01b5334358758115a7d8b14ee00c064192c07', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-20 07:55:22', '2026-02-19 07:55:22', '2026-01-20 02:55:22'),
(81, 1, NULL, '6cf82db795343c26d58d263e47c5a5d6bb7795f3818385d5555e14215db4249d', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-22 02:12:02', '2026-02-21 02:12:02', '2026-01-21 21:12:02'),
(85, 1, NULL, '4990abfb5845347a4709d59b818d03ef460766b53600b88a6f20db08537c1951', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-22 03:24:19', '2026-02-21 03:24:19', '2026-01-21 22:24:19'),
(93, 1, NULL, 'cacb9e783033cb978234d1e741529d86ad439cdf06a3ac85d990cdc46fb4b138', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-22 04:27:44', '2026-02-21 04:27:44', '2026-01-21 23:27:44'),
(97, 1, NULL, '354ba4973e319d450d4103878ed2d34c24909c340e814c3f546fbf22f78765ea', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-22 08:47:11', '2026-02-21 08:47:11', '2026-01-22 03:47:11'),
(117, 1, NULL, '3ed4c998ae213632da9f6891a5f04a75932eb815acc062265cc133f3227c271c', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-22 11:03:26', '2026-02-21 11:03:26', '2026-01-22 06:03:26'),
(120, 3, NULL, '20efa3f8ca58a336e5574ae6ef1195f2ca9e1439fb25319d9548dad257a9e00b', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:21:37', '2026-02-21 13:21:37', '2026-01-22 08:21:37'),
(121, 3, NULL, '354f5f400ec807fc7f9c5adf1524ab0e730ac1f18deb8a97fb67005da06dfd8e', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:21:40', '2026-02-21 13:21:40', '2026-01-22 08:21:40'),
(122, 3, NULL, '4eb6ffe14e528e32f66a532e5f4324a715c4c5a21ebec73d7d0ef1e4b621e03e', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:22:12', '2026-02-21 13:22:12', '2026-01-22 08:22:12'),
(123, 3, NULL, '07a8b29cf484ebf319e59bbeea3013064567bb3600beb2d36fdd753402608f87', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:22:31', '2026-02-21 13:22:31', '2026-01-22 08:22:31'),
(124, 3, NULL, 'a45ea62c56d8a5002ed9022ff544e25130ed4c4db6602af4965d541c28c61149', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:22:58', '2026-02-21 13:22:58', '2026-01-22 08:22:58'),
(125, 3, NULL, '0abc1ff47d0c776100ed5b73f65e95337c388b41288f365185b5bd250fd5d52c', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:23:19', '2026-02-21 13:23:19', '2026-01-22 08:23:19'),
(126, 3, NULL, '79d00d2eceee7604a193e5e4d4a8daeaedfe6d3f71b1b78317e9de89a6fb3dae', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:23:32', '2026-02-21 13:23:32', '2026-01-22 08:23:32'),
(127, 3, NULL, 'cace3309254b68c3bca971c7ad82cb514ceb017d11b3ed60d7fdf8a15d08815e', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:24:15', '2026-02-21 13:24:15', '2026-01-22 08:24:15'),
(128, 3, NULL, '38adfe3f6ae4ebd75cfcc72c9ae96037bd036abb97b795ad00e34da5f3e21d74', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-22 13:24:24', '2026-02-21 13:24:24', '2026-01-22 08:24:24'),
(130, 1, NULL, '914477fa7f86a6e6277d70799ee6f520e54294ab1d97f5ef96eeb3d4b35b414a', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-23 02:55:23', '2026-02-22 02:55:23', '2026-01-22 21:55:23'),
(131, 1, NULL, '915d60ac115473272e4af9d68cd2c197dec01d5b2b16e72132ec0e3800363ad0', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2026-01-23 02:55:23', '2026-02-22 02:55:23', '2026-01-22 21:55:23'),
(134, 3, NULL, '3ffcb9b2ea9645e6ac3a70493c19b82277ff8dd025ca2ec723e896fe5fc7903e', 0x31e192ca, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-23 07:47:03', '2026-02-22 07:47:03', '2026-01-23 02:47:03'),
(135, 3, NULL, 'c553d8e050367d2ae8ff81c6355cb9292403baa52f77ef33821630b1e0bdf616', 0x31e192ca, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-23 07:47:03', '2026-02-22 07:47:03', '2026-01-23 02:47:03'),
(136, 3, NULL, 'd10450665b1af599abc2aeb7322fba7c40e8da466535250873db5a5047fd5462', 0x31e192ca, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-23 07:47:31', '2026-02-22 07:47:31', '2026-01-23 02:47:31'),
(137, 3, NULL, 'f5323eba8a367184d41a08a2cec6a5929ffb3939ebab20a890f1c53577ae4cdd', 0x31e192ca, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-23 07:47:36', '2026-02-22 07:47:36', '2026-01-23 02:47:36'),
(138, 3, NULL, 'd48fb16aa2ec921af1dcd54ab2e7bfef54868523f7c647ca807b88e26fff1699', 0x31e192ca, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-23 07:47:41', '2026-02-22 07:47:41', '2026-01-23 02:47:41'),
(139, 3, NULL, '2c5228de2b8aa38b49e9ce2ed95658fee7e08e0afd4935767f5b5e0fe666e835', 0x31e192ca, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-23 07:49:06', '2026-02-22 07:49:06', '2026-01-23 02:49:06'),
(140, 1, NULL, 'ba9e11c56dc5b4721a75e5731f743c3b828fc1e697906df03d1cde5f729f4a28', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-23 13:43:03', '2026-02-22 13:43:03', '2026-01-23 08:43:03'),
(142, 1, NULL, '702f052e86857ee1a919a6455272085819a8de166043b802b4aba6f9b80a6138', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-23 13:43:06', '2026-02-22 13:43:06', '2026-01-23 08:43:06'),
(143, 1, NULL, 'd6be8bdb07447004c74d72d4b2a0ebcb0e89a9cf7ba9c9cd0e9ffd92fc1b3644', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-24 07:20:30', '2026-02-23 07:20:30', '2026-01-24 02:20:30'),
(144, 1, NULL, 'aea2de64a4bbb49815664f6732609509339c1377560f1d24db3683dfccf7727b', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-24 07:20:30', '2026-02-23 07:20:30', '2026-01-24 02:20:30'),
(148, 1, NULL, '12722612d02332d7d752d4c3784971ba7b4f728332950f2c00be9fe16c25b297', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-24 07:21:12', '2026-02-23 07:21:12', '2026-01-24 02:21:12'),
(149, 1, NULL, '4d9940e9ddfe5749add822380294f604dd99b1963935874e9d981fcd9a4060b3', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-24 08:59:19', '2026-02-23 08:59:19', '2026-01-24 03:59:19'),
(152, 1, NULL, 'a0dfb55c5738412b685f5e187c781cb4854ade1c8965e93d4aab67060bb9f8ee', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-24 12:19:49', '2026-02-23 12:19:49', '2026-01-24 07:19:49'),
(156, 1, NULL, 'f194d8af70a4fbde7ce8eddf6f2aba5714b2e73766e6bd2a80c2b16743c956e8', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-25 07:04:41', '2026-02-24 07:04:41', '2026-01-25 02:04:41'),
(159, 1, NULL, 'aba76f29a7e6f687eab102d8b8655fc6a20c2e295a88ffadfbee6ec8fd07545e', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 09:45:05', '2026-02-24 09:45:05', '2026-01-25 04:45:05'),
(163, 1, NULL, 'bbba6125fd580182125d89ac40e7dea2b60d30a2e7f2c4e9a3bafb3318229a1d', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 10:35:34', '2026-02-24 10:35:34', '2026-01-25 05:35:34'),
(166, 1, NULL, '9ba6a89f23e15bf6cb1a989ce1dd9fc4e97c09bff411be65f87137209fe7271b', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 12:01:37', '2026-02-24 12:01:37', '2026-01-25 07:01:37'),
(169, 1, NULL, 'fb1867e40cf7a56d7136162b0870b04762c128c5c001368165a6dfad3268e4cf', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 12:03:54', '2026-02-24 12:03:54', '2026-01-25 07:03:54'),
(172, 1, NULL, '1b0603caae6a7e0b491b6cdf8d546310640a38ffad00e70b7168be3c72047cf4', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 12:04:28', '2026-02-24 12:04:28', '2026-01-25 07:04:28'),
(175, 1, NULL, '0b78c04a7ce53df4df4aaa4b787ae524cee4ca297db223caa0aff6eae3499ee3', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 12:41:22', '2026-02-24 12:41:22', '2026-01-25 07:41:22'),
(176, 1, NULL, '8dcc357ac775d3368813d8c95e6a1c73ca014208cdd9a3587764d481687e4bd9', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 12:41:22', '2026-02-24 12:41:22', '2026-01-25 07:41:22'),
(179, 1, NULL, '4a0aaa0d5ba8e92007b1b777aa1c9b1568657b61b6c11fc5ff56e40c55850999', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 12:47:24', '2026-02-24 12:47:24', '2026-01-25 07:47:24'),
(181, 1, NULL, '21dd9bf380db623d531b457191301119f36ca11ac198c87e51f4548d6e1d8393', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 12:47:31', '2026-02-24 12:47:31', '2026-01-25 07:47:31'),
(184, 1, NULL, 'c5fd365d90f7c89ab09dfee75f1ce4b855eebba66cf3079e0e8407d94e5fb617', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 13:51:34', '2026-02-24 13:51:34', '2026-01-25 08:51:34'),
(187, 1, NULL, 'e7c9e817d3f4136152ccfe6b4367f443923e049a8e14be5d42f6edcb850d9c70', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-25 14:06:41', '2026-02-24 14:06:41', '2026-01-25 09:06:41'),
(189, 1, NULL, 'c2f344592ec9d03aeafdc23a5d7d29a55067a6a46ac70d49aa21ef8a7e042f52', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-25 14:06:58', '2026-02-24 14:06:58', '2026-01-25 09:06:58'),
(191, 1, NULL, '32d8042489577c58a9f2adbbb6c6d7bbd001f9725daf8b98089ba61db7c9ef27', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 14:07:45', '2026-02-24 14:07:45', '2026-01-25 09:07:45'),
(195, 1, NULL, 'a14b99aed2d0e1827b7f401b4f3c730830b075982bd65a3fb0c777af2eba66aa', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', '2026-01-25 14:17:27', '2026-02-24 14:17:27', '2026-01-25 09:17:27'),
(198, 1, NULL, '7f8147b494ec6a9942a4ef9243f1d79af3fe205539bf9e4e7e85b8813fedbd3d', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-25 14:18:28', '2026-02-24 14:18:28', '2026-01-25 09:18:28'),
(201, 1, NULL, '16c6e5659e1d146b2e33d102626cfff2eb0e7c15acc040678bb952cfb29ef705', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 14:20:00', '2026-02-24 14:20:00', '2026-01-25 09:20:00'),
(202, 1, NULL, 'a830891600048c9cd5e5e40d9ce0ddd76c6b4893f021a2f386b378bd6c570f28', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 14:20:00', '2026-02-24 14:20:00', '2026-01-25 09:20:00'),
(203, 1, NULL, 'fd959ef4a201bbf3b083c3e5a43696b4dd178e1390ce8d06e8ecf64644d33567', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 14:20:03', '2026-02-24 14:20:03', '2026-01-25 09:20:03'),
(205, 1, NULL, 'cd921cb66350013722dde28226a8f3586e8c4ece88245750d09d3d82aaf2b20d', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 16:14:33', '2026-02-24 16:14:33', '2026-01-25 11:14:33'),
(206, 1, NULL, '60303df25ac71955a2a54c1e25d21711d989ade8acddbce11564efb4325929d9', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 16:14:33', '2026-02-24 16:14:33', '2026-01-25 11:14:33'),
(207, 1, NULL, 'c2c7aad060d9b107984131aa0d1e0a67a1108edc0d82cef534c0868e485ff280', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 16:15:25', '2026-02-24 16:15:25', '2026-01-25 11:15:25'),
(208, 1, NULL, '0edf99e61406ee49c03fdd4c3843a3002e69fbbf099f49320550144be5225389', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 16:15:53', '2026-02-24 16:15:53', '2026-01-25 11:15:53'),
(209, 1, NULL, 'a23ada44cf2ba7c824e46be4b1f5030b0210e95fda54f778a369d78a3a5c3ae5', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 16:16:10', '2026-02-24 16:16:10', '2026-01-25 11:16:10'),
(210, 1, NULL, '6461b99d59f0d21f36d112103896266318fee4c6c4b50017d2c34c88cb3cf722', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 16:16:18', '2026-02-24 16:16:18', '2026-01-25 11:16:18'),
(211, 1, NULL, '95bb97ec1a57715d98a504d5b38b0ccc6b2cf4c50e8279f67a8408e72d14bff3', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-25 16:16:32', '2026-02-24 16:16:32', '2026-01-25 11:16:32'),
(213, 1, NULL, 'e008de9cf1d2ac9579a2248f2606ed5df0128d0ced37b4e30c11fecef235e580', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', '2026-01-26 04:48:45', '2026-02-25 04:48:45', '2026-01-25 23:48:45'),
(215, 1, NULL, '7e3106aca4f5aceff8c71437c3c5df3f7cd372c77bfb96bdad48073b2cdf32d0', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 08:43:05', '2026-02-25 08:43:05', '2026-01-26 03:43:05'),
(217, 1, NULL, '2d5297e2cd79d94c7f1cebc5e7ced43333c4fda9d015a39240bcabd752f99f61', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', '2026-01-26 09:23:18', '2026-02-25 09:23:18', '2026-01-26 04:23:18'),
(218, 1, NULL, '629e8038518f44ca31b74bd81ca56a2d4098ce61ace60b698f66400b965bbf33', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', '2026-01-26 09:23:19', '2026-02-25 09:23:19', '2026-01-26 04:23:19'),
(219, 1, NULL, 'f21a48284bb3fc27933c113d3cb5a6cfb4b92aac0d2c733bd5c8286d4241ff3d', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:32:18', '2026-02-25 13:32:18', '2026-01-26 08:32:18'),
(220, 1, NULL, 'fa59707a7821ce47d11e3d67c10de9296768d8d0d9e9e819cc72f1a56935f3b4', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:32:19', '2026-02-25 13:32:19', '2026-01-26 08:32:19'),
(221, 1, NULL, '61f12b33f332f25ff90144ed093e10d329807a690893514853f747fda29a4ac8', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:37:45', '2026-02-25 13:37:45', '2026-01-26 08:37:45'),
(222, 1, NULL, '1754d3d17a3750285a8601421b481df17cb9c0fb7f0552c660a8b5d5b39db7da', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:37:45', '2026-02-25 13:37:45', '2026-01-26 08:37:45'),
(223, 1, NULL, '1b28ff5967a4614db38f69addd64b0109c33c8550dd851048c30415e5c246da5', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:38:08', '2026-02-25 13:38:08', '2026-01-26 08:38:08'),
(224, 1, NULL, 'b3e1221b03af36e2c7d1208671643e605df8bb72200acd0700ae1b270fae4a3a', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:38:32', '2026-02-25 13:38:32', '2026-01-26 08:38:32'),
(225, 1, NULL, 'ad7b880adfb70ae46de163b9b8d176696d8b37682a8036b5d3786c6152fc75e4', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:38:32', '2026-02-25 13:38:32', '2026-01-26 08:38:32'),
(226, 1, NULL, '8f887e39f2f459d8d84b0d1df4766b3dbea56410058712fbc62221970388db6b', 0xcbd36c9a, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-26 13:41:36', '2026-02-25 13:41:36', '2026-01-26 08:41:36'),
(228, 1, NULL, 'dbabd5f0bdeeee08f13aa42ca37dd798fcb657b3c89c2a1be23835234f3fec29', 0xcbd36c9a, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', '2026-01-26 14:05:06', '2026-02-25 14:05:06', '2026-01-26 09:05:06'),
(229, 1, NULL, '8cbd2494e3ce7747038fe1c19134a2c2e437a2262145e22a7e5b99ec16add326', 0xcbd36c94, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-29 02:55:38', '2026-02-28 02:55:38', '2026-01-28 21:55:38'),
(230, 1, NULL, '57477bb426eb4d50238f1be8cf6b80812212b26ead8cc8b6c2384e07f8ea6679', 0xcbd36c94, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-29 02:55:38', '2026-02-28 02:55:38', '2026-01-28 21:55:38'),
(231, 1, NULL, '446a2b3ca69a26ac09d2eabf30830f74d69b486d913d54f2bc0811146f8af07b', 0xcbd36c94, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-29 12:37:02', '2026-02-28 12:37:02', '2026-01-29 07:37:02'),
(234, 1, NULL, 'b8ff0a789e491fae03330a3b02980b614bf2503a67329a90daf3a3da4784fc85', 0xcbd36c94, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-29 13:39:30', '2026-02-28 13:39:30', '2026-01-29 08:39:30'),
(242, 1, NULL, 'c946d3c5cf2328bff31cf68c1a62187514c466660fcbf54117e3db3b64c18da4', 0xcbd36c94, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-29 14:38:44', '2026-02-28 14:38:44', '2026-01-29 09:38:44'),
(246, 1, NULL, 'c4ed997a49b55463a2e6beac4cdbbb8aba59b23b81003ab3f61644cdf5adcce7', 0xcbd36c94, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-30 06:38:20', '2026-03-01 06:38:20', '2026-01-30 01:38:20'),
(247, 1, NULL, 'aca25a553a595c43df74a53e356f275c91fe31ba2c1dbeed4be3cf970bf4c422', 0xcbd36c94, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-01-30 06:39:29', '2026-03-01 06:39:29', '2026-01-30 01:39:29'),
(249, 1, NULL, '007dbdefd8f0b39759a5543d69253638d29d4d17d65fbb87af675a1e40d07b93', 0xcbd36c07, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-01 05:28:01', '2026-03-03 05:28:01', '2026-02-01 00:28:01'),
(253, 1, NULL, '77fe9f4a2ee19a57c1041db085dfd61ee1caa11956d5d6abfa7c137e3b57d59a', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:39:13', '2026-03-03 08:39:13', '2026-02-01 03:39:13'),
(254, 1, NULL, 'b1d37091622a76f4bffa7c2886946b3ea5b42154325e011d6844a32d6e5b8d3f', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:39:14', '2026-03-03 08:39:14', '2026-02-01 03:39:14'),
(255, 1, NULL, '2fa328d0cecb51a2b044f554fa7a7b4f1974a0b86fed63aabd0b6cf08a67b44f', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:40:06', '2026-03-03 08:40:06', '2026-02-01 03:40:06'),
(256, 1, NULL, 'f833aa4568fba1c147e56e554f228b226980cf6d58322817dd06580eff698c75', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:40:11', '2026-03-03 08:40:11', '2026-02-01 03:40:11'),
(257, 1, NULL, 'd76ad8bbd65b6c716da685e97aceffb931721d8a05b9873488a01cecfabd601c', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:41:16', '2026-03-03 08:41:16', '2026-02-01 03:41:16'),
(258, 1, NULL, 'd49f4ec221e40c5f28fa7b8cd72e56e8155d7ba9e449d38630332eac85b4d518', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:41:20', '2026-03-03 08:41:20', '2026-02-01 03:41:20'),
(260, 1, NULL, '34df38885b1225910e71d04012f7d847a5e0ed0326e9ec21cfae34bf170ba515', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:41:55', '2026-03-03 08:41:55', '2026-02-01 03:41:55'),
(261, 1, NULL, '2b9a8f93bd4236bf773bff8cc8fd7413f6fcbca2246a958fe7cd77f817dd13bf', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:41:58', '2026-03-03 08:41:58', '2026-02-01 03:41:58'),
(262, 1, NULL, 'aa3315fe374878a22cadf171060d19f181af913a28c006414bb369be12b74108', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:42:10', '2026-03-03 08:42:10', '2026-02-01 03:42:10'),
(263, 1, NULL, 'ea4b1d62b3b4f2292190cb05ccac19b0144e81acbbed13e90fd961dc54bd314d', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-01 08:42:22', '2026-03-03 08:42:22', '2026-02-01 03:42:22'),
(264, 1, NULL, '9d885a55a8bc22d5754e90883126c9af6d54eb57c6f9125ca18f82ea01fe05d7', 0xcbd36c07, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-03 06:35:54', '2026-03-05 06:35:54', '2026-02-03 01:35:54'),
(265, 1, NULL, '4ffaf6898c00e61e25dd7628a5c5935e9823184c08e91cef214d60164f14e8f9', 0xcbd36c07, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-03 06:35:55', '2026-03-05 06:35:55', '2026-02-03 01:35:55'),
(267, 1, NULL, '36154bc35be97f8cd5610220f73abc596ef0f63155fce79f783587af0c09e5e8', 0xcbd36c07, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-03 06:35:58', '2026-03-05 06:35:58', '2026-02-03 01:35:58'),
(268, 1, NULL, '9041db7efaf53fc12d362d289e54ac2ce58ff9ea62ae4e2df69f6377a146f3ee', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:40:08', '2026-03-05 06:40:08', '2026-02-03 01:40:08'),
(269, 1, NULL, '2341d5eb242e48e738d973bfc2de4eac61265ec9a9b293bda31b929f33595bef', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:40:08', '2026-03-05 06:40:08', '2026-02-03 01:40:08'),
(270, 1, NULL, '5e81851f257f837cfe330fa33d4c4f0ada94cd7a147c0a8bdbe9f7b5770caaa3', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:40:25', '2026-03-05 06:40:25', '2026-02-03 01:40:25'),
(271, 1, NULL, '5e472bfccd4471cfe167a4f5e7ea88243f00bff7e5687321f8e8c14168cabf28', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:40:30', '2026-03-05 06:40:30', '2026-02-03 01:40:30'),
(272, 1, NULL, '60ccb532b85cfe5b8ad208b0d3e8b81fec1036e514adfe625e3b87789f90998e', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:50:23', '2026-03-05 06:50:23', '2026-02-03 01:50:23');
INSERT INTO `db_tg_sessions` (`id`, `user_id`, `tg_id`, `token`, `ip`, `ua`, `created_at`, `expires_at`, `updated_at`) VALUES
(273, 1, NULL, '95c33df35b2fd44cf2b8ded58dd36648581e353f57ec2a6672fe499755ee6635', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:50:23', '2026-03-05 06:50:23', '2026-02-03 01:50:23'),
(274, 1, NULL, 'b7e27e28f77f85c9e09174af6042100fa707a8def73541a7fe91077a4af24439', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:50:32', '2026-03-05 06:50:32', '2026-02-03 01:50:32'),
(275, 1, NULL, 'bac83b884ca3ca2f8f2ea17b1b3307b8e56c4cbc6d78bc18bbf2796715a55653', 0xcbd36c07, 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', '2026-02-03 06:50:35', '2026-03-05 06:50:35', '2026-02-03 01:50:35');

-- --------------------------------------------------------

--
-- Table structure for table `db_uips`
--

CREATE TABLE `db_uips` (
  `id` int(11) NOT NULL,
  `ip` varchar(125) NOT NULL DEFAULT '',
  `ip2` varchar(125) NOT NULL DEFAULT '',
  `country` varchar(2) NOT NULL DEFAULT '0',
  `count` int(11) NOT NULL DEFAULT 0,
  `time` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `db_users`
--

CREATE TABLE `db_users` (
  `id` int(11) NOT NULL,
  `login` varchar(90) NOT NULL DEFAULT '0',
  `username` varchar(255) NOT NULL DEFAULT '0',
  `email` varchar(255) NOT NULL DEFAULT '0',
  `pass` varchar(160) NOT NULL DEFAULT '0',
  `reg` int(11) NOT NULL DEFAULT 0,
  `auth` int(11) NOT NULL DEFAULT 0,
  `ban` int(11) NOT NULL DEFAULT 0,
  `money_b` float(50,6) NOT NULL DEFAULT 0.000000,
  `money_p` float(50,6) NOT NULL DEFAULT 0.000000,
  `sum_in` float(50,2) NOT NULL DEFAULT 0.00,
  `sum_out` float(50,2) NOT NULL DEFAULT 0.00,
  `sum_ads` float(50,4) NOT NULL DEFAULT 0.0000,
  `surf_view` int(11) NOT NULL DEFAULT 0,
  `surf_earn` float(10,6) NOT NULL DEFAULT 0.000000,
  `earn_bonus` float(10,2) NOT NULL DEFAULT 0.00,
  `speed` decimal(50,6) NOT NULL DEFAULT 0.000000,
  `bank` decimal(50,4) NOT NULL DEFAULT 0.0000,
  `bankin` float(10,4) NOT NULL DEFAULT 0.0000,
  `bankout` float(10,4) NOT NULL DEFAULT 0.0000,
  `last` int(11) NOT NULL DEFAULT 0,
  `refsite` varchar(255) NOT NULL DEFAULT '0',
  `referer` varchar(80) NOT NULL DEFAULT '0',
  `rid` int(11) NOT NULL DEFAULT 0,
  `refs` int(11) NOT NULL DEFAULT 0,
  `income` float(10,6) NOT NULL DEFAULT 0.000000,
  `points_total` bigint(20) NOT NULL DEFAULT 0,
  `points_spendable` bigint(20) NOT NULL DEFAULT 0,
  `ref_views` int(11) NOT NULL DEFAULT 0,
  `ref_to` float(10,6) NOT NULL DEFAULT 0.000000,
  `ref_ok` int(11) NOT NULL DEFAULT 0,
  `freebet` int(11) NOT NULL DEFAULT 0,
  `role` int(11) NOT NULL DEFAULT 1,
  `telegram_id` bigint(20) DEFAULT NULL,
  `tg_firstname` varchar(255) DEFAULT NULL,
  `tg_lastname` varchar(255) DEFAULT NULL,
  `tg_username` varchar(255) DEFAULT NULL,
  `tg_lang` varchar(10) DEFAULT NULL,
  `tg_photo_url` varchar(512) DEFAULT NULL,
  `tg_is_premium` tinyint(1) NOT NULL DEFAULT 0,
  `ref_code` varchar(32) DEFAULT NULL,
  `tg_photo` varchar(512) DEFAULT NULL,
  `tg_updated_at` int(10) UNSIGNED DEFAULT NULL,
  `tg_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `rid_set_at` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rid_lock` tinyint(1) NOT NULL DEFAULT 0,
  `payout_lock` tinyint(1) NOT NULL DEFAULT 0,
  `risk_tag` varchar(32) NOT NULL DEFAULT '',
  `risk_note` text DEFAULT NULL,
  `ref_start_param` varchar(64) NOT NULL DEFAULT '',
  `ref_ip_hash` char(64) NOT NULL DEFAULT '',
  `ref_ua_hash` char(64) NOT NULL DEFAULT '',
  `vx_onboarded` tinyint(1) NOT NULL DEFAULT 0,
  `vx_onboarded_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `db_users`
--

INSERT INTO `db_users` (`id`, `login`, `username`, `email`, `pass`, `reg`, `auth`, `ban`, `money_b`, `money_p`, `sum_in`, `sum_out`, `sum_ads`, `surf_view`, `surf_earn`, `earn_bonus`, `speed`, `bank`, `bankin`, `bankout`, `last`, `refsite`, `referer`, `rid`, `refs`, `income`, `points_total`, `points_spendable`, `ref_views`, `ref_to`, `ref_ok`, `freebet`, `role`, `telegram_id`, `tg_firstname`, `tg_lastname`, `tg_username`, `tg_lang`, `tg_photo_url`, `tg_is_premium`, `ref_code`, `tg_photo`, `tg_updated_at`, `tg_name`, `created_at`, `updated_at`, `rid_set_at`, `rid_lock`, `payout_lock`, `risk_tag`, `risk_note`, `ref_start_param`, `ref_ip_hash`, `ref_ua_hash`, `vx_onboarded`, `vx_onboarded_at`) VALUES
(1, 'tg_8563001032', '', '', '0', 1768782560, 1768782560, 0, 0.000000, 0.000000, 0.00, 0.00, 0.0000, 0, 0.000000, 0.00, 0.000000, 0.0000, 0.0000, 0.0000, 0, '0', '0', 0, 0, 0.000000, 513, 513, 0, 0.000000, 0, 0, 1, 8563001032, 'VaultBoss', '', '', 'en', NULL, 0, '89F30CF5', 'https://t.me/i/userpic/320/IhzxYFWhMODM9muBsQ9JOQLzuKY6eR74Bd9jBrUFjkGoHFJw3M1TdHSkdl2gxIg-.svg', 1770083435, NULL, '2026-01-19 00:29:20', '2026-02-03 01:50:35', 0, 0, 0, '', NULL, '', '', '', 1, 1768782588),
(3, 'tg_Nitrokkjjjk', 'Nitrokkjjjk', '', '0', 1768783996, 1768783996, 0, 0.000000, 0.000000, 0.00, 0.00, 0.0000, 0, 0.000000, 0.00, 0.000000, 0.0000, 0.0000, 0.0000, 0, '0', '0', 1, 0, 0.000000, 250, 250, 0, 0.000000, 0, 0, 1, 7639351718, 'NitroBid Admin', '', 'Nitrokkjjjk', 'en', NULL, 0, 'CC39800E', 'https://t.me/i/userpic/320/PZdoNEO-kb-A-xRQRPBGWdZBvYhs4xoLux8_GSGdZMMLYcJfNZZ-T1Km6YFq11wG.svg', 1769136546, NULL, '2026-01-19 00:53:16', '2026-01-23 02:49:06', 1768783996, 1, 0, '', NULL, '89F30CF5', 'b8bfac5802418f7f8bb5c2cc169ee1b425057163927cc1f5a3a29b76ca425563', 'c009526b5dbe2a01057419d30b2a04f74103eb53bfc057fa864596a3845e99c3', 1, 1768784009);

-- --------------------------------------------------------

--
-- Table structure for table `db_user_points`
--

CREATE TABLE `db_user_points` (
  `id` int(10) UNSIGNED NOT NULL,
  `uid` int(10) UNSIGNED NOT NULL,
  `points` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `lifetime_points` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events_log`
--

CREATE TABLE `events_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `ctx` varchar(64) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `ua` varchar(191) DEFAULT NULL,
  `created_at` int(10) UNSIGNED NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `events_log`
--

INSERT INTO `events_log` (`id`, `user_id`, `event_type`, `ctx`, `ip`, `ua`, `created_at`, `updated_at`) VALUES
(1, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769331094, '2026-01-25 08:51:34'),
(2, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769331096, '2026-01-25 08:51:36'),
(3, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769331099, '2026-01-25 08:51:39'),
(4, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769332001, '2026-01-25 09:06:41'),
(5, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769332017, '2026-01-25 09:06:57'),
(6, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769332018, '2026-01-25 09:06:58'),
(7, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769332018, '2026-01-25 09:06:58'),
(8, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769332065, '2026-01-25 09:07:45'),
(9, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769332071, '2026-01-25 09:07:51'),
(10, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769332074, '2026-01-25 09:07:54'),
(11, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769332647, '2026-01-25 09:17:27'),
(12, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769332647, '2026-01-25 09:17:27'),
(13, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769332649, '2026-01-25 09:17:29'),
(14, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36', 1769332688, '2026-01-25 09:18:08'),
(15, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769332708, '2026-01-25 09:18:28'),
(16, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769332712, '2026-01-25 09:18:32'),
(17, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769332714, '2026-01-25 09:18:34'),
(18, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769332800, '2026-01-25 09:20:00'),
(19, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769332800, '2026-01-25 09:20:00'),
(20, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769332803, '2026-01-25 09:20:03'),
(21, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769332805, '2026-01-25 09:20:05'),
(22, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339673, '2026-01-25 11:14:33'),
(23, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339673, '2026-01-25 11:14:33'),
(24, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339725, '2026-01-25 11:15:25'),
(25, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339753, '2026-01-25 11:15:53'),
(26, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339770, '2026-01-25 11:16:10'),
(27, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339778, '2026-01-25 11:16:18'),
(28, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339792, '2026-01-25 11:16:32'),
(29, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769339792, '2026-01-25 11:16:32'),
(30, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1769384925, '2026-01-25 23:48:45'),
(31, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1769384925, '2026-01-25 23:48:45'),
(32, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769398985, '2026-01-26 03:43:05'),
(33, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769398985, '2026-01-26 03:43:05'),
(34, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"webaut', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1769401398, '2026-01-26 04:23:18'),
(35, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"webaut', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1769401399, '2026-01-26 04:23:19'),
(36, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416338, '2026-01-26 08:32:18'),
(37, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416339, '2026-01-26 08:32:19'),
(38, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416665, '2026-01-26 08:37:45'),
(39, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416665, '2026-01-26 08:37:45'),
(40, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416688, '2026-01-26 08:38:08'),
(41, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416712, '2026-01-26 08:38:32'),
(42, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416712, '2026-01-26 08:38:32'),
(43, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416896, '2026-01-26 08:41:36'),
(44, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.154', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769416896, '2026-01-26 08:41:36'),
(45, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"webaut', '203.211.108.154', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0', 1769418306, '2026-01-26 09:05:06'),
(46, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769637338, '2026-01-28 21:55:38'),
(47, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.7499.192 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769637338, '2026-01-28 21:55:38'),
(48, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769672222, '2026-01-29 07:37:02'),
(49, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769672229, '2026-01-29 07:37:09'),
(50, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769672231, '2026-01-29 07:37:11'),
(51, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769675970, '2026-01-29 08:39:30'),
(52, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769676019, '2026-01-29 08:40:19'),
(53, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769676020, '2026-01-29 08:40:20'),
(54, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769676028, '2026-01-29 08:40:28'),
(55, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769676031, '2026-01-29 08:40:31'),
(56, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769676033, '2026-01-29 08:40:33'),
(57, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769676035, '2026-01-29 08:40:35'),
(58, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769676038, '2026-01-29 08:40:38'),
(59, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769679524, '2026-01-29 09:38:44'),
(60, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769679527, '2026-01-29 09:38:47'),
(61, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769679530, '2026-01-29 09:38:50'),
(62, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769737099, '2026-01-30 01:38:19'),
(63, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769737100, '2026-01-30 01:38:20'),
(64, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769737169, '2026-01-30 01:39:29'),
(65, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.148', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769737175, '2026-01-30 01:39:35'),
(66, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769905681, '2026-02-01 00:28:01'),
(67, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769905682, '2026-02-01 00:28:02'),
(68, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769905691, '2026-02-01 00:28:11'),
(69, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1769905694, '2026-02-01 00:28:14'),
(70, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917153, '2026-02-01 03:39:13'),
(71, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917154, '2026-02-01 03:39:14'),
(72, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917206, '2026-02-01 03:40:06'),
(73, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917211, '2026-02-01 03:40:11'),
(74, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917276, '2026-02-01 03:41:16'),
(75, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917280, '2026-02-01 03:41:20'),
(76, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917289, '2026-02-01 03:41:29'),
(77, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917315, '2026-02-01 03:41:55'),
(78, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917318, '2026-02-01 03:41:58'),
(79, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917330, '2026-02-01 03:42:10'),
(80, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1769917342, '2026-02-01 03:42:22'),
(81, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1770082554, '2026-02-03 01:35:54'),
(82, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1770082555, '2026-02-03 01:35:55'),
(83, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1770082557, '2026-02-03 01:35:57'),
(84, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 1770082558, '2026-02-03 01:35:58'),
(85, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770082808, '2026-02-03 01:40:08'),
(86, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770082808, '2026-02-03 01:40:08'),
(87, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770082825, '2026-02-03 01:40:25'),
(88, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770082830, '2026-02-03 01:40:30'),
(89, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770083423, '2026-02-03 01:50:23'),
(90, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770083423, '2026-02-03 01:50:23'),
(91, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770083432, '2026-02-03 01:50:32'),
(92, 1, 'ref_apply', '{\"ok\":false,\"reason\":\"no_ref_code\",\"rid\":0,\"start_param\":\"\"}', '203.211.108.7', 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.7559.59 Mobile Safari/537.36 Telegram-Android/12.3.1 (Oppo CPH2271; Android 13; SDK 33; AVERAGE)', 1770083435, '2026-02-03 01:50:35');

-- --------------------------------------------------------

--
-- Table structure for table `leaderboard_cache`
--

CREATE TABLE `leaderboard_cache` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope` varchar(32) NOT NULL,
  `bucket` varchar(32) NOT NULL,
  `payload` mediumtext NOT NULL,
  `generated_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `league_assignments`
--

CREATE TABLE `league_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `week_key` varchar(12) NOT NULL,
  `league_key` varchar(16) NOT NULL,
  `week_points` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `league_history`
--

CREATE TABLE `league_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `week_key` varchar(12) NOT NULL,
  `league_key` varchar(16) NOT NULL,
  `week_points` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications_log`
--

CREATE TABLE `notifications_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(32) NOT NULL,
  `level` varchar(16) NOT NULL DEFAULT 'info',
  `title` varchar(120) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `data_json` text DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications_log`
--

INSERT INTO `notifications_log` (`id`, `user_id`, `type`, `level`, `title`, `message`, `data_json`, `is_read`, `created_at`, `read_at`, `updated_at`) VALUES
(1, 1, 'guardian_ended', 'warning', 'Mutant Crop ended', 'Reactivate this Mutant Crop to earn balance again AND unlock Legacy VP (only earned by reactivations after a full term).', '{\"store_id\":1,\"tarif_id\":1,\"end_ts\":1765097032,\"cta\":\"/user/plans?reactivate=1\",\"uk\":\"guardian_end_1\"}', 1, '2026-01-19 00:29:25', '2026-01-19 00:29:27', '2026-01-19 00:29:27'),
(2, 1, 'guardian_collector', 'info', 'Mutant Crop added to Mutant Index', 'This completed Mutant Crop is now a collectible in your Mutant Index. You can reactivate future Guardians to unlock more Legacy VP.', '{\"store_id\":1,\"tarif_id\":1,\"end_ts\":1765097032,\"cta\":\"/user/codex\",\"uk\":\"collector_1\"}', 1, '2026-01-19 00:29:33', '2026-01-19 00:29:35', '2026-01-19 00:29:35'),
(3, 1, 'season_ending', 'warning', 'Season ending soon', 'Season ends soon. Push your rank now to lock in rewards.', '{\"season_no\":2,\"ends_ts\":1769392658,\"uk\":\"season_ending_2\"}', 1, '2026-01-24 09:29:51', '2026-01-25 03:52:07', '2026-01-25 03:52:07'),
(4, 1, 'mutant_ended', 'warning', 'Mutant Crop ended', 'Reactivate this Mutant Crop to earn balance again AND unlock Legacy VP (only earned by reactivations after a full term).', '{\"store_id\":1,\"tarif_id\":1,\"end_ts\":1765097032,\"cta\":\"/user/mutants?reactivate=1\",\"uk\":\"mutant_end_1\"}', 0, '2026-01-29 08:44:58', NULL, '2026-01-29 08:44:58'),
(5, 1, 'streak_freeze', 'info', 'Streak freeze used', 'You missed a day — your streak was protected this week.', '{\"week\":\"2026-W05\",\"streak\":2,\"uk\":\"freeze_2026-W05\"}', 0, '2026-02-01 00:28:07', NULL, '2026-02-01 00:28:07'),
(6, 1, 'streak_freeze', 'info', 'Streak freeze used', 'You missed a day — your streak was protected this week.', '{\"week\":\"2026-W06\",\"streak\":2,\"uk\":\"freeze_2026-W06\"}', 0, '2026-02-03 01:35:57', NULL, '2026-02-03 01:35:57');

-- --------------------------------------------------------

--
-- Table structure for table `pool_ledger`
--

CREATE TABLE `pool_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `season_id` int(10) UNSIGNED NOT NULL,
  `points` int(10) UNSIGNED NOT NULL,
  `ctx` varchar(32) NOT NULL,
  `meta_json` text DEFAULT NULL,
  `created_at` int(10) UNSIGNED NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `referral_share_events`
--

CREATE TABLE `referral_share_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `event` varchar(32) NOT NULL,
  `context` varchar(32) DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reward_ledger`
--

CREATE TABLE `reward_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(32) NOT NULL,
  `asset` varchar(16) NOT NULL DEFAULT 'POINTS',
  `amount` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `title` varchar(120) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `ref_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seasons`
--

CREATE TABLE `seasons` (
  `id` int(11) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `active` tinyint(1) DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `seasons`
--

INSERT INTO `seasons` (`id`, `name`, `start_date`, `end_date`, `active`) VALUES
(1, 'Season 1', '2026-01-29 01:26:38', '2026-03-30 01:26:38', 1);

-- --------------------------------------------------------

--
-- Table structure for table `system_flags`
--

CREATE TABLE `system_flags` (
  `key` varchar(50) NOT NULL,
  `value` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_flags`
--

INSERT INTO `system_flags` (`key`, `value`) VALUES
('season_active', 1),
('season_xp_lock', 0),
('rewards_paused', 0),
('god_drops_paused', 0),
('season_pass_paused', 0);

-- --------------------------------------------------------

--
-- Table structure for table `tg_init_replay`
--

CREATE TABLE `tg_init_replay` (
  `init_hash` char(64) NOT NULL,
  `expires_at` int(11) NOT NULL,
  `id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_badges`
--

CREATE TABLE `user_badges` (
  `user_id` int(11) NOT NULL,
  `badge_code` varchar(50) NOT NULL,
  `season_id` int(11) NOT NULL,
  `created_at` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_boosts`
--

CREATE TABLE `user_boosts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `boost_key` varchar(32) NOT NULL,
  `until_ts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_earnings_buffer`
--

CREATE TABLE `user_earnings_buffer` (
  `user_id` bigint(20) NOT NULL,
  `pending_earnings` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_guardians`
--

CREATE TABLE `user_guardians` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `vault_definition_id` int(11) NOT NULL,
  `purchase_amount` decimal(12,2) NOT NULL,
  `daily_percent` decimal(5,2) NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `total_earned` decimal(12,2) NOT NULL DEFAULT 0.00,
  `last_collected_at` datetime DEFAULT NULL,
  `status` enum('active','expired') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_meta`
--

CREATE TABLE `user_meta` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `meta_key` varchar(64) NOT NULL,
  `meta_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_meta`
--

INSERT INTO `user_meta` (`id`, `user_id`, `meta_key`, `meta_value`, `updated_at`, `created_at`) VALUES
(1, 1, 'first_login_bonus', '1', '2026-01-19 00:29:20', '2026-01-19 00:29:20'),
(2, 1, 'first_login_bonus_pts', '250', '2026-01-19 00:29:20', '2026-01-19 00:29:20'),
(3, 1, 'first_login_bonus_at', '1768782560', '2026-01-19 00:29:20', '2026-01-19 00:29:20'),
(4, 1, 'season pass_badge', 'Season Pass Holder', '2026-01-19 00:29:20', '2026-01-19 00:29:20'),
(5, 1, 'season pass_badge_at', '1768782560', '2026-01-19 00:29:20', '2026-01-19 00:29:20'),
(6, 1, 'founders_badge', '1', '2026-01-19 00:29:20', '2026-01-19 00:29:20'),
(7, 1, 'founders_badge_at', '1768782560', '2026-01-19 00:29:20', '2026-01-19 00:29:20'),
(8, 1, 'last_open', '2026-02-03', '2026-02-03 01:35:57', '2026-01-19 00:29:25'),
(9, 1, 'streak_days', '2', '2026-02-03 01:35:57', '2026-01-19 00:29:25'),
(10, 1, 'guardian_end_prompted_1', '1', '2026-01-19 00:29:25', '2026-01-19 00:29:25'),
(11, 1, 'refs_last', '0', '2026-02-03 01:51:31', '2026-01-19 00:29:25'),
(12, 1, 'collector_store_1', '1', '2026-01-19 00:29:33', '2026-01-19 00:29:33'),
(15, 1, 'founders_popup_seen', '1', '2026-01-19 00:29:48', '2026-01-19 00:29:48'),
(19, 2, 'first_login_bonus', '1', '2026-01-19 00:32:38', '2026-01-19 00:32:38'),
(20, 2, 'first_login_bonus_pts', '250', '2026-01-19 00:32:38', '2026-01-19 00:32:38'),
(21, 2, 'first_login_bonus_at', '1768782758', '2026-01-19 00:32:38', '2026-01-19 00:32:38'),
(22, 2, 'season pass_badge', 'Season Pass Holder', '2026-01-19 00:32:38', '2026-01-19 00:32:38'),
(23, 2, 'season pass_badge_at', '1768782758', '2026-01-19 00:32:38', '2026-01-19 00:32:38'),
(24, 2, 'founders_badge', '1', '2026-01-19 00:32:38', '2026-01-19 00:32:38'),
(25, 2, 'founders_badge_at', '1768782758', '2026-01-19 00:32:38', '2026-01-19 00:32:38'),
(26, 2, 'last_open', '2026-01-19', '2026-01-19 00:32:48', '2026-01-19 00:32:48'),
(27, 2, 'streak_days', '1', '2026-01-19 00:32:48', '2026-01-19 00:32:48'),
(28, 2, 'refs_last', '0', '2026-01-19 00:37:04', '2026-01-19 00:32:48'),
(29, 2, 'founders_popup_seen', '1', '2026-01-19 00:33:05', '2026-01-19 00:33:05'),
(37, 3, 'first_login_bonus', '1', '2026-01-19 00:53:16', '2026-01-19 00:53:16'),
(38, 3, 'first_login_bonus_pts', '250', '2026-01-19 00:53:16', '2026-01-19 00:53:16'),
(39, 3, 'first_login_bonus_at', '1768783996', '2026-01-19 00:53:16', '2026-01-19 00:53:16'),
(40, 3, 'season pass_badge', 'Season Pass Holder', '2026-01-19 00:53:16', '2026-01-19 00:53:16'),
(41, 3, 'season pass_badge_at', '1768783996', '2026-01-19 00:53:16', '2026-01-19 00:53:16'),
(42, 3, 'founders_badge', '1', '2026-01-19 00:53:16', '2026-01-19 00:53:16'),
(43, 3, 'founders_badge_at', '1768783996', '2026-01-19 00:53:16', '2026-01-19 00:53:16'),
(44, 3, 'last_open', '2026-01-23', '2026-01-23 02:47:29', '2026-01-19 00:53:23'),
(45, 3, 'streak_days', '2', '2026-01-23 02:47:29', '2026-01-19 00:53:23'),
(46, 3, 'refs_last', '0', '2026-01-23 02:49:05', '2026-01-19 00:53:23'),
(47, 3, 'founders_popup_seen', '1', '2026-01-19 00:53:29', '2026-01-19 00:53:29'),
(83, 1, 'onboarding_v1_done', '1', '2026-01-19 21:55:51', '2026-01-19 21:55:51'),
(309, 1, 'mutant_end_prompted_1', '1', '2026-01-29 08:44:58', '2026-01-29 08:44:58'),
(338, 1, 'streak_freeze_week', '2026-W06', '2026-02-03 01:35:57', '2026-02-01 00:28:07');

-- --------------------------------------------------------

--
-- Table structure for table `vault_definitions`
--

CREATE TABLE `vault_definitions` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `rarity` enum('common','uncommon','rare','epic','legendary','legendary_special','god') DEFAULT NULL,
  `min_daily_percent` decimal(5,2) NOT NULL,
  `max_daily_percent` decimal(5,2) NOT NULL,
  `duration_days` int(11) NOT NULL DEFAULT 30,
  `max_supply` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `vp_per_day` int(11) NOT NULL DEFAULT 0,
  `lp_per_day` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  `stars` int(11) DEFAULT 1,
  `earns_balance` tinyint(1) DEFAULT 0,
  `earns_gf_points` tinyint(1) DEFAULT 0,
  `earns_gf_shards` tinyint(1) DEFAULT 0,
  `season_only` tinyint(1) DEFAULT 0,
  `season_id` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vault_definitions`
--

INSERT INTO `vault_definitions` (`id`, `name`, `price`, `rarity`, `min_daily_percent`, `max_daily_percent`, `duration_days`, `max_supply`, `is_active`, `created_at`, `vp_per_day`, `lp_per_day`, `updated_at`, `stars`, `earns_balance`, `earns_gf_points`, `earns_gf_shards`, `season_only`, `season_id`) VALUES
(1, 'Test Paid Seed', 10.00, 'common', 1.00, 1.50, 30, NULL, 1, '2026-01-18 18:17:46', 0, 0, 0, 1, 0, 0, 0, 0, NULL),
(10001, 'FoundersEnex', 0.00, 'common', 0.00, 0.00, 30, NULL, 1, '2026-01-24 23:11:24', 0, 0, 1769314284, 1, 0, 0, 0, 0, NULL),
(10002, 'Verdurion — God of Growth', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 500, 2, 0, 1, 0, 0, 0, 0, NULL),
(10003, 'Solharvest — God of Sun & Yield', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 500, 2, 0, 1, 0, 0, 0, 0, NULL),
(10004, 'Emberoot — God of Fire Crops', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 500, 2, 0, 1, 0, 0, 0, 0, NULL),
(10005, 'Tidemelon — God of Water Crops', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 500, 2, 0, 1, 0, 0, 0, 0, NULL),
(10006, 'Brambleon — God of Thorns', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 500, 2, 0, 1, 0, 0, 0, 0, NULL),
(10007, 'Gaiax — God of Life', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 3000, 12, 0, 1, 0, 0, 0, 0, NULL),
(10008, 'Pyrograin — God of Inferno Harvest', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 3000, 12, 0, 1, 0, 0, 0, 0, NULL),
(10009, 'Aqualoom — God of the Deep Fields', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 3000, 12, 0, 1, 0, 0, 0, 0, NULL),
(10010, 'Terravault — God of Earth', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 3000, 12, 0, 1, 0, 0, 0, 0, NULL),
(10011, 'Luminseed — God of Radiance', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 3000, 12, 0, 1, 0, 0, 0, 0, NULL),
(10012, 'Eldergrow — God of Eternity', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 8000, 35, 0, 1, 0, 0, 0, 0, NULL),
(10013, 'Rotmaw — God of Decay', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 8000, 35, 0, 1, 0, 0, 0, 0, NULL),
(10014, 'Chronofield — God of Time', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 8000, 35, 0, 1, 0, 0, 0, 0, NULL),
(10015, 'Overbloom — God of Endless Growth', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 8000, 35, 0, 1, 0, 0, 0, 0, NULL),
(10016, 'Voidsoil — God of the Black Field', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 8000, 35, 0, 1, 0, 0, 0, 0, NULL),
(10017, 'GenesisField — First Grower', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 20000, 100, 0, 1, 0, 0, 0, 0, NULL),
(10018, 'Nullharvest — End of Seasons', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 20000, 100, 0, 1, 0, 0, 0, 0, NULL),
(10019, 'Axiomroot — Law of Growth', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 20000, 100, 0, 1, 0, 0, 0, 0, NULL),
(10020, 'Primaseed — Source of All Crops', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 20000, 100, 0, 1, 0, 0, 0, 0, NULL),
(10021, 'The Fallower — Bringer of Reset', 0.00, 'god', 0.00, 0.00, 0, NULL, 1, '2026-01-28 22:45:23', 20000, 100, 0, 1, 0, 0, 0, 0, NULL),
(10022, 'Season 1 Genesis Mutant', 0.00, 'legendary_special', 0.00, 0.00, 30, NULL, 1, '2026-01-29 01:40:50', 0, 0, 0, 5, 0, 1, 0, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `vault_shop_rotation`
--

CREATE TABLE `vault_shop_rotation` (
  `id` int(11) NOT NULL,
  `vault_definition_id` int(11) NOT NULL,
  `available_from` datetime NOT NULL,
  `available_until` datetime NOT NULL,
  `stock` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_activity_log`
--

CREATE TABLE `vx_activity_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` int(11) NOT NULL,
  `type` varchar(32) NOT NULL,
  `amount` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `meta_json` mediumtext DEFAULT NULL,
  `created_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_activity_log`
--

INSERT INTO `vx_activity_log` (`id`, `uid`, `type`, `amount`, `meta_json`, `created_at`) VALUES
(1, 1, 'rank_up', 0.00000000, '{\"season_id\":2,\"rank\":1000}', 1768782567),
(2, 2, 'rank_up', 0.00000000, '{\"season_id\":2,\"rank\":1000}', 1768782770),
(3, 3, 'rank_up', 0.00000000, '{\"season_id\":2,\"rank\":1000}', 1768784005);

-- --------------------------------------------------------

--
-- Table structure for table `vx_admin_actions`
--

CREATE TABLE `vx_admin_actions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` int(11) NOT NULL,
  `admin_uid` int(11) NOT NULL DEFAULT 0,
  `action` varchar(64) NOT NULL,
  `season_id` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `ua` varchar(255) NOT NULL DEFAULT '',
  `details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_boosts`
--

CREATE TABLE `vx_boosts` (
  `key` varchar(64) NOT NULL,
  `title` varchar(120) NOT NULL,
  `desc` varchar(255) NOT NULL,
  `cost` int(11) NOT NULL DEFAULT 0,
  `duration_sec` int(11) NOT NULL DEFAULT 0,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `sort` int(11) NOT NULL DEFAULT 100,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  `id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_boosts`
--

INSERT INTO `vx_boosts` (`key`, `title`, `desc`, `cost`, `duration_sec`, `enabled`, `sort`, `created_at`, `updated_at`, `id`) VALUES
('bio_luminescent_aura', 'Bio-Luminescent Aura', 'Your profile and Mutants emit a rare bio-luminescent glow. Cosmetic only.', 200, 604800, 1, 70, 1769673910, 1769673910, NULL),
('fertile_soil', 'Fertile Soil', 'Enriches your farm soil, boosting all Mutant yields by 15% for a full day.', 400, 86400, 1, 30, 1769673910, 1769673910, NULL),
('mutant_growth_serum', 'Mutant Growth Serum', 'Feeds your Mutants a potent growth serum, increasing their output by 25% while active.', 350, 21600, 1, 20, 1769673910, 1769673910, NULL),
('mutant_harvest_rush', 'Mutant Harvest Rush', 'Mutants harvest at double efficiency. Earn 2× rewards from all active Mutants while this boost is active.', 500, 10800, 1, 10, 1766275329, 1769673738, NULL),
('pollination_surge', 'Pollination Surge', 'Pollination spreads faster across GreenFarm, increasing referral rewards while active.', 300, 259200, 1, 60, 1769673910, 1769673910, NULL),
('solar_grow_cycle', 'Solar Grow Cycle', 'A perfect solar cycle supercharges your farm. Mutants produce 40% more during this window.', 600, 7200, 1, 40, 1769673910, 1769673910, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vx_daily_scores`
--

CREATE TABLE `vx_daily_scores` (
  `day_key` date NOT NULL,
  `user_id` int(11) NOT NULL,
  `points` bigint(20) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_guardian_art`
--

CREATE TABLE `vx_guardian_art` (
  `tarif_id` int(11) NOT NULL,
  `evolve_level` tinyint(4) NOT NULL,
  `img_key` varchar(190) NOT NULL DEFAULT '',
  `updated_at` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_guardian_art`
--

INSERT INTO `vx_guardian_art` (`tarif_id`, `evolve_level`, `img_key`, `updated_at`) VALUES
(1, 0, '1_e0_20260124041715', 1769228235);

-- --------------------------------------------------------

--
-- Table structure for table `vx_guardian_catalog`
--

CREATE TABLE `vx_guardian_catalog` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(24) NOT NULL DEFAULT 'plan',
  `guardian_no` int(11) NOT NULL DEFAULT 0,
  `guardian_code` varchar(32) NOT NULL,
  `tarif_id` int(11) DEFAULT NULL,
  `base_name` varchar(80) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `type_primary` varchar(32) NOT NULL DEFAULT 'Mystic',
  `type_secondary` varchar(32) DEFAULT NULL,
  `rarity` varchar(24) NOT NULL DEFAULT 'common',
  `max_evolve` tinyint(4) NOT NULL DEFAULT 5,
  `forms_total` tinyint(4) NOT NULL DEFAULT 6,
  `blur_in_codex` tinyint(4) NOT NULL DEFAULT 1,
  `unlock_method` varchar(32) NOT NULL DEFAULT 'purchase',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_guardian_chains`
--

CREATE TABLE `vx_guardian_chains` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` int(11) NOT NULL,
  `tarif` int(11) NOT NULL,
  `root_store_id` int(11) NOT NULL,
  `current_store_id` int(11) NOT NULL,
  `season_id` int(11) NOT NULL DEFAULT 0,
  `rarity` varchar(24) NOT NULL DEFAULT 'common',
  `crossbreed_level` tinyint(4) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `term_end` int(11) NOT NULL DEFAULT 0,
  `matured_at` int(11) NOT NULL DEFAULT 0,
  `crossbreed_deadline` int(11) NOT NULL DEFAULT 0,
  `vp_total` bigint(20) NOT NULL DEFAULT 0,
  `lp_total` bigint(20) NOT NULL DEFAULT 0,
  `vp_frac` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `lp_frac` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `points_last_at` int(11) NOT NULL DEFAULT 0,
  `codex_reason` varchar(24) DEFAULT NULL,
  `codex_at` int(11) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  `kind` varchar(24) NOT NULL DEFAULT 'plan',
  `title_override` varchar(190) DEFAULT NULL,
  `vp_per_day_override` int(11) NOT NULL DEFAULT 0,
  `lp_per_day_override` int(11) NOT NULL DEFAULT 0,
  `max_evolve` tinyint(4) NOT NULL DEFAULT 5,
  `shiny` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_guardian_chains`
--

INSERT INTO `vx_guardian_chains` (`id`, `uid`, `tarif`, `root_store_id`, `current_store_id`, `season_id`, `rarity`, `crossbreed_level`, `status`, `term_end`, `matured_at`, `crossbreed_deadline`, `vp_total`, `lp_total`, `vp_frac`, `lp_frac`, `points_last_at`, `codex_reason`, `codex_at`, `created_at`, `updated_at`, `kind`, `title_override`, `vp_per_day_override`, `lp_per_day_override`, `max_evolve`, `shiny`) VALUES
(1, 1, 10001, 0, 0, 2, 'legendary', 0, 'active', 0, 0, 0, 263, 0, 0.487556, 0.000000, 1770083434, 'founders', 1768782560, 1768782560, 1770083434, 'founder', 'Founders Guardian', 5, 0, 5, 0);

-- --------------------------------------------------------

--
-- Table structure for table `vx_guardian_chain_seasons`
--

CREATE TABLE `vx_guardian_chain_seasons` (
  `chain_id` bigint(20) UNSIGNED NOT NULL,
  `season_id` int(11) NOT NULL,
  `vp_total` bigint(20) NOT NULL DEFAULT 0,
  `lp_total` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_guardian_chain_seasons`
--

INSERT INTO `vx_guardian_chain_seasons` (`chain_id`, `season_id`, `vp_total`, `lp_total`, `updated_at`) VALUES
(1, 2, 123, 0, 1769389879),
(1, 3, 140, 0, 1770082557);

-- --------------------------------------------------------

--
-- Table structure for table `vx_guardian_seen`
--

CREATE TABLE `vx_guardian_seen` (
  `uid` int(11) NOT NULL,
  `tarif_id` int(11) NOT NULL,
  `seen_at` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_idempotency`
--

CREATE TABLE `vx_idempotency` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `idem_key` varchar(190) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'inflight',
  `response_json` mediumtext DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `expires_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `vx_leaderboard_source`
-- (See below for the actual view)
--
CREATE TABLE `vx_leaderboard_source` (
`user_id` int(11)
,`telegram_id` bigint(20)
,`username` varchar(255)
,`points` bigint(20) unsigned
,`updated_at` int(10) unsigned
);

-- --------------------------------------------------------

--
-- Table structure for table `vx_lp_ledger`
--

CREATE TABLE `vx_lp_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` int(11) NOT NULL,
  `delta` int(11) NOT NULL,
  `ctx` varchar(48) NOT NULL,
  `meta_json` mediumtext DEFAULT NULL,
  `created_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_rate_limits`
--

CREATE TABLE `vx_rate_limits` (
  `k` varchar(120) NOT NULL,
  `hits` int(11) NOT NULL DEFAULT 0,
  `reset_at` int(11) NOT NULL,
  `id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_ref_earnings`
--

CREATE TABLE `vx_ref_earnings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rid` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `deposit_id` bigint(20) UNSIGNED NOT NULL,
  `deposit_usd` decimal(18,2) NOT NULL DEFAULT 0.00,
  `reward_usd` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `pct_used` decimal(8,2) NOT NULL DEFAULT 0.00,
  `receipt` text DEFAULT NULL,
  `created_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_ref_qualifications`
--

CREATE TABLE `vx_ref_qualifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `referrer_uid` int(11) NOT NULL,
  `buyer_uid` int(11) NOT NULL,
  `tarif_id` int(11) NOT NULL DEFAULT 0,
  `usd_value` decimal(18,2) NOT NULL DEFAULT 0.00,
  `hold_points` bigint(20) NOT NULL DEFAULT 0,
  `event_ts` int(11) NOT NULL DEFAULT 0,
  `qualify_after` int(11) NOT NULL DEFAULT 0,
  `qualified_at` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `reason` varchar(190) DEFAULT NULL,
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `ua_hash` char(64) NOT NULL DEFAULT '',
  `meta_json` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_seasons`
--

CREATE TABLE `vx_seasons` (
  `id` int(11) NOT NULL,
  `season_no` int(11) NOT NULL,
  `starts_at` int(11) NOT NULL,
  `ends_at` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_locked` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_seasons`
--

INSERT INTO `vx_seasons` (`id`, `season_no`, `starts_at`, `ends_at`, `is_active`, `created_at`, `updated_at`, `is_locked`) VALUES
(1, 1, 1766103482, 1767578258, 1, 1766103482, '2026-01-05 01:57:38', 0),
(2, 2, 1767578258, 1769392658, 0, 1767578258, '2026-01-05 01:57:38', 0),
(3, 3, 1769392700, 1771207100, 0, 1769392700, '2026-01-26 01:58:20', 0);

-- --------------------------------------------------------

--
-- Table structure for table `vx_season_caps`
--

CREATE TABLE `vx_season_caps` (
  `season_id` int(11) NOT NULL,
  `tarif_id` int(11) NOT NULL,
  `cap` int(11) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  `id` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_season_caps`
--

INSERT INTO `vx_season_caps` (`season_id`, `tarif_id`, `cap`, `created_at`, `id`, `updated_at`) VALUES
(1, 1, 0, 1766103482, NULL, '2025-12-22 07:13:24'),
(1, 2, 0, 1766103482, NULL, '2025-12-21 21:46:47'),
(1, 3, 0, 1766103482, NULL, '2025-12-21 21:46:47'),
(1, 4, 0, 1766103482, NULL, '2025-12-21 21:46:47'),
(1, 5, 0, 1766103482, NULL, '2025-12-21 21:46:47'),
(1, 6, 100, 1766103482, NULL, '2025-12-21 21:46:47'),
(1, 7, 5, 1766103482, NULL, '2025-12-21 21:46:47'),
(1, 8, 2, 1766103482, NULL, '2025-12-21 21:46:47'),
(2, 1, 0, 1767578258, NULL, '2026-01-05 01:57:38'),
(2, 2, 1000, 1767578258, NULL, '2026-01-05 01:57:38'),
(2, 3, 500, 1767578258, NULL, '2026-01-05 01:57:38'),
(2, 4, 200, 1767578258, NULL, '2026-01-05 01:57:38'),
(2, 5, 200, 1767578258, NULL, '2026-01-05 01:57:38'),
(2, 6, 100, 1767578258, NULL, '2026-01-05 01:57:38'),
(2, 7, 4, 1767578258, NULL, '2026-01-05 01:57:38'),
(2, 8, 4, 1767578258, NULL, '2026-01-05 01:57:38'),
(3, 1, 1000, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 2, 1000, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 3, 500, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 4, 200, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 5, 200, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 6, 200, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 7, 100, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 8, 100, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 9, 4, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 11, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 12, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 13, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 14, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 15, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 16, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 17, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 18, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 19, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 20, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 21, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 22, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 23, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 24, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 25, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 26, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 27, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 28, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 29, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 30, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 31, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 32, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 33, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 34, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 35, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 36, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 37, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 38, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 39, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 40, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 41, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 42, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 43, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 44, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 45, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 46, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 47, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 48, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 49, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 50, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 51, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 52, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 53, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 54, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 55, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 56, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 57, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 58, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 59, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 60, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 61, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 62, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 63, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 64, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 65, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 66, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 67, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 68, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 69, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 70, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 71, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 72, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 73, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 74, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 75, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 76, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 77, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 78, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 79, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 80, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 81, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 82, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 83, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 84, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 85, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 86, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 87, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 88, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 89, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 90, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 91, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 92, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 93, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 94, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 95, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 96, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 97, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 98, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 99, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 100, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 101, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 102, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 103, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 104, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 105, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 106, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 107, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 108, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 109, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 110, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 111, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 112, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 113, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 114, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 115, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 116, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 117, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 118, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 119, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 120, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 121, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 122, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 123, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 124, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 125, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 126, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 127, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 128, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 129, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 130, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 131, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 132, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 133, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 134, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 135, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 136, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 137, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 138, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 139, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 140, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 141, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 142, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 143, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 144, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 145, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 146, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 147, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 148, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 149, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 150, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10001, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10002, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10003, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10004, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10005, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10006, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10007, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10008, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10009, 0, 1769392700, NULL, '2026-01-26 01:58:20'),
(3, 10010, 0, 1769392700, NULL, '2026-01-26 01:58:20');

-- --------------------------------------------------------

--
-- Table structure for table `vx_season_passes`
--

CREATE TABLE `vx_season_passes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` int(11) NOT NULL,
  `season_id` int(11) NOT NULL,
  `purchased_via` varchar(24) NOT NULL DEFAULT 'balance',
  `created_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_season_points`
--

CREATE TABLE `vx_season_points` (
  `uid` int(11) NOT NULL,
  `season_id` int(11) NOT NULL,
  `vp_total` bigint(20) NOT NULL DEFAULT 0,
  `lp_total` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_season_points`
--

INSERT INTO `vx_season_points` (`uid`, `season_id`, `vp_total`, `lp_total`, `updated_at`) VALUES
(1, 2, 373, 0, 1769389879),
(1, 3, 140, 0, 1770082557),
(2, 2, 250, 0, 1768782758),
(3, 2, 250, 0, 1768783996);

-- --------------------------------------------------------

--
-- Table structure for table `vx_share_events`
--

CREATE TABLE `vx_share_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `ref_code` varchar(32) NOT NULL DEFAULT '',
  `event` varchar(24) NOT NULL,
  `ctx` varchar(64) NOT NULL DEFAULT 'generic',
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `ua_hash` char(64) NOT NULL DEFAULT '',
  `created_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_share_events`
--

INSERT INTO `vx_share_events` (`id`, `uid`, `ref_code`, `event`, `ctx`, `ip_hash`, `ua_hash`, `created_at`) VALUES
(1, 1, '1', 'share', 'founders_popup', 'b8bfac5802418f7f8bb5c2cc169ee1b425057163927cc1f5a3a29b76ca425563', '62d18984722ed057781bce4342a009271fd0bf4799981a048ca348f09af03584', 1768820564);

-- --------------------------------------------------------

--
-- Table structure for table `vx_user_boosts`
--

CREATE TABLE `vx_user_boosts` (
  `id` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `boost_key` varchar(64) NOT NULL,
  `active_until` int(11) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vx_user_metrics`
--

CREATE TABLE `vx_user_metrics` (
  `uid` int(11) NOT NULL,
  `season_id` int(11) NOT NULL DEFAULT 0,
  `last_rank` int(11) NOT NULL DEFAULT 0,
  `last_rank_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vx_user_metrics`
--

INSERT INTO `vx_user_metrics` (`uid`, `season_id`, `last_rank`, `last_rank_at`, `updated_at`) VALUES
(1, 2, 1, 1769318784, 1769318784),
(2, 2, 1, 1768783016, 1768783016),
(3, 2, 1, 1768785907, 1768785907);

-- --------------------------------------------------------

--
-- Table structure for table `vx_yield_ledger`
--

CREATE TABLE `vx_yield_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` int(11) NOT NULL,
  `guardian_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount_usd` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `ctx` varchar(32) NOT NULL DEFAULT 'claim',
  `meta_json` text DEFAULT NULL,
  `created_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `xp_history`
--

CREATE TABLE `xp_history` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `amount` int(11) NOT NULL,
  `source` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `community_pool`
--
ALTER TABLE `community_pool`
  ADD PRIMARY KEY (`season_id`),
  ADD UNIQUE KEY `uniq_community_pool_season` (`season_id`),
  ADD KEY `idx_community_pool_season_id` (`season_id`);

--
-- Indexes for table `db_aff_conf`
--
ALTER TABLE `db_aff_conf`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_bonus`
--
ALTER TABLE `db_bonus`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uid` (`uid`),
  ADD KEY `idx_db_bonus_bonus_id` (`bonus_id`);

--
-- Indexes for table `db_bonus2`
--
ALTER TABLE `db_bonus2`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uid` (`uid`);

--
-- Indexes for table `db_bonus_tg`
--
ALTER TABLE `db_bonus_tg`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_conf`
--
ALTER TABLE `db_conf`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_contest_ref`
--
ALTER TABLE `db_contest_ref`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_contest_ref_u`
--
ALTER TABLE `db_contest_ref_u`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_earnings`
--
ALTER TABLE `db_earnings`
  ADD PRIMARY KEY (`uid`);

--
-- Indexes for table `db_insert`
--
ALTER TABLE `db_insert`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_refcredited` (`ref_credited`),
  ADD KEY `idx_db_insert_uid_end` (`uid`,`end`),
  ADD KEY `idx_db_insert_uid_status` (`uid`,`status`),
  ADD KEY `idx_db_insert_ref_credited` (`ref_credited`);

--
-- Indexes for table `db_liders`
--
ALTER TABLE `db_liders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_loto_wins`
--
ALTER TABLE `db_loto_wins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_news`
--
ALTER TABLE `db_news`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_payout`
--
ALTER TABLE `db_payout`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_request_id` (`request_id`),
  ADD KEY `uid` (`uid`),
  ADD KEY `ix_paid_at` (`paid_at`);

--
-- Indexes for table `db_paysystem`
--
ALTER TABLE `db_paysystem`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_percent`
--
ALTER TABLE `db_percent`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_points_ledger`
--
ALTER TABLE `db_points_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_points_uid` (`uid`),
  ADD KEY `idx_points_uid_time` (`uid`,`created_at`),
  ADD KEY `idx_db_points_ledger_tarif_id` (`tarif_id`);

--
-- Indexes for table `db_points_log`
--
ALTER TABLE `db_points_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_uid_created` (`uid`,`created_at`),
  ADD KEY `idx_db_points_log_tarif_id` (`tarif_id`);

--
-- Indexes for table `db_purse`
--
ALTER TABLE `db_purse`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uid` (`uid`);

--
-- Indexes for table `db_ref_earn`
--
ALTER TABLE `db_ref_earn`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_reviews`
--
ALTER TABLE `db_reviews`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_stats`
--
ALTER TABLE `db_stats`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_store`
--
ALTER TABLE `db_store`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uid` (`uid`),
  ADD KEY `status` (`status`),
  ADD KEY `db_store_season_ix` (`season_id`,`tarif`,`status`),
  ADD KEY `idx_db_store_season_id` (`season_id`);

--
-- Indexes for table `db_surf`
--
ALTER TABLE `db_surf`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_surf_config`
--
ALTER TABLE `db_surf_config`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_surf_views`
--
ALTER TABLE `db_surf_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uid` (`uid`);

--
-- Indexes for table `db_tarif`
--
ALTER TABLE `db_tarif`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_tarif_points`
--
ALTER TABLE `db_tarif_points`
  ADD PRIMARY KEY (`tarif_id`),
  ADD KEY `idx_db_tarif_points_tarif_id` (`tarif_id`);

--
-- Indexes for table `db_tg_sessions`
--
ALTER TABLE `db_tg_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_token` (`token`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_db_tg_sessions_user_id` (`user_id`),
  ADD KEY `idx_tg_sessions_tg_id` (`tg_id`),
  ADD KEY `idx_tg_sessions_user_id` (`user_id`);

--
-- Indexes for table `db_uips`
--
ALTER TABLE `db_uips`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `db_users`
--
ALTER TABLE `db_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `telegram_id` (`telegram_id`),
  ADD UNIQUE KEY `uniq_ref_code` (`ref_code`),
  ADD KEY `rid` (`rid`),
  ADD KEY `ref_to` (`ref_to`),
  ADD KEY `idx_rid` (`rid`),
  ADD KEY `idx_db_users_rid` (`rid`),
  ADD KEY `idx_db_users_sum_in` (`sum_in`),
  ADD KEY `idx_db_users_telegram_id` (`telegram_id`),
  ADD KEY `idx_users_rid` (`rid`),
  ADD KEY `idx_users_rid_set_at` (`rid_set_at`),
  ADD KEY `idx_ref_start_param` (`ref_start_param`),
  ADD KEY `idx_ref_ip_hash` (`ref_ip_hash`),
  ADD KEY `idx_ref_ua_hash` (`ref_ua_hash`),
  ADD KEY `idx_reg` (`reg`),
  ADD KEY `ix_vx_onboarded` (`vx_onboarded`),
  ADD KEY `ix_vx_onboarded_at` (`vx_onboarded_at`),
  ADD KEY `ix_rid` (`rid`);

--
-- Indexes for table `db_user_points`
--
ALTER TABLE `db_user_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_uid` (`uid`),
  ADD KEY `idx_updated_at` (`updated_at`);

--
-- Indexes for table `events_log`
--
ALTER TABLE `events_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_time` (`user_id`,`created_at`),
  ADD KEY `idx_type_time` (`event_type`,`created_at`),
  ADD KEY `idx_events_log_user_id` (`user_id`);

--
-- Indexes for table `leaderboard_cache`
--
ALTER TABLE `leaderboard_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_scope_bucket` (`scope`,`bucket`),
  ADD KEY `idx_scope` (`scope`),
  ADD KEY `idx_bucket` (`bucket`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `league_assignments`
--
ALTER TABLE `league_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_week` (`user_id`,`week_key`),
  ADD KEY `idx_league` (`league_key`),
  ADD KEY `idx_week` (`week_key`),
  ADD KEY `idx_league_assignments_user_id` (`user_id`);

--
-- Indexes for table `league_history`
--
ALTER TABLE `league_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_week` (`week_key`),
  ADD KEY `idx_league` (`league_key`),
  ADD KEY `idx_league_history_user_id` (`user_id`);

--
-- Indexes for table `notifications_log`
--
ALTER TABLE `notifications_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_notifications_log_user_id` (`user_id`);

--
-- Indexes for table `pool_ledger`
--
ALTER TABLE `pool_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_season` (`season_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_pool_ledger_season_id` (`season_id`);

--
-- Indexes for table `referral_share_events`
--
ALTER TABLE `referral_share_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_event` (`event`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_referral_share_events_user_id` (`user_id`);

--
-- Indexes for table `reward_ledger`
--
ALTER TABLE `reward_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_source` (`source`),
  ADD KEY `idx_asset` (`asset`),
  ADD KEY `idx_reward_ledger_user_id` (`user_id`),
  ADD KEY `idx_reward_ledger_ref_id` (`ref_id`),
  ADD KEY `idx_reward_ledger_user_time` (`user_id`,`created_at`);

--
-- Indexes for table `seasons`
--
ALTER TABLE `seasons`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_flags`
--
ALTER TABLE `system_flags`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `tg_init_replay`
--
ALTER TABLE `tg_init_replay`
  ADD PRIMARY KEY (`init_hash`);

--
-- Indexes for table `user_badges`
--
ALTER TABLE `user_badges`
  ADD PRIMARY KEY (`user_id`,`badge_code`,`season_id`);

--
-- Indexes for table `user_boosts`
--
ALTER TABLE `user_boosts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_boost` (`user_id`,`boost_key`),
  ADD KEY `idx_until` (`until_ts`),
  ADD KEY `idx_user_boosts_user_id` (`user_id`);

--
-- Indexes for table `user_earnings_buffer`
--
ALTER TABLE `user_earnings_buffer`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_guardians`
--
ALTER TABLE `user_guardians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_genesis_claim` (`user_id`,`vault_definition_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `vault_definition_id` (`vault_definition_id`),
  ADD KEY `idx_user_mutant` (`user_id`,`vault_definition_id`),
  ADD KEY `idx_user_vault` (`user_id`,`vault_definition_id`);

--
-- Indexes for table `user_meta`
--
ALTER TABLE `user_meta`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_key` (`user_id`,`meta_key`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_key` (`meta_key`),
  ADD KEY `idx_user_meta_user_id` (`user_id`);

--
-- Indexes for table `vault_definitions`
--
ALTER TABLE `vault_definitions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `vault_shop_rotation`
--
ALTER TABLE `vault_shop_rotation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vault_definition_id` (`vault_definition_id`);

--
-- Indexes for table `vx_activity_log`
--
ALTER TABLE `vx_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_type_time` (`type`,`created_at`),
  ADD KEY `ix_uid_time` (`uid`,`created_at`);

--
-- Indexes for table `vx_admin_actions`
--
ALTER TABLE `vx_admin_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vx_admin_actions_ix` (`created_at`,`action`,`season_id`);

--
-- Indexes for table `vx_boosts`
--
ALTER TABLE `vx_boosts`
  ADD PRIMARY KEY (`key`),
  ADD KEY `vx_boosts_enabled_ix` (`enabled`,`sort`);

--
-- Indexes for table `vx_daily_scores`
--
ALTER TABLE `vx_daily_scores`
  ADD PRIMARY KEY (`day_key`,`user_id`),
  ADD KEY `idx_points` (`points` DESC);

--
-- Indexes for table `vx_guardian_art`
--
ALTER TABLE `vx_guardian_art`
  ADD PRIMARY KEY (`tarif_id`,`evolve_level`),
  ADD KEY `ix_updated` (`updated_at`);

--
-- Indexes for table `vx_guardian_catalog`
--
ALTER TABLE `vx_guardian_catalog`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_code` (`guardian_code`),
  ADD UNIQUE KEY `ux_kind_no` (`kind`,`guardian_no`),
  ADD KEY `ix_tarif` (`tarif_id`),
  ADD KEY `ix_kind` (`kind`);

--
-- Indexes for table `vx_guardian_chains`
--
ALTER TABLE `vx_guardian_chains`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_root` (`root_store_id`),
  ADD KEY `ix_uid_status` (`uid`,`status`),
  ADD KEY `ix_uid_tarif` (`uid`,`tarif`),
  ADD KEY `ix_deadline` (`crossbreed_deadline`);

--
-- Indexes for table `vx_guardian_chain_seasons`
--
ALTER TABLE `vx_guardian_chain_seasons`
  ADD PRIMARY KEY (`chain_id`,`season_id`),
  ADD KEY `ix_season` (`season_id`,`vp_total`,`lp_total`);

--
-- Indexes for table `vx_guardian_seen`
--
ALTER TABLE `vx_guardian_seen`
  ADD PRIMARY KEY (`uid`,`tarif_id`),
  ADD KEY `seen_at` (`seen_at`);

--
-- Indexes for table `vx_idempotency`
--
ALTER TABLE `vx_idempotency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_idem_key` (`idem_key`),
  ADD KEY `ix_expires` (`expires_at`);

--
-- Indexes for table `vx_lp_ledger`
--
ALTER TABLE `vx_lp_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_uid_time` (`uid`,`created_at`);

--
-- Indexes for table `vx_rate_limits`
--
ALTER TABLE `vx_rate_limits`
  ADD PRIMARY KEY (`k`);

--
-- Indexes for table `vx_ref_earnings`
--
ALTER TABLE `vx_ref_earnings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_dep_rid` (`deposit_id`,`rid`),
  ADD KEY `ix_rid_created` (`rid`,`created_at`);

--
-- Indexes for table `vx_ref_qualifications`
--
ALTER TABLE `vx_ref_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_buyer` (`buyer_uid`),
  ADD KEY `ix_ref_status` (`referrer_uid`,`status`),
  ADD KEY `ix_due` (`qualify_after`,`status`);

--
-- Indexes for table `vx_seasons`
--
ALTER TABLE `vx_seasons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vx_seasons_no_uq` (`season_no`),
  ADD KEY `vx_seasons_window_ix` (`starts_at`,`ends_at`),
  ADD KEY `vx_seasons_locked_ix` (`is_locked`);

--
-- Indexes for table `vx_season_caps`
--
ALTER TABLE `vx_season_caps`
  ADD PRIMARY KEY (`season_id`,`tarif_id`),
  ADD KEY `vx_caps_tarif_ix` (`tarif_id`),
  ADD KEY `idx_vx_season_caps_season_id` (`season_id`),
  ADD KEY `idx_vx_season_caps_tarif_id` (`tarif_id`);

--
-- Indexes for table `vx_season_passes`
--
ALTER TABLE `vx_season_passes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_uid_season` (`uid`,`season_id`),
  ADD KEY `ix_season` (`season_id`),
  ADD KEY `ix_uid` (`uid`);

--
-- Indexes for table `vx_season_points`
--
ALTER TABLE `vx_season_points`
  ADD PRIMARY KEY (`uid`,`season_id`),
  ADD KEY `ix_season_vp` (`season_id`,`vp_total`),
  ADD KEY `ix_season_lp` (`season_id`,`lp_total`);

--
-- Indexes for table `vx_share_events`
--
ALTER TABLE `vx_share_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_event` (`event`,`ctx`,`created_at`),
  ADD KEY `ix_ref` (`ref_code`,`created_at`),
  ADD KEY `ix_uid` (`uid`,`created_at`);

--
-- Indexes for table `vx_user_boosts`
--
ALTER TABLE `vx_user_boosts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vx_user_boost_uq` (`uid`,`boost_key`),
  ADD KEY `vx_user_boost_uid_ix` (`uid`),
  ADD KEY `vx_user_boost_until_ix` (`active_until`);

--
-- Indexes for table `vx_user_metrics`
--
ALTER TABLE `vx_user_metrics`
  ADD PRIMARY KEY (`uid`,`season_id`);

--
-- Indexes for table `vx_yield_ledger`
--
ALTER TABLE `vx_yield_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_uid_created` (`uid`,`created_at`),
  ADD KEY `ix_guardian_created` (`guardian_id`,`created_at`);

--
-- Indexes for table `xp_history`
--
ALTER TABLE `xp_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_xp_user` (`user_id`),
  ADD KEY `idx_xp_time` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_logs`
--
ALTER TABLE `admin_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_bonus`
--
ALTER TABLE `db_bonus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_bonus2`
--
ALTER TABLE `db_bonus2`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_bonus_tg`
--
ALTER TABLE `db_bonus_tg`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_conf`
--
ALTER TABLE `db_conf`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `db_contest_ref`
--
ALTER TABLE `db_contest_ref`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_contest_ref_u`
--
ALTER TABLE `db_contest_ref_u`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_insert`
--
ALTER TABLE `db_insert`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_liders`
--
ALTER TABLE `db_liders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_loto_wins`
--
ALTER TABLE `db_loto_wins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_news`
--
ALTER TABLE `db_news`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `db_payout`
--
ALTER TABLE `db_payout`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_paysystem`
--
ALTER TABLE `db_paysystem`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=526;

--
-- AUTO_INCREMENT for table `db_percent`
--
ALTER TABLE `db_percent`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `db_points_ledger`
--
ALTER TABLE `db_points_ledger`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `db_points_log`
--
ALTER TABLE `db_points_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_purse`
--
ALTER TABLE `db_purse`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_ref_earn`
--
ALTER TABLE `db_ref_earn`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_reviews`
--
ALTER TABLE `db_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_stats`
--
ALTER TABLE `db_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12001;

--
-- AUTO_INCREMENT for table `db_store`
--
ALTER TABLE `db_store`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `db_surf`
--
ALTER TABLE `db_surf`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_surf_config`
--
ALTER TABLE `db_surf_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `db_surf_views`
--
ALTER TABLE `db_surf_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `db_tg_sessions`
--
ALTER TABLE `db_tg_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=276;

--
-- AUTO_INCREMENT for table `db_uips`
--
ALTER TABLE `db_uips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `db_users`
--
ALTER TABLE `db_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `db_user_points`
--
ALTER TABLE `db_user_points`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events_log`
--
ALTER TABLE `events_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `leaderboard_cache`
--
ALTER TABLE `leaderboard_cache`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `league_assignments`
--
ALTER TABLE `league_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `league_history`
--
ALTER TABLE `league_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications_log`
--
ALTER TABLE `notifications_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `pool_ledger`
--
ALTER TABLE `pool_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `referral_share_events`
--
ALTER TABLE `referral_share_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reward_ledger`
--
ALTER TABLE `reward_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_boosts`
--
ALTER TABLE `user_boosts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_guardians`
--
ALTER TABLE `user_guardians`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_meta`
--
ALTER TABLE `user_meta`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=374;

--
-- AUTO_INCREMENT for table `vault_definitions`
--
ALTER TABLE `vault_definitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10023;

--
-- AUTO_INCREMENT for table `vault_shop_rotation`
--
ALTER TABLE `vault_shop_rotation`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_activity_log`
--
ALTER TABLE `vx_activity_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vx_admin_actions`
--
ALTER TABLE `vx_admin_actions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_guardian_catalog`
--
ALTER TABLE `vx_guardian_catalog`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_guardian_chains`
--
ALTER TABLE `vx_guardian_chains`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vx_idempotency`
--
ALTER TABLE `vx_idempotency`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `vx_lp_ledger`
--
ALTER TABLE `vx_lp_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_ref_earnings`
--
ALTER TABLE `vx_ref_earnings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_ref_qualifications`
--
ALTER TABLE `vx_ref_qualifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_seasons`
--
ALTER TABLE `vx_seasons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `vx_season_passes`
--
ALTER TABLE `vx_season_passes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_share_events`
--
ALTER TABLE `vx_share_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vx_user_boosts`
--
ALTER TABLE `vx_user_boosts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vx_yield_ledger`
--
ALTER TABLE `vx_yield_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `xp_history`
--
ALTER TABLE `xp_history`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `vx_leaderboard_source`
--
DROP TABLE IF EXISTS `vx_leaderboard_source`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vx_leaderboard_source`  AS SELECT `u`.`id` AS `user_id`, `u`.`telegram_id` AS `telegram_id`, `u`.`username` AS `username`, `up`.`points` AS `points`, `up`.`updated_at` AS `updated_at` FROM (`db_users` `u` join `db_user_points` `up` on(`up`.`uid` = `u`.`id`)) ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
