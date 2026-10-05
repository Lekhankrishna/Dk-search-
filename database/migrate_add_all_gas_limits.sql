-- All Gas per-provider monthly limits (2026-10-04, per explicit instruction):
-- how many found Indane / Bharat Gas / HP Gas searches an agent may run on
-- All Gas per calendar month, set in Admin > Agents. 50 each to start (the
-- same allowance HP Gas / Indane Gas were given); admins are never limited.
ALTER TABLE users
  ADD COLUMN all_gas_indane_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER all_gas_access,
  ADD COLUMN all_gas_bharat_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER all_gas_indane_monthly_limit,
  ADD COLUMN all_gas_hp_monthly_limit     SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER all_gas_bharat_monthly_limit;
