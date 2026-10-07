#!/bin/bash
# Menus end-to-end checks (Menus page, booking form, documents) over HTTP. Local only. Setup:
#   create database aomess_e2e, import database/schema.sql + seed.sql + menu_data.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e_menus.sh   (drop aomess_e2e afterwards)
# E2E_BASE and E2E_DB point it at another server / database.
B=${E2E_BASE:-http://127.0.0.1:8093}
PHP=/c/xampp/php/php.exe
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3307 -N ${E2E_DB:-aomess_e2e}"
T=$(mktemp -d)
# Windows curl can't open /tmp paths: convert for -F file=@
W() { cygpath -w "$T/$1"; }
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1  -- $2"; }
req() { local jar=$1 m=$2 p=$3; shift 3
  local args=(-s -b "$T/$jar" -c "$T/$jar" -o "$T/body" -D "$T/hdr" -w "%{http_code}")
  if [ "$m" = POST ]; then for d in "$@"; do args+=(--data-urlencode "$d"); done; fi
  CODE=$(curl "${args[@]}" -X "$m" "$B$p")
  LOC=$(grep -i '^location:' "$T/hdr" | tr -d '\r' | cut -d' ' -f2); }
upload() { local jar=$1 p=$2; shift 2
  local args=(-s -b "$T/$jar" -c "$T/$jar" -o "$T/body" -D "$T/hdr" -w "%{http_code}")
  for d in "$@"; do args+=(-F "$d"); done
  CODE=$(curl "${args[@]}" "$B$p"); LOC=$(grep -i '^location:' "$T/hdr" | tr -d '\r' | cut -d' ' -f2); }
csrf() { req "$1" GET "$2"; TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$T/body" | head -1 | sed 's/.*value="//; s/"//'); }
expect() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], expected [$2]"; fi; }
contains() { if grep -q -- "$1" "$T/body"; then ok "$2"; else bad "$2" "body lacks [$1]"; fi; }
lacks() { if grep -q -- "$1" "$T/body"; then bad "$2" "body has [$1]"; else ok "$2"; fi; }
login() { csrf "$1" /auth/login.php; req "$1" POST /auth/login.php "_csrf=$TOKEN" "username=$2" "password=$3"; }

HASH=$($PHP -r 'echo password_hash("Passw0rd-e2e", PASSWORD_DEFAULT);')
$MYSQL -e "UPDATE users SET password_hash='$HASH', must_change_password=0 WHERE username='admin';
  INSERT INTO users (username,password_hash,role,name,firm_name,rep_name,contact,status) VALUES
  ('menuuser','$HASH','user','Menu User','Menu Caterers','Menu User','0300-2222222','active');"
WEDDING=$($MYSQL -e "SELECT id FROM menu_packages WHERE name='Special Wedding Menu No. 8'")
QORMA=$($MYSQL -e "SELECT i.id FROM menu_package_items i JOIN menu_dishes d ON d.id = i.dish_id WHERE i.package_id=$WEDDING AND d.name='Chicken Qorma'")
FISH=$($MYSQL -e "SELECT id FROM menu_dishes WHERE name='Fish Fry'")
TEA=$($MYSQL -e "SELECT id FROM menu_dishes WHERE name='Tea'")
# A 1x1 PNG for the menu card, and a PDF that must be refused.
$PHP -r 'file_put_contents($argv[1], base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="));' "$(W card.png)"
printf '%%PDF-1.4 test' > "$T/card.pdf"

login a admin Passw0rd-e2e
login v menuuser Passw0rd-e2e

echo "== Menus page"
req v GET /admin/menus.php;          expect "$CODE" "404" "a user cannot open the Menus page"
csrf a /admin/menus.php;             expect "$CODE" "200" "admin opens the Menus page"
contains "Special Wedding Menu No. 8" "imported package listed"
contains "= Rs. 520 per guest" "group price worked out per guest"
contains "Chicken Karahi or Chicken Qorma" "choice group reads as alternatives"
contains "Welcome Drinks (Free)" "free dish marked"
req a POST /admin/menus.php "_csrf=$TOKEN" "action=package_create" "name=E2E Hi-Tea" "per_head_rate=1,200" "min_guests=40" "sort_order=30"
NEW=$($MYSQL -e "SELECT id FROM menu_packages WHERE name='E2E Hi-Tea'")
expect "$CODE $LOC" "303 /admin/menus.php#package-$NEW" "package created, back at it"
req a POST /admin/menus.php "_csrf=$TOKEN" "action=item_add" "item_id=$NEW" "dish_id=$TEA" "choice_group=" "sort_order=10"
expect "$($MYSQL -e "SELECT COUNT(*) FROM menu_package_items WHERE package_id=$NEW")" "1" "dish added to the package"
req a POST /admin/menus.php "_csrf=$TOKEN" "action=item_add" "item_id=$NEW" "dish_id=$TEA"
req a GET /admin/menus.php;          contains "already in this package" "the same dish twice is refused"
upload a /admin/menus.php "_csrf=$TOKEN" "action=card_upload" "item_id=$NEW" "card=@$(W card.pdf);type=application/pdf"
req a GET /admin/menus.php;          contains "must be a JPG or PNG" "a PDF menu card is refused"
upload a /admin/menus.php "_csrf=$TOKEN" "action=card_upload" "item_id=$NEW" "card=@$(W card.png);type=image/png"
expect "$CODE" "303" "menu card uploaded"
req v GET "/menu/card.php?id=$NEW";  expect "$CODE" "200" "a user can see a package's menu card"
grep -qi '^content-type: image/png' "$T/hdr" && ok "menu card served as PNG" || bad "menu card type" "$(grep -i content-type "$T/hdr")"
NOCARD=$($MYSQL -e "SELECT id FROM menu_packages WHERE card_stored_name IS NULL AND id <> $NEW LIMIT 1")
[ -n "$NOCARD" ] || NOCARD=999999
req v GET "/menu/card.php?id=$NOCARD"; expect "$CODE" "404" "a package without a card has none"

FISH_CAT=$($MYSQL -e "SELECT category_id FROM menu_dishes WHERE id=$FISH")
csrf a /admin/menus.php
req a POST /admin/menus.php "_csrf=$TOKEN" "action=dish_update" "item_id=$FISH" "name=Fish Fry" "category_id=$FISH_CAT"   "extra_rate=150" "extra_unit=per head" "is_active=1" "sort_order=10"
expect "$($MYSQL -e "SELECT CONCAT(extra_rate, '|', extra_unit) FROM menu_dishes WHERE id=$FISH")" "150.00|per head" "admin prices an extra dish"

echo "== Booking form"
csrf v /booking/form.php
contains 'name="menu_package_id"' "menu package dropdown on the user's form"
contains "E2E Hi-Tea" "new package offered"
contains "Small Party Menu No. 2 — Rs. 52,000 for 100 guests" "group package priced for the group"
contains "Iftar Box Deal #1 — Rs. 300 per box" "box package priced per box"
contains "· Rs. 150 per guest" "extra dish shows its rate"
contains 'name="menu_pick\[' "choice groups offered"
contains "View menu card" "menu card linked"
req v POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Menu E2E" "guests=200" \
  "menu_present=1" "menu_package_id=$WEDDING" "menu_pick[$WEDDING][1]=999999"
expect "$CODE" "422" "a pick that isn't in the group is refused"
contains "choose one of Chicken Karahi / Chicken Qorma" "says which group needs a pick"
req v POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Menu E2E" "guests=200" \
  "menu_present=1" "menu_package_id=$WEDDING" "menu_pick[$WEDDING][1]=$QORMA" "menu_extra[]=$FISH" "food_items=No onions in the raita"
expect "$CODE" "303" "user saves a booking with a package"
ID=${LOC##*=}
expect "$($MYSQL -e "SELECT CONCAT(per_head_rate, '|', menu_package_name) FROM bookings WHERE id=$ID")" "575.00|Special Wedding Menu No. 8" "package rate and name on the booking"
expect "$($MYSQL -e "SELECT charges_total FROM bookings WHERE id=$ID")" "30000.00" "extra dish charged: Rs 150 x 200 guests"
req v GET "/booking/form.php?id=$ID"
contains "Menu on this booking: Special Wedding Menu No. 8" "saved menu shown on the form"
contains "This package is for at least 250 guests; the booking has 200." "minimum guests warning"
grep -q "value=\"$QORMA\" checked" "$T/body" && ok "the pick is ticked again" || bad "pick ticked" "radio not checked"
grep -Eq "value=\"$FISH\"[^>]* checked" "$T/body" && ok "the extra dish is ticked again" || bad "extra ticked" "checkbox not checked"
contains "menu-extra-rates" "extra dish listed with its rate on the booking"
lacks "name=\"menu_extra_rate\[" "a user gets no rate box for extras"

echo "== Documents"
req v GET "/documents/agreement.php?id=$ID"; expect "$CODE" "200" "agreement opens"
contains "Menu package" "agreement shows the package"
contains "Chicken Qorma" "agreement lists the picked dish"
contains "Welcome Drinks (Free), Arabian Puff" "agreement lists the starters"
contains "Fish Fry" "agreement lists the extra dish"
contains "Menu notes" "food items print as menu notes"
req a GET "/documents/ops_sheet.php?id=$ID"; expect "$CODE" "200" "ops sheet opens"
contains "Welcome Drinks (Free)" "ops sheet lists the menu"
req a GET "/documents/invoice.php?id=$ID"; contains "Special Wedding Menu No. 8" "invoice names the package"

echo
echo "$PASS passed, $FAIL failed"
rm -rf "$T"
[ $FAIL -eq 0 ]
