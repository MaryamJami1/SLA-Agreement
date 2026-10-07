#!/bin/bash
# Phase 10 end-to-end checks (vendor management) over HTTP. Local only. Setup:
#   create database aomess_e2e, import database/schema.sql + seed.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e_vendors.sh   (drop aomess_e2e afterwards)
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
q() { $MYSQL -e "$1"; }
TODAY=$(date +%Y-%m-%d)
inv_money() { q "SELECT CONCAT(grand_total,'|',paid_total,'|',balance) FROM vendor_invoices WHERE id=$1"; }
# vpay ID AMOUNT : fetch a fresh invoice page, then record a payment
vpay() { csrf a "/vendors/invoice.php?id=$1"
  req a POST /vendors/save.php "_csrf=$TOKEN" "form_token=$FORM_TOKEN" "invoice_id=$1" "action=add_payment" \
    "amount=$2" "paid_on=$TODAY" "method=bank_transfer" "bank_name=HBL" "reference_no=TX-$RANDOM" "notes=installment"; }

HASH=$($PHP -r 'echo password_hash("Passw0rd-e2e", PASSWORD_DEFAULT);')
q "UPDATE users SET password_hash='$HASH', must_change_password=0 WHERE username='admin';
  INSERT INTO users (username,password_hash,role,name,firm_name,rep_name,contact,status) VALUES
  ('uzair','$HASH','user','Uzair Khan','Uzair Caterers','Uzair Khan','0312-2159834','active');"
USER_ID=$(q "SELECT id FROM users WHERE username='uzair'")
LAWN_A=$(q "SELECT id FROM venues WHERE name='Lawn A'")
CATERING=$(q "SELECT id FROM vendor_categories WHERE name='Catering'")
login v uzair Passw0rd-e2e
login a admin Passw0rd-e2e

echo "== Categories"
req a GET /admin/vendor_categories.php; expect "$CODE" "200" "categories page opens"; contains "Sound &amp; Lighting" "seeded categories listed"
csrf a /admin/vendor_categories.php
req a POST /admin/vendor_categories.php "_csrf=$TOKEN" "action=create" "name=Florists" "sort_order=110"
expect "$(q "SELECT COUNT(*) FROM vendor_categories WHERE name='Florists'")" "1" "category created"
req a POST /admin/vendor_categories.php "_csrf=$TOKEN" "action=create" "name=Florists" "sort_order=0"
req a GET /admin/vendor_categories.php; contains "already a category called" "duplicate category refused"
FLOR=$(q "SELECT id FROM vendor_categories WHERE name='Florists'")
csrf a /admin/vendor_categories.php
req a POST /admin/vendor_categories.php "_csrf=$TOKEN" "action=delete" "category_id=$FLOR"
expect "$(q "SELECT COUNT(*) FROM vendor_categories WHERE name='Florists'")" "0" "unused category deleted"

echo "== Vendor and services"
req a GET /admin/vendors.php; expect "$CODE" "200" "vendor list opens"; contains "No vendors yet" "empty vendor list"
csrf a /admin/vendors.php
req a POST /admin/vendors.php "_csrf=$TOKEN" "is_active=1" "name=ABC Caterers" "category_id=$CATERING" "contact_person=Imran" \
  "phone=0300-1112223" "email=abc@example.com" "address=Shahrah-e-Faisal, Karachi"
VID=$(q "SELECT id FROM vendors WHERE name='ABC Caterers'")
expect "$LOC" "/admin/vendor.php?id=$VID" "creating a vendor opens its page"
csrf a /admin/vendors.php
req a POST /admin/vendors.php "_csrf=$TOKEN" "is_active=1" "name=Bad Mail" "category_id=$CATERING" "email=not-an-email"
contains "valid email" "invalid email refused"; contains 'value="Bad Mail"' "typed vendor kept on the form"
csrf a "/admin/vendor.php?id=$VID"
for s in "Buffet|per person|2,500" "Tea|per person|300" "Dessert|per person|400" "Live Cooking|fixed|60000"; do
  IFS='|' read -r n u r <<< "$s"
  req a POST "/admin/vendor.php?id=$VID" "_csrf=$TOKEN" "action=service_create" "is_active=1" "name=$n" "unit=$u" "rate=$r" "sort_order=0"
