-- Caps how many RC Print searches each agent can run per day - each search
-- spends real credits on the single shared locateme.services account (see
-- Gas/lpg_web/rc_print.py), so an admin needs a way to stop one agent from
-- burning through the whole account's budget alone. Default 5/day is a
-- starting point, editable per agent from Admin > Agents; admins themselves
-- bypass this entirely (see rc_print_api.php), same as LPG's admin-vs-agent
-- number-cap split.
--
-- Superseded by migrate_rename_rc_print_limit_to_monthly.sql (renamed to a
-- per-month limit before this ever shipped with real usage) - kept as-is
-- rather than edited in place so this file still matches what actually ran.
ALTER TABLE `users`
  ADD COLUMN `rc_print_daily_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `rc_print_access`;
