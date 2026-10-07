-- ---------------------------------------------------------------------------
-- 009_vendors
--
-- Vendor management (admin only): vendor categories, vendors, their priced services, vendor
-- services assigned to an event, vendor invoices with frozen line items, and payments made to
-- vendors. Vendors have no login. Nothing existing is changed except the audit_log action list.
-- Take a phpMyAdmin export first.
-- ---------------------------------------------------------------------------

-- vendor_categories: admin-managed kinds of service provider (Catering, Decoration, …).
-- A category any vendor uses can't be deleted, only deactivated.
CREATE TABLE vendor_categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- vendors: service providers the admin books for events. They have no login.
-- Invoices copy the vendor's name and contact details, so edits here never change history.
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

-- vendor_services: what each vendor provides and at what rate. Event lines copy the name, unit
-- and rate, so changing a rate here never changes an event or an invoice.
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

-- vendor_invoices: what Booking Organizer owes a vendor for one event. Vendor and event details are
-- snapshots. Totals are written only by recompute_vendor_invoice_totals() in app/vendors.php.
-- Never deleted; a mistaken invoice is voided (only while it has no payments).
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

-- vendor_invoice_items: the lines of an invoice, copied when it is generated and never changed.
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

-- booking_vendor_items: vendor services assigned to an event. label / unit / rate are snapshots.
-- A line on an issued invoice is locked; voiding the invoice releases it.
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

-- vendor_payments: money paid to a vendor against an invoice. Never deleted; voided once.
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

-- vendor_invoice_counters: VINV number sequence per year (see next_vendor_invoice_no()).
CREATE TABLE vendor_invoice_counters (
  year_key SMALLINT UNSIGNED NOT NULL,
  seq      INT UNSIGNED      NOT NULL,
  PRIMARY KEY (year_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE audit_log
  MODIFY action ENUM('create','update','amend','confirm','complete','cancel','delete_draft',
                     'payment_add','payment_void','attachment_add','attachment_void',
                     'user_register','user_create','user_approve','user_disable','user_delete','password_reset','password_change',
                     'login_ok','login_fail','catalog_change','venue_change',
                     'vendor_change','vendor_assign','vendor_invoice','vendor_payment_add','vendor_payment_void') NOT NULL;

INSERT INTO vendor_categories (name, sort_order) VALUES
  ('Catering', 10), ('Decoration', 20), ('Photography', 30), ('Videography', 40), ('Venue', 50),
  ('Sound & Lighting', 60), ('Transportation', 70), ('Entertainment', 80), ('Security', 90), ('Other', 100);

INSERT INTO schema_version (version) VALUES (9);
