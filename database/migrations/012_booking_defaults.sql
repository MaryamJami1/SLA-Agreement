-- ---------------------------------------------------------------------------
-- 012_booking_defaults
--
-- Booking Organizer's standard refund policy, set once on the Catalog page. Every new booking starts
-- with it — including one a user creates — so the user and the client see the refund terms straight
-- away. The admin can still change them on any single booking. Existing bookings are untouched.
-- Take a phpMyAdmin export first.
-- ---------------------------------------------------------------------------

CREATE TABLE booking_defaults (
  field ENUM('refund_pct_30','refund_pct_7') NOT NULL,
  value DECIMAL(5,2) NULL,
  PRIMARY KEY (field)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version) VALUES (12);
