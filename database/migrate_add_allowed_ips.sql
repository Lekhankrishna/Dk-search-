-- Per-account IP allow-list (Admin > Agents > "Allowed IPs") - opt-in, off
-- by default like every other per-account setting here: NULL/empty means
-- unrestricted (existing accounts keep working exactly as before). A
-- free-form, comma/newline-separated list of exact IP addresses - no CIDR
-- ranges, kept deliberately simple. Enforced both at login (login.php) and
-- on every subsequent request (includes/auth.php's requireLogin()), so a
-- restriction added while an agent is already logged in takes effect
-- immediately rather than only on their next login.
ALTER TABLE users ADD COLUMN allowed_ips TEXT NULL AFTER max_concurrent_sessions;
