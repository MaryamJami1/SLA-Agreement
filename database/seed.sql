-- AO Mess Event Booking & SLA System — initial data. Import after schema.sql.
--
-- Default admin login:  username  admin
--                       password  ChangeMe-2026
-- must_change_password = 1, so the first login can do nothing except set a new password.

SET NAMES utf8mb4;
SET time_zone = '+05:00';

-- ---------------------------------------------------------------------------
-- Default admin
-- ---------------------------------------------------------------------------
INSERT INTO users (username, password_hash, role, name, status, must_change_password) VALUES
('admin', '$2y$10$5LL8yFENyEb/6tVeDuF5BeCX/.Jga2UpYNTr4XLEa3leyZzV5yNb6', 'admin', 'AO Mess Administrator', 'active', 1);

-- ---------------------------------------------------------------------------
-- Venues (open question 3: default list; the admin manages it)
-- ---------------------------------------------------------------------------
-- Locations are left blank on purpose: the admin fills in the real ones on the Venues page.
INSERT INTO venues (name, location, is_active, sort_order) VALUES
('Lawn A',    NULL, 1, 10),
('Lawn B',    NULL, 1, 20),
('Lawn C',    NULL, 1, 30),
('Pool side', NULL, 1, 40),
('Hall',      NULL, 1, 50);

-- ---------------------------------------------------------------------------
-- Charge catalog — neutral names, no default rates (open question 2: the admin fills them in).
-- ---------------------------------------------------------------------------
INSERT INTO item_catalog (section, name, unit, default_rate, sort_order) VALUES
('charge', 'Venue Charges',              'fixed',    NULL, 10),
('charge', 'Generator Charges',          'fixed',    NULL, 20),
('charge', 'Cleaning Charges',           'fixed',    NULL, 30),
('charge', 'Service Charges',            'fixed',    NULL, 40),
('charge', 'Stage Charges',              'fixed',    NULL, 50),
('charge', 'Lighting / Tracing (by size)','per unit', NULL, 60),
('charge', 'Valet Parking',              'fixed',    NULL, 70),
('charge', 'Miscellaneous',              'fixed',    NULL, 80);

-- ---------------------------------------------------------------------------
-- Decor checklist (from the reference "GENERAL DECORD" sheet). Inclusion only, no money.
-- ---------------------------------------------------------------------------
INSERT INTO item_catalog (section, name, unit, sort_order) VALUES
('decor_general', 'Acrylic Chairs',                 'fixed', 10),
('decor_general', 'Cover Tables (with fabric)',     'fixed', 20),
('decor_general', 'Buffet Station (with fabric)',   'fixed', 30),
('decor_general', 'Lounges',                        'fixed', 40),
('decor_general', 'Paneling',                       'fixed', 50),
('decor_general', 'Sound (with mic)',               'fixed', 60),
('decor_general', 'General Carpets',                'fixed', 70),
('decor_general', 'Walkway Carpet',                 'fixed', 80),
('decor_general', 'Valet Parking',                  'fixed', 90),
('decor_general', 'Crockery and Cutlery',           'fixed', 100),
('decor_general', 'Takhat Platform',                'fixed', 110),
('decor_general', 'VIP Waiters / General Waiters',  'fixed', 120),
('decor_light',     'General Lights',               'fixed', 10),
('decor_light',     'LED',                          'fixed', 20),
('decor_light',     'Chilli Light 30 feet',         'fixed', 30),
('decor_generator', 'Stand By Generator',           'fixed', 10),
('decor_flower',    'Table Arrangements',           'fixed', 10),
('decor_flower',    'Console',                      'fixed', 20),
('decor_extra',     'Cold Drink (as per use)',      'fixed', 10),
('decor_extra',     'Mineral Water (as per use)',   'fixed', 20),
('decor_extra',     'Tea or Coffee',                'fixed', 30);

-- ---------------------------------------------------------------------------
-- Operations sheet items (from the reference event-inventory sheet). Quantity and notes only.
-- ---------------------------------------------------------------------------
INSERT INTO item_catalog (section, name, unit, sort_order) VALUES
('ops_item', 'Basic Setup',          'per unit', 10),
('ops_item', 'Flower Work',          'per unit', 20),
('ops_item', 'Tracing',              'per unit', 30),
('ops_item', 'Welcome Board',        'per unit', 40),
('ops_item', 'Walkway',              'per unit', 50),
('ops_item', 'Console',              'per unit', 60),
('ops_item', 'Ply Single Stage',     'per unit', 70),
('ops_item', 'Lobby',                'per unit', 80),
('ops_item', 'Sofa',                 'per unit', 90),
('ops_item', 'Fanous (Lanterns)',    'per unit', 100);
