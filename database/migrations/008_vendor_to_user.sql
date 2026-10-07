-- ---------------------------------------------------------------------------
-- 008_vendor_to_user
--
-- The accounts that book events for clients were called "vendors"; they are now "users".
-- ("Vendor" is kept free for the service providers — catering, sound, furniture — added later.)
--   users.role                 'vendor'            → 'user'
--   bookings.vendor_id         → user_id           (with its index and foreign key)
--   bookings.vendor_sign_*     → user_sign_*
--   audit_log.action           'vendor_<x>'        → 'user_<x>'   (existing rows renamed too)
-- Take a phpMyAdmin export first.
-- ---------------------------------------------------------------------------

ALTER TABLE users MODIFY role ENUM('admin','vendor','user') NOT NULL DEFAULT 'user';
UPDATE users SET role = 'user' WHERE role = 'vendor';
ALTER TABLE users MODIFY role ENUM('admin','user') NOT NULL DEFAULT 'user';

ALTER TABLE bookings DROP FOREIGN KEY fk_bookings_vendor;
ALTER TABLE bookings
  CHANGE vendor_id user_id INT UNSIGNED NULL,
  CHANGE vendor_sign_name user_sign_name VARCHAR(150) NULL,
  CHANGE vendor_sign_date user_sign_date DATE NULL,
  DROP INDEX idx_bookings_vendor_status,
  ADD KEY idx_bookings_user_status (user_id, status),
  ADD CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT;

ALTER TABLE audit_log
  MODIFY action ENUM('create','update','amend','confirm','complete','cancel','delete_draft',
                     'payment_add','payment_void','attachment_add','attachment_void',
                     'vendor_register','vendor_create','vendor_approve','vendor_disable','vendor_delete',
                     'user_register','user_create','user_approve','user_disable','user_delete','password_reset','password_change',
                     'login_ok','login_fail','catalog_change','venue_change') NOT NULL;
UPDATE audit_log SET action = REPLACE(action, 'vendor_', 'user_') WHERE action LIKE 'vendor\_%';
ALTER TABLE audit_log
  MODIFY action ENUM('create','update','amend','confirm','complete','cancel','delete_draft',
                     'payment_add','payment_void','attachment_add','attachment_void',
                     'user_register','user_create','user_approve','user_disable','user_delete','password_reset','password_change',
                     'login_ok','login_fail','catalog_change','venue_change') NOT NULL;

INSERT INTO schema_version (version) VALUES (8);
