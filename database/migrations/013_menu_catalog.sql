-- ---------------------------------------------------------------------------
-- 013_menu_catalog
--
-- Menus become admin-managed data instead of free text, so each client's own menus can be set up
-- from the Menus page without touching the code:
--   menu_categories    — Starter, Main Course, Dessert, Beverages, Sea Food, ...
--   menu_dishes        — the dish library, one category each
--   menu_packages      — a priced menu (per-head rate, minimum guests, optional menu card image)
--   menu_package_items — the dishes in a package; dishes sharing a choice_group are alternatives
--                        ("Beef Biryani OR Pulao") and the booking picks one of them
--
-- A booking copies the package name and the chosen dishes onto itself (menu_package_name,
-- menu_selection), so renaming or retiring a dish or package never changes an existing booking.
-- Take a phpMyAdmin export first.
-- ---------------------------------------------------------------------------

CREATE TABLE menu_categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_dishes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  is_live     TINYINT(1)   NOT NULL DEFAULT 0,      -- cooked at a live counter at the event
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_dishes_name (category_id, name),
  KEY idx_menu_dishes_category (category_id, is_active, sort_order),
  CONSTRAINT fk_menu_dishes_category FOREIGN KEY (category_id) REFERENCES menu_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_packages (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name             VARCHAR(100)  NOT NULL,
  description      VARCHAR(255)  NULL,
  per_head_rate    DECIMAL(12,2) NULL,              -- copied onto a booking when the package is chosen
  min_guests       INT UNSIGNED  NULL,              -- a warning on the booking, never a block
  card_stored_name VARCHAR(64)   NULL,              -- the menu card image, under storage/uploads/
  card_mime        VARCHAR(100)  NULL,
  is_active        TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order       INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_packages_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_package_items (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  package_id   INT UNSIGNED     NOT NULL,
  dish_id      INT UNSIGNED     NOT NULL,
  choice_group TINYINT UNSIGNED NULL,               -- same number = alternatives; NULL = always included
  is_free      TINYINT(1)       NOT NULL DEFAULT 0, -- printed as "(Free)"
  sort_order   INT              NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_package_dish (package_id, dish_id),
  KEY idx_menu_package_items_dish (dish_id),
  CONSTRAINT fk_menu_items_package FOREIGN KEY (package_id) REFERENCES menu_packages (id) ON DELETE CASCADE,
  CONSTRAINT fk_menu_items_dish    FOREIGN KEY (dish_id)    REFERENCES menu_dishes (id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- menu_package_id is a soft reference: the booking's own copy (name + selection) is what counts.
ALTER TABLE bookings
  ADD COLUMN menu_package_id   INT UNSIGNED NULL AFTER menu_type_other,
  ADD COLUMN menu_package_name VARCHAR(100) NULL AFTER menu_package_id,
  ADD COLUMN menu_selection    TEXT         NULL AFTER menu_package_name;

INSERT INTO menu_categories (name, is_active, sort_order) VALUES
('Starter',     1, 10),
('Main Course', 1, 20),
('Others',      1, 30),
('Dessert',     1, 40),
('Beverages',   1, 50);

INSERT INTO schema_version (version) VALUES (13);
