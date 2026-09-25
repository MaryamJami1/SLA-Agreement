#!/bin/bash
# Phase 4 end-to-end checks (registry + lifecycle) over HTTP. Local only. Setup:
#   create database aomess_e2e, import database/schema.sql + seed.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e_lifecycle.sh   (drop aomess_e2e afterwards)
# Simultaneous confirmation / amendment races are covered by tests/concurrency_check.php
# (PHP's built-in server handles one request at a time, so it can't race).
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
expect() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], expected [$2]"; fi; }
contains() { if grep -q -- "$1" "$T/body"; then ok "$2"; else bad "$2" "body lacks [$1]"; fi; }
lacks() { if grep -q -- "$1" "$T/body"; then bad "$2" "body has [$1]"; else ok "$2"; fi; }
login() { csrf "$1" /auth/login.php; req "$1" POST /auth/login.php "_csrf=$TOKEN" "username=$2" "password=$3"; }
ver() { $MYSQL -e "SELECT version FROM bookings WHERE id=$1"; }
status() { $MYSQL -e "SELECT status FROM bookings WHERE id=$1"; }
# new_draft JAR CLIENT DATE VENUE -> ID (a confirmable draft: Rs. 1,00,000)
# Only AO Mess sets money, so the priced draft is saved by the admin with the vendor as its
# owner. The JAR argument is kept for readability; ownership is what the tests below turn on.
new_draft() { csrf a /booking/form.php
  req a POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=$2" "event_date=$3" "venue_id=$4" \
    "guests=100" "per_head_rate=1000" "client_cnic=42101-1234567-1" "vendor_id=$VENDOR_ID"
  ID=${LOC##*=}; }

HASH=$($PHP -r 'echo password_hash("Passw0rd-e2e", PASSWORD_DEFAULT);')
$MYSQL -e "UPDATE users SET password_hash='$HASH', must_change_password=0 WHERE username='admin';
  INSERT INTO users (username,password_hash,role,name,firm_name,rep_name,contact,status) VALUES
  ('uzair','$HASH','vendor','Uzair Khan','Uzair Caterers','Uzair Khan','0312-2159834','active'),
  ('bilal','$HASH','vendor','Bilal Ahmed','Bilal Events','Bilal Ahmed','0300-1111111','active');"
VENDOR_ID=$($MYSQL -e "SELECT id FROM users WHERE username='uzair'")
LAWN_A=$($MYSQL -e "SELECT id FROM venues WHERE name='Lawn A'")
LAWN_B=$($MYSQL -e "SELECT id FROM venues WHERE name='Lawn B'")
login v uzair Passw0rd-e2e
login o bilal Passw0rd-e2e
login a admin Passw0rd-e2e

echo "== Confirm"
new_draft v "Ayesha Siddiqui" 2027-01-10 $LAWN_A; A=$ID
new_draft v "Bushra Ali" 2027-01-10 $LAWN_A; BB=$ID
req v GET "/booking/form.php?id=$A"; lacks "Confirm booking" "vendor has no Confirm button"
contains "Delete draft" "owner vendor can delete their draft"
csrf v "/booking/form.php?id=$A"
req v POST /booking/confirm.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)"; expect "$CODE" "404" "vendor POST to confirm: 404"
req v POST /booking/cancel.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)" "reason=x"; expect "$CODE" "404" "vendor POST to cancel: 404"
csrf a "/booking/form.php?id=$A"; contains "Confirm booking" "admin sees Confirm"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$A" "version=$(( $(ver $A) - 1 ))"
req a GET "/booking/form.php?id=$A"; contains "changed by someone else after you opened it, so nothing was done" "stale version: nothing done"
expect "$(status $A)" "draft" "still a draft"
csrf a "/booking/form.php?id=$A"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)"
req a GET "/booking/form.php?id=$A"; contains "is confirmed." "admin confirms A"; expect "$(status $A)" "confirmed" "A confirmed in the database"

