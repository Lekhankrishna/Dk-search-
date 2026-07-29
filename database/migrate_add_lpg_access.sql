-- Adds a per-account permission flag gating visibility of and direct access
-- to the LPG Search menu item / lpg_search.php. Defaults to 0 (no access)
-- for every existing and new account — opt-in only, granted per agent from
-- Admin > Agents.
ALTER TABLE `users`
  ADD COLUMN `lpg_search_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;
