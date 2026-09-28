-- ---------------------------------------------------------------------------
-- 003_venue_slots
--
-- Event slots: each venue offers fixed time slots (e.g. Morning 12:00–15:00)
-- that the admin configures on the Event Slots page. A booking holds one slot
-- on one date, and the same venue + date + slot can't be held twice.
--
-- * venue_slots is the configuration. An end_time at or before start_time means
--   the slot ends the next day (20:00 → 00:00 runs until midnight).
-- * bookings.slot_id points at the slot; slot_name / slot_start / slot_end are
--   snapshots taken when the slot is chosen, so editing a slot later never
--   changes the time on an existing booking or its paperwork.
-- * slot_hold is slot_id while the booking is live (not cancelled) and NULL
--   otherwise. The unique key on (slot_hold, event_date) is the database-level
--   guard against double booking; NULLs never collide, so cancelled bookings
--   and bookings without a slot are unaffected.
--
-- Existing bookings keep slot_id NULL and their own start_time. Every existing
-- venue receives the default Morning / Afternoon / Evening slots, which the
-- admin can then change.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE venue_slots (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venue_id   INT UNSIGNED NOT NULL,
  name       VARCHAR(60)  NOT NULL,
  icon       VARCHAR(16)  NULL,                 -- optional emoji shown on the slot card
  start_time TIME         NOT NULL,
  end_time   TIME         NOT NULL,             -- at or before start_time = ends the next day
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venue_slots_name (venue_id, name),
  KEY idx_venue_slots_venue (venue_id, is_active, sort_order),
  CONSTRAINT fk_venue_slots_venue FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
  ADD COLUMN slot_id    INT UNSIGNED NULL AFTER start_time,
  ADD COLUMN slot_name  VARCHAR(60)  NULL AFTER slot_id,
  ADD COLUMN slot_start TIME         NULL AFTER slot_name,
  ADD COLUMN slot_end   TIME         NULL AFTER slot_start,
  ADD COLUMN slot_hold  INT UNSIGNED GENERATED ALWAYS AS (IF(status <> 'cancelled', slot_id, NULL)) STORED AFTER slot_end,
  ADD UNIQUE KEY uq_bookings_slot_hold (slot_hold, event_date),
  ADD CONSTRAINT fk_bookings_slot FOREIGN KEY (slot_id) REFERENCES venue_slots (id) ON DELETE RESTRICT;

INSERT INTO venue_slots (venue_id, name, icon, start_time, end_time, sort_order)
SELECT v.id, d.name, d.icon, d.start_time, d.end_time, d.sort_order
  FROM venues v
  CROSS JOIN (SELECT 'Morning' AS name, '🌅' AS icon, '12:00:00' AS start_time, '15:00:00' AS end_time, 10 AS sort_order
              UNION ALL SELECT 'Afternoon', '☀️', '16:00:00', '19:00:00', 20
              UNION ALL SELECT 'Evening',   '🌙', '20:00:00', '00:00:00', 30) d;

INSERT INTO schema_version (version) VALUES (3);