echo "== Vendor view of a confirmed booking"
req v GET "/booking/form.php?id=$A"; contains "is confirmed and can no longer be edited" "vendor: read-only"
lacks "Save Changes" "vendor: no save"; lacks "Delete draft" "vendor: no delete"

echo "== Venue conflict on confirm, and the override"
csrf a "/booking/form.php?id=$BB"; contains "Override reason" "B's page asks for an override reason"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$BB" "version=$(ver $BB)"
req a GET "/booking/form.php?id=$BB"; contains "The venue is already confirmed for SLA-" "B refused without an override"
expect "$(status $BB)" "draft" "B still a draft"
csrf a "/booking/form.php?id=$BB"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$BB" "version=$(ver $BB)" "override_reason=Different halves of the lawn"
expect "$(status $BB)" "confirmed" "B confirmed with an override"
expect "$($MYSQL -e "SELECT COUNT(*) FROM audit_log WHERE action='confirm' AND booking_id=$BB AND details LIKE '%Different halves%'")" "1" "override reason in the audit log"

echo "== Direct edit vs amendment through the form"
FORM=( "client_name=Ayesha Siddiqui" "client_cnic=42101-1234567-1" "event_date=2027-01-10" "venue_id=$LAWN_A" "guests=100"
       "per_head_rate=1000" "vendor_id=$VENDOR_ID" "firm_name=Uzair Caterers" "rep_name=Uzair Khan" "rep_contact=0312-2159834" )
csrf a "/booking/form.php?id=$A"; contains "Changing a confirmed agreement" "admin sees the amendment panel"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)" "${FORM[@]}" "client_contact=0333-7654321" "client_sign_name=Ayesha Siddiqui" "vendor_sign_name=Uzair Khan"
req a GET "/booking/form.php?id=$A"; contains "no change to the agreed terms" "contact + signatures saved as a direct edit"
expect "$($MYSQL -e "SELECT CONCAT(revision,'|',client_sign_name) FROM bookings WHERE id=$A")" "0|Ayesha Siddiqui" "no revision, signatures kept"
csrf a "/booking/form.php?id=$A"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)" "${FORM[@]/guests=100/guests=150}" "client_contact=0333-7654321" "client_sign_name=Ayesha Siddiqui" "vendor_sign_name=Uzair Khan"
expect "$CODE" "422" "amendment without a reason refused"
contains "Amendment reason: required" "reason error shown"
contains "Upload the signed copy of Rev 0 before amending." "signed-copy rule shown (the agreement was signed)"
$MYSQL -e "INSERT INTO attachments (booking_id, original_name, stored_name, mime, size_bytes, uploaded_by, signed_revision)
           VALUES ($A, 'signed-rev0.pdf', MD5(RAND()), 'application/pdf', 1, 1, 0)"
csrf a "/booking/form.php?id=$A"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)" "${FORM[@]/guests=100/guests=150}" "client_contact=0333-7654321" \
  "client_sign_name=Ayesha Siddiqui" "vendor_sign_name=Uzair Khan" "amend_reason=Client added 50 guests"
req a GET "/booking/form.php?id=$A"; contains "amended: it is now Rev 1" "amendment accepted"
contains "ID: SLA-[0-9]*-[0-9]* Rev 1" "page shows the Rev 1 number"
expect "$($MYSQL -e "SELECT CONCAT(revision,'|',IFNULL(client_sign_name,'-'),'|',guests) FROM bookings WHERE id=$A")" "1|-|150" "Rev 1, signatures cleared, guests 150"
req a GET "/booking/list.php"; contains "Rev 1" "registry shows the revision"

