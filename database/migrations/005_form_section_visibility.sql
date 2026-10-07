-- ---------------------------------------------------------------------------
-- 005_form_section_visibility
--
-- The admin can show or hide each of the Decoration & Setup Standards dropdowns (Stage Decoration,
-- Entrance Decoration, Lighting, Floor Covering) on the booking form. Hiding one never changes a
-- booking that already has a value for it: the stored value is kept and still printed.
-- ---------------------------------------------------------------------------

CREATE TABLE form_sections (
  list_key ENUM('stage','entrance','lighting','floor') NOT NULL,
  is_shown TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (list_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO form_sections (list_key, is_shown) VALUES
('stage', 1), ('entrance', 1), ('lighting', 1), ('floor', 1);

INSERT INTO schema_version (version) VALUES (5);
