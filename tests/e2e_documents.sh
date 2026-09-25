#!/bin/bash
# Phase 6 end-to-end checks (agreement, invoice, operations sheet) over HTTP. Local only. Setup:
#   create database aomess_e2e, import database/schema.sql + seed.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e_documents.sh   (drop aomess_e2e afterwards)
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
csrf() { req "$1" GET "$2"; TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$T/body" | head -1 | sed 's/.*value="//; s/"//')
         FORM_TOKEN=$(grep -o 'name="form_token" value="[a-f0-9]*"' "$T/body" | head -1 | sed 's/.*value="//; s/"//'); }
expect() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], expected [$2]"; fi; }
contains() { if grep -q -- "$1" "$T/body"; then ok "$2"; else bad "$2" "body lacks [$1]"; fi; }
lacks() { if grep -q -- "$1" "$T/body"; then bad "$2" "body has [$1]"; else ok "$2"; fi; }
login() { csrf "$1" /auth/login.php; req "$1" POST /auth/login.php "_csrf=$TOKEN" "username=$2" "password=$3"; }
ver() { $MYSQL -e "SELECT version FROM bookings WHERE id=$1"; }
TODAY=$(date +%Y-%m-%d)

HASH=$($PHP -r 'echo password_hash("Passw0rd-e2e", PASSWORD_DEFAULT);')
$MYSQL -e "UPDATE users SET password_hash='$HASH', must_change_password=0 WHERE username='admin';
  INSERT INTO users (username,password_hash,role,name,firm_name,rep_name,contact,status) VALUES
  ('uzair','$HASH','vendor','Uzair Khan','Uzair Caterers','Uzair Khan','0312-2159834','active'),
  ('bilal','$HASH','vendor','Bilal Ahmed','Bilal Events','Bilal Ahmed','0300-1111111','active');"
VENDOR_ID=$($MYSQL -e "SELECT id FROM users WHERE username='uzair'")
LAWN_A=$($MYSQL -e "SELECT id FROM venues WHERE name='Lawn A'")
VENUE_CHARGE=$($MYSQL -e "SELECT id FROM item_catalog WHERE name='Venue Charges'")
TRACING=$($MYSQL -e "SELECT id FROM item_catalog WHERE section='charge' AND unit='per unit' LIMIT 1")
LED=$($MYSQL -e "SELECT id FROM item_catalog WHERE name='LED'")
SOFA=$($MYSQL -e "SELECT id FROM item_catalog WHERE section='ops_item' AND name='Sofa'")
login v uzair Passw0rd-e2e
login o bilal Passw0rd-e2e
login a admin Passw0rd-e2e

FORM=( "client_name=Ayesha Siddiqui" "client_relation=D/o Muhammad Siddiqui" "client_cnic=42101-1234567-1"
       "client_contact=0333-1234567" "client_address=12-C, Khayaban-e-Shahbaz, DHA Phase 6, Karachi"
       "event_type=Valima" "event_date=2027-02-14" "venue_id=$LAWN_A" "guests=200" "per_head_rate=1500"
       "setup_time=16:00" "start_time=19:30" "menu_type=Buffet" "food_items=Mutton Karahi
Chicken Biryani" "theme=Ivory and gold" "stage=Fabric" "agreement_day=14th" "agreement_month=September, 2026"
       "discount=5000" "refund_pct_30=50" "refund_pct_7=25" "special_commitments=Dedicated event coordinator on site"
       "vendor_id=$VENDOR_ID"
       "firm_name=Uzair Caterers" "rep_name=Uzair Khan" "rep_contact=0312-2159834"
       "lines[c$VENUE_CHARGE][present]=1" "lines[c$VENUE_CHARGE][selected]=1" "lines[c$VENUE_CHARGE][rate]=50000"
       "lines[c$TRACING][present]=1" "lines[c$TRACING][selected]=1" "lines[c$TRACING][rate]=300" "lines[c$TRACING][qty]=10"
       "lines[c$LED][present]=1" "lines[c$LED][selected]=1" "lines[c$LED][notes]=warm white"
       "lines[c$SOFA][present]=1" "lines[c$SOFA][selected]=1" "lines[c$SOFA][qty]=2" )
