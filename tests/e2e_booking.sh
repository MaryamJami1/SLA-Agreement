#!/bin/bash
# Phase 3 end-to-end checks (booking form + save) over HTTP. Local only. Setup:
#   create database aomess_e2e, import database/schema.sql + seed.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e_booking.sh   (drop aomess_e2e afterwards)
B=http://127.0.0.1:8093
PHP=/c/xampp/php/php.exe
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3307 -N aomess_e2e"
T=$(mktemp -d)
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1  -- $2"; }
req() { local jar=$1 m=$2 p=$3; shift 3
  local args=(-s -b "$T/$jar" -c "$T/$jar" -o "$T/body" -D "$T/hdr" -w "%{http_code}")
  if [ "$m" = POST ]; then for d in "$@"; do args+=(--data-urlencode "$d"); done; fi
  CODE=$(curl "${args[@]}" -X "$m" "$B$p")
  LOC=$(grep -i '^location:' "$T/hdr" | tr -d '\r' | cut -d' ' -f2); }
csrf() { req "$1" GET "$2"; TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$T/body" | head -1 | sed 's/.*value="//; s/"//'); }
field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$T/body" | head -1 | sed 's/.*value="//; s/"$//'; }
expect() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], expected [$2]"; fi; }
contains() { if grep -q -- "$1" "$T/body"; then ok "$2"; else bad "$2" "body lacks [$1]"; fi; }
lacks() { if grep -q -- "$1" "$T/body"; then bad "$2" "body has [$1]"; else ok "$2"; fi; }
login() { csrf "$1" /auth/login.php; req "$1" POST /auth/login.php "_csrf=$TOKEN" "username=$2" "password=$3"; }

# Accounts: admin (password already changed) and two active vendors.
HASH=$($PHP -r 'echo password_hash("Passw0rd-e2e", PASSWORD_DEFAULT);')
$MYSQL -e "UPDATE users SET password_hash='$HASH', must_change_password=0 WHERE username='admin';
  INSERT INTO users (username,password_hash,role,name,firm_name,rep_name,contact,status) VALUES
  ('uzair','$HASH','vendor','Uzair Khan','Uzair Caterers','Uzair Khan','0312-2159834','active'),
  ('bilal','$HASH','vendor','Bilal Ahmed','Bilal Events','Bilal Ahmed','0300-1111111','active');"
LAWN_A=$($MYSQL -e "SELECT id FROM venues WHERE name='Lawn A'")
VENUE_CHARGE=$($MYSQL -e "SELECT id FROM item_catalog WHERE name='Venue Charges'")
TRACING=$($MYSQL -e "SELECT id FROM item_catalog WHERE section='charge' AND unit='per unit' LIMIT 1")
LED=$($MYSQL -e "SELECT id FROM item_catalog WHERE name='LED'")

echo "== Create (vendor)"
login v uzair Passw0rd-e2e
csrf v /booking/form.php
contains "assigned on first save" "new form has no SLA number yet"
contains "Uzair Caterers" "vendor snapshot pre-filled from the profile"
req v POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Ayesha Siddiqui" "client_cnic=4210112345671" \
  "event_type=Valima" "event_date=2026-12-20" "venue_id=$LAWN_A" "guests=200" "per_head_rate=1,500" "discount=5000" \
  "lines[c$VENUE_CHARGE][present]=1" "lines[c$VENUE_CHARGE][selected]=1" "lines[c$VENUE_CHARGE][rate]=50,000" \
  "lines[c$TRACING][present]=1" "lines[c$TRACING][selected]=1" "lines[c$TRACING][rate]=300" "lines[c$TRACING][qty]=10" \
  "lines[c$LED][present]=1" "lines[c$LED][selected]=1" "lines[c$LED][notes]=warm white"
