-- Caps how many HP Gas Advanced searches each agent can run per calendar
-- month - each search spends real credits (150 per search, observed
-- 2026-08-08) on the single shared locateme.services account, same
-- reasoning as migrate_rename_rc_print_limit_to_monthly.sql. Default 5/month
-- is a starting point, editable per agent from Admin > Agents; admins
-- bypass this entirely (see hp_gas_api.php), same admin-vs-agent split as
-- RC Print and LPG.
ALTER TABLE `users`
  ADD COLUMN `hp_gas_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `hp_gas_access`;
