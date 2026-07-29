-- =============================================================================
--  E-Commerce delivery records — separate shape from the state customer
--  tables (no father's name/DOB/gender/identity — has delivery_date and
--  coordinates instead), so it gets its own table rather than being forced
--  into the customers_<state> pattern.
-- =============================================================================

USE `crm_db`;

CREATE TABLE IF NOT EXISTS `ecommerce_orders` (

  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `name`           VARCHAR(128)    NOT NULL DEFAULT '',
  `mobile_no`      CHAR(15)        NOT NULL DEFAULT '',
  `alternative_no` CHAR(15)        NOT NULL DEFAULT '',
  `address`        VARCHAR(512)    NOT NULL DEFAULT '',
  `delivery_date`  DATE            NULL     DEFAULT NULL,
  `latitude`       DECIMAL(10,7)   NULL     DEFAULT NULL,
  `longitude`      DECIMAL(10,7)   NULL     DEFAULT NULL,

  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  KEY `idx_mobile`        (`mobile_no`),
  KEY `idx_alt_no`        (`alternative_no`),
  KEY `idx_name`          (`name`(32)),
  KEY `idx_delivery_date` (`delivery_date`),

  FULLTEXT KEY `ft_address` (`address`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC
  COMMENT='E-Commerce delivery records';
