-- Caps how many "Advance Pan India" (theeagleeye.biz) searches each agent
-- can run per calendar month. Unlike locateme.services (RC Print/HP Gas,
-- billed per search), theeagleeye.biz uses a single shared monthly plan
-- pool for the whole account (observed 2026-08-08: "930 / 1000 searches"
-- remaining in its own navbar) - this per-agent cap exists so one agent
-- can't burn through the whole account's monthly plan alone. Default
-- 5/month is a starting point, editable per agent from Admin > Agents;
-- admins bypass this entirely (see eagle_eye_api.php).
ALTER TABLE `users`
  ADD COLUMN `eagle_eye_monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `eagle_eye_access`;
