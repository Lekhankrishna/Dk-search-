-- Indane Gas Pro is now unlimited for every user (explicit instruction,
-- 2026-09-03) - no more per-agent monthly cap, and every existing account
-- gets access granted outright rather than needing an admin to opt them in
-- individually. New accounts still default to indane_gas_pro_access = 0
-- (admin/agents.php's create-form checkbox is unchecked by default, same as
-- every other tool) - only existing accounts are granted here.
ALTER TABLE users DROP COLUMN indane_gas_pro_monthly_limit;
UPDATE users SET indane_gas_pro_access = 1;
