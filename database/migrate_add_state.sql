-- Run this if you already imported schema.sql and need to add the state column
USE crm_db;

ALTER TABLE customers
  ADD COLUMN `state` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'Karnataka / Tamil Nadu / Kerala / Andhra Pradesh'
      AFTER `pincode`,
  ADD KEY `idx_state`          (`state`),
  ADD KEY `idx_state_mobile`   (`state`, `mobile_no`),
  ADD KEY `idx_state_name`     (`state`, `name`(32)),
  ADD KEY `idx_state_identity` (`state`, `identity_no`);