done
expect "$(q "SELECT COUNT(*) FROM vendor_services WHERE vendor_id=$VID")" "4" "four services added"
expect "$(q "SELECT rate FROM vendor_services WHERE vendor_id=$VID AND name='Buffet'")" "2500.00" "Buffet rate stored"
req a POST "/admin/vendor.php?id=$VID" "_csrf=$TOKEN" "action=service_create" "is_active=1" "name=Tea" "unit=fixed" "rate=1" "sort_order=0"
req a GET "/admin/vendor.php?id=$VID"; contains "already has a service called" "duplicate service refused"
BUFFET=$(q "SELECT id FROM vendor_services WHERE vendor_id=$VID AND name='Buffet'")
TEA=$(q "SELECT id FROM vendor_services WHERE vendor_id=$VID AND name='Tea'")
DESSERT=$(q "SELECT id FROM vendor_services WHERE vendor_id=$VID AND name='Dessert'")
LIVE=$(q "SELECT id FROM vendor_services WHERE vendor_id=$VID AND name='Live Cooking'")

echo "== Booking"
csrf a /booking/form.php
req a POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Vendor Client" "event_type=Barat" "event_date=2027-11-20" \
  "venue_id=$LAWN_A" "guests=300" "per_head_rate=3000" "user_id=$USER_ID"
