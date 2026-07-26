-- Stores the single shared SDMS/LPG portal login (AES-256-GCM encrypted,
-- key in config/secrets.php, never in the DB) that authorized agents'
-- browsers fetch at bookmarklet-click time to autofill IndianOil's login
-- form. One row only (id is always 1).
CREATE TABLE IF NOT EXISTS `lpg_credentials` (
  `id`               TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  `sdms_username`    VARCHAR(191)     NOT NULL,
  `password_ciphertext` VARBINARY(512) NOT NULL,
  `password_iv`      VARBINARY(16)    NOT NULL,
  `password_tag`     VARBINARY(16)    NOT NULL,
  `updated_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`       INT UNSIGNED     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A long random per-agent key embedded in that agent's bookmarklet — NOT the
-- SDMS password. Identifies + authorizes the autofill request; independent
-- of the shared SDMS password, so leaking one agent's key only requires
-- regenerating that agent's key (Admin > Agents), not rotating the shared
-- SDMS login for everyone. NULL until the agent is first granted LPG access.
ALTER TABLE `users`
  ADD COLUMN `lpg_bookmarklet_key` CHAR(64) NULL DEFAULT NULL AFTER `lpg_search_access`,
  ADD UNIQUE KEY `idx_lpg_bookmarklet_key` (`lpg_bookmarklet_key`);
