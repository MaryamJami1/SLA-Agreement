-- ---------------------------------------------------------------------------
-- Booking Organizer menus: categories, dish library and packages, typed in from the
-- menu card images in menu/ (Rayyan Caterers packages, A.V Caterer dish lists).
--
-- Optional client data, not part of a fresh install: import it after schema.sql + seed.sql
-- (or after migration 014 on an existing database). Then copy the menu card images:
--   cp menu/*/*.jpeg storage/uploads/        (each package names its card by file name)
-- Safe to import twice: rows that already exist are left as they are.
--
-- Generated; to change a menu afterwards, use the Menus page.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

INSERT INTO menu_categories (name, is_active, sort_order) VALUES
('Starter', 1, 10),
('Soups', 1, 20),
('Main Course', 1, 30),
('Rice', 1, 40),
('Qorma, Karahi & Handi', 1, 50),
('Barbecue', 1, 60),
('Sea Food', 1, 70),
('Breads', 1, 80),
('Salads & Chutni', 1, 90),
('Others', 1, 100),
('Dessert', 1, 110),
('Beverages', 1, 120),
('Stalls', 1, 130)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- Starter
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Welcome Drinks' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Fresh Juice' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Mint Lemonade' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Lemon Shots' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Lemonade Punch' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Mint Margarita' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Musambi and Orange Juice' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Fresh Orange Juice' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Mocktail Shots' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Khajoor' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Chicken One Bite Samosa' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Beef One Bite Samosa' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'One Bite Samosa' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Chicken Samosa' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Beef Samosa' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Chicken Vegetable Wonton' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'Chicken Wonton' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Wonton' AS name, 0 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Pizza Pocket' AS name, 0 AS is_live, 190 AS sort_order
  UNION ALL SELECT 'Turkish Puff' AS name, 0 AS is_live, 200 AS sort_order
  UNION ALL SELECT 'Arabian Puff' AS name, 0 AS is_live, 210 AS sort_order
  UNION ALL SELECT 'Pizza Cone' AS name, 0 AS is_live, 220 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Roll' AS name, 0 AS is_live, 230 AS sort_order
  UNION ALL SELECT 'Spring Roll' AS name, 0 AS is_live, 240 AS sort_order
  UNION ALL SELECT 'Vegetable Roll' AS name, 0 AS is_live, 250 AS sort_order
  UNION ALL SELECT 'Cheese Balls' AS name, 0 AS is_live, 260 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Cone' AS name, 0 AS is_live, 270 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Tempura' AS name, 0 AS is_live, 280 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Chiki Miki' AS name, 0 AS is_live, 290 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Strips' AS name, 0 AS is_live, 300 AS sort_order
  UNION ALL SELECT 'Crispy Fried Chicken' AS name, 0 AS is_live, 310 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Tango' AS name, 0 AS is_live, 320 AS sort_order
  UNION ALL SELECT 'Fried Sesame Chicken Bites' AS name, 0 AS is_live, 330 AS sort_order
  UNION ALL SELECT 'Chicken Skewers' AS name, 0 AS is_live, 340 AS sort_order
  UNION ALL SELECT 'Chicken Shaslick Sticks' AS name, 0 AS is_live, 350 AS sort_order
  UNION ALL SELECT 'Hummus with Pita' AS name, 0 AS is_live, 360 AS sort_order
  UNION ALL SELECT 'Korean Chicken' AS name, 0 AS is_live, 370 AS sort_order
  UNION ALL SELECT 'Korean Bites in Shots' AS name, 0 AS is_live, 380 AS sort_order
  UNION ALL SELECT 'Peri Bites' AS name, 0 AS is_live, 390 AS sort_order
  UNION ALL SELECT 'Peri Bite Shots' AS name, 0 AS is_live, 400 AS sort_order
  UNION ALL SELECT 'Dynamite Prawns' AS name, 0 AS is_live, 410 AS sort_order
  UNION ALL SELECT 'Dynamite Chicken' AS name, 0 AS is_live, 420 AS sort_order
  UNION ALL SELECT 'Dynamite Fish Shots' AS name, 0 AS is_live, 430 AS sort_order
  UNION ALL SELECT 'Chicken Wings' AS name, 0 AS is_live, 440 AS sort_order
) d WHERE c.name = 'Starter';

-- Soups
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Chicken Corn Soup with Crackers' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Hot n Sour Soup with Crackers' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Hot & Sour Soup' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Prawn Vegetable Soup' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Cream of Chicken Soup' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Special Soup' AS name, 0 AS is_live, 60 AS sort_order
) d WHERE c.name = 'Soups';

-- Main Course
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Fettuccine Alfredo Pasta' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Beef Chilli Dry' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Chicken Chilli Dry' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Beef Crispy Dry with Garlic Rice' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Chicken Steam' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Chicken Tarragon Steam' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Chicken Shashlik' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Mutton Fried Chops' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Mutton Masala Chops' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Mutton Turkish Chops' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Mutton Chops in White Sauce' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Mutton Iskandria' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Chicken Kabsa' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Lahori Chargha' AS name, 1 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Mix Tarkari' AS name, 0 AS is_live, 150 AS sort_order
) d WHERE c.name = 'Main Course';

-- Rice
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Beef Biryani' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Beef Pulao' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Chicken Pulao' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Chicken White Pulao' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Chicken Raseeli Biryani' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Chicken Raseeli Pulao' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Beef Raseeli Biryani' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Beef White Biryani' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Beef Boneless Biryani' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Dam Chicken Biryani' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Chicken Sindhi Biryani' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Mutton Danda Biryani' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Mutton Dum Biryani' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Allo Biryani' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Zafrani Biryani (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'White Biryani (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Yakhni Pulao (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Afghani Pulao (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 190 AS sort_order
  UNION ALL SELECT 'Hyderabadi Pulao (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 200 AS sort_order
  UNION ALL SELECT 'Kashmiri Pulao (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 210 AS sort_order
  UNION ALL SELECT 'Sindhi Biryani (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 220 AS sort_order
  UNION ALL SELECT 'Bombay Biryani (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 230 AS sort_order
  UNION ALL SELECT 'Masala Biryani (Mutton/Beef/Chicken)' AS name, 0 AS is_live, 240 AS sort_order
) d WHERE c.name = 'Rice';

-- Qorma, Karahi & Handi
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Chicken Karahi' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi (Live)' AS name, 1 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Chicken BBQ Korma' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Chicken Badami Korma' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Chicken Handi (White)' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Chicken Handi (Makhni)' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Chicken Makhni Handi (Boneless)' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Mutton Kunna' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Mutton Kunna Paya' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Mutton Chinyoti Kunna' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Mutton Qorma' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Mutton Badami Qorma' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Mutton Danedar Qorma' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Mutton Dhaba Karahi' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Prawn Peshawari Karahi' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'Prawn Peshawari Handi' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Chicken Koila Karahi' AS name, 0 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Chicken Peshawari Karahi' AS name, 0 AS is_live, 190 AS sort_order
  UNION ALL SELECT 'Chicken Tikka Karahi' AS name, 0 AS is_live, 200 AS sort_order
  UNION ALL SELECT 'Chicken White Karahi' AS name, 0 AS is_live, 210 AS sort_order
  UNION ALL SELECT 'Chicken Green Karahi' AS name, 0 AS is_live, 220 AS sort_order
  UNION ALL SELECT 'Chicken Shahi Karahi' AS name, 0 AS is_live, 230 AS sort_order
  UNION ALL SELECT 'Chicken Afghani Karahi' AS name, 0 AS is_live, 240 AS sort_order
  UNION ALL SELECT 'Chicken Maghzi Katakat Handi' AS name, 0 AS is_live, 250 AS sort_order
  UNION ALL SELECT 'Chicken Shahi Handi' AS name, 0 AS is_live, 260 AS sort_order
  UNION ALL SELECT 'Chicken Paneer Reshmi Handi' AS name, 0 AS is_live, 270 AS sort_order
  UNION ALL SELECT 'Chicken White Handi' AS name, 0 AS is_live, 280 AS sort_order
  UNION ALL SELECT 'Chicken Kashmiri Handi' AS name, 0 AS is_live, 290 AS sort_order
  UNION ALL SELECT 'Hari Mirch Qeema' AS name, 0 AS is_live, 300 AS sort_order
  UNION ALL SELECT 'Mutton Zafrani Qorma' AS name, 0 AS is_live, 310 AS sort_order
  UNION ALL SELECT 'Mutton Aaloo Salan' AS name, 0 AS is_live, 320 AS sort_order
  UNION ALL SELECT 'Mutton Shabdaigh' AS name, 0 AS is_live, 330 AS sort_order
  UNION ALL SELECT 'Mutton Karahi' AS name, 0 AS is_live, 340 AS sort_order
  UNION ALL SELECT 'Mutton Lemon Dasti' AS name, 0 AS is_live, 350 AS sort_order
  UNION ALL SELECT 'Live Mutton Koila Karahi' AS name, 1 AS is_live, 360 AS sort_order
) d WHERE c.name = 'Qorma, Karahi & Handi';

-- Barbecue
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Gola Kabab' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Chandan Kabab' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Chapli Kabab' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Turkish Kabab' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Cocktail Kabab' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Arabic Cheese Kabab' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Cheese Kabab BBQ' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Grilled Adana Kabab' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Hunzai Kabab' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Beef Angara Kabab' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Chicken Afghani Kababs' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Chicken Kabuli Kababs' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Chicken Turkish Kabab' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'BBQ Platter (Afghani Boti, Beef Stick Kabab)' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Barbecue Boti' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'Shahi Chatak Tikka (Leg & Thigh)' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Tikka' AS name, 0 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Chicken Balochi Tikka' AS name, 0 AS is_live, 190 AS sort_order
  UNION ALL SELECT 'Chicken Malai Tikka' AS name, 0 AS is_live, 200 AS sort_order
  UNION ALL SELECT 'Malai Tikka' AS name, 0 AS is_live, 210 AS sort_order
  UNION ALL SELECT 'Lebanese Boti Boneless' AS name, 0 AS is_live, 220 AS sort_order
  UNION ALL SELECT 'Chicken Lebanese Boti' AS name, 0 AS is_live, 230 AS sort_order
  UNION ALL SELECT 'Sheesh Tauk' AS name, 0 AS is_live, 240 AS sort_order
  UNION ALL SELECT 'Shish Taouk Boneless BBQ' AS name, 0 AS is_live, 250 AS sort_order
  UNION ALL SELECT 'Mutton Chops' AS name, 0 AS is_live, 260 AS sort_order
  UNION ALL SELECT 'Mutton Ribs' AS name, 0 AS is_live, 270 AS sort_order
  UNION ALL SELECT 'Mutton Kabab' AS name, 0 AS is_live, 280 AS sort_order
  UNION ALL SELECT 'Beef Kastori Kabab' AS name, 0 AS is_live, 290 AS sort_order
  UNION ALL SELECT 'Chicken Kastori Kabab' AS name, 0 AS is_live, 300 AS sort_order
  UNION ALL SELECT 'Beef Bihari Kabab' AS name, 0 AS is_live, 310 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Kabab' AS name, 0 AS is_live, 320 AS sort_order
  UNION ALL SELECT 'Beef Lebanese Kabab' AS name, 0 AS is_live, 330 AS sort_order
  UNION ALL SELECT 'Chicken Lebanese Kabab' AS name, 0 AS is_live, 340 AS sort_order
  UNION ALL SELECT 'Beef Afghani Kabab' AS name, 0 AS is_live, 350 AS sort_order
  UNION ALL SELECT 'Chicken Afghani Kabab' AS name, 0 AS is_live, 360 AS sort_order
  UNION ALL SELECT 'Chicken Moroccan Kabab' AS name, 0 AS is_live, 370 AS sort_order
  UNION ALL SELECT 'Chicken Reshmi Kabab' AS name, 0 AS is_live, 380 AS sort_order
  UNION ALL SELECT 'Chicken Angara Kabab' AS name, 0 AS is_live, 390 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Kashmiri Kabab' AS name, 0 AS is_live, 400 AS sort_order
  UNION ALL SELECT 'Chicken Silk Kabab' AS name, 0 AS is_live, 410 AS sort_order
  UNION ALL SELECT 'Chicken Dhaaga Kabab' AS name, 0 AS is_live, 420 AS sort_order
  UNION ALL SELECT 'Chicken Gola Kabab' AS name, 0 AS is_live, 430 AS sort_order
  UNION ALL SELECT 'Beef Gola Kabab' AS name, 0 AS is_live, 440 AS sort_order
  UNION ALL SELECT 'Beef Dhaaga Kabab' AS name, 0 AS is_live, 450 AS sort_order
  UNION ALL SELECT 'Chicken Malai Boti' AS name, 0 AS is_live, 460 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Boti' AS name, 0 AS is_live, 470 AS sort_order
  UNION ALL SELECT 'Chicken Achari Boti' AS name, 0 AS is_live, 480 AS sort_order
  UNION ALL SELECT 'Chicken Tikka Boti' AS name, 0 AS is_live, 490 AS sort_order
  UNION ALL SELECT 'Chicken Green Masala Boti' AS name, 0 AS is_live, 500 AS sort_order
  UNION ALL SELECT 'Chicken Tikka' AS name, 0 AS is_live, 510 AS sort_order
  UNION ALL SELECT 'Chicken Achari Tikka' AS name, 0 AS is_live, 520 AS sort_order
  UNION ALL SELECT 'Chicken Reshmi Tikka' AS name, 0 AS is_live, 530 AS sort_order
  UNION ALL SELECT 'Chicken Kastori Tikka' AS name, 0 AS is_live, 540 AS sort_order
  UNION ALL SELECT 'Chicken Moroccan Tikka' AS name, 0 AS is_live, 550 AS sort_order
  UNION ALL SELECT 'Chicken Lebanese Tikka' AS name, 0 AS is_live, 560 AS sort_order
  UNION ALL SELECT 'Chicken Afghani Tikka' AS name, 0 AS is_live, 570 AS sort_order
  UNION ALL SELECT 'Chicken Chops' AS name, 0 AS is_live, 580 AS sort_order
  UNION ALL SELECT 'Chicken Lakhnavi Tikka' AS name, 0 AS is_live, 590 AS sort_order
  UNION ALL SELECT 'Chicken Kashmiri Tikka' AS name, 0 AS is_live, 600 AS sort_order
  UNION ALL SELECT 'Chicken Malai Boti (4 Kg)' AS name, 0 AS is_live, 610 AS sort_order
  UNION ALL SELECT 'Gola / Seekh Kabab (3 Kg)' AS name, 0 AS is_live, 620 AS sort_order
) d WHERE c.name = 'Barbecue';

-- Sea Food
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Fish Fry' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Sweet & Sour Fish' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Fish Tempura' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Tawa Fish' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Fish Biscuits' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Finger Fish' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Grill Fish' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Prawn Tempura' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Prawn Japanese Tempura' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Sweet Thai Chilli Prawn' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Thai Chilli Prawns' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Fire Prawn' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Grill Prawn' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Fried Crumb Prawns' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Tamarind Prawns' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Fish with Tamarind Sauce' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'Fish & Chips' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Surmai Tawa Fried Fish' AS name, 0 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Pomfret Fried Fish' AS name, 0 AS is_live, 190 AS sort_order
) d WHERE c.name = 'Sea Food';

-- Breads
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Milky Naan' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Taftaan' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Sheermal' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Bay Chita' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Kulcha' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Chapati' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Laal Aatay Ki Roti' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Roghni Naan' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Nan' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Shahi Nan' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Garlic Naan' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Garlic Naan (Live)' AS name, 1 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Garlic Naan Tandoor' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Beachita Tandoor' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Assorted Nan' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Paratha' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'Paratha (3 Kg)' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Live Tandoor' AS name, 1 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Live Tandoor & Assorted Naans' AS name, 1 AS is_live, 190 AS sort_order
) d WHERE c.name = 'Breads';

-- Salads & Chutni
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Fresh Salad' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Green Salad' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Salad' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Russian Salad' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Royal Salad' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Salad Cart' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Salad Bar' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Salad Bars' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Fresh Salad Bar' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Russian Salad Bar' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Russian Salad Counter' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Continental Salad Counter' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Continental Salad Bar' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Turkish Salad Bar' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Russian Salad & Fruit Bar' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Raita' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'Zeera Raita' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Imli Ki Chatni' AS name, 0 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Aloo Bukhara Ki Chatni' AS name, 0 AS is_live, 190 AS sort_order
  UNION ALL SELECT 'Chatni' AS name, 0 AS is_live, 200 AS sort_order
) d WHERE c.name = 'Salads & Chutni';

-- Others
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Kachori' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Dahi Phulki' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Banana' AS name, 0 AS is_live, 30 AS sort_order
) d WHERE c.name = 'Others';

-- Dessert
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Gulab Jamun' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Mini Gulab Jamun' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Hot Gulab Jamun' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Hot Mini Gulab Jamuns' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Hot Gulab Jamun Shots' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Cherry Crunch' AS name, 0 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Cream Cocktail' AS name, 0 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Chocolate Cocktail' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Rabri Kheer' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Dessert Bar (8 Items)' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Premium Dessert Bar' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Pista Cream' AS name, 0 AS is_live, 140 AS sort_order
  UNION ALL SELECT 'Turkish Delight' AS name, 0 AS is_live, 150 AS sort_order
  UNION ALL SELECT 'Dodh Dulari' AS name, 0 AS is_live, 160 AS sort_order
  UNION ALL SELECT 'Caramel Pudding' AS name, 0 AS is_live, 170 AS sort_order
  UNION ALL SELECT 'Luki Halwa' AS name, 0 AS is_live, 180 AS sort_order
  UNION ALL SELECT 'Makhanay Ka Halwa' AS name, 0 AS is_live, 190 AS sort_order
  UNION ALL SELECT 'Hot Kaju Malai Halwa' AS name, 0 AS is_live, 200 AS sort_order
  UNION ALL SELECT 'Gajar Rabri Halwa' AS name, 0 AS is_live, 210 AS sort_order
  UNION ALL SELECT 'Gajar Halwa' AS name, 0 AS is_live, 220 AS sort_order
  UNION ALL SELECT 'Garam Gajar Halwa' AS name, 0 AS is_live, 230 AS sort_order
  UNION ALL SELECT 'Gondh Makhana Ka Halwa' AS name, 0 AS is_live, 240 AS sort_order
  UNION ALL SELECT 'Kunafa with Vanilla Ice Cream' AS name, 0 AS is_live, 250 AS sort_order
  UNION ALL SELECT 'Sicilian Ice Cream' AS name, 0 AS is_live, 260 AS sort_order
  UNION ALL SELECT 'Sharifa Ice Cream' AS name, 0 AS is_live, 270 AS sort_order
  UNION ALL SELECT 'Delfrio Ice Cream' AS name, 0 AS is_live, 280 AS sort_order
  UNION ALL SELECT 'Strawberry Cheese Ice Cream' AS name, 0 AS is_live, 290 AS sort_order
  UNION ALL SELECT 'Creamy Chocolate Alaska' AS name, 0 AS is_live, 300 AS sort_order
  UNION ALL SELECT 'Magnum Ice Cream Counter' AS name, 0 AS is_live, 310 AS sort_order
  UNION ALL SELECT 'Stick Kulfi' AS name, 0 AS is_live, 320 AS sort_order
  UNION ALL SELECT 'Mango Delight' AS name, 0 AS is_live, 330 AS sort_order
  UNION ALL SELECT 'Caramel Crunch' AS name, 0 AS is_live, 340 AS sort_order
  UNION ALL SELECT 'Mini Pancakes' AS name, 0 AS is_live, 350 AS sort_order
  UNION ALL SELECT 'Waffles' AS name, 0 AS is_live, 360 AS sort_order
  UNION ALL SELECT 'Live Kunafa' AS name, 1 AS is_live, 370 AS sort_order
  UNION ALL SELECT 'Ice Berg Ice Cream' AS name, 0 AS is_live, 380 AS sort_order
  UNION ALL SELECT 'Live Tawa Ice Cream' AS name, 1 AS is_live, 390 AS sort_order
  UNION ALL SELECT 'Ice Cream Alaska' AS name, 0 AS is_live, 400 AS sort_order
  UNION ALL SELECT 'Ice Cream Slice' AS name, 0 AS is_live, 410 AS sort_order
  UNION ALL SELECT 'Seasonal Ice Cream' AS name, 0 AS is_live, 420 AS sort_order
  UNION ALL SELECT 'Laukey Ka Halwa' AS name, 0 AS is_live, 430 AS sort_order
  UNION ALL SELECT 'Dry Fruit Ka Halwa' AS name, 0 AS is_live, 440 AS sort_order
  UNION ALL SELECT 'Injeer Ka Halwa' AS name, 0 AS is_live, 450 AS sort_order
  UNION ALL SELECT 'Suji Ka Halwa' AS name, 0 AS is_live, 460 AS sort_order
  UNION ALL SELECT 'One Bite Jalebi' AS name, 0 AS is_live, 470 AS sort_order
  UNION ALL SELECT 'One Bite Imarti' AS name, 0 AS is_live, 480 AS sort_order
  UNION ALL SELECT 'Arabian Delight' AS name, 0 AS is_live, 490 AS sort_order
  UNION ALL SELECT 'Rabri' AS name, 0 AS is_live, 500 AS sort_order
  UNION ALL SELECT 'Kheer' AS name, 0 AS is_live, 510 AS sort_order
) d WHERE c.name = 'Dessert';

-- Beverages
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Soft Drinks' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Cold Drinks & Mineral Water' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Roh Afza' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Tea' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Coffee' AS name, 0 AS is_live, 60 AS sort_order
  UNION ALL SELECT 'Kashmiri Chai' AS name, 0 AS is_live, 70 AS sort_order
  UNION ALL SELECT 'Live Karak Tea Counter' AS name, 1 AS is_live, 80 AS sort_order
  UNION ALL SELECT 'Live Tea Counters' AS name, 1 AS is_live, 90 AS sort_order
  UNION ALL SELECT 'Espresso Coffee' AS name, 0 AS is_live, 100 AS sort_order
  UNION ALL SELECT 'Green Tea' AS name, 0 AS is_live, 110 AS sort_order
  UNION ALL SELECT 'Matka Chai' AS name, 0 AS is_live, 120 AS sort_order
  UNION ALL SELECT 'Matka Coffee' AS name, 0 AS is_live, 130 AS sort_order
  UNION ALL SELECT 'Paan' AS name, 0 AS is_live, 140 AS sort_order
) d WHERE c.name = 'Beverages';

-- Stalls
INSERT IGNORE INTO menu_dishes (category_id, name, is_live, sort_order)
SELECT c.id, d.name, d.is_live, d.sort_order FROM menu_categories c JOIN (
            SELECT 'Golgappay' AS name, 0 AS is_live, 10 AS sort_order
  UNION ALL SELECT 'Chana Chaat' AS name, 0 AS is_live, 20 AS sort_order
  UNION ALL SELECT 'Dahi Baray' AS name, 0 AS is_live, 30 AS sort_order
  UNION ALL SELECT 'Halwa Puri' AS name, 0 AS is_live, 40 AS sort_order
  UNION ALL SELECT 'Kachori Bhaji' AS name, 0 AS is_live, 50 AS sort_order
  UNION ALL SELECT 'Bun Kabab' AS name, 0 AS is_live, 60 AS sort_order
) d WHERE c.name = 'Stalls';

INSERT IGNORE INTO menu_packages (name, description, price_basis, per_head_rate, group_price, group_size,
                                  min_guests, card_stored_name, card_mime, is_active, sort_order) VALUES
('Standard Menu 01', 'Standard menu: traditional service featuring classic favourites.', 'guest', NULL, NULL, NULL, NULL, 'standard-menu-01.jpeg', 'image/jpeg', 1, 10),
('Standard Menu 02', 'Standard menu: traditional service featuring classic favourites.', 'guest', NULL, NULL, NULL, NULL, 'standard-menu-02.jpeg', 'image/jpeg', 1, 20),
('Standard Menu 03', 'Standard menu: traditional service featuring classic favourites.', 'guest', NULL, NULL, NULL, NULL, 'standard-menu-03.jpeg', 'image/jpeg', 1, 30),
('Standard Menu 04', 'Standard menu: traditional service featuring classic favourites. Mutton kunna: paya optional.', 'guest', NULL, NULL, NULL, NULL, 'standard-menu-04.jpeg', 'image/jpeg', 1, 40),
('Standard Menu 05', 'Standard menu: traditional service featuring classic favourites.', 'guest', NULL, NULL, NULL, NULL, 'standard-menu-05.jpeg', 'image/jpeg', 1, 50),
('Standard Menu 06', 'Standard menu: traditional service featuring classic favourites.', 'guest', NULL, NULL, NULL, NULL, 'standard-menu-06.jpeg', 'image/jpeg', 1, 60),
('Elite Menu 01', 'Elite per-head menu: event-style menu with flat-rate pricing per guest.', 'guest', 2750, NULL, NULL, NULL, 'elite-menu-01.jpeg', 'image/jpeg', 1, 70),
('Elite Menu 02', 'Elite per-head menu: event-style menu with flat-rate pricing per guest.', 'guest', 2550, NULL, NULL, NULL, 'elite-menu-02.jpeg', 'image/jpeg', 1, 80),
('Elite Menu 04', 'Elite per-head menu: event-style menu with flat-rate pricing per guest.', 'guest', 2850, NULL, NULL, NULL, 'elite-menu-04.jpeg', 'image/jpeg', 1, 90),
('Premium Menu 1', 'Premium menu: gourmet selections featuring regional and international dishes.', 'guest', NULL, NULL, NULL, NULL, 'premium-menu-1.jpeg', 'image/jpeg', 1, 100),
('Premium Menu 2', 'Premium menu: gourmet selections featuring regional and international dishes.', 'guest', NULL, NULL, NULL, NULL, 'premium-menu-2.jpeg', 'image/jpeg', 1, 110),
('Premium Menu 3', 'Premium menu: gourmet selections featuring regional and international dishes.', 'guest', NULL, NULL, NULL, NULL, 'premium-menu-3.jpeg', 'image/jpeg', 1, 120),
('Premium Menu 4', 'Premium menu: gourmet selections featuring regional and international dishes.', 'guest', NULL, NULL, NULL, NULL, 'premium-menu-4.jpeg', 'image/jpeg', 1, 130),
('Premium Menu 5', 'Premium menu: gourmet selections featuring regional and international dishes.', 'guest', NULL, NULL, NULL, NULL, 'premium-menu-5.jpeg', 'image/jpeg', 1, 140),
('Premium Menu 6', 'Premium menu: gourmet selections featuring regional and international dishes.', 'guest', NULL, NULL, NULL, NULL, 'premium-menu-6.jpeg', 'image/jpeg', 1, 150),
('Special Wedding Menu No. 1', 'Welcome drinks and gulab jamun are free.', 'guest', 385, NULL, NULL, 250, 'wedding-menu-01.jpeg', 'image/jpeg', 1, 160),
('Special Wedding Menu No. 2', 'Welcome drinks and gulab jamun are free.', 'guest', 410, NULL, NULL, 250, 'wedding-menu-02.jpeg', 'image/jpeg', 1, 170),
('Special Wedding Menu No. 3', 'Welcome drinks and gulab jamun are free.', 'guest', 425, NULL, NULL, 250, 'wedding-menu-03.jpeg', 'image/jpeg', 1, 180),
('Special Wedding Menu No. 4', 'Welcome drinks and gulab jamun are free.', 'guest', 450, NULL, NULL, 250, 'wedding-menu-04.jpeg', 'image/jpeg', 1, 190),
('Special Wedding Menu No. 5', 'Welcome drinks and gulab jamun are free.', 'guest', 500, NULL, NULL, 250, 'wedding-menu-05.jpeg', 'image/jpeg', 1, 200),
('Special Wedding Menu No. 6', 'Welcome drinks and gulab jamun are free.', 'guest', 525, NULL, NULL, 250, 'wedding-menu-06.jpeg', 'image/jpeg', 1, 210),
('Special Wedding Menu No. 7', 'Welcome drinks and gulab jamun are free.', 'guest', 550, NULL, NULL, 250, 'wedding-menu-07.jpeg', 'image/jpeg', 1, 220),
('Special Wedding Menu No. 8', 'Welcome drinks and gulab jamun are free.', 'guest', 575, NULL, NULL, 250, 'wedding-menu-08.jpeg', 'image/jpeg', 1, 230),
('Special Wedding Menu No. 9', 'Welcome drinks and gulab jamun are free.', 'guest', 600, NULL, NULL, 250, 'wedding-menu-09.jpeg', 'image/jpeg', 1, 240),
('Special Wedding Menu No. 10', 'Welcome drinks and gulab jamun are free.', 'guest', 700, NULL, NULL, 250, 'wedding-menu-10.jpeg', 'image/jpeg', 1, 250),
('Special Wedding Menu No. 11', 'Welcome drinks and gulab jamun are free.', 'guest', 750, NULL, NULL, 250, 'wedding-menu-11.jpeg', 'image/jpeg', 1, 260),
('Special Wedding Menu No. 12', 'Welcome drinks and gulab jamun are free.', 'guest', 750, NULL, NULL, 250, 'wedding-menu-12.jpeg', 'image/jpeg', 1, 270),
('Special Wedding Menu No. 13', 'Welcome drinks and gulab jamun are free.', 'guest', 900, NULL, NULL, 250, 'wedding-menu-13.jpeg', 'image/jpeg', 1, 280),
('Special Wedding Menu No. 14', 'Welcome drinks and gulab jamun are free.', 'guest', 1200, NULL, NULL, 250, 'wedding-menu-14.jpeg', 'image/jpeg', 1, 290),
('Special Mehndi Menu No. 1', 'Dahi phulki is free.', 'guest', 350, NULL, NULL, 100, 'mehndi-menu-1.jpeg', 'image/jpeg', 1, 300),
('Special Mehndi Menu No. 2', 'Dahi phulki is free.', 'guest', 400, NULL, NULL, 100, 'mehndi-menu-2.jpeg', 'image/jpeg', 1, 310),
('Special Mehndi Menu No. 3', 'Dahi phulki is free.', 'guest', 450, NULL, NULL, 100, 'mehndi-menu-3.jpeg', 'image/jpeg', 1, 320),
('Small Party Menu No. 1', 'Price for 100 persons.', 'group', 400.00, 40000, 100, NULL, 'small-party-menu-1.jpeg', 'image/jpeg', 1, 330),
('Small Party Menu No. 2', 'Price for 100 persons.', 'group', 520.00, 52000, 100, NULL, 'small-party-menu-2.jpeg', 'image/jpeg', 1, 340),
('Small Party Menu No. 3', 'Price for 50 persons.', 'group', 540.00, 27000, 50, NULL, 'small-party-menu-3.jpeg', 'image/jpeg', 1, 350),
('Small Party Menu No. 4', 'Rs 11,000 for 15-20 persons. Delivery charges included; online payment only.', 'group', 550.00, 11000, 20, NULL, 'small-party-menu-4.jpeg', 'image/jpeg', 1, 360),
('Iftar + Dinner Deal #1', 'Price for 100 persons. Delivery charges as per distance.', 'group', 600.00, 60000, 100, NULL, 'iftar-dinner-deal-1.jpeg', 'image/jpeg', 1, 370),
('Iftar + Dinner Deal #2', 'Price for 100 persons. Delivery charges as per distance.', 'group', 500.00, 50000, 100, NULL, 'iftar-dinner-deal-2.jpeg', 'image/jpeg', 1, 380),
('Iftar Box Deal #1', 'Minimum 50 boxes. Delivery charges as per distance.', 'box', 300, NULL, NULL, 50, 'iftar-box-deals.jpeg', 'image/jpeg', 1, 390),
('Iftar Box Deal #2', 'Minimum 50 boxes. Delivery charges as per distance.', 'box', 250, NULL, NULL, 50, 'iftar-box-deals.jpeg', 'image/jpeg', 1, 400),
('Iftar Box Deal #3', 'Minimum 50 boxes. Delivery charges as per distance.', 'box', 200, NULL, NULL, 50, 'iftar-box-deals.jpeg', 'image/jpeg', 1, 410);

-- Dishes in each package. choice_group: dishes with the same number are alternatives (pick one).
-- Standard Menu 01
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, 1 AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Mint Lemonade' AS dish, 1 AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, 2 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Beef Pulao' AS dish, 2 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 3 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 3 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Arabic Cheese Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Wonton' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Taftaan' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Zeera Raita' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Cream Cocktail' AS dish, 4 AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Rabri Kheer' AS dish, 4 AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Soft Drinks' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Tea' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Standard Menu 01'
  JOIN menu_dishes d ON d.name = i.dish;

-- Standard Menu 02
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, 1 AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Mint Lemonade' AS dish, 1 AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, 2 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Beef Pulao' AS dish, 2 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 3 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 3 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Karahi (Live)' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Lahori Chargha' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Pizza Cone' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'One Bite Samosa' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Garlic Naan (Live)' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Taftaan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Zeera Raita' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Russian Salad' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Cherry Crunch' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Mini Gulab Jamun' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Soft Drinks' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
  UNION ALL SELECT 'Tea' AS dish, NULL AS choice_group, 0 AS is_free, 160 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Standard Menu 02'
  JOIN menu_dishes d ON d.name = i.dish;

-- Standard Menu 03
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Beef Pulao' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi (Live)' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Wonton' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Taftaan' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Zeera Raita' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Rabri Kheer' AS dish, 2 AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Mini Gulab Jamun' AS dish, 2 AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Soft Drinks' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Standard Menu 03'
  JOIN menu_dishes d ON d.name = i.dish;

-- Standard Menu 04
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'One Bite Samosa' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Pulao' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mutton Kunna' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Steam' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Chapli Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Spring Roll' AS dish, 2 AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Cheese Balls' AS dish, 2 AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Garlic Naan' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Taftaan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Zeera Raita' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Cherry Crunch' AS dish, 3 AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, 3 AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Soft Drinks' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Tea' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Standard Menu 04'
  JOIN menu_dishes d ON d.name = i.dish;

-- Standard Menu 05
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Dynamite Chicken' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Raseeli Biryani' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Raseeli Pulao' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken BBQ Korma' AS dish, 2 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Badami Korma' AS dish, 2 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Turkish Kabab' AS dish, 3 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Cocktail Kabab' AS dish, 3 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Shahi Chatak Tikka (Leg & Thigh)' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Wonton' AS dish, 4 AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Cheese Balls' AS dish, 4 AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Garlic Naan' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Taftaan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Zeera Raita' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, 5 AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, 5 AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Soft Drinks' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Tea' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Standard Menu 05'
  JOIN menu_dishes d ON d.name = i.dish;

-- Standard Menu 06
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, 1 AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Wonton' AS dish, 1 AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Raseeli Biryani' AS dish, 2 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Raseeli Pulao' AS dish, 2 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Handi (White)' AS dish, 3 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Handi (Makhni)' AS dish, 3 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Strips' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chandan Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Taftaan' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Zeera Raita' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, 4 AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Chocolate Cocktail' AS dish, 4 AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Soft Drinks' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Standard Menu 06'
  JOIN menu_dishes d ON d.name = i.dish;

-- Elite Menu 01
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Lemonade Punch' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Hot & Sour Soup' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Skewers' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Beef Chilli Dry' AS dish, 1 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Chilli Dry' AS dish, 1 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Shashlik' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Fish with Tamarind Sauce' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Mutton Badami Qorma' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Sheesh Tauk' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Mutton Fried Chops' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Live Tandoor & Assorted Naans' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Russian Salad Counter' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Luki Halwa' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Sicilian Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Kashmiri Chai' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Live Karak Tea Counter' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
  UNION ALL SELECT 'Cold Drinks & Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 160 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Elite Menu 01'
  JOIN menu_dishes d ON d.name = i.dish;

-- Elite Menu 02
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fried Sesame Chicken Bites' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Musambi and Orange Juice' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Mutton Kunna' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Dam Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Karahi (Live)' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Fish & Chips' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Barbecue Boti' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Chicken Afghani Kababs' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Live Tandoor & Assorted Naans' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Continental Salad Counter' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Makhanay Ka Halwa' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Caramel Pudding' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Live Tea Counters' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Kashmiri Chai' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Espresso Coffee' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Elite Menu 02'
  JOIN menu_dishes d ON d.name = i.dish;

-- Elite Menu 04
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Mint Margarita' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Lemonade Punch' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Cream of Chicken Soup' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Fettuccine Alfredo Pasta' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Tamarind Prawns' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Mutton Kunna Paya' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Mutton Danda Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Chicken Kabuli Kababs' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Chicken Lebanese Boti' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Live Tandoor & Assorted Naans' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Salad Bars' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Magnum Ice Cream Counter' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Hot Mini Gulab Jamuns' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Live Tea Counters' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Cold Drinks & Mineral Water' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Elite Menu 04'
  JOIN menu_dishes d ON d.name = i.dish;

-- Premium Menu 1
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'BBQ Platter (Afghani Boti, Beef Stick Kabab)' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Mocktail Shots' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Continental Salad Bar' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Prawn Japanese Tempura' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Mutton Chops in White Sauce' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Grilled Adana Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Mutton Danedar Qorma' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Beef Raseeli Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Shahi Nan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Beachita Tandoor' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Live Tandoor & Assorted Naans' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Salad Bars' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Hot Kaju Malai Halwa' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Sharifa Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Premium Menu 1'
  JOIN menu_dishes d ON d.name = i.dish;

-- Premium Menu 2
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Peri Bite Shots' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Russian Salad Bar' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Beef White Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Mutton Dhaba Karahi' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Malai Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Chicken Turkish Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Thai Chilli Prawns' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Assorted Nan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Live Tandoor & Assorted Naans' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Salad Bars' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Gajar Rabri Halwa' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Delfrio Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Premium Menu 2'
  JOIN menu_dishes d ON d.name = i.dish;

-- Premium Menu 3
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Korean Bites in Shots' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Fresh Orange Juice' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Turkish Salad Bar' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mutton Iskandria' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Surmai Tawa Fried Fish' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Lebanese Boti Boneless' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Hunzai Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Prawn Peshawari Karahi' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Mutton Dum Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Shahi Nan' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Beachita Tandoor' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Live Tandoor & Assorted Naans' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Salad Bars' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Premium Dessert Bar' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Hot Gulab Jamun Shots' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Premium Menu 3'
  JOIN menu_dishes d ON d.name = i.dish;

-- Premium Menu 4
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Dynamite Chicken' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Hot n Sour Soup with Crackers' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Fresh Salad Bar' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Fish & Chips' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Beef Angara Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Chicken Karahi (Live)' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Chicken Sindhi Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Shahi Nan' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Garlic Naan Tandoor' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Strawberry Cheese Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Creamy Chocolate Alaska' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Premium Menu 4'
  JOIN menu_dishes d ON d.name = i.dish;

-- Premium Menu 5
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Dynamite Fish Shots' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Corn Soup with Crackers' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Russian Salad & Fruit Bar' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mutton Masala Chops' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Thai Chilli Prawns' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Lebanese Boti Boneless' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Mutton Chinyoti Kunna' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Beef Boneless Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Shahi Nan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Garlic Naan Tandoor' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Gondh Makhana Ka Halwa' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Stick Kulfi' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Garam Gajar Halwa' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Premium Menu 5'
  JOIN menu_dishes d ON d.name = i.dish;

-- Premium Menu 6
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Special Soup' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Hummus with Pita' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Shaslick Sticks' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mutton Turkish Chops' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Pomfret Fried Fish' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Chicken Tarragon Steam' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Shish Taouk Boneless BBQ' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Cheese Kabab BBQ' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Prawn Peshawari Handi' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Mutton Danedar Qorma' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Chicken Kabsa' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Beef Crispy Dry with Garlic Rice' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Shahi Nan' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Garlic Naan Tandoor' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Kunafa with Vanilla Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
  UNION ALL SELECT 'Premium Dessert Bar' AS dish, NULL AS choice_group, 0 AS is_free, 160 AS sort_order
  UNION ALL SELECT 'Gajar Halwa' AS dish, NULL AS choice_group, 0 AS is_free, 170 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Premium Menu 6'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 1
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 90 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 1'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 2
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 90 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 2'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 3
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Wonton' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 3'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 4
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Wonton' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 4'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 5
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Gola Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 100 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 5'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 6
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 100 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 6'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 7
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Gola Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Arabian Puff' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 7'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 8
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Arabian Puff' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 8'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 9
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Gola Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 9'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 10
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Gola Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Arabian Puff' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Dessert Bar (8 Items)' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 130 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 10'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 11
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Gola Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Strips' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Pista Cream' AS dish, 3 AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Turkish Delight' AS dish, 3 AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 130 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 11'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 12
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Mutton Kunna' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mutton Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Bihari Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Gola Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Pista Cream' AS dish, 3 AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Turkish Delight' AS dish, 3 AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 120 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 12'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 13
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Welcome Drinks' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Makhni Handi (Boneless)' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Balochi Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chandan Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Strips' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Pista Cream' AS dish, 1 AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Turkish Delight' AS dish, 1 AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Dessert Bar (8 Items)' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Ice Cream' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 140 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 13'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Wedding Menu No. 14
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Fresh Juice' AS dish, NULL AS choice_group, 1 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Peri Bites' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Karahi (Live)' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Malai Tikka' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Fish Biscuits' AS dish, 1 AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Chicken Cheese Strips' AS dish, 1 AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Turkish Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Mutton Kunna' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Live Tandoor' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Salad Bar' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 130 AS sort_order
  UNION ALL SELECT 'Dodh Dulari' AS dish, NULL AS choice_group, 0 AS is_free, 140 AS sort_order
  UNION ALL SELECT 'Dessert Bar (8 Items)' AS dish, NULL AS choice_group, 0 AS is_free, 150 AS sort_order
  UNION ALL SELECT 'Tea' AS dish, 2 AS choice_group, 0 AS is_free, 160 AS sort_order
  UNION ALL SELECT 'Coffee' AS dish, 2 AS choice_group, 0 AS is_free, 160 AS sort_order
  UNION ALL SELECT 'Gulab Jamun' AS dish, NULL AS choice_group, 1 AS is_free, 170 AS sort_order
  UNION ALL SELECT 'Paan' AS dish, NULL AS choice_group, 0 AS is_free, 180 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Wedding Menu No. 14'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Mehndi Menu No. 1
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Gola Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Paratha' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Kachori' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mix Tarkari' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Dahi Phulki' AS dish, NULL AS choice_group, 1 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Hot Gulab Jamun' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Chatni' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Mehndi Menu No. 1'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Mehndi Menu No. 2
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Chandan Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Paratha' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Kachori' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mix Tarkari' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Dahi Phulki' AS dish, NULL AS choice_group, 1 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Hot Gulab Jamun' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Chatni' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Mehndi Menu No. 2'
  JOIN menu_dishes d ON d.name = i.dish;

-- Special Mehndi Menu No. 3
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Gola Kabab' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken White Pulao' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Paratha' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Kachori' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Mix Tarkari' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Dahi Phulki' AS dish, NULL AS choice_group, 1 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Hot Gulab Jamun' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Fresh Salad' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Chatni' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Special Mehndi Menu No. 3'
  JOIN menu_dishes d ON d.name = i.dish;

-- Small Party Menu No. 1
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Salad' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Small Party Menu No. 1'
  JOIN menu_dishes d ON d.name = i.dish;

-- Small Party Menu No. 2
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Gola Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Seekh Kabab' AS dish, 2 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Salad' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Small Party Menu No. 2'
  JOIN menu_dishes d ON d.name = i.dish;

-- Small Party Menu No. 3
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Beef Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chicken Qorma' AS dish, 1 AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Milky Naan' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Salad' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Small Party Menu No. 3'
  JOIN menu_dishes d ON d.name = i.dish;

-- Small Party Menu No. 4
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Chicken Malai Boti (4 Kg)' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Gola / Seekh Kabab (3 Kg)' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Paratha (3 Kg)' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Salad' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chatni' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Small Party Menu No. 4'
  JOIN menu_dishes d ON d.name = i.dish;

-- Iftar + Dinner Deal #1
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Khajoor' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Roh Afza' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Chana Chaat' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Vegetable Roll' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Dahi Baray' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Nan' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 110 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 120 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Iftar + Dinner Deal #1'
  JOIN menu_dishes d ON d.name = i.dish;

-- Iftar + Dinner Deal #2
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Khajoor' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Roh Afza' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Vegetable Roll' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Chicken Karahi' AS dish, NULL AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Lab-e-Shireen' AS dish, NULL AS choice_group, 0 AS is_free, 60 AS sort_order
  UNION ALL SELECT 'Nan' AS dish, NULL AS choice_group, 0 AS is_free, 70 AS sort_order
  UNION ALL SELECT 'Kulcha' AS dish, NULL AS choice_group, 0 AS is_free, 80 AS sort_order
  UNION ALL SELECT 'Green Salad' AS dish, NULL AS choice_group, 0 AS is_free, 90 AS sort_order
  UNION ALL SELECT 'Raita' AS dish, NULL AS choice_group, 0 AS is_free, 100 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Iftar + Dinner Deal #2'
  JOIN menu_dishes d ON d.name = i.dish;

-- Iftar Box Deal #1
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Khajoor' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Vegetable Roll' AS dish, NULL AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Banana' AS dish, NULL AS choice_group, 0 AS is_free, 40 AS sort_order
  UNION ALL SELECT 'Fresh Juice' AS dish, 1 AS choice_group, 0 AS is_free, 50 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, 1 AS choice_group, 0 AS is_free, 50 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Iftar Box Deal #1'
  JOIN menu_dishes d ON d.name = i.dish;

-- Iftar Box Deal #2
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Khajoor' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Chicken Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Fresh Juice' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Iftar Box Deal #2'
  JOIN menu_dishes d ON d.name = i.dish;

-- Iftar Box Deal #3
INSERT IGNORE INTO menu_package_items (package_id, dish_id, choice_group, is_free, sort_order)
SELECT p.id, d.id, i.choice_group, i.is_free, i.sort_order FROM (
            SELECT 'Khajoor' AS dish, NULL AS choice_group, 0 AS is_free, 10 AS sort_order
  UNION ALL SELECT 'Allo Biryani' AS dish, NULL AS choice_group, 0 AS is_free, 20 AS sort_order
  UNION ALL SELECT 'Fresh Juice' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
  UNION ALL SELECT 'Mineral Water' AS dish, 1 AS choice_group, 0 AS is_free, 30 AS sort_order
) i
  JOIN menu_packages p ON p.name = 'Iftar Box Deal #3'
  JOIN menu_dishes d ON d.name = i.dish;
