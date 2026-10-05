-- Promotes "Indane Gas Info" out of the generic Tracing 2.0 per-tool
-- checklist into its own dedicated Feature Access checkbox + count-based
-- monthly quota (2026-08-18) - same pattern as rc_print_access/hp_gas_access
-- (migrate_add_rc_print_access.sql / hp_gas's own migration): its own
-- access flag, its own "N searches/month" limit, independent of the
-- shared tracing2_monthly_limit CREDIT budget every other tool draws from.
ALTER TABLE users
  ADD COLUMN indane_gas_access TINYINT(1) NOT NULL DEFAULT 0 AFTER hp_gas_monthly_limit,
  ADD COLUMN indane_gas_monthly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER indane_gas_access;

-- Preserve existing effective access: any agent who already had
-- tracing2_access=1 could already reach Indane Gas Info via the generic
-- picker/checklist (admins bypass the per-tool checklist entirely once
-- tracing2_access is on - see hasTracing2ToolAccess()), so this migration
-- shouldn't silently take that away.
UPDATE users SET indane_gas_access = 1 WHERE tracing2_access = 1;
