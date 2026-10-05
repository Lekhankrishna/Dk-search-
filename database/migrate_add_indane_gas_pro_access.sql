ALTER TABLE users
  ADD COLUMN indane_gas_pro_access TINYINT(1) NOT NULL DEFAULT 0 AFTER indane_gas_monthly_limit,
  ADD COLUMN indane_gas_pro_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER indane_gas_pro_access;
