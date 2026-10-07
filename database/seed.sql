-- Booking Organizer Event Booking & SLA System — initial data. Import after schema.sql.
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
('admin', '$2y$10$5LL8yFENyEb/6tVeDuF5BeCX/.Jga2UpYNTr4XLEa3leyZzV5yNb6', 'admin', 'Booking Organizer Administrator', 'active', 1);

-- ---------------------------------------------------------------------------
-- Venues (open question 3: default list; the admin manages it)
-- ---------------------------------------------------------------------------
-- Locations are left blank on purpose: the admin fills in the real ones on the Venues page.
INSERT INTO menu_types (name, is_active, sort_order) VALUES
('Buffet',         1, 10),
('Sitting Dinner', 1, 20),
('Hi-Tea',         1, 30);

-- ---------------------------------------------------------------------------
-- Menus: the default categories (same as migration 013). Dishes and packages are each client's own:
-- add them on the Menus page, or import database/menu_data.sql for Booking Organizer's menus.
-- ---------------------------------------------------------------------------
INSERT INTO menu_categories (name, is_active, sort_order) VALUES
('Starter',     1, 10),
('Main Course', 1, 20),
('Others',      1, 30),
('Dessert',     1, 40),
('Beverages',   1, 50);

INSERT INTO form_sections (list_key, is_shown) VALUES
('stage', 1), ('entrance', 1), ('lighting', 1), ('floor', 1);

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

INSERT INTO venues (name, location, is_active, sort_order) VALUES
('Lawn A',    NULL, 1, 10),
('Lawn B',    NULL, 1, 20),
('Lawn C',    NULL, 1, 30),
('Pool side', NULL, 1, 40),
('Hall',      NULL, 1, 50);

-- Default event slots for every venue (the admin changes them on the Event Slots page).
-- Evening ends at midnight: an end time at or before the start means "the next day".
INSERT INTO venue_slots (venue_id, name, icon, start_time, end_time, sort_order)
SELECT v.id, d.name, d.icon, d.start_time, d.end_time, d.sort_order
  FROM venues v
  CROSS JOIN (SELECT 'Morning' AS name, '🌅' AS icon, '12:00:00' AS start_time, '15:00:00' AS end_time, 10 AS sort_order
              UNION ALL SELECT 'Afternoon', '☀️', '16:00:00', '19:00:00', 20
              UNION ALL SELECT 'Evening',   '🌙', '20:00:00', '00:00:00', 30) d;

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
-- Valet Parking is offered as a charge; the decor copy is retired (migration 011).
UPDATE item_catalog SET is_active = 0 WHERE section = 'decor_general' AND name = 'Valet Parking';

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

-- ---------------------------------------------------------------------------
-- Vendor categories (the admin manages them on the Vendors page)
-- ---------------------------------------------------------------------------
INSERT INTO vendor_categories (name, sort_order) VALUES
('Catering', 10), ('Decoration', 20), ('Photography', 30), ('Videography', 40), ('Venue', 50),
('Sound & Lighting', 60), ('Transportation', 70), ('Entertainment', 80), ('Security', 90), ('Other', 100);

-- Services each vendor category offers (from vendor_services.txt).
INSERT IGNORE INTO vendor_categories (name, sort_order) VALUES
  ('Accommodation & Travel', 110),
  ('Staffing & Personnel', 120),
  ('Technology & Registration', 130),
  ('Marketing & Promotion', 140),
  ('Exhibition & Trade Show', 150),
  ('Rentals & Specialty Equipment', 160),
  ('Legal, Permits & Insurance', 170),
  ('Gifts & Merchandise', 180),
  ('Post-Event Services', 190);

