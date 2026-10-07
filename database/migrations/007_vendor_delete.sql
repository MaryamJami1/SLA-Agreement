-- ---------------------------------------------------------------------------
-- 007_vendor_delete
--
-- The admin can delete a vendor account that has no bookings, payments or files (anything else is
-- disabled instead, so history is kept). Each deletion is audited as 'vendor_delete'.
-- ---------------------------------------------------------------------------

ALTER TABLE audit_log
  MODIFY action ENUM('create','update','amend','confirm','complete','cancel','delete_draft',
                     'payment_add','payment_void','attachment_add','attachment_void',
                     'vendor_register','vendor_create','vendor_approve','vendor_disable','vendor_delete','password_reset','password_change',
                     'login_ok','login_fail','catalog_change','venue_change') NOT NULL;

INSERT INTO schema_version (version) VALUES (7);
