-- Tracks real locateme.services credit cost per Locate Me search, so the
-- monthly quota (users.locate_me_monthly_limit) can be enforced as an
-- actual credit budget instead of a flat search count - a cheap SMS Header
-- Decode search (1 credit) and an expensive Mobile Info search (100
-- credits) used to count identically against the same "N searches/month"
-- cap, which didn't reflect what was actually being spent on the shared
-- locateme.services account.
--
-- NULL for every existing row (nothing before this retroactively gets a
-- credit value - only future Locate Me searches populate it). Other
-- search_types (customer-data lookups, LPG, etc.) never set this at all,
-- since they aren't locateme.services-credit-metered.
ALTER TABLE `search_logs`
  ADD COLUMN `credits_spent` SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `result_count`;
