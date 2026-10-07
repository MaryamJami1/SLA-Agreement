-- ---------------------------------------------------------------------------
-- 015_menu_extra_rates
--
-- A dish added to a booking on top of its package ("extra dishes") now carries a price. Each dish
-- may have an extra rate, per guest or a fixed amount. The booking copies the rate when the dish is
-- added (in bookings.menu_selection), and the admin can change it on that booking; the extras are
-- added to the booking's items & charges. A dish with no rate is added at no charge until priced.
-- Take a phpMyAdmin export first.
-- ---------------------------------------------------------------------------

ALTER TABLE menu_dishes
  ADD COLUMN extra_rate DECIMAL(12,2) NULL AFTER is_live,
  ADD COLUMN extra_unit ENUM('per head','fixed') NOT NULL DEFAULT 'per head' AFTER extra_rate;

INSERT INTO schema_version (version) VALUES (15);
