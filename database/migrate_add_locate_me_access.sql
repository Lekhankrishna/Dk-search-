-- Adds a per-account permission flag gating visibility of and direct access
-- to the Locate Me menu item / locate_me.php, mirroring lpg_search_access /
-- pan_india_access exactly (migrate_add_lpg_access.sql). Defaults to 0 (no
-- access) for every existing and new account - opt-in only, granted per
-- agent from Admin > Agents > "Locate Me Access". No monthly-limit column,
-- same as LPG Search/Pan India - locate_me.php just embeds locateme.services'
-- own dashboard in an iframe, it doesn't spend per-search credits like
-- RC Print/HP Gas/Tata Play/Advance Pan India/Night Out/Advanced Search do.
ALTER TABLE `users`
  ADD COLUMN `locate_me_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `whatsapp_button_access`;
