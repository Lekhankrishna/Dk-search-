-- =============================================================================
--  State-wise Customer Tables  ·  MySQL 8.0
--  One table per state — ~25M rows each, faster than a single 100M row table
--  Columns match the original import headers:
--    mobile, name, fname (father_name), alt, address, dob,
--    per_address, email, gender, identity_doc, pincode
-- =============================================================================

USE `crm_db`;

-- =============================================================================
-- MACRO: same structure repeated for each state
-- Columns kept identical so the same PHP code queries any table
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. KARNATAKA
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `customers_karnataka` (

  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_code`     VARCHAR(32)      NOT NULL DEFAULT '',

  -- Personal
  `name`              VARCHAR(128)     NOT NULL DEFAULT '',
  `father_name`       VARCHAR(128)     NOT NULL DEFAULT '',   -- fname
  `gender`            VARCHAR(10)      NOT NULL DEFAULT '',   -- M / F / O
  `dob`               DATE             NULL     DEFAULT NULL,

  -- Contact
  `mobile_no`         CHAR(15)         NOT NULL DEFAULT '',   -- mobile
  `alternative_no`    CHAR(15)         NOT NULL DEFAULT '',   -- alt
  `email`             VARCHAR(191)     NOT NULL DEFAULT '',

  -- Address
  `address`           VARCHAR(512)     NOT NULL DEFAULT '',
  `permanent_address` VARCHAR(512)     NOT NULL DEFAULT '',   -- per_address
  `pincode`           CHAR(6)          NOT NULL DEFAULT '',

  -- Identity
  `identity_no`       VARCHAR(64)      NOT NULL DEFAULT '',   -- identity_doc
  `identity_type`     VARCHAR(16)      NOT NULL DEFAULT '',   -- PAN/AADHAAR/DL etc.

  -- Extended
  `circle`            VARCHAR(64)      NOT NULL DEFAULT '',
  `merge_mob`         CHAR(15)         NOT NULL DEFAULT '',

  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Exact-match
  KEY `idx_mobile`        (`mobile_no`),
  KEY `idx_alt`           (`alternative_no`),
  KEY `idx_identity`      (`identity_no`, `identity_type`),
  KEY `idx_email`         (`email`(64)),
  KEY `idx_pincode`       (`pincode`),
  KEY `idx_dob`           (`dob`),
  KEY `idx_customer_code` (`customer_code`),

  -- Prefix / composite
  KEY `idx_name`          (`name`(32)),
  KEY `idx_name_father`   (`name`(32), `father_name`(32)),
  KEY `idx_name_dob`      (`name`(32), `dob`),
  KEY `idx_name_pincode`  (`name`(32), `pincode`),

  -- Full-text
  FULLTEXT KEY `ft_address` (`address`, `permanent_address`),
  FULLTEXT KEY `ft_name`    (`name`, `father_name`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC
  COMMENT='Karnataka customers — target ~25M rows';


-- ─────────────────────────────────────────────────────────────────────────────
-- 2. TAMIL NADU
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `customers_tamil_nadu` (

  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_code`     VARCHAR(32)      NOT NULL DEFAULT '',

  `name`              VARCHAR(128)     NOT NULL DEFAULT '',
  `father_name`       VARCHAR(128)     NOT NULL DEFAULT '',
  `gender`            VARCHAR(10)      NOT NULL DEFAULT '',
  `dob`               DATE             NULL     DEFAULT NULL,

  `mobile_no`         CHAR(15)         NOT NULL DEFAULT '',
  `alternative_no`    CHAR(15)         NOT NULL DEFAULT '',
  `email`             VARCHAR(191)     NOT NULL DEFAULT '',

  `address`           VARCHAR(512)     NOT NULL DEFAULT '',
  `permanent_address` VARCHAR(512)     NOT NULL DEFAULT '',
  `pincode`           CHAR(6)          NOT NULL DEFAULT '',

  `identity_no`       VARCHAR(64)      NOT NULL DEFAULT '',
  `identity_type`     VARCHAR(16)      NOT NULL DEFAULT '',

  `circle`            VARCHAR(64)      NOT NULL DEFAULT '',
  `merge_mob`         CHAR(15)         NOT NULL DEFAULT '',

  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  KEY `idx_mobile`        (`mobile_no`),
  KEY `idx_alt`           (`alternative_no`),
  KEY `idx_identity`      (`identity_no`, `identity_type`),
  KEY `idx_email`         (`email`(64)),
  KEY `idx_pincode`       (`pincode`),
  KEY `idx_dob`           (`dob`),
  KEY `idx_customer_code` (`customer_code`),

  KEY `idx_name`          (`name`(32)),
  KEY `idx_name_father`   (`name`(32), `father_name`(32)),
  KEY `idx_name_dob`      (`name`(32), `dob`),
  KEY `idx_name_pincode`  (`name`(32), `pincode`),

  FULLTEXT KEY `ft_address` (`address`, `permanent_address`),
  FULLTEXT KEY `ft_name`    (`name`, `father_name`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC
  COMMENT='Tamil Nadu customers — target ~25M rows';


-- ─────────────────────────────────────────────────────────────────────────────
-- 3. KERALA
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `customers_kerala` (

  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_code`     VARCHAR(32)      NOT NULL DEFAULT '',

  `name`              VARCHAR(128)     NOT NULL DEFAULT '',
  `father_name`       VARCHAR(128)     NOT NULL DEFAULT '',
  `gender`            VARCHAR(10)      NOT NULL DEFAULT '',
  `dob`               DATE             NULL     DEFAULT NULL,

  `mobile_no`         CHAR(15)         NOT NULL DEFAULT '',
  `alternative_no`    CHAR(15)         NOT NULL DEFAULT '',
  `email`             VARCHAR(191)     NOT NULL DEFAULT '',

  `address`           VARCHAR(512)     NOT NULL DEFAULT '',
  `permanent_address` VARCHAR(512)     NOT NULL DEFAULT '',
  `pincode`           CHAR(6)          NOT NULL DEFAULT '',

  `identity_no`       VARCHAR(64)      NOT NULL DEFAULT '',
  `identity_type`     VARCHAR(16)      NOT NULL DEFAULT '',

  `circle`            VARCHAR(64)      NOT NULL DEFAULT '',
  `merge_mob`         CHAR(15)         NOT NULL DEFAULT '',

  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  KEY `idx_mobile`        (`mobile_no`),
  KEY `idx_alt`           (`alternative_no`),
  KEY `idx_identity`      (`identity_no`, `identity_type`),
  KEY `idx_email`         (`email`(64)),
  KEY `idx_pincode`       (`pincode`),
  KEY `idx_dob`           (`dob`),
  KEY `idx_customer_code` (`customer_code`),

  KEY `idx_name`          (`name`(32)),
  KEY `idx_name_father`   (`name`(32), `father_name`(32)),
  KEY `idx_name_dob`      (`name`(32), `dob`),
  KEY `idx_name_pincode`  (`name`(32), `pincode`),

  FULLTEXT KEY `ft_address` (`address`, `permanent_address`),
  FULLTEXT KEY `ft_name`    (`name`, `father_name`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC
  COMMENT='Kerala customers — target ~25M rows';


-- ─────────────────────────────────────────────────────────────────────────────
-- 4. ANDHRA PRADESH
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `customers_andhra_pradesh` (

  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_code`     VARCHAR(32)      NOT NULL DEFAULT '',

  `name`              VARCHAR(128)     NOT NULL DEFAULT '',
  `father_name`       VARCHAR(128)     NOT NULL DEFAULT '',
  `gender`            VARCHAR(10)      NOT NULL DEFAULT '',
  `dob`               DATE             NULL     DEFAULT NULL,

  `mobile_no`         CHAR(15)         NOT NULL DEFAULT '',
  `alternative_no`    CHAR(15)         NOT NULL DEFAULT '',
  `email`             VARCHAR(191)     NOT NULL DEFAULT '',

  `address`           VARCHAR(512)     NOT NULL DEFAULT '',
  `permanent_address` VARCHAR(512)     NOT NULL DEFAULT '',
  `pincode`           CHAR(6)          NOT NULL DEFAULT '',

  `identity_no`       VARCHAR(64)      NOT NULL DEFAULT '',
  `identity_type`     VARCHAR(16)      NOT NULL DEFAULT '',

  `circle`            VARCHAR(64)      NOT NULL DEFAULT '',
  `merge_mob`         CHAR(15)         NOT NULL DEFAULT '',

  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  KEY `idx_mobile`        (`mobile_no`),
  KEY `idx_alt`           (`alternative_no`),
  KEY `idx_identity`      (`identity_no`, `identity_type`),
  KEY `idx_email`         (`email`(64)),
  KEY `idx_pincode`       (`pincode`),
  KEY `idx_dob`           (`dob`),
  KEY `idx_customer_code` (`customer_code`),

  KEY `idx_name`          (`name`(32)),
  KEY `idx_name_father`   (`name`(32), `father_name`(32)),
  KEY `idx_name_dob`      (`name`(32), `dob`),
  KEY `idx_name_pincode`  (`name`(32), `pincode`),

  FULLTEXT KEY `ft_address` (`address`, `permanent_address`),
  FULLTEXT KEY `ft_name`    (`name`, `father_name`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC
  COMMENT='Andhra Pradesh customers — target ~25M rows';


-- =============================================================================
-- UNIFIED VIEW  — query all 4 states at once (used when no state filter)
-- =============================================================================
CREATE OR REPLACE VIEW `customers_all` AS
  SELECT *, 'Karnataka'      AS state FROM `customers_karnataka`
  UNION ALL
  SELECT *, 'Tamil Nadu'     AS state FROM `customers_tamil_nadu`
  UNION ALL
  SELECT *, 'Kerala'         AS state FROM `customers_kerala`
  UNION ALL
  SELECT *, 'Andhra Pradesh' AS state FROM `customers_andhra_pradesh`;


-- =============================================================================
-- COLUMN REFERENCE  (maps original CSV headers → column names)
-- =============================================================================
-- CSV Header        → MySQL Column
-- ─────────────────────────────────
-- mobile            → mobile_no
-- name              → name
-- fname             → father_name
-- alt               → alternative_no
-- address           → address
-- dob               → dob
-- per_address       → permanent_address
-- email             → email
-- gender            → gender      (M / F / O)
-- identity_doc      → identity_no (PAN / Aadhaar / DL / Voter ID)
-- pincode           → pincode
-- =============================================================================
