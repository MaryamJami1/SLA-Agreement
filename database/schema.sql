-- Booking Organizer Event Booking & SLA System — full schema (schema version 1).
-- Portable between MySQL 8 and MariaDB 10.4+: InnoDB, utf8mb4, no JSON type, no reliance on CHECK.
-- Import into an EMPTY database (phpMyAdmin → Import), then import seed.sql.
-- Money columns are DECIMAL(12,2); the application does all arithmetic in integer paisa.

SET NAMES utf8mb4;
SET time_zone = '+05:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users: admins and users (the agents who book events for clients). status / must_change_password are re-read on every request.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  role                 ENUM('admin','user') NOT NULL DEFAULT 'user',
  name                 VARCHAR(100) NOT NULL,
  firm_name            VARCHAR(150) NULL,
  rep_name             VARCHAR(100) NULL,
  contact              VARCHAR(50)  NULL,
  status               ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- login_attempts: every attempt, successful or not. Drives throttling and "known IPs".
-- ---------------------------------------------------------------------------
CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username     VARCHAR(50)  NOT NULL,
  ip           VARCHAR(45)  NOT NULL,
  success      TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_username_time (username, attempted_at),
  KEY idx_la_ip_time (ip, attempted_at),
  KEY idx_la_username_ip_success (username, ip, success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- counters: SLA number sequence per creation year (see app/counters.php).
-- ---------------------------------------------------------------------------
CREATE TABLE counters (
  year_key SMALLINT UNSIGNED NOT NULL,
  seq      INT UNSIGNED      NOT NULL,
  PRIMARY KEY (year_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- venues: once referenced by any booking, never renamed or deleted — only deactivated.
-- ---------------------------------------------------------------------------
CREATE TABLE venues (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  location   VARCHAR(150) NULL,                 -- where it sits inside Booking Organizer; copied onto each booking
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venues_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- item_catalog: admin-managed master list for charges, decor checklist and ops items.
-- Bookings copy label / unit / rate into booking_line_items, so edits here never change history.
-- ---------------------------------------------------------------------------
CREATE TABLE item_catalog (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section      ENUM('charge','decor_general','decor_light','decor_generator','decor_flower','decor_extra','ops_item') NOT NULL,
  name         VARCHAR(150)  NOT NULL,
  unit         ENUM('fixed','per unit','per head') NOT NULL DEFAULT 'fixed',
  default_rate DECIMAL(12,2) NULL,
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order   INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_catalog_section (section, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- menu_types: admin-managed options of the booking form's Menu Type dropdown ("Other" is built in).
-- Bookings store the chosen name as text, so edits here never change history.
-- ---------------------------------------------------------------------------
CREATE TABLE menu_types (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Menus (Menus page): categories, the dish library, and priced packages built from dishes.
-- Dishes in a package that share a choice_group are alternatives; the booking picks one.
-- Bookings copy the package name and chosen dishes (bookings.menu_selection), so edits here never
-- change history.
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
  extra_rate  DECIMAL(12,2) NULL,                   -- price when added on top of a package (copied onto the booking)
  extra_unit  ENUM('per head','fixed') NOT NULL DEFAULT 'per head',
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
  price_basis      ENUM('guest','box','group') NOT NULL DEFAULT 'guest',  -- per guest, per box, or one price for a group
  per_head_rate    DECIMAL(12,2) NULL,              -- copied onto a booking when the package is chosen
  group_price      DECIMAL(12,2) NULL,              -- 'group' only: per_head_rate = group_price / group_size
  group_size       INT UNSIGNED  NULL,
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

-- ---------------------------------------------------------------------------
-- form_options: admin-managed options of the booking form's Stage, Entrance, Lighting and Floor
-- Covering dropdowns ("Other" is built in). Bookings store the chosen name as text.
-- ---------------------------------------------------------------------------
CREATE TABLE form_options (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_key   ENUM('stage','entrance','lighting','floor') NOT NULL,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_form_options_name (list_key, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- form_sections: whether each form_options dropdown is shown on the booking form. Hiding one
-- never changes a booking that already has a value for it.
-- ---------------------------------------------------------------------------
CREATE TABLE form_sections (
  list_key ENUM('stage','entrance','lighting','floor') NOT NULL,
  is_shown TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (list_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- booking_defaults: the standard refund policy every new booking starts with (set on the Catalog
-- page). A field with no row, or a NULL value, has no default. Existing bookings never change.
-- ---------------------------------------------------------------------------
CREATE TABLE booking_defaults (
  field ENUM('refund_pct_30','refund_pct_7') NOT NULL,
  value DECIMAL(5,2) NULL,
  PRIMARY KEY (field)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- bookings: one row per booking / agreement.
-- ---------------------------------------------------------------------------
CREATE TABLE bookings (
  -- Identity & lifecycle
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  unique_id           VARCHAR(20)  NOT NULL,
  user_id             INT UNSIGNED NULL,
  created_by          INT UNSIGNED NOT NULL,
  updated_by          INT UNSIGNED NULL,
  status              ENUM('draft','confirmed','completed','cancelled') NOT NULL DEFAULT 'draft',
  version             INT UNSIGNED      NOT NULL DEFAULT 1,
  revision            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  revised_at          DATETIME NULL,
  confirmed_at        DATETIME NULL,
  completed_at        DATETIME NULL,
  cancelled_at        DATETIME NULL,
  cancelled_by        INT UNSIGNED NULL,
  cancellation_reason TEXT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NULL,

  -- Agreement (free text: legal phrasing)
  agreement_day       VARCHAR(20)  NULL,
  agreement_month     VARCHAR(40)  NULL,
  agreement_place     VARCHAR(80)  NOT NULL DEFAULT 'Karachi',

  -- Client
  client_name         VARCHAR(150) NULL,
  client_relation     VARCHAR(150) NULL,
  client_cnic         CHAR(15)     NULL,
  client_contact      VARCHAR(50)  NULL,
  client_contact2     VARCHAR(50)  NULL,
  client_company      VARCHAR(150) NULL,
  client_address      VARCHAR(255) NULL,

  -- Reference
  reference_name       VARCHAR(150) NULL,
  reference_department VARCHAR(150) NULL,

  -- User snapshot
  firm_name           VARCHAR(150) NULL,
  rep_name            VARCHAR(100) NULL,
  rep_contact         VARCHAR(50)  NULL,

  -- Event
  event_type          VARCHAR(50)  NULL,
  event_type_other    VARCHAR(100) NULL,
  event_date          DATE NULL,
  alt_date            DATE NULL,
  venue_id            INT UNSIGNED NULL,
  venue_other         VARCHAR(150) NULL,
  venue_location      VARCHAR(150) NULL,        -- snapshot of venues.location at save time
  setup_time          TIME NULL,
  start_time          TIME NULL,
  guests              INT UNSIGNED NOT NULL DEFAULT 0,

  -- Catering
  menu_type           VARCHAR(50)  NULL,
  menu_type_other     VARCHAR(100) NULL,
  menu_package_id     INT UNSIGNED NULL,        -- soft reference to menu_packages
  menu_package_name   VARCHAR(100) NULL,        -- snapshot of the package name
  menu_selection      TEXT NULL,                -- JSON snapshot of the chosen dishes (see app/menus.php)
  food_items          TEXT NULL,

  -- Decoration standards
  theme               VARCHAR(150) NULL,
  stage               VARCHAR(100) NULL,
  stage_other         VARCHAR(150) NULL,
  stage_desc          TEXT NULL,
  entrance            VARCHAR(100) NULL,
  entrance_other      VARCHAR(150) NULL,
  lighting            VARCHAR(100) NULL,
  lighting_other      VARCHAR(150) NULL,
  floor_covering      VARCHAR(100) NULL,
  floor_other         VARCHAR(150) NULL,
  addl_decor          TEXT NULL,
  decor_by            VARCHAR(150) NULL,

  -- Furniture & manpower (total staff is calculated, not stored)
  sofas               INT UNSIGNED NOT NULL DEFAULT 0,
  chairs              INT UNSIGNED NOT NULL DEFAULT 0,
  tables_dining       INT UNSIGNED NOT NULL DEFAULT 0,
  tables_buffet       INT UNSIGNED NOT NULL DEFAULT 0,
  waiters             INT UNSIGNED NOT NULL DEFAULT 0,
  chefs               INT UNSIGNED NOT NULL DEFAULT 0,

  -- Money inputs
  per_head_rate       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,

  -- Money totals: written ONLY by recompute_booking_totals() in app/money.php
  guest_charges       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  charges_total       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sub_total           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance             DECIMAL(12,2) NOT NULL DEFAULT 0.00,

  -- Terms
  due_on              ENUM('Event Day','As agreed') NOT NULL DEFAULT 'Event Day',
  refund_pct_30       DECIMAL(5,2) NULL,
  refund_pct_7        DECIMAL(5,2) NULL,
  special_commitments TEXT NULL,

  -- Signatures
  user_sign_name      VARCHAR(150) NULL,
  user_sign_date      DATE NULL,
  client_sign_name    VARCHAR(150) NULL,
  client_sign_date    DATE NULL,

  -- Booking Organizer record
  received_by         VARCHAR(100) NULL,
  received_date       DATE NULL,
  received_time       TIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_unique_id (unique_id),
  KEY idx_bookings_event_date (event_date),
  KEY idx_bookings_user_status (user_id, status),
  KEY idx_bookings_status (status),
  KEY idx_bookings_client_name (client_name),
  KEY idx_bookings_venue_date (venue_id, event_date),
  CONSTRAINT fk_bookings_user         FOREIGN KEY (user_id)      REFERENCES users (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_created_by   FOREIGN KEY (created_by)   REFERENCES users (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_updated_by   FOREIGN KEY (updated_by)   REFERENCES users (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_venue        FOREIGN KEY (venue_id)     REFERENCES venues (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- booking_line_items: charges (carry money), decor checklist and ops items (no money).
-- label / unit_snapshot / rate are snapshots taken when the line is added.
-- ---------------------------------------------------------------------------
CREATE TABLE booking_line_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id    INT UNSIGNED NOT NULL,
  catalog_id    INT UNSIGNED NULL,
  section       ENUM('charge','decor_general','decor_light','decor_generator','decor_flower','decor_extra','ops_item') NOT NULL,
  label         VARCHAR(150)  NOT NULL,
  unit_snapshot ENUM('fixed','per unit','per head') NOT NULL DEFAULT 'fixed',
  is_selected   TINYINT(1)    NOT NULL DEFAULT 0,
  qty           INT UNSIGNED  NULL,
  rate          DECIMAL(12,2) NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes         VARCHAR(255)  NULL,
  sort_order    INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_lines_booking (booking_id, section, sort_order),
  KEY idx_lines_catalog (catalog_id),
  CONSTRAINT fk_lines_booking FOREIGN KEY (booking_id) REFERENCES bookings (id)     ON DELETE CASCADE,
  CONSTRAINT fk_lines_catalog FOREIGN KEY (catalog_id) REFERENCES item_catalog (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payments: installments and refunds. Never deleted; the only update is voiding, once.
-- ---------------------------------------------------------------------------
CREATE TABLE payments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id   INT UNSIGNED NOT NULL,
  kind         ENUM('payment','refund') NOT NULL DEFAULT 'payment',
  amount       DECIMAL(12,2) NOT NULL,
  paid_on      DATE NOT NULL,
  method       ENUM('cash','bank_transfer','cheque','online') NOT NULL,
  bank_name    VARCHAR(100) NULL,
  reference_no VARCHAR(100) NULL,
  notes        VARCHAR(255) NULL,
  recorded_by  INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at    DATETIME NULL,
  voided_by    INT UNSIGNED NULL,
  void_reason  VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_payments_booking (booking_id, voided_at),
  CONSTRAINT fk_payments_booking     FOREIGN KEY (booking_id)  REFERENCES bookings (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_payments_voided_by   FOREIGN KEY (voided_by)   REFERENCES users (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- attachments: files in storage/uploads/ under random names. Rows are removed only by the
-- draft-deletion flow; otherwise they are voided.
-- ---------------------------------------------------------------------------
CREATE TABLE attachments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id      INT UNSIGNED NOT NULL,
  original_name   VARCHAR(255) NOT NULL,
  stored_name     CHAR(32)     NOT NULL,
  mime            VARCHAR(50)  NOT NULL,
  size_bytes      INT UNSIGNED NOT NULL,
  signed_revision SMALLINT UNSIGNED NULL,
  uploaded_by     INT UNSIGNED NOT NULL,
  uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at       DATETIME NULL,
  voided_by       INT UNSIGNED NULL,
  void_reason     VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attachments_stored_name (stored_name),
  KEY idx_attachments_booking (booking_id, signed_revision, voided_at),
  CONSTRAINT fk_attachments_booking     FOREIGN KEY (booking_id)  REFERENCES bookings (id) ON DELETE RESTRICT,
  CONSTRAINT fk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_attachments_voided_by   FOREIGN KEY (voided_by)   REFERENCES users (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vendor_categories: admin-managed kinds of service provider (Catering, Decoration, …).
-- A category any vendor uses can't be deleted, only deactivated.
-- ---------------------------------------------------------------------------
CREATE TABLE vendor_categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vendors: service providers the admin books for events. They have no login.
-- Invoices copy the vendor's name and contact details, so edits here never change history.
-- ---------------------------------------------------------------------------
CREATE TABLE vendors (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id    INT UNSIGNED NOT NULL,
  name           VARCHAR(150) NOT NULL,
  contact_person VARCHAR(100) NULL,
  phone          VARCHAR(50)  NULL,
  phone2         VARCHAR(50)  NULL,
  email          VARCHAR(150) NULL,
  address        VARCHAR(255) NULL,
  notes          TEXT         NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendors_name (name),
  KEY idx_vendors_category (category_id, is_active),
  CONSTRAINT fk_vendors_category FOREIGN KEY (category_id) REFERENCES vendor_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vendor_services: what each vendor provides and at what rate. Event lines copy the name, unit
-- and rate, so changing a rate here never changes an event or an invoice.
-- ---------------------------------------------------------------------------
CREATE TABLE vendor_services (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  vendor_id  INT UNSIGNED  NOT NULL,
  name       VARCHAR(150)  NOT NULL,
  unit       VARCHAR(20)   NOT NULL DEFAULT 'fixed',
  rate       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_active  TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_services_name (vendor_id, name),
  CONSTRAINT fk_vendor_services_vendor FOREIGN KEY (vendor_id) REFERENCES vendors (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- vendor_category_services: the services each category offers (checkbox list on the vendor pages).
CREATE TABLE vendor_category_services (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vcs_name (category_id, name),
  CONSTRAINT fk_vcs_category FOREIGN KEY (category_id) REFERENCES vendor_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vendor_invoices: what Booking Organizer owes a vendor for one event. Vendor and event details are
-- snapshots. Totals are written only by recompute_vendor_invoice_totals() in app/vendors.php.
-- Never deleted; a mistaken invoice is voided (only while it has no payments).
-- ---------------------------------------------------------------------------
CREATE TABLE vendor_invoices (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  invoice_no            VARCHAR(20)   NOT NULL,
  booking_id            INT UNSIGNED  NOT NULL,
  vendor_id             INT UNSIGNED  NOT NULL,
  invoice_date          DATE          NOT NULL,
  vendor_name           VARCHAR(150)  NOT NULL,
  vendor_contact_person VARCHAR(100)  NULL,
  vendor_phone          VARCHAR(110)  NULL,
  vendor_email          VARCHAR(150)  NULL,
  vendor_address        VARCHAR(255)  NULL,
  event_label           VARCHAR(255)  NOT NULL,
  event_date            DATE          NULL,
  event_venue           VARCHAR(255)  NULL,
  sub_total             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_pct               DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  tax_amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_total            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes                 VARCHAR(255)  NULL,
  status                ENUM('issued','void') NOT NULL DEFAULT 'issued',
  voided_at             DATETIME      NULL,
  voided_by             INT UNSIGNED  NULL,
  void_reason           VARCHAR(255)  NULL,
  created_by            INT UNSIGNED  NOT NULL,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_invoices_no (invoice_no),
  KEY idx_vendor_invoices_vendor (vendor_id, status),
  KEY idx_vendor_invoices_booking (booking_id, status),
  CONSTRAINT fk_vendor_invoices_booking    FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT,
  CONSTRAINT fk_vendor_invoices_vendor     FOREIGN KEY (vendor_id)  REFERENCES vendors (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_vendor_invoices_created_by FOREIGN KEY (created_by) REFERENCES users (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_vendor_invoices_voided_by  FOREIGN KEY (voided_by)  REFERENCES users (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vendor_invoice_items: the lines of an invoice, copied when it is generated and never changed.
-- ---------------------------------------------------------------------------
CREATE TABLE vendor_invoice_items (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  vendor_invoice_id INT UNSIGNED  NOT NULL,
  label             VARCHAR(150)  NOT NULL,
  unit              VARCHAR(20)   NOT NULL,
  qty               INT UNSIGNED  NOT NULL,
  rate              DECIMAL(12,2) NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  notes             VARCHAR(255)  NULL,
  sort_order        INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_vii_invoice (vendor_invoice_id, sort_order),
  CONSTRAINT fk_vii_invoice FOREIGN KEY (vendor_invoice_id) REFERENCES vendor_invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- booking_vendor_items: vendor services assigned to an event. label / unit / rate are snapshots.
-- A line on an issued invoice is locked; voiding the invoice releases it.
-- ---------------------------------------------------------------------------
CREATE TABLE booking_vendor_items (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  booking_id        INT UNSIGNED  NOT NULL,
  vendor_id         INT UNSIGNED  NOT NULL,
  vendor_service_id INT UNSIGNED  NULL,
  label             VARCHAR(150)  NOT NULL,
  unit              VARCHAR(20)   NOT NULL,
  qty               INT UNSIGNED  NOT NULL,
  rate              DECIMAL(12,2) NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  notes             VARCHAR(255)  NULL,
  vendor_invoice_id INT UNSIGNED  NULL,
  created_by        INT UNSIGNED  NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bvi_booking (booking_id, vendor_id),
  KEY idx_bvi_vendor (vendor_id),
  KEY idx_bvi_invoice (vendor_invoice_id),
  CONSTRAINT fk_bvi_booking    FOREIGN KEY (booking_id)        REFERENCES bookings (id)        ON DELETE CASCADE,
  CONSTRAINT fk_bvi_vendor     FOREIGN KEY (vendor_id)         REFERENCES vendors (id)         ON DELETE RESTRICT,
  CONSTRAINT fk_bvi_service    FOREIGN KEY (vendor_service_id) REFERENCES vendor_services (id) ON DELETE SET NULL,
  CONSTRAINT fk_bvi_invoice    FOREIGN KEY (vendor_invoice_id) REFERENCES vendor_invoices (id) ON DELETE RESTRICT,
  CONSTRAINT fk_bvi_created_by FOREIGN KEY (created_by)        REFERENCES users (id)           ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vendor_payments: money paid to a vendor against an invoice. Never deleted; voided once.
-- ---------------------------------------------------------------------------
CREATE TABLE vendor_payments (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  vendor_invoice_id INT UNSIGNED  NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  paid_on           DATE          NOT NULL,
  method            ENUM('cash','bank_transfer','cheque','online') NOT NULL,
  bank_name         VARCHAR(100)  NULL,
  reference_no      VARCHAR(100)  NULL,
  notes             VARCHAR(255)  NULL,
  recorded_by       INT UNSIGNED  NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at         DATETIME      NULL,
  voided_by         INT UNSIGNED  NULL,
  void_reason       VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  KEY idx_vendor_payments_invoice (vendor_invoice_id, voided_at),
  CONSTRAINT fk_vendor_payments_invoice     FOREIGN KEY (vendor_invoice_id) REFERENCES vendor_invoices (id) ON DELETE RESTRICT,
  CONSTRAINT fk_vendor_payments_recorded_by FOREIGN KEY (recorded_by)       REFERENCES users (id)           ON DELETE RESTRICT,
  CONSTRAINT fk_vendor_payments_voided_by   FOREIGN KEY (voided_by)         REFERENCES users (id)           ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vendor_invoice_counters: VINV number sequence per year (see next_vendor_invoice_no()).
-- ---------------------------------------------------------------------------
CREATE TABLE vendor_invoice_counters (
  year_key SMALLINT UNSIGNED NOT NULL,
  seq      INT UNSIGNED      NOT NULL,
  PRIMARY KEY (year_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- audit_log: APPEND-ONLY. The application only INSERTs (through app/audit.php).
-- booking_id is deliberately not a foreign key, so history survives draft deletion.
-- ---------------------------------------------------------------------------
CREATE TABLE audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  booking_id INT UNSIGNED NULL,
  action     ENUM('create','update','amend','confirm','complete','cancel','delete_draft',
                  'payment_add','payment_void','attachment_add','attachment_void',
                  'user_register','user_create','user_approve','user_disable','user_delete','password_reset','password_change',
                  'login_ok','login_fail','catalog_change','venue_change',
                  'vendor_change','vendor_assign','vendor_invoice','vendor_payment_add','vendor_payment_void') NOT NULL,
  details    TEXT NULL,
  ip         VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_booking (booking_id, created_at),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- schema_version: one row per applied schema version. Later changes go in database/migrations/.
-- ---------------------------------------------------------------------------
CREATE TABLE schema_version (
  version    INT UNSIGNED NOT NULL,
  applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- This file carries version 1 plus every migration in database/migrations/ folded in,
-- so a fresh install lands on the same structure an upgraded database reaches.
INSERT INTO schema_version (version) VALUES (1), (2), (3), (4), (5), (6), (7), (8), (9), (10), (11), (12), (13), (14), (15);

SET FOREIGN_KEY_CHECKS = 1;