INSERT INTO vendor_category_services (category_id, name, sort_order) VALUES
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Banquet halls', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Hotels', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Convention centers', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Outdoor grounds', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Farmhouses', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Marquees', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Stage', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Backdrops', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Floor plans', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Seating layouts', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Chairs', 110),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Tables', 120),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Lounges', 130),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Podiums', 140),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Carpets', 150),
  ((SELECT id FROM vendor_categories WHERE name = 'Venue'), 'Tents and canopies', 160),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Buffet catering', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Plated catering', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Live stations', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Menu design', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Desserts', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Cakes', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Food trucks', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Coffee carts', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Mocktail / juice bar', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Bartenders', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Water supply', 110),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Tea and coffee service', 120),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Waiters', 130),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Chefs', 140),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Cleaners', 150),
  ((SELECT id FROM vendor_categories WHERE name = 'Catering'), 'Crockery and cutlery rental', 160),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Centerpieces', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Floral arches', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Bouquets', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Stage flowers', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Custom backdrops', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Photo booths', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Props', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Signage', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Balloon decor', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Fairy lights', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Neon signs', 110),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Chandeliers', 120),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Banners', 130),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Standees', 140),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Flags', 150),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Name tags', 160),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Invitations', 170),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Menus (printed)', 180),
  ((SELECT id FROM vendor_categories WHERE name = 'Decoration'), 'Programs (printed)', 190),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Speakers', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Microphones', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Mixers', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Sound engineers', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Stage lighting', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Intelligent lights', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Spotlights', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Ambient lighting', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'LED walls', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Projectors', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Screens', 110),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Live streaming', 120),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Recording', 130),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Generators', 140),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Truss systems', 150),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Stage platforms', 160),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Cabling', 170),
  ((SELECT id FROM vendor_categories WHERE name = 'Sound & Lighting'), 'Technical crew', 180),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'DJs', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Live bands', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Singers', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Dancers', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Comedians', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Magicians', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'MCs / emcees', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Anchors', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Keynote speakers', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Celebrity appearances', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Games and activities', 110),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Kids'' entertainers', 120),
  ((SELECT id FROM vendor_categories WHERE name = 'Entertainment'), 'Cultural shows', 130),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Candid photography', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Formal photography', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Event coverage', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Drone footage', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), '360° booth', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Photo kiosks', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Photo editing', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Albums', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Photography'), 'Same-day edits', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Videography'), 'Event videography', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Videography'), 'Cinematic videography', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Videography'), 'Drone videography', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Videography'), 'Highlight reels', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Videography'), 'Video editing', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Videography'), 'Same-day video edits', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Shuttles', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Buses', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Limousines', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Valet parking', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Cargo', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Loading and unloading', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Warehousing', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Airport transfers', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Transportation'), 'Fleet management', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Security'), 'Security guards', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Security'), 'Crowd control', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Security'), 'Bag checks', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Security'), 'VIP protection', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Accommodation & Travel'), 'Hotel room blocks (guests)', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Accommodation & Travel'), 'Speaker and VIP accommodation', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Accommodation & Travel'), 'Flights', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Accommodation & Travel'), 'Visas', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Accommodation & Travel'), 'Itineraries', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Ushers', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Hostesses', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Registration desk teams', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Coordinators', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Paramedics', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Ambulance standby', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Pre-event cleaning', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'During-event cleaning', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Staffing & Personnel'), 'Post-event cleaning', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Online ticketing', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'QR codes', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Check-in systems', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Event app: agenda', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Event app: networking', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Polls and Q&A', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Webinar tools', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Streaming platform', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Interpretation', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'RFID', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Badge printing', 110),
  ((SELECT id FROM vendor_categories WHERE name = 'Technology & Registration'), 'Data and analytics', 120),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Press releases', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Media invitations', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Social media campaigns', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Influencers', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Email marketing', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Graphic design', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Copywriting', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Content videography', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Sponsorship coordination', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Marketing & Promotion'), 'Partnership coordination', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Exhibition & Trade Show'), 'Modular stands', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Exhibition & Trade Show'), 'Custom booth builds', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Exhibition & Trade Show'), 'Freight handling', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Exhibition & Trade Show'), 'Customs handling', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Exhibition & Trade Show'), 'Product demo specialists', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Exhibition & Trade Show'), 'Lead retrieval systems', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Air conditioners', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Heaters', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Coolers', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Fans', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Portable restrooms', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Luxury restrooms', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Fireworks', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Pyrotechnics', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Fog effects', 90),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Confetti', 100),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Cold sparks', 110),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Tents', 120),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Flooring', 130),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Dance floors', 140),
  ((SELECT id FROM vendor_categories WHERE name = 'Rentals & Specialty Equipment'), 'Generators (rental)', 150),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'Permit consultants', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'Noise clearance', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'Fire clearance', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'Liability insurance', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'Cancellation insurance', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'Weather coverage', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'Contract advice', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Legal, Permits & Insurance'), 'IP and copyright (music licensing)', 80),
  ((SELECT id FROM vendor_categories WHERE name = 'Gifts & Merchandise'), 'Corporate gifts', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Gifts & Merchandise'), 'Custom merchandise', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Gifts & Merchandise'), 'Souvenirs', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Gifts & Merchandise'), 'Wedding favors', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Gifts & Merchandise'), 'Awards and trophies', 50),
  ((SELECT id FROM vendor_categories WHERE name = 'Gifts & Merchandise'), 'Engraving', 60),
  ((SELECT id FROM vendor_categories WHERE name = 'Gifts & Merchandise'), 'Certificates', 70),
  ((SELECT id FROM vendor_categories WHERE name = 'Post-Event Services'), 'Teardown and dismantling', 10),
  ((SELECT id FROM vendor_categories WHERE name = 'Post-Event Services'), 'Waste management', 20),
  ((SELECT id FROM vendor_categories WHERE name = 'Post-Event Services'), 'Recycling', 30),
  ((SELECT id FROM vendor_categories WHERE name = 'Post-Event Services'), 'Feedback and survey tools', 40),
  ((SELECT id FROM vendor_categories WHERE name = 'Post-Event Services'), 'Reporting and ROI analysis', 50);
