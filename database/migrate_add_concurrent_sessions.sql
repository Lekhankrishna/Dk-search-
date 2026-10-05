-- Replaces the single users.session_token column (one login slot per
-- account, enforced by overwriting it - any older session immediately
-- mismatches and gets signed out) with a proper multi-session table, so an
-- admin can allow a shared account to be logged into several systems at
-- once. max_concurrent_sessions defaults to 1 for every existing and new
-- account, matching the exact old behavior until an admin raises it.
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `session_token` CHAR(64)     NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_token` (`session_token`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `max_concurrent_sessions` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `pan_india_access`;

-- session_token is fully superseded by user_sessions above - nothing else
-- reads it after this migration (includes/auth.php, login.php, logout.php,
-- admin/agents.php all switch over in the same change).
ALTER TABLE `users` DROP COLUMN `session_token`;
