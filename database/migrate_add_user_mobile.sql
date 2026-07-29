-- Adds mobile_no to users (agents/admins), replacing email in the Admin > Agents UI.
ALTER TABLE `users`
  ADD COLUMN `mobile_no` VARCHAR(15) NULL DEFAULT NULL AFTER `full_name`;
