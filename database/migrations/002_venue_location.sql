-- ---------------------------------------------------------------------------
-- 002_venue_location
--
-- Venues gain a location (the hall/lawn's place inside AO Mess, e.g. "Ground
-- Floor, Block B"). Bookings keep their own copy of it, the same way they keep
-- firm_name and line-item labels: the booking must still read correctly years
-- later even if the venue is later moved, renamed or deactivated.
-- ---------------------------------------------------------------------------

ALTER TABLE venues
  ADD COLUMN location VARCHAR(150) NULL AFTER name;

ALTER TABLE bookings
  ADD COLUMN venue_location VARCHAR(150) NULL AFTER venue_other;

INSERT INTO schema_version (version) VALUES (2);
