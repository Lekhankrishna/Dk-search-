-- Per-tool access list for Locate Me (Admin > Agents > "Locate Me" ->
-- expandable tool checklist), on top of the existing locate_me_access
-- on/off flag. Stores a JSON array of allowed tool slugs (e.g.
-- '["mobile-info","upi-finder"]'), matching includes/locateme_tools.php's
-- registry keys. RC Print and HP Gas Advanced are NOT covered by this list
-- - they keep their own separate rc_print_access/hp_gas_access flags (see
-- includes/locateme_tools.php's 'requiresAccess' comment).
--
-- NULL (the default, including every existing row) means "never explicitly
-- configured" - back-compat for the accounts that already had
-- locate_me_access granted before this column existed (Lekhan, Dhanushkodi
-- as of 2026-08-17): they keep full access to every tool rather than
-- silently losing it. A saved-but-empty JSON array ('[]') is a DIFFERENT,
-- explicit state meaning "no tools selected" - only reachable by an admin
-- actually unchecking every box in the new checklist and saving.
ALTER TABLE `users`
  ADD COLUMN `locate_me_tools` TEXT NULL DEFAULT NULL AFTER `locate_me_monthly_limit`;
