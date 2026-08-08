-- Lets an admin allow a single account to be signed in from more than one
-- device at once (Admin > Agents > "Max Simultaneous Logins", next to the
-- Pan India Access checkbox). Previously `users.session_token` held exactly
-- one active token - a fresh login always overwrote it, silently signing
-- out whichever device was already using it. That's now the DEFAULT
-- behaviour (max_concurrent_sessions = 1, same as every account already
-- had) rather than the only possible one: raise the number for an account
-- and it gets that many concurrent session slots instead, oldest evicted
-- first once full (same "new login always wins" spirit, just extended
-- past one slot).
ALTER TABLE `users`
  ADD COLUMN `max_concurrent_sessions` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `pan_india_access`;

-- Replaces the single `users.session_token` column as the source of truth
-- for "is this session currently valid" - one row per active device per
-- account instead of one token total. `users.session_token` itself is left
-- in place (unused by the app after this migration) rather than dropped,
-- so this migration stays reversible without a data-loss risk.
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED  NOT NULL,
  `session_token` CHAR(64)      NOT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_token` (`session_token`),
  KEY `idx_user_id` (`user_id`, `last_seen_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per currently-active login session, up to users.max_concurrent_sessions per account.';
