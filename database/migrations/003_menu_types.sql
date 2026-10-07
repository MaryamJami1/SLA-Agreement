-- ---------------------------------------------------------------------------
-- 003_menu_types
--
-- The Menu Type dropdown on the booking form (Buffet, Sitting Dinner, ...) becomes an
-- admin-managed list instead of a constant in the code. Bookings keep storing the chosen
-- name as text, so renaming or retiring a menu type never changes an existing booking.
-- "Other" stays built into the form and is not stored here.
-- ---------------------------------------------------------------------------

CREATE TABLE menu_types (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO menu_types (name, is_active, sort_order) VALUES
('Buffet',         1, 10),
('Sitting Dinner', 1, 20),
('Hi-Tea',         1, 30);

INSERT INTO schema_version (version) VALUES (3);
