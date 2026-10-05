-- Monthly limits for the All Gas Advanced API tabs (2026-10-04, per explicit
-- instruction): how many found Indian Gas Advanced / HP Gas Advanced /
-- Bharat Gas Advanced searches an agent may run per calendar month, set in
-- Admin > Agents. 50 each to start; admins are never limited.
ALTER TABLE users
  ADD COLUMN indian_gas_api_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER indian_gas_api_access,
  ADD COLUMN hp_gas_api_monthly_limit     SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER hp_gas_api_access,
  ADD COLUMN bharat_gas_api_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER bharat_gas_api_access;
