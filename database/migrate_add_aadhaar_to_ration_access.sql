ALTER TABLE users
  ADD COLUMN aadhaar_to_ration_access TINYINT(1) NOT NULL DEFAULT 0 AFTER tata_play_monthly_limit,
  ADD COLUMN aadhaar_to_ration_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER aadhaar_to_ration_access;
