-- Adds a per-account permission flag gating visibility of and direct access
-- to the Pan India Search menu item / pan_india.php / api/pan_india.php.
--
-- Unlike migrate_add_lpg_access.sql (DEFAULT 0, opt-in from day one for a
-- brand-new gated feature), this defaults to 1 — Pan India has always been
-- open to every logged-in user with no per-agent grant, so a DEFAULT 0 here
-- would silently revoke access from every existing agent the moment this
-- migration runs. DEFAULT 1 preserves current behaviour for everyone who
-- already has it; going forward, access is granted per agent from
-- Admin > Agents, same as LPG Search.
ALTER TABLE `users`
  ADD COLUMN `pan_india_access` TINYINT(1) NOT NULL DEFAULT 1 AFTER `whatsapp_button_access`;
