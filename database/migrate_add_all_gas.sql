-- "All Gas" (tracekart.in Skip Trace "Gas Connection", 2026-10-03): per-
-- account access flag, off by default like every other tool (granted from
-- Admin > Agents), plus its own read-through cache table in the same shape
-- as migrate_add_search_cache_tables.sql's.
ALTER TABLE users
  ADD COLUMN all_gas_access TINYINT(1) NOT NULL DEFAULT 0 AFTER advanced_search_monthly_limit;

CREATE TABLE IF NOT EXISTS `search_cache_all_gas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `search_key` CHAR(32) NOT NULL,
  `search_key_display` VARCHAR(255) NOT NULL,
  `result_json` LONGTEXT NOT NULL,
  `searched_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_search_key` (`search_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
