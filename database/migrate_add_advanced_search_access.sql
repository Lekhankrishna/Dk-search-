-- Adds a per-account permission flag gating visibility of and direct access
-- to the "Advanced Search" menu item / advanced_search.php /
-- advanced_search_api.php (backed by tracekart.in). Same pattern as
-- migrate_add_rc_print_access.sql - DEFAULT 0, opt-in per agent from
-- Admin > Agents.
ALTER TABLE `users`
  ADD COLUMN `advanced_search_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pan_india_pro_monthly_limit`;

-- Caps how many Advanced Search searches each agent can run per calendar
-- month - single shared account on tracekart.in's side (own daily/IP
-- limits observed on the vendor's own login page), same reasoning as
-- migrate_rename_rc_print_limit_to_monthly.sql. Default 5/month, editable
-- per agent from Admin > Agents; admins bypass this entirely, same
-- admin-vs-agent split as every other tool here.
ALTER TABLE `users`
  ADD COLUMN `advanced_search_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `advanced_search_access`;
