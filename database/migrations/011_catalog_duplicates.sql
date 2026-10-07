-- ---------------------------------------------------------------------------
-- 011_catalog_duplicates
--
-- The booking form now shows every ticklist as one "Items to provide" list, where Valet Parking
-- appeared twice: once as a charge and once as a general decor item. The decor copy is retired
-- (not deleted), so a booking that already has it keeps its line; new bookings offer only the charge.
-- Take a phpMyAdmin export first.
-- ---------------------------------------------------------------------------

UPDATE item_catalog SET is_active = 0 WHERE section = 'decor_general' AND name = 'Valet Parking';

INSERT INTO schema_version (version) VALUES (11);
