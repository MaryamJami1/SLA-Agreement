#!/bin/bash
# Phase 5 end-to-end checks (payments and refunds) over HTTP. Local only. Setup:
#   create database aomess_e2e, import database/schema.sql + seed.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e_payments.sh   (drop aomess_e2e afterwards)
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
money() { $MYSQL -e "SELECT CONCAT(paid_total,'|',balance) FROM bookings WHERE id=$1"; }
count() { $MYSQL -e "SELECT COUNT(*) FROM payments WHERE booking_id=$1"; }
TODAY=$(date +%Y-%m-%d)
# pay JAR ID AMOUNT [KIND] [METHOD] : fetch a fresh form, then post it
pay() { csrf "$1" "/booking/form.php?id=$2"
  req "$1" POST /payments/add.php "_csrf=$TOKEN" "form_token=$FORM_TOKEN" "booking_id=$2" "kind=${4:-payment}" \
    "amount=$3" "paid_on=$TODAY" "method=${5:-cash}" "reference_no=R-$RANDOM"; }

HASH=$($PHP -r 'echo password_hash("Passw0rd-e2e", PASSWORD_DEFAULT);')
$MYSQL -e "UPDATE users SET password_hash='$HASH', must_change_password=0 WHERE username='admin';
  INSERT INTO users (username,password_hash,role,name,firm_name,rep_name,contact,status) VALUES
  ('uzair','$HASH','vendor','Uzair Khan','Uzair Caterers','Uzair Khan','0312-2159834','active');"
VENDOR_ID=$($MYSQL -e "SELECT id FROM users WHERE username='uzair'")
LAWN_A=$($MYSQL -e "SELECT id FROM venues WHERE name='Lawn A'")
login v uzair Passw0rd-e2e
login a admin Passw0rd-e2e
csrf a /booking/form.php
req a POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Payment Client" "event_date=2027-12-12" "venue_id=$LAWN_A" \
  "guests=100" "per_head_rate=1000" "vendor_id=$VENDOR_ID" "refund_pct_30=50"
