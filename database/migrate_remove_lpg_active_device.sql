-- Reverts migrate_add_lpg_active_device.sql - the per-agent-computer device-
-- claim mechanism it supported no longer applies now that the LPG search
-- service runs centrally on the server itself (found 2026-07-27) rather
-- than one install per agent's own computer. There's only ever one
-- computer running it now, so "which computer is currently active" is a
-- question that no longer needs an answer.
ALTER TABLE `users`
  DROP COLUMN `lpg_active_device_key`;
