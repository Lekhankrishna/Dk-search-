-- Locate Me was originally access-only (see migrate_add_locate_me_access.sql),
-- built as a raw iframe of locateme.services' own dashboard. Rebuilt
-- 2026-08-17 as a hidden server-side Selenium automation against the
-- Mobile Info tool (Gas/lpg_web/mobile_info.py), same shape as RC Print/HP
-- Gas - each search now spends real credits on the shared locateme.services
-- account, so it needs the same per-agent monthly cap those tools have.
ALTER TABLE `users`
  ADD COLUMN `locate_me_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `locate_me_access`;