ID=${LOC##*=}
req a GET "/booking/form.php?id=$ID"; contains 'id="vendors"' "admin sees the Vendors section"; contains "Assign a vendor service" "assign form shown"
contains 'data-guests="300"' "guest count handed to the picker"

echo "== Assign services (300 × 2,500 = 7,50,000)"
csrf a "/booking/form.php?id=$ID"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$BUFFET" "qty=300" "rate="
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$TEA" "qty=300" "rate=300"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$DESSERT" "qty=300" "rate=400"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$LIVE" "qty=1" "rate=" "notes=to be removed"
expect "$(q "SELECT amount FROM booking_vendor_items WHERE booking_id=$ID AND vendor_service_id=$BUFFET")" "750000.00" "Buffet amount = 7,50,000"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$BUFFET" "qty=0" "rate="
req a GET "/booking/form.php?id=$ID"; contains "Quantity: Enter a quantity of 1 or more." "zero quantity refused"
LIVE_LINE=$(q "SELECT id FROM booking_vendor_items WHERE booking_id=$ID AND vendor_service_id=$LIVE")
csrf a "/booking/form.php?id=$ID"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=remove_line" "line_id=$LIVE_LINE"
expect "$(q "SELECT COUNT(*) FROM booking_vendor_items WHERE booking_id=$ID")" "3" "un-invoiced line removed"
req a GET "/booking/form.php?id=$ID"; contains "Rs. 9,60,000" "vendor total on the booking"

echo "== User never sees vendors"
req v GET "/booking/form.php?id=$ID"; expect "$CODE" "200" "user can open the booking"
lacks 'id="vendors"' "no Vendors section for a user"; lacks "ABC Caterers" "no vendor names for a user"
req v GET /admin/vendors.php; expect "$CODE" "404" "user: vendor list 404"
req v GET "/admin/vendor.php?id=$VID"; expect "$CODE" "404" "user: vendor page 404"
csrf v "/booking/form.php?id=$ID"
req v POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$TEA" "qty=1"
expect "$CODE" "404" "user POST to assign: 404"
expect "$(q "SELECT COUNT(*) FROM booking_vendor_items WHERE booking_id=$ID")" "3" "nothing written by the user"

echo "== Generate invoice"
csrf a "/booking/form.php?id=$ID"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=generate_invoice" "vendor_id=$VID" "discount=2000000" "tax_pct="
req a GET "/booking/form.php?id=$ID"; contains "discount can" "discount above the sub total refused"
expect "$(q "SELECT COUNT(*) FROM vendor_invoices")" "0" "no invoice written"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=generate_invoice" "vendor_id=$VID" "discount=" "tax_pct=" "notes=Advance on signing"
INV=$(q "SELECT id FROM vendor_invoices WHERE booking_id=$ID")
expect "$LOC" "/vendors/invoice.php?id=$INV" "generating opens the invoice"
expect "$(q "SELECT invoice_no FROM vendor_invoices WHERE id=$INV")" "VINV-$(date +%Y)-0001" "invoice number"
expect "$(inv_money $INV)" "960000.00|0.00|960000.00" "invoice total 9,60,000, nothing paid"
expect "$(q "SELECT COUNT(*) FROM vendor_invoice_items WHERE vendor_invoice_id=$INV")" "3" "items copied"
expect "$(q "SELECT COUNT(*) FROM booking_vendor_items WHERE booking_id=$ID AND vendor_invoice_id=$INV")" "3" "lines linked to the invoice"
req a GET "/vendors/invoice.php?id=$INV"; expect "$CODE" "200" "invoice page opens"
contains "Vendor Invoice" "invoice heading"; contains "Print / Save as PDF" "print button"; contains "UNPAID" "status: unpaid"
contains "Imran" "vendor contact on the invoice"; contains "Nine Lakh Sixty Thousand Rupees Only" "total in words"
BUF_LINE=$(q "SELECT id FROM booking_vendor_items WHERE booking_id=$ID AND vendor_service_id=$BUFFET")
csrf a "/booking/form.php?id=$ID"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=remove_line" "line_id=$BUF_LINE"
req a GET "/booking/form.php?id=$ID"; contains "on an issued invoice" "invoiced line can't be removed"

echo "== History stays put when the vendor changes"
csrf a "/admin/vendor.php?id=$VID"
req a POST "/admin/vendor.php?id=$VID" "_csrf=$TOKEN" "action=service_update" "service_id=$BUFFET" "name=Grand Buffet" "unit=per person" \
  "rate=3000" "sort_order=0" "is_active=1"
req a POST "/admin/vendor.php?id=$VID" "_csrf=$TOKEN" "action=update" "name=ABC Caterers & Co" "category_id=$CATERING" "contact_person=Imran" \
  "phone=0300-9999999" "is_active=1"
expect "$(q "SELECT CONCAT(label,'|',rate) FROM vendor_invoice_items WHERE vendor_invoice_id=$INV ORDER BY id LIMIT 1")" "Buffet|2500.00" "invoice keeps the old service name and rate"
expect "$(q "SELECT CONCAT(vendor_name,'|',vendor_phone) FROM vendor_invoices WHERE id=$INV")" "ABC Caterers|0300-1112223" "invoice keeps the old vendor details"
req a GET "/admin/vendor.php?id=$VID"; lacks "Delete this vendor" "a used vendor can't be deleted"; contains "used on 1 event line" "service usage shown"

echo "== Payments: 5,00,000 of 9,60,000"
vpay $INV "5,00,000"
expect "$(inv_money $INV)" "960000.00|500000.00|460000.00" "paid 5,00,000, remaining 4,60,000"
req a GET "/vendors/invoice.php?id=$INV"; contains "PARTIALLY PAID" "status: partially paid"; contains "Rs. 4,60,000" "remaining shown"
csrf a "/vendors/invoice.php?id=$INV"; SAVED_FT=$FORM_TOKEN; SAVED_CSRF=$TOKEN
req a POST /vendors/save.php "_csrf=$SAVED_CSRF" "form_token=$SAVED_FT" "invoice_id=$INV" "action=add_payment" "amount=1000" "paid_on=$TODAY" "method=cash"
req a POST /vendors/save.php "_csrf=$SAVED_CSRF" "form_token=$SAVED_FT" "invoice_id=$INV" "action=add_payment" "amount=1000" "paid_on=$TODAY" "method=cash"
expect "$(q "SELECT COUNT(*) FROM vendor_payments WHERE vendor_invoice_id=$INV")" "2" "the same form submitted twice records once"
SMALL=$(q "SELECT id FROM vendor_payments WHERE vendor_invoice_id=$INV AND amount=1000")
csrf a "/vendors/invoice.php?id=$INV"
req a POST /vendors/save.php "_csrf=$TOKEN" "invoice_id=$INV" "action=void_payment" "payment_id=$SMALL" "reason=typed twice"
expect "$(inv_money $INV)" "960000.00|500000.00|460000.00" "void restores the remaining amount"
csrf a "/vendors/invoice.php?id=$INV"
req a POST /vendors/save.php "_csrf=$TOKEN" "form_token=$FORM_TOKEN" "invoice_id=$INV" "action=add_payment" "amount=500" "paid_on=$TODAY" "method=cheque"
req a GET "/vendors/invoice.php?id=$INV"; contains "Cheque number: required" "cheque needs a number"; contains 'value="500"' "typed amount kept"
csrf a "/vendors/invoice.php?id=$INV"
req a POST /vendors/save.php "_csrf=$TOKEN" "invoice_id=$INV" "action=void_invoice" "reason=wrong"
req a GET "/vendors/invoice.php?id=$INV"; contains "has payments" "invoice with payments can't be voided"
vpay $INV "460000"
expect "$(inv_money $INV)" "960000.00|960000.00|0.00" "fully paid"
req a GET "/vendors/invoice.php?id=$INV"; contains ">PAID<" "status: paid"
req a GET "/admin/vendor.php?id=$VID"; contains "Rs. 9,60,000" "vendor page totals"; contains "VINV-" "vendor page lists the invoice"
req a GET /admin/vendors.php; contains "ABC Caterers &amp; Co" "vendor in the list"

echo "== Void an invoice and re-issue"
csrf a "/booking/form.php?id=$ID"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$LIVE" "qty=1" "rate=50000"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=generate_invoice" "vendor_id=$VID" "discount=5000" "tax_pct=16"
INV2=$(q "SELECT id FROM vendor_invoices WHERE booking_id=$ID ORDER BY id DESC LIMIT 1")
expect "$(q "SELECT CONCAT(sub_total,'|',tax_amount,'|',grand_total) FROM vendor_invoices WHERE id=$INV2")" "50000.00|7200.00|52200.00" "discount then 16% tax"
expect "$(q "SELECT invoice_no FROM vendor_invoices WHERE id=$INV2")" "VINV-$(date +%Y)-0002" "second invoice number"
csrf a "/vendors/invoice.php?id=$INV2"
req a POST /vendors/save.php "_csrf=$TOKEN" "invoice_id=$INV2" "action=void_invoice" "reason=forgot the decor"
expect "$(q "SELECT status FROM vendor_invoices WHERE id=$INV2")" "void" "invoice voided"
expect "$(q "SELECT COUNT(*) FROM booking_vendor_items WHERE booking_id=$ID AND vendor_invoice_id IS NULL")" "1" "its line released"
expect "$(q "SELECT COUNT(*) FROM vendor_invoice_items WHERE vendor_invoice_id=$INV2")" "1" "voided invoice keeps its items"
req a GET "/vendors/invoice.php?id=$INV2"; contains ">VOID<" "void watermark"; lacks "Record a payment" "no payments on a void invoice"

echo "== Draft with a vendor invoice can't be deleted"
csrf a /booking/form.php
req a POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Draft Client" "event_date=2027-10-10" "venue_id=$LAWN_A" \
  "guests=10" "user_id=$USER_ID"
DID=${LOC##*=}
csrf a "/booking/form.php?id=$DID"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$DID" "action=add_line" "service_id=$TEA" "qty=10" "rate="
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$DID" "action=generate_invoice" "vendor_id=$VID"
csrf a "/booking/form.php?id=$DID"
req a POST /booking/delete.php "_csrf=$TOKEN" "id=$DID" "version=$(q "SELECT version FROM bookings WHERE id=$DID")"
expect "$(q "SELECT COUNT(*) FROM bookings WHERE id=$DID")" "1" "draft with a vendor invoice kept"
req a GET "/booking/form.php?id=$DID"; contains "vendor invoices" "refusal explained"

echo "== Cancelled booking"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/cancel.php "_csrf=$TOKEN" "id=$ID" "version=$(q "SELECT version FROM bookings WHERE id=$ID")" "reason=client withdrew"
csrf a "/booking/form.php?id=$ID"
req a POST /vendors/save.php "_csrf=$TOKEN" "booking_id=$ID" "action=add_line" "service_id=$TEA" "qty=1"
req a GET "/booking/form.php?id=$ID"; contains "can&#039;t be added to a cancelled booking" "no new lines on a cancelled booking"

echo "== Audit"
q "SELECT action, COUNT(*) FROM audit_log WHERE action LIKE 'vendor%' GROUP BY action"
req a GET /admin/dashboard.php; expect "$CODE" "200" "dashboard still opens with vendor activity"

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ $FAIL -eq 0 ]
