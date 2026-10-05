-- Adds a per-account permission flag gating visibility of and direct access
-- to the HP Gas Advanced menu item / hp_gas.php / hp_gas_api.php. Same
-- pattern as migrate_add_rc_print_access.sql - DEFAULT 0, opt-in per agent
-- from Admin > Agents.
ALTER TABLE `users`
  ADD COLUMN `hp_gas_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rc_print_monthly_limit`;
