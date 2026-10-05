-- Renames every "Locate Me" trace to "Tracing 2.0" per explicit instruction
-- (2026-08-17) - column rename preserves existing data/grants (CHANGE
-- COLUMN, not drop+recreate), and existing search_logs rows get their
-- search_type relabeled too so historical audit entries read consistently
-- with the new name rather than showing "locate_me" forever.
ALTER TABLE `users`
  CHANGE COLUMN `locate_me_access` `tracing2_access` TINYINT(1) NOT NULL DEFAULT 0,
  CHANGE COLUMN `locate_me_monthly_limit` `tracing2_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 1000,
  CHANGE COLUMN `locate_me_tools` `tracing2_tools` TEXT NULL DEFAULT NULL;

UPDATE `search_logs` SET `search_type` = 'tracing2' WHERE `search_type` = 'locate_me';
