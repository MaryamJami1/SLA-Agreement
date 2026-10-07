-- Vendor services & rates (from refrences-files/vendorservices.txt).
-- Additive and safe to re-run: skips any name already in the catalog; existing items are untouched.
INSERT INTO item_catalog (section, name, unit, default_rate, sort_order)
SELECT 'ops_item', v.name, v.unit, v.rate, v.ord FROM (
  SELECT 'BASIC' AS name, 'fixed' AS unit, 300 AS rate, 1010 AS ord
  UNION ALL
  SELECT 'LIGHTS' AS name, 'fixed' AS unit, 40000 AS rate, 1020 AS ord
  UNION ALL
  SELECT 'SOUND' AS name, 'fixed' AS unit, 8000 AS rate, 1030 AS ord
  UNION ALL
  SELECT 'LOUNGES' AS name, 'fixed' AS unit, 5000 AS rate, 1040 AS ord
  UNION ALL
  SELECT 'HEAD TABLE (14/14)' AS name, 'fixed' AS unit, 16000 AS rate, 1050 AS ord
  UNION ALL
  SELECT 'SERVICES' AS name, 'fixed' AS unit, 8000 AS rate, 1060 AS ord
  UNION ALL
  SELECT 'PANELLING (450 RFT)' AS name, 'fixed' AS unit, 40000 AS rate, 1070 AS ord
  UNION ALL
  SELECT 'VALET' AS name, 'fixed' AS unit, 5000 AS rate, 1080 AS ord
  UNION ALL
  SELECT 'FLOWER WORK (100,000 / 150,000)' AS name, 'fixed' AS unit, 100000 AS rate, 1090 AS ord
  UNION ALL
  SELECT 'TRASSING 30' AS name, 'fixed' AS unit, 30000 AS rate, 1100 AS ord
  UNION ALL
  SELECT 'WELCOME BOARD' AS name, 'fixed' AS unit, 1500 AS rate, 1110 AS ord
  UNION ALL
  SELECT 'WALKWAY' AS name, 'fixed' AS unit, 30000 AS rate, 1120 AS ord
  UNION ALL
  SELECT 'PECOCK' AS name, 'fixed' AS unit, 20000 AS rate, 1130 AS ord
  UNION ALL
  SELECT 'FOUNTAIN' AS name, 'fixed' AS unit, 20000 AS rate, 1140 AS ord
  UNION ALL
  SELECT 'CONSOUL' AS name, 'per unit' AS unit, 2000 AS rate, 1150 AS ord
  UNION ALL
  SELECT 'WOODEN TABLE' AS name, 'per unit' AS unit, 2000 AS rate, 1160 AS ord
  UNION ALL
  SELECT 'WOODEN CHAIR' AS name, 'per unit' AS unit, 250 AS rate, 1170 AS ord
  UNION ALL
  SELECT 'FANCY WOODEN' AS name, 'per unit' AS unit, 300 AS rate, 1180 AS ord
  UNION ALL
  SELECT 'SS CHAIR' AS name, 'per unit' AS unit, 250 AS rate, 1190 AS ord
  UNION ALL
  SELECT 'DONUT TABLE' AS name, 'per unit' AS unit, 8000 AS rate, 1200 AS ord
  UNION ALL
  SELECT 'OVAL TABLE' AS name, 'per unit' AS unit, 6000 AS rate, 1210 AS ord
  UNION ALL
  SELECT 'ELECTRIC ANNAR (MIN 10)' AS name, 'per unit' AS unit, 1500 AS rate, 1220 AS ord
  UNION ALL
  SELECT 'FIRE GUN' AS name, 'per unit' AS unit, 6000 AS rate, 1230 AS ord
  UNION ALL
  SELECT 'SMOKE' AS name, 'fixed' AS unit, 6000 AS rate, 1240 AS ord
  UNION ALL
  SELECT 'FOCUS LIGHT' AS name, 'fixed' AS unit, 6000 AS rate, 1250 AS ord
  UNION ALL
  SELECT 'PLY SINGLE STAGE' AS name, 'fixed' AS unit, 800 AS rate, 1260 AS ord
  UNION ALL
  SELECT 'STAGE' AS name, 'fixed' AS unit, NULL AS rate, 1270 AS ord
  UNION ALL
  SELECT 'LOBY' AS name, 'fixed' AS unit, NULL AS rate, 1280 AS ord
) v WHERE NOT EXISTS (SELECT 1 FROM item_catalog c WHERE c.name = v.name);
