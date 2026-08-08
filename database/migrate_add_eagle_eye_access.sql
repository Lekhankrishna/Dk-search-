-- Adds a per-account permission flag gating visibility of and direct access
-- to the "Advance Pan India" menu item / eagle_eye.php / eagle_eye_api.php
-- (backed by theeagleeye.biz's Advanced Search tool). Same pattern as
-- migrate_add_rc_print_access.sql - DEFAULT 0, opt-in per agent from
-- Admin > Agents.
ALTER TABLE `users`
  ADD COLUMN `eagle_eye_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hp_gas_monthly_limit`;
