-- Adds a per-account permission flag gating visibility of and direct access
-- to the RC Print menu item / rc_print.php / rc_print_api.php. Same pattern
-- as migrate_add_lpg_access.sql - DEFAULT 0, opt-in per agent from
-- Admin > Agents, since this is a brand-new gated feature.
ALTER TABLE `users`
  ADD COLUMN `rc_print_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lpg_search_access`;
