-- ---------------------------------------------------------------------------
-- 014_menu_package_pricing
--
-- Not every menu is priced per guest. A package is now priced one of three ways:
--   guest — a rate per guest (Wedding, Mehndi, Elite menus)
--   box   — a rate per box (Iftar boxes); the booking's guest count is the number of boxes
--   group — one price for a group ("Rs 52,000 for 100 persons"): group_price / group_size
-- per_head_rate stays what a booking is charged per guest (or box). For a group package it is
-- worked out as group_price / group_size when the package is saved, so the booking totals need
-- no special case. Take a phpMyAdmin export first.
-- ---------------------------------------------------------------------------

ALTER TABLE menu_packages
  ADD COLUMN price_basis ENUM('guest','box','group') NOT NULL DEFAULT 'guest' AFTER description,
  ADD COLUMN group_price DECIMAL(12,2) NULL AFTER per_head_rate,
  ADD COLUMN group_size  INT UNSIGNED  NULL AFTER group_price;

INSERT INTO schema_version (version) VALUES (14);
