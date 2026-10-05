-- Adds a per-account permission flag gating visibility of and direct access
-- to the Tata Play Search menu item / tataplay.php / tataplay_api.php. Same
-- pattern as migrate_add_hp_gas_access.sql - DEFAULT 0, opt-in per agent
-- from Admin > Agents.
ALTER TABLE `users`
  ADD COLUMN `tata_play_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hp_gas_monthly_limit`;
