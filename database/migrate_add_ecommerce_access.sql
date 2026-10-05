-- E Commerce access (2026-10-04, per explicit instruction): a per-account
-- flag in Admin > Agents like the other apps. Existing accounts default to
-- granted so nobody loses the access they already had; new agents get it
-- only when the box is ticked.
ALTER TABLE users
  ADD COLUMN ecommerce_access TINYINT(1) NOT NULL DEFAULT 1 AFTER lpg_search_access;
