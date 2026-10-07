-- ---------------------------------------------------------------------------
-- 006_vendor_create
--
-- The admin can create a vendor account from Admin → Vendors (as well as vendors registering
-- themselves). Each one is audited as 'vendor_create'.
-- ---------------------------------------------------------------------------

ALTER TABLE audit_log
  MODIFY action ENUM('create','update','amend','confirm','complete','cancel','delete_draft',
                     'payment_add','payment_void','attachment_add','attachment_void',
                     'vendor_register','vendor_create','vendor_approve','vendor_disable','password_reset','password_change',
                     'login_ok','login_fail','catalog_change','venue_change') NOT NULL;

INSERT INTO schema_version (version) VALUES (6);
