-- One-time catch-up for THIS database after merging in the
-- lookup-tracing-tool fork (2026-08-19). Not part of the normal migration
-- sequence a fresh install would replay - this DB already has
-- pan_india_access, rc_print_access, max_concurrent_sessions, and
-- user_sessions from earlier work done independently of that fork's own
-- migrate_add_rc_print_access.sql / migrate_add_max_concurrent_sessions.sql
-- / etc, so those files' net effect is applied directly here instead of
-- replaying their exact historical steps (e.g. RC Print's limit went
-- daily -> renamed monthly on that fork's DB before it ever shipped with
-- real usage; this DB never had the daily version, so it's added as
-- monthly directly).
ALTER TABLE `users`
  ADD COLUMN `rc_print_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `rc_print_access`;

ALTER TABLE `users`
  ADD COLUMN `hp_gas_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rc_print_monthly_limit`,
  ADD COLUMN `hp_gas_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `hp_gas_access`;

ALTER TABLE `users`
  ADD COLUMN `eagle_eye_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hp_gas_monthly_limit`,
  ADD COLUMN `eagle_eye_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `eagle_eye_access`;

ALTER TABLE `users`
  ADD COLUMN `pan_india_pro_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `eagle_eye_monthly_limit`,
  ADD COLUMN `pan_india_pro_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `pan_india_pro_access`;

ALTER TABLE `users`
  ADD COLUMN `advanced_search_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pan_india_pro_monthly_limit`,
  ADD COLUMN `advanced_search_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `advanced_search_access`;

-- last_seen_at didn't exist on this DB's user_sessions table (it was
-- created before the LRU-eviction design was adopted from the merge) -
-- backfilled from created_at for any already-active session rather than
-- left at CURRENT_TIMESTAMP, so an existing session isn't unfairly treated
-- as "just used" (and therefore protected from eviction) the moment this
-- migration runs.
ALTER TABLE `user_sessions`
  ADD COLUMN `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_at`;
UPDATE `user_sessions` SET `last_seen_at` = `created_at`;
ALTER TABLE `user_sessions`
  DROP INDEX `idx_user_id`,
  ADD KEY `idx_user_id` (`user_id`, `last_seen_at`);