echo "== Complete"
csrf a "/booking/form.php?id=$A"; contains "Available on or after the event date" "complete not offered before the event date"
$MYSQL -e "UPDATE bookings SET event_date = CURDATE() - INTERVAL 1 DAY WHERE id=$A"
csrf a "/booking/form.php?id=$A"; contains "I acknowledge Rs. 1,50,000 is still outstanding" "outstanding balance must be acknowledged"
req a POST /booking/complete.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)"
req a GET "/booking/form.php?id=$A"; contains "Tick the acknowledgement" "refused without the tick"
csrf a "/booking/form.php?id=$A"
req a POST /booking/complete.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)" "ack_balance=1"
expect "$(status $A)" "completed" "completed with the tick"
req a GET "/booking/form.php?id=$A"; contains "COMPLETED" "completed panel shown"; lacks "Cancel booking" "no cancel on a completed booking"
csrf a "/booking/form.php?id=$A"
req a POST /booking/cancel.php "_csrf=$TOKEN" "id=$A" "version=$(ver $A)" "reason=too late"
expect "$(status $A)" "completed" "direct POST can't cancel a completed booking"

echo "== Cancel"
csrf a "/booking/form.php?id=$BB"
req a POST /booking/cancel.php "_csrf=$TOKEN" "id=$BB" "version=$(ver $BB)" "reason="
req a GET "/booking/form.php?id=$BB"; contains "Enter a cancellation reason." "cancel needs a reason"
csrf a "/booking/form.php?id=$BB"
req a POST /booking/cancel.php "_csrf=$TOKEN" "id=$BB" "version=$(ver $BB)" "reason=Client postponed indefinitely"
expect "$($MYSQL -e "SELECT CONCAT(status,'|',balance) FROM bookings WHERE id=$BB")" "cancelled|0.00" "cancelled, balance 0"
req a GET "/booking/form.php?id=$BB"; contains "Client postponed indefinitely" "cancellation reason shown"; contains "Amount retained" "amount retained shown"

echo "== Delete a draft"
new_draft v "Draft To Delete" 2027-03-03 $LAWN_B; DEL=$ID
csrf o /booking/list.php
req o POST /booking/delete.php "_csrf=$TOKEN" "id=$DEL" "version=$(ver $DEL)"; expect "$CODE" "404" "another vendor can't delete it (404)"
csrf v "/booking/form.php?id=$DEL"
req v POST /booking/delete.php "_csrf=$TOKEN" "id=$DEL" "version=$(ver $DEL)"
expect "$CODE $LOC" "303 /booking/list.php" "owner deletes; back to the registry"
req v GET /booking/list.php; contains "was deleted" "deletion message"
expect "$($MYSQL -e "SELECT COUNT(*) FROM bookings WHERE id=$DEL")" "0" "booking gone"
expect "$($MYSQL -e "SELECT COUNT(*) FROM audit_log WHERE booking_id=$DEL AND action IN ('create','delete_draft')")" "2" "create + delete_draft audit rows kept"

echo "== Registry: search, filter, scope, paging"
for i in $(seq 1 27); do
  $MYSQL -e "INSERT INTO bookings (unique_id, vendor_id, created_by, client_name, event_date) VALUES ('SLA-2099-$(printf %04d $i)', $VENDOR_ID, 1, 'Bulk Client $i', '2027-08-01')"
done
req a GET "/booking/list.php"; contains "page 1 of 2" "30 bookings → 2 pages"
req a GET "/booking/list.php?page=2"; contains "Page 2 of 2" "page 2 opens"
req a GET "/booking/list.php?q=Ayesha"; contains "1 booking(s) match" "search by client"
req a GET "/booking/list.php?status=cancelled"; contains "Bushra Ali" "status filter"; lacks "Ayesha Siddiqui" "status filter excludes others"
req a GET "/booking/list.php?q=%25"; contains "0 booking(s) match" "a % in the search is literal, not a wildcard"
req a GET "/booking/list.php?q=Uzair+Caterers"; contains "booking(s) match" "search by firm"
lacks "42101-1234567-1" "CNIC never shown in the registry"
req o GET "/booking/list.php"; contains "No bookings yet" "other vendor sees none of them"
req a GET "/"; expect "$CODE $LOC" "303 /booking/list.php" "home redirects to the registry"

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ $FAIL -eq 0 ]
