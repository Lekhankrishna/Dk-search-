-- Tracks which computer (by lpg_bookmarklet_key) the account is currently
-- using the LPG tool from. NULL until the tool page is first loaded
-- anywhere - see lpg_verify_user.php's claim=1 handling and app.py's
-- claim_device() route/comments for the full flow.
ALTER TABLE `users`
  ADD COLUMN `lpg_active_device_key` CHAR(64) NULL DEFAULT NULL AFTER `lpg_bookmarklet_key`;