ID=${LOC##*=}
csrf a "/booking/form.php?id=$ID"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$ID" "version=$($MYSQL -e "SELECT version FROM bookings WHERE id=$ID")"

echo "== Record payments (checkpoint part 1: void → balance restored)"
req a GET "/booking/form.php?id=$ID"; contains "Record a payment" "admin sees the payment form"; contains "No payments recorded yet." "empty list"
pay a $ID "30,000"; req a GET "/booking/form.php?id=$ID"; contains "Payment of Rs. 30,000 recorded." "installment 1 recorded"
pay a $ID "20000" bank_transfer; expect "$(money $ID)" "50000.00|50000.00" "two installments: paid 50,000, balance 50,000"
FIRST=$($MYSQL -e "SELECT MIN(id) FROM payments WHERE booking_id=$ID")
csrf a "/booking/form.php?id=$ID"
req a POST /payments/void.php "_csrf=$TOKEN" "booking_id=$ID" "payment_id=$FIRST" "reason=Entered on the wrong booking"
expect "$(money $ID)" "20000.00|80000.00" "void → balance restored"
req a GET "/booking/form.php?id=$ID"; contains "VOIDED" "voided entry marked"; contains "Entered on the wrong booking" "void reason shown"
csrf a "/booking/form.php?id=$ID"
req a POST /payments/void.php "_csrf=$TOKEN" "booking_id=$ID" "payment_id=$FIRST" "reason=again"
req a GET "/booking/form.php?id=$ID"; contains "already been voided" "second void of the same entry refused"

echo "== Double submit and invalid input"
csrf a "/booking/form.php?id=$ID"; SAVED_FORM_TOKEN=$FORM_TOKEN; SAVED_CSRF=$TOKEN
N=$(count $ID)
req a POST /payments/add.php "_csrf=$SAVED_CSRF" "form_token=$SAVED_FORM_TOKEN" "booking_id=$ID" "kind=payment" "amount=5000" "paid_on=$TODAY" "method=cash"
req a POST /payments/add.php "_csrf=$SAVED_CSRF" "form_token=$SAVED_FORM_TOKEN" "booking_id=$ID" "kind=payment" "amount=5000" "paid_on=$TODAY" "method=cash"
expect "$(count $ID)" "$((N + 1))" "submitting the same form twice records it once"
req a GET "/booking/form.php?id=$ID"; contains "already submitted" "duplicate submission explained"
N=$(count $ID)
pay a $ID "-500"; req a GET "/booking/form.php?id=$ID"; contains "Payment not recorded: Amount:" "negative amount rejected"
contains 'value="-500"' "typed amount kept on the form"
pay a $ID "0"; pay a $ID "abc"
csrf a "/booking/form.php?id=$ID"
req a POST /payments/add.php "_csrf=$TOKEN" "form_token=$FORM_TOKEN" "booking_id=$ID" "kind=payment" "amount=1000" "paid_on=$TODAY" "method=cheque"
req a GET "/booking/form.php?id=$ID"; contains "Cheque number: required" "cheque needs a number"
expect "$(count $ID)" "$N" "no rows written for rejected entries"

echo "== Overpayment (checkpoint part 2)"
pay a $ID "80,000"; expect "$(money $ID)" "105000.00|-5000.00" "overpaid → balance -5,000"
req a GET "/booking/form.php?id=$ID"; contains "Overpaid by" "overpaid label"; contains "The booking is now overpaid by Rs. 5,000." "overpaid message"
contains "Refunds are recorded only after a booking is cancelled." "no refund on a confirmed booking"
req a GET /booking/list.php; contains "Rs. -5,000" "registry shows the negative balance"

echo "== Vendor view"
req v GET "/booking/form.php?id=$ID"; lacks "PAYMENTS &amp; REFUNDS" "vendor does not see the payments section"
lacks "Record a payment" "vendor has no payment form"; lacks 'name="payment_id"' "vendor has no void buttons"
csrf v "/booking/form.php?id=$ID"
req v POST /payments/add.php "_csrf=$TOKEN" "form_token=x" "booking_id=$ID" "kind=payment" "amount=1" "paid_on=$TODAY" "method=cash"
expect "$CODE" "404" "vendor POST to record a payment: 404"
req v POST /payments/void.php "_csrf=$TOKEN" "booking_id=$ID" "payment_id=$FIRST" "reason=x"; expect "$CODE" "404" "vendor POST to void: 404"

echo "== Refunds on a cancelled booking"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/cancel.php "_csrf=$TOKEN" "id=$ID" "version=$($MYSQL -e "SELECT version FROM bookings WHERE id=$ID")" "reason=Client cancelled"
req a GET "/booking/form.php?id=$ID"; contains "Record a refund" "refund form shown"; lacks "Record a payment" "no payment form"
contains "Refundable: <strong>Rs. 1,05,000" "refundable amount shown"
contains "Policy suggests refunding <strong>Rs. 52,500" "50% policy suggestion"
pay a $ID "1,05,001" refund; req a GET "/booking/form.php?id=$ID"; contains "Refund exceeds the refundable amount (Rs. 1,05,000)." "refund above the cap refused"
pay a $ID "52,500" refund; expect "$(money $ID)" "52500.00|0.00" "refund recorded; retained 52,500; balance 0"
req a GET "/booking/form.php?id=$ID"; contains "Amount retained" "amount retained shown"
contains "Policy suggests refunding <strong>Rs. 0" "suggestion accounts for the refund already made"
pay a $ID "10" payment; req a GET "/booking/form.php?id=$ID"; lacks "Payment of Rs. 10 recorded" "no payment on a cancelled booking"

echo "== Audit"
$MYSQL -e "SELECT action, COUNT(*) FROM audit_log WHERE booking_id=$ID AND action LIKE 'payment%' GROUP BY action"

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ $FAIL -eq 0 ]
