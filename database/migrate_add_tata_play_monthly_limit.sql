-- Caps how many Tata Play searches each agent can run per calendar month -
-- each search is a real login against the distributor's own
-- mysso.tataplay.com account, same reasoning as
-- migrate_add_hp_gas_monthly_limit.sql. Default 5/month is a starting
-- point, editable per agent from Admin > Agents; admins bypass this
-- entirely (see tataplay_api.php), same admin-vs-agent split as HP Gas/RC
-- Print/LPG.
ALTER TABLE `users`
  ADD COLUMN `tata_play_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `tata_play_access`;
