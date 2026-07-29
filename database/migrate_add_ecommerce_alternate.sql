-- Adds an alternate/secondary mobile number column to ecommerce_orders,
-- matching the customers_<state> tables' existing `alternative_no`
-- convention (CHAR(15), indexed) rather than inventing a new naming/type.
ALTER TABLE `ecommerce_orders`
  ADD COLUMN `alternative_no` CHAR(15) NOT NULL DEFAULT '' AFTER `mobile_no`,
  ADD KEY `idx_alt_no` (`alternative_no`);
