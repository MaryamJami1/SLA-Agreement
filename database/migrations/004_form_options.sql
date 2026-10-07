-- ---------------------------------------------------------------------------
-- 004_form_options
--
-- The Stage Decoration, Entrance Decoration, Lighting and Floor Covering dropdowns on the
-- booking form become admin-managed lists instead of constants in the code. Bookings keep
-- storing the chosen name as text, so renaming or retiring an option never changes an
-- existing booking. "Other" stays built into the form and is not stored here.
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

INSERT INTO form_options (list_key, name, is_active, sort_order) VALUES
('stage',    'Full backdrop — fresh flowers', 1, 10),
('stage',    'Artificial flowers',            1, 20),
('stage',    'Fabric',                        1, 30),
('entrance', 'Welcome arch',                  1, 10),
('entrance', 'Floral gate',                   1, 20),
('entrance', 'None',                          1, 30),
('lighting', 'Simple',                        1, 10),
('lighting', 'Uplighting',                    1, 20),
('lighting', 'Fairy lights',                  1, 30),
('lighting', 'Spotlights',                    1, 40),
('floor',    'Red Carpet',                    1, 10),
('floor',    'Regular Flooring',              1, 20);

INSERT INTO schema_version (version) VALUES (4);
