-- Aadhaar to Family Advanced monthly limit (2026-10-05, per explicit
-- instruction): searches an agent may run per calendar month - every search
-- counts, found or not (see includes/nexora_client.php NEXORA_COUNT_EVERY_SEARCH).
-- 50 to start; admins are never limited.
ALTER TABLE users
  ADD COLUMN aadhaar_family_api_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER aadhaar_family_api_access;
