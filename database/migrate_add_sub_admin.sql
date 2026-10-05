-- Sub-admin role (Admin > Agents only, scoped to agents they personally
-- created) - a restricted subset of admin, not a new access tier for the
-- search tools themselves (those stay gated by the existing per-tool
-- access columns/isSubAdmin() is NOT special-cased into any hasXAccess()
-- bypass - see includes/auth.php).
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'sub_admin', 'agent') NOT NULL DEFAULT 'agent';

-- Tracks which account created each user - NULL for accounts created
-- before this feature, or created directly by a real admin (both
-- treated the same: "no sub-admin owns this account"). A sub-admin's own
-- "Admin > Agents" view is filtered to WHERE created_by = their own id.
ALTER TABLE users ADD COLUMN created_by INT UNSIGNED NULL AFTER role;
