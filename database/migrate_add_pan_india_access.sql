-- Adds a per-account permission flag gating visibility of and direct access
-- to PAN India search / pan_india.php, mirroring lpg_search_access exactly
-- (migrate_add_lpg_access.sql). Defaults to 0 (no access) for every
-- existing and new account - opt-in only, granted per agent from
-- Admin > Agents > "PAN India Access".
ALTER TABLE `users`
  ADD COLUMN `pan_india_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `whatsapp_button_access`;
