-- "Indian Gas Advanced" (2026-10-04): the Nexora API's Indane gas connection
-- lookup (/api/bharat/gas-connection-indane-paanel) - the same API crm-app-v3's
-- "Indian Gas Advance" tool calls. Per-account access flag, off by default
-- like every other tool (granted from Admin > Agents), plus its own
-- read-through cache table in the same shape as the other search_cache_*.
ALTER TABLE users
  ADD COLUMN indian_gas_api_access TINYINT(1) NOT NULL DEFAULT 0 AFTER all_gas_access;

CREATE TABLE IF NOT EXISTS `search_cache_indian_gas_api` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `search_key` CHAR(32) NOT NULL,
  `search_key_display` VARCHAR(255) NOT NULL,
  `result_json` LONGTEXT NOT NULL,
  `searched_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_search_key` (`search_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
