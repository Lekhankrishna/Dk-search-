-- =============================================================================
--  CRM Database Schema  ·  MySQL 8.0
--  Target: 100 million+ customer records with sub-second multi-mode search
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `crm_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `crm_db`;

-- =============================================================================
-- 1.  LOOKUP TABLES  (tiny — always resident in InnoDB buffer pool)
-- =============================================================================

-- ── Pincode reference (Indian 6-digit pincodes) ───────────────────────────
CREATE TABLE IF NOT EXISTS `pincodes` (
  `pincode`   CHAR(6)      NOT NULL,
  `city`      VARCHAR(100) NOT NULL DEFAULT '',
  `district`  VARCHAR(100) NOT NULL DEFAULT '',
  `state`     VARCHAR(100) NOT NULL DEFAULT '',
  `country`   VARCHAR(50)  NOT NULL DEFAULT 'India',
  PRIMARY KEY (`pincode`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Pincode ↔ city/state mapping. Populate separately.';

-- =============================================================================
-- 2.  USERS  (agents + admins — expected < 1 000 rows)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED                  NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)                   NOT NULL,
  `password_hash` VARCHAR(255)                  NOT NULL,
  `full_name`     VARCHAR(128)                  NOT NULL,
  `role`          ENUM('admin','agent')         NOT NULL DEFAULT 'agent',
  `is_active`     TINYINT(1)                    NOT NULL DEFAULT 1,
  `expires_at`    DATETIME                      NULL     DEFAULT NULL,
  `last_login_at` DATETIME                      NULL     DEFAULT NULL,
  `created_at`    DATETIME                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME                      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='CRM portal users (agents and admins).';

-- =============================================================================
-- 3.  SEARCH LOGS  (append-only audit trail)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `search_logs` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED     NOT NULL,
  `search_type`  VARCHAR(32)      NOT NULL,
  `search_query` VARCHAR(512)     NOT NULL,
  `result_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `ip_address`   VARCHAR(45)      NOT NULL DEFAULT '' COMMENT 'IPv4 or IPv6',
  `searched_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_time`  (`user_id`, `searched_at`),
  KEY `idx_searched_at`(`searched_at`),
  KEY `idx_user_type`  (`user_id`, `search_type`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable audit trail of every search action.';

-- =============================================================================
-- 4.  CUSTOMERS  ─ Main table — designed for 100 M+ rows
-- =============================================================================
--
--  WHY NOT PARTITIONED?
--  MySQL 8.0 InnoDB does not support FULLTEXT indexes on partitioned tables.
--  We need FULLTEXT for address keyword search, so we keep the table intact
--  and let InnoDB's B-tree indexes + buffer pool carry the load.
--
--  If your deployment is purely exact-lookup (mobile / identity) with no
--  LIKE-anywhere address search, you can add:
--      PARTITION BY HASH(`id`) PARTITIONS 32
--  and remove the FULLTEXT keys.
--
--  PERFORMANCE CHECKLIST (apply in my.ini / my.cnf):
--    innodb_buffer_pool_size        = 70-80 % of total RAM
--    innodb_buffer_pool_instances   = 8          (one per ~1.5 GB)
--    innodb_io_capacity             = 4000       (SSD) or 400 (HDD)
--    innodb_io_capacity_max         = 8000
--    innodb_log_file_size           = 1G
--    innodb_flush_log_at_trx_commit = 2          (faster imports, minimal risk)
--    ft_min_word_len                = 2          (restart required)
--
--  After bulk import, always run:
--    ANALYZE TABLE customers;
-- =============================================================================

CREATE TABLE IF NOT EXISTS `customers` (

  -- ── Primary key ────────────────────────────────────────────────────────
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT
                                       COMMENT 'Internal surrogate — BIGINT fits 9.2 × 10^18 rows',
  `customer_code`     VARCHAR(32)      NOT NULL DEFAULT ''
                                       COMMENT 'Stable import / upsert key (e.g. CUST0001)',

  -- ── Personal identity ──────────────────────────────────────────────────
  `name`              VARCHAR(128)     NOT NULL DEFAULT ''
                                       COMMENT 'Full / common name',
  `father_name`       VARCHAR(128)     NOT NULL DEFAULT ''
                                       COMMENT 'Father''s / guardian name',
  `gender`            VARCHAR(10)      NOT NULL DEFAULT ''
                                       COMMENT 'M / F / O (Male / Female / Other)',
  `dob`               DATE             NULL     DEFAULT NULL
                                       COMMENT 'Date of birth',

  -- ── Contact ───────────────────────────────────────────────────────────
  `mobile_no`         CHAR(15)         NOT NULL DEFAULT ''
                                       COMMENT 'Primary mobile — CHAR for fixed-width index efficiency',
  `alternative_no`    CHAR(15)         NOT NULL DEFAULT ''
                                       COMMENT 'Alternate / secondary mobile',
  `email`             VARCHAR(191)     NOT NULL DEFAULT ''
                                       COMMENT '191 = safe utf8mb4 prefix for unique key if added later',

  -- ── Address ───────────────────────────────────────────────────────────
  `address`           VARCHAR(512)     NOT NULL DEFAULT ''
                                       COMMENT 'Current / residential address',
  `permanent_address` VARCHAR(512)     NOT NULL DEFAULT ''
                                       COMMENT 'Permanent / native address',
  `pincode`           CHAR(6)          NOT NULL DEFAULT ''
                                       COMMENT 'Indian 6-digit pincode',

  -- ── Identity document ─────────────────────────────────────────────────
  `identity_no`       VARCHAR(64)      NOT NULL DEFAULT ''
                                       COMMENT 'PAN / Aadhaar / DL / Voter ID / Passport number',
  `identity_type`     VARCHAR(16)      NOT NULL DEFAULT ''
                                       COMMENT 'AADHAAR / PAN / DL / VOTERID / PASSPORT',

  -- ── State ─────────────────────────────────────────────────────────────
  `state`             VARCHAR(32)      NOT NULL DEFAULT ''
                                       COMMENT 'Karnataka / Tamil Nadu / Kerala / Andhra Pradesh / etc.',

  -- ── Extended telecom fields ───────────────────────────────────────────
  `circle`            VARCHAR(64)      NOT NULL DEFAULT ''
                                       COMMENT 'Telecom circle / region',
  `merge_mob`         CHAR(15)         NOT NULL DEFAULT ''
                                       COMMENT 'Linked or merged mobile number',

  -- ── Timestamps ────────────────────────────────────────────────────────
  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,

  -- ── Primary key ────────────────────────────────────────────────────────
  PRIMARY KEY (`id`),

  -- ── Exact-match B-tree indexes ─────────────────────────────────────────
  --   mobile search  — most frequent query, O(log n) with this index
  KEY `idx_mobile_no`       (`mobile_no`),
  KEY `idx_alt_no`          (`alternative_no`),
  KEY `idx_merge_mob`       (`merge_mob`),

  --   identity document exact lookup
  KEY `idx_identity`        (`identity_no`, `identity_type`),

  --   email prefix  (191-char field; 64-char prefix covers typical addresses)
  KEY `idx_email`           (`email`(64)),

  --   pincode — used in area-based and combined lookups
  KEY `idx_pincode`         (`pincode`),

  --   customer_code — fast upsert and admin lookup
  KEY `idx_customer_code`   (`customer_code`),

  --   dob — standalone date search
  KEY `idx_dob`             (`dob`),

  -- ── Prefix indexes ─────────────────────────────────────────────────────
  --   name prefix scan  (LIKE 'Raj%')
  KEY `idx_name`            (`name`(32)),

  -- ── Composite indexes — one per combined search mode ───────────────────
  --   search: name_father  →  name LIKE 'X%' AND father_name LIKE 'Y%'
  KEY `idx_name_father`     (`name`(32), `father_name`(32)),

  --   search: name_dob     →  name LIKE 'X%' AND dob = '...'
  KEY `idx_name_dob`        (`name`(32), `dob`),

  --   search: name + pincode (quick area filter before FULLTEXT)
  KEY `idx_name_pincode`    (`name`(32), `pincode`),

  -- ── FULLTEXT indexes — power LIKE '%keyword%' without full table scans ─
  --   Address keyword search (current + permanent)
  FULLTEXT KEY `ft_address` (`address`, `permanent_address`),

  --   Name / father keyword search (supports partial-word search via IN BOOLEAN MODE)
  FULLTEXT KEY `ft_name`    (`name`, `father_name`),

  -- ── State filter index ─────────────────────────────────────────────────
  KEY `idx_state`           (`state`),
  KEY `idx_state_mobile`    (`state`, `mobile_no`),
  KEY `idx_state_name`      (`state`, `name`(32)),
  KEY `idx_state_identity`  (`state`, `identity_no`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC
  COMMENT='Customer master table. BIGINT PK supports 9 × 10^18 rows.';

-- =============================================================================
-- 5.  SEED DATA
-- =============================================================================

-- Default admin (password = "password" — CHANGE IMMEDIATELY AFTER FIRST LOGIN)
INSERT IGNORE INTO `users` (`username`, `password_hash`, `full_name`, `role`)
VALUES (
  'admin',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'Administrator',
  'admin'
);

-- =============================================================================
-- 6.  RECOMMENDED QUERY PATTERNS  (reference for developers)
-- =============================================================================
--
-- ① Mobile exact lookup  (uses idx_mobile_no)
--      SELECT ... FROM customers WHERE mobile_no = '9876543210' LIMIT 50;
--
-- ② Identity exact lookup  (uses idx_identity)
--      SELECT ... FROM customers WHERE identity_no = 'ABCDE1234F' LIMIT 50;
--
-- ③ Name prefix  (uses idx_name)
--      SELECT ... FROM customers WHERE name LIKE 'Raj%' LIMIT 50;
--
-- ④ Name + Father composite  (uses idx_name_father)
--      SELECT ... FROM customers
--       WHERE name LIKE 'Raj%' AND father_name LIKE 'Mohan%' LIMIT 50;
--
-- ⑤ Name + DOB  (uses idx_name_dob)
--      SELECT ... FROM customers
--       WHERE name LIKE 'Raj%' AND dob = '1990-05-15' LIMIT 50;
--
-- ⑥ Address keyword (uses ft_address FULLTEXT — far faster than LIKE '%...%')
--      SELECT ... FROM customers
--       WHERE MATCH(address, permanent_address) AGAINST ('mumbai thane' IN BOOLEAN MODE)
--       LIMIT 50;
--
-- ⑦ Multi-mobile IN list  (uses idx_mobile_no for each value via range scan)
--      SELECT ... FROM customers
--       WHERE mobile_no IN ('9876543210','9123456789',...) LIMIT 500;
--
-- ⑧ Pincode area  (uses idx_pincode)
--      SELECT ... FROM customers WHERE pincode = '400001' LIMIT 50;
--
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 1;
