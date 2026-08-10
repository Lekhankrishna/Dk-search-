-- RC Print's usage cap turned out to need a per-month reset, not per-day
-- (found 2026-08-08, before this ever shipped with real usage) - CHANGE
-- rather than a separate add+drop so any already-set per-agent value
-- carries over unchanged, just reinterpreted against a calendar month
-- instead of a calendar day (see rc_print_api.php's limit check).
ALTER TABLE `users`
  CHANGE COLUMN `rc_print_daily_limit` `rc_print_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5;