csrf a /booking/form.php
req a POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "${FORM[@]}"
ID=${LOC##*=}
YEAR=$(date +%Y)

echo "== Draft documents (watermark)"
req a GET "/booking/form.php?id=$ID"; contains "SLA agreement" "documents linked from the booking page"
req a GET "/documents/agreement.php?id=$ID"; expect "$CODE" "200" "agreement opens"
contains '<div class="watermark">DRAFT</div>' "draft agreement prints a DRAFT watermark"
contains "Agreement No." "agreement number shown"
contains "class=\"page-table\"" "letterhead wrapper present (repeats on printed pages)"
contains "<thead>" "letterhead sits in the table head"
contains "letterhead-logo" "letterhead logo"
req a GET "/documents/invoice.php?id=$ID"; contains '<div class="watermark">DRAFT</div>' "draft invoice watermarked"
contains "not issued yet" "unconfirmed invoice has no invoice date"

echo "== Confirmed agreement"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)"
req a GET "/documents/agreement.php?id=$ID"
lacks "watermark" "confirmed agreement has no watermark"
contains "SLA-$YEAR-0001" "agreement number"
contains "Ayesha Siddiqui" "client name"
contains "D/o Muhammad Siddiqui" "client relation"
contains "42101-1234567-1" "CNIC on the agreement"
contains "Uzair Caterers" "vendor firm"
contains "14 Feb 2027" "event date"
contains "(Sunday)" "day of the week calculated"
contains "Lawn A" "venue"
contains "7:30 pm" "start time"
contains "Rs. 3,00,000" "guest charges 1,500 x 200"
contains "Rs. 50,000" "venue charge line"
contains "Rs. 3,000" "per-unit tracing line 10 x 300"
contains "Rs. 3,48,000" "net amount after the Rs. 5,000 discount"
contains "Three Lakh Forty Eight Thousand Rupees Only" "net amount in words"
contains "warm white" "decor checklist note"
contains "50%" "refund policy 30 days"
contains "25%" "refund policy 7-30 days"
contains "Dedicated event coordinator" "special commitments"
contains "pending legal review" "legal review note (open question 1)"
contains "14th" "agreement day as typed"
contains "September, 2026" "agreement month as typed"
lacks "supersedes" "Rev 0 prints no revision suffix"

echo "== Invoice"
req a GET "/documents/invoice.php?id=$ID"
contains "INV-$YEAR-0001" "invoice number derived from the SLA number"
INVDATE=$($MYSQL -e "SELECT DATE_FORMAT(confirmed_at,'%d %b %Y') FROM bookings WHERE id=$ID")
contains "$INVDATE" "invoice date is the confirmation date"
contains "Billed To" "billed-to box"; contains "Event Reference" "event reference box"
contains "Catering, decoration &amp; event management services — Valima (Buffet menu) at Lawn A on 14 Feb 2027." "description line"
contains "No payments recorded against this invoice yet." "no payments yet"
contains "Balance due" "balance line"
contains "Three Lakh Forty Eight Thousand Rupees Only" "balance in words"
contains "Authorized Signatory" "signature lines"

echo "== Invoice with payments"
csrf a "/booking/form.php?id=$ID"
req a POST /payments/add.php "_csrf=$TOKEN" "form_token=$FORM_TOKEN" "booking_id=$ID" "kind=payment" "amount=1,00,000" "paid_on=$TODAY" "method=cheque" "bank_name=HBL" "reference_no=CHQ-4471"
req a GET "/documents/invoice.php?id=$ID"
contains "Payments received" "payments table"
contains "HBL CHQ-4471" "payment reference"
contains "Cheque" "payment method"
contains "Total received" "total received"
contains "Rs. 2,48,000" "balance after the payment"
contains "Two Lakh Forty Eight Thousand Rupees Only" "balance in words after the payment"

echo "== Operations sheet"
req a GET "/documents/vendor_sheet.php?id=$ID"
contains "Operations Sheet" "ops sheet opens"
contains "No. of PAX" "PAX row (from the reference sheet)"
contains "Sofa" "ops item listed"
contains "Internal working copy" "internal note"
contains "Mutton Karahi" "catering items"
lacks "42101-1234567-1" "CNIC not printed on the internal ops sheet"

echo "== Amendment: Rev 1 on both documents (checkpoint)"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)" "${FORM[@]/guests=200/guests=250}" "amend_reason=Client added 50 guests"
req a GET "/documents/agreement.php?id=$ID"
contains "SLA-$YEAR-0001 Rev 1" "agreement prints the Rev 1 number"
contains "supersedes Rev 0" "amended agreement supersedes the previous revision"
contains "Client added 50 guests" "amendment reason printed"
contains "Rs. 3,75,000" "guest charges recomputed for 250 guests"
req a GET "/documents/invoice.php?id=$ID"
contains "INV-$YEAR-0001 Rev 1" "invoice prints the Rev 1 number"
contains "$INVDATE" "invoice date still the confirmation date"
contains "Revised" "revision date printed beside it"

echo "== Cancelled booking"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/cancel.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)" "reason=Client postponed"
req a GET "/documents/invoice.php?id=$ID"
contains '<div class="watermark">CANCELLED</div>' "cancelled invoice watermarked"
contains "Amount retained" "amount retained instead of a balance"
contains "Nothing further is payable" "no balance due on a cancelled booking"

echo "== Access"
req v GET "/documents/agreement.php?id=$ID"; expect "$CODE" "404" "documents are admin-only: owner vendor gets 404 on the agreement"
req v GET "/documents/vendor_sheet.php?id=$ID"; expect "$CODE" "404" "documents are admin-only: owner vendor gets 404 on the ops sheet"
req o GET "/documents/agreement.php?id=$ID"; expect "$CODE" "404" "another vendor: 404 on the agreement"
req o GET "/documents/invoice.php?id=$ID"; expect "$CODE" "404" "another vendor: 404 on the invoice"
req o GET "/documents/vendor_sheet.php?id=$ID"; expect "$CODE" "404" "another vendor: 404 on the ops sheet"
req a GET "/documents/invoice.php?id=999999"; expect "$CODE" "404" "missing booking: 404"

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ $FAIL -eq 0 ]