case "$LOC" in /booking/form.php\?id=*) ok "save redirects to the saved booking (Post/Redirect/Get)";; *) bad "PRG redirect" "[$CODE $LOC]";; esac
ID=${LOC##*=}
YEAR=$(date +%Y)
req v GET "/booking/form.php?id=$ID"
contains "Booking created: SLA-$YEAR-0001" "first booking is SLA-$YEAR-0001"
contains "ID: SLA-$YEAR-0001" "reopened booking shows its SLA number"
contains 'value="Ayesha Siddiqui"' "reopened booking shows the client"
contains 'value="42101-1234567-1"' "CNIC saved in #####-#######-# form"
contains "Rs. 3,00,000" "guest charges 1,500 × 200 = Rs. 3,00,000"
contains "Rs. 3,48,000" "net amount 3,00,000 + 50,000 + 3,000 − 5,000 = Rs. 3,48,000"
contains 'name="lines\[c' "catalog items not yet on the booking are offered"
V1=$(field version); expect "$V1" "1" "version 1 after create"
ROWS=$($MYSQL -e "SELECT COUNT(*) FROM bookings"); expect "$ROWS" "1" "exactly one booking row (refresh can't duplicate: the POST was redirected)"

echo "== Edit, and a stale version from a second tab"
csrf v /booking/form.php?id=$ID; TAB1=$TOKEN
LINE=$($MYSQL -e "SELECT id FROM booking_line_items WHERE booking_id=$ID AND label='Venue Charges'")
req v POST /booking/save.php "_csrf=$TAB1" "id=$ID" "version=1" "client_name=Ayesha Siddiqui" "event_type=Valima" "event_date=2026-12-20" \
  "venue_id=$LAWN_A" "guests=250" "per_head_rate=1500" "lines[l$LINE][present]=1" "lines[l$LINE][selected]=1" "lines[l$LINE][rate]=50000"
expect "$CODE" "303" "tab 1 saves (version 1 → 2)"
req v GET "/booking/form.php?id=$ID"; expect "$(field version)" "2" "version is now 2"
contains "Rs. 3,75,000" "guests 250 recomputed the guest charges"
req v POST /booking/save.php "_csrf=$TAB1" "id=$ID" "version=1" "client_name=Tab Two Change" "guests=999"
expect "$CODE" "409" "tab 2 (still version 1) is rejected"
contains "changed by someone else" "conflict message shown"
contains 'value="Tab Two Change"' "the rejected input is kept on screen"
DB=$($MYSQL -e "SELECT CONCAT(client_name,'|',guests,'|',version) FROM bookings WHERE id=$ID")
expect "$DB" "Ayesha Siddiqui|250|2" "stale save changed nothing"

echo "== Validation keeps the input"
req v POST /booking/save.php "_csrf=$TAB1" "id=$ID" "version=2" "client_name=Ayesha Siddiqui" "guests=-5" "per_head_rate=abc" "event_date=2026-02-30"
expect "$CODE" "422" "invalid values rejected"
contains "Estimated guests: Enter a whole number" "guests error shown"
contains "Per-head rate: Enter an amount" "rate error shown"
contains "Date of event: enter a valid date" "date error shown"
contains 'value="-5"' "typed value redisplayed"
expect "$($MYSQL -e "SELECT version FROM bookings WHERE id=$ID")" "2" "nothing written"

echo "== Access rules"
login o bilal Passw0rd-e2e
req o GET "/booking/form.php?id=$ID";  expect "$CODE" "404" "another vendor gets 404 on the form"
csrf o /booking/form.php
req o POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=2" "client_name=Hijack"; expect "$CODE" "404" "another vendor gets 404 on save"
req o GET /booking/list.php; lacks "Ayesha" "another vendor's registry doesn't list it"
req o GET "/booking/form.php?id=999999"; expect "$CODE" "404" "missing booking is the same 404"
login a admin Passw0rd-e2e
req a GET "/booking/form.php?id=$ID"; expect "$CODE" "200" "admin opens any booking"
contains "Uzair Caterers — Uzair Khan (uzair)" "admin sees the vendor dropdown"
req a GET /booking/list.php; contains "SLA-$YEAR-0001" "admin registry lists the booking"

echo "== Venue warning (drafts only warn)"
csrf a /booking/form.php
req a POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Second Client" "event_date=2026-12-20" "venue_id=$LAWN_A"
ID2=${LOC##*=}
req a GET "/booking/form.php?id=$ID2"
contains "Lawn A also has another draft booking on 20 Dec 2026 (SLA-$YEAR-0001)" "admin sees the clash with the SLA number"
contains "SLA-$YEAR-0002" "second booking saved despite the clash (drafts never block)"
req v GET "/booking/form.php?id=$ID"
contains "Lawn A also has another draft booking on 20 Dec 2026." "vendor sees the clash"
lacks "(SLA-$YEAR-0002)" "vendor isn't shown the other booking's number"

echo "== Confirmed bookings are read-only in Phase 3"
$MYSQL -e "UPDATE bookings SET status='confirmed' WHERE id=$ID"
req v GET "/booking/form.php?id=$ID"; contains "is confirmed and can no longer be edited" "vendor sees a read-only confirmed booking"
lacks "Save Changes" "no save button"
csrf v "/booking/form.php?id=$ID"
req v POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=2" "client_name=Late change"; expect "$CODE" "404" "vendor save on a confirmed booking: 404"

echo "== Audit"
$MYSQL -e "SELECT action, COUNT(*) FROM audit_log WHERE booking_id IS NOT NULL GROUP BY action"

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ $FAIL -eq 0 ]
