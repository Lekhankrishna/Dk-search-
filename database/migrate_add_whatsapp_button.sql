-- Adds the floating WhatsApp contact button feature: a per-account
-- visibility flag (mirrors lpg_search_access) plus a single global
-- settings row (mirrors lpg_credentials) controlling the master
-- enable/disable switch, the destination number, and the default message.
ALTER TABLE `users`
  ADD COLUMN `whatsapp_button_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lpg_bookmarklet_key`;

-- One row only (id is always 1). is_enabled is the master switch: when 0,
-- the button is hidden for everyone regardless of per-user access; when 1,
-- it is shown only to users with whatsapp_button_access = 1.
CREATE TABLE IF NOT EXISTS `whatsapp_settings` (
  `id`              TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  `is_enabled`      TINYINT(1)       NOT NULL DEFAULT 0,
  `phone_number`    VARCHAR(20)      NOT NULL DEFAULT '919901431238',
  `default_message` VARCHAR(255)     NOT NULL DEFAULT 'Hi',
  `updated_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`      INT UNSIGNED     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `whatsapp_settings` (`id`) VALUES (1);
