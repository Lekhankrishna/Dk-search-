-- "Mobile to Delivery Address Advanced" (2026-10-04): the Nexora API's mobile -> PAN prefill lookup
-- (/api/bharat/mobile-advance) - crm-app-v3's "Mobile to PAN Prefill". Per-
-- account access flag (off by default, granted from Admin > Agents), a
-- monthly limit of found searches (50 to start, admins never limited), and
-- its own read-through cache table.
ALTER TABLE users
  ADD COLUMN mobile_address_adv_access TINYINT(1) NOT NULL DEFAULT 0 AFTER mobile_to_address_monthly_limit,
  ADD COLUMN mobile_address_adv_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER mobile_address_adv_access;

CREATE TABLE IF NOT EXISTS `search_cache_mobile_address_adv` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `search_key` CHAR(32) NOT NULL,
  `search_key_display` VARCHAR(255) NOT NULL,
  `result_json` LONGTEXT NOT NULL,
  `searched_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_search_key` (`search_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
