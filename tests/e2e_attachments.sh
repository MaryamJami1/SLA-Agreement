#!/bin/bash
# Phase 7 end-to-end checks (attachments, catalog, venues) over HTTP. Local only. Setup:
#   create database aomess_e2e, import database/schema.sql + seed.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e_attachments.sh   (drop aomess_e2e afterwards)
B=http://127.0.0.1:8093
PHP=/c/xampp/php/php.exe
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3307 -N aomess_e2e"
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
# upload JAR PATH FILE [extra form fields...]
upload() { local jar=$1 p=$2; shift 2
  local args=(-s -b "$T/$jar" -c "$T/$jar" -o "$T/body" -D "$T/hdr" -w "%{http_code}")
  for d in "$@"; do args+=(-F "$d"); done  # file=@ paths must be Windows-style (see W below)
  CODE=$(curl "${args[@]}" "$B$p"); LOC=$(grep -i '^location:' "$T/hdr" | tr -d '\r' | cut -d' ' -f2); }
csrf() { req "$1" GET "$2"; TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$T/body" | head -1 | sed 's/.*value="//; s/"//'); }
expect() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], expected [$2]"; fi; }
contains() { if grep -q -- "$1" "$T/body"; then ok "$2"; else bad "$2" "body lacks [$1]"; fi; }
lacks() { if grep -q -- "$1" "$T/body"; then bad "$2" "body has [$1]"; else ok "$2"; fi; }
login() { csrf "$1" /auth/login.php; req "$1" POST /auth/login.php "_csrf=$TOKEN" "username=$2" "password=$3"; }
ver() { $MYSQL -e "SELECT version FROM bookings WHERE id=$1"; }

# Fixtures: a real PDF, a PNG, a PHP script renamed .pdf, and a 6 MB file.
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > "$T/signed.pdf"
echo 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' | base64 -d > "$T/photo.png"
printf '<?php system($_GET["c"]); ?>\n' > "$T/evil.pdf"
$PHP -r 'file_put_contents($argv[1], str_repeat("A", 6*1024*1024));' "$T/big.pdf"

HASH=$($PHP -r 'echo password_hash("Passw0rd-e2e", PASSWORD_DEFAULT);')
$MYSQL -e "UPDATE users SET password_hash='$HASH', must_change_password=0 WHERE username='admin';
  INSERT INTO users (username,password_hash,role,name,firm_name,rep_name,contact,status) VALUES
  ('uzair','$HASH','vendor','Uzair Khan','Uzair Caterers','Uzair Khan','0312-2159834','active'),
  ('bilal','$HASH','vendor','Bilal Ahmed','Bilal Events','Bilal Ahmed','0300-1111111','active');"
VENDOR_ID=$($MYSQL -e "SELECT id FROM users WHERE username='uzair'")
LAWN_A=$($MYSQL -e "SELECT id FROM venues WHERE name='Lawn A'")
login v uzair Passw0rd-e2e
login o bilal Passw0rd-e2e
login a admin Passw0rd-e2e
csrf v /booking/form.php
req v POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Ayesha Siddiqui" "event_date=2027-04-04" \
  "venue_id=$LAWN_A" "guests=100" "per_head_rate=1000"
ID=${LOC##*=}

echo "== Upload, download, type and size checks"
req v GET "/booking/form.php?id=$ID"; contains "ATTACHMENTS" "attachments panel"; contains "No files attached yet." "empty list"
csrf v "/booking/form.php?id=$ID"
upload v /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "file=@$(W signed.pdf);type=application/pdf"
req v GET "/booking/form.php?id=$ID"; contains "Uploaded signed.pdf" "PDF uploaded"; contains "signed.pdf</a>" "file listed with a download link"
AID=$($MYSQL -e "SELECT id FROM attachments WHERE booking_id=$ID ORDER BY id DESC LIMIT 1")
curl -s -b "$T/v" -o "$T/dl" -D "$T/hdr" "$B/documents/download.php?id=$AID"
grep -qi '^content-type: application/pdf' "$T/hdr" && ok "download sends the stored type" || bad "download type" "$(grep -i content-type "$T/hdr")"
grep -qi '^x-content-type-options: nosniff' "$T/hdr" && ok "download sends nosniff" || bad "nosniff" "missing"
grep -qi 'filename="signed.pdf"' "$T/hdr" && ok "download keeps the file name" || bad "filename" "$(grep -i disposition "$T/hdr")"
cmp -s "$T/dl" "$T/signed.pdf" && ok "downloaded file matches the uploaded one" || bad "download content" "differs"
csrf v "/booking/form.php?id=$ID"
upload v /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "file=@$(W evil.pdf);type=application/pdf"
req v GET "/booking/form.php?id=$ID"; contains "Only PDF, JPG and PNG" "a .php file renamed .pdf is rejected (plan test 15)"
lacks "evil.pdf</a>" "rejected file not listed"
csrf v "/booking/form.php?id=$ID"
upload v /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "file=@$(W big.pdf);type=application/pdf"
req v GET "/booking/form.php?id=$ID"; contains "too large" "a 6 MB file is rejected"
csrf v "/booking/form.php?id=$ID"
upload v /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "file=@$(W photo.png);type=image/png"
req v GET "/booking/form.php?id=$ID"; contains "Uploaded photo.png" "PNG accepted"
expect "$($MYSQL -e "SELECT COUNT(*) FROM attachments WHERE booking_id=$ID")" "2" "only the accepted files are stored"

echo "== Access"
req o GET "/documents/download.php?id=$AID"; expect "$CODE" "404" "another vendor can't download it"
csrf o /booking/list.php
upload o /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "file=@$(W signed.pdf);type=application/pdf"
expect "$CODE" "404" "another vendor can't upload to it"
req a GET "/documents/download.php?id=$AID"; expect "$CODE" "200" "admin can download"
req v GET "/documents/download.php?id=999999"; expect "$CODE" "404" "unknown attachment: 404"

echo "== Signed copy and the amendment rule (plan test 19, in full)"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)"
req v GET "/booking/form.php?id=$ID"; lacks "Attach a file" "vendor can't attach once confirmed"
csrf v "/booking/form.php?id=$ID"
upload v /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "file=@$(W signed.pdf);type=application/pdf"
expect "$CODE" "404" "vendor upload to a confirmed booking: 404"
FORM=( "client_name=Ayesha Siddiqui" "event_date=2027-04-04" "venue_id=$LAWN_A" "guests=100" "per_head_rate=1000"
       "vendor_id=$VENDOR_ID" "firm_name=Uzair Caterers" "rep_name=Uzair Khan" "rep_contact=0312-2159834" )
csrf a "/booking/form.php?id=$ID"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)" "${FORM[@]}" "vendor_sign_name=Uzair Khan" "client_sign_name=Ayesha Siddiqui"
req a GET "/booking/form.php?id=$ID"; contains "This agreement is signed at Rev 0" "signed booking asks for the scan"
contains "signed copy of Rev 0" "signed-copy option offered"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)" "${FORM[@]/guests=100/guests=150}" "vendor_sign_name=Uzair Khan" "client_sign_name=Ayesha Siddiqui" "amend_reason=Client added 50 guests"
contains "Upload the signed copy of Rev 0 before amending." "amendment refused without the scan"
expect "$($MYSQL -e "SELECT CONCAT(revision,'|',guests) FROM bookings WHERE id=$ID")" "0|100" "nothing changed"
csrf a "/booking/form.php?id=$ID"
upload a /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "is_signed_copy=1" "signed_revision=0" "file=@$(W signed.pdf);type=application/pdf"
req a GET "/booking/form.php?id=$ID"; contains "as the signed copy of Rev 0." "scan filed as the signed copy"
contains "SIGNED COPY — REV 0" "badge on the file"
contains "The signed copy of Rev 0 is on file." "status note"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)" "${FORM[@]/guests=100/guests=150}" "vendor_sign_name=Uzair Khan" "client_sign_name=Ayesha Siddiqui" "amend_reason=Client added 50 guests"
req a GET "/booking/form.php?id=$ID"; contains "amended: it is now Rev 1" "amendment accepted after the upload"
expect "$($MYSQL -e "SELECT CONCAT(revision,'|',guests,'|',IFNULL(client_sign_name,'-')) FROM bookings WHERE id=$ID")" "1|150|-" "Rev 1, guests 150, signatures cleared"
req a GET "/documents/agreement.php?id=$ID"; contains "Rev 1" "agreement prints Rev 1"

echo "== Voiding a signed copy (checkpoint)"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)" "${FORM[@]/guests=100/guests=150}" "vendor_sign_name=Uzair Khan" "client_sign_name=Ayesha Siddiqui"
csrf a "/booking/form.php?id=$ID"
upload a /documents/upload.php "_csrf=$TOKEN" "booking_id=$ID" "is_signed_copy=1" "signed_revision=1" "file=@$(W signed.pdf);type=application/pdf"
SIGNED1=$($MYSQL -e "SELECT id FROM attachments WHERE booking_id=$ID AND signed_revision=1")
csrf a "/booking/form.php?id=$ID"
req a POST /documents/attachment_void.php "_csrf=$TOKEN" "booking_id=$ID" "attachment_id=$SIGNED1" "reason=Wrong scan attached"
req a GET "/booking/form.php?id=$ID"; contains "no longer counts as a signed copy" "void confirmed"; contains "VOIDED" "file marked voided"
csrf a "/booking/form.php?id=$ID"
req a POST /booking/save.php "_csrf=$TOKEN" "id=$ID" "version=$(ver $ID)" "${FORM[@]/guests=100/guests=175}" "vendor_sign_name=Uzair Khan" "client_sign_name=Ayesha Siddiqui" "amend_reason=More guests again"
contains "Upload the signed copy of Rev 1 before amending." "a voided signed copy no longer satisfies the check"
req v GET "/booking/form.php?id=$ID"; lacks "VOIDED" "vendors don't see voided files"
req v GET "/documents/download.php?id=$SIGNED1"; expect "$CODE" "404" "vendor can't download a voided file"
req a GET "/documents/download.php?id=$SIGNED1"; expect "$CODE" "200" "admin can still download it"
csrf a "/booking/form.php?id=$ID"
req a POST /documents/attachment_void.php "_csrf=$TOKEN" "booking_id=$ID" "attachment_id=$SIGNED1" "reason=again"
req a GET "/booking/form.php?id=$ID"; contains "already been voided" "second void refused"
csrf v "/booking/form.php?id=$ID"
req v POST /documents/attachment_void.php "_csrf=$TOKEN" "booking_id=$ID" "attachment_id=$AID" "reason=x"
expect "$CODE" "404" "vendor can't void files"

echo "== Catalog (checkpoint: renaming doesn't change old invoices)"
req a GET /admin/catalog.php; expect "$CODE" "200" "catalog page opens"
contains "Venue Charges" "seeded charge listed"
VENUE_CHARGE=$($MYSQL -e "SELECT id FROM item_catalog WHERE name='Venue Charges'")
csrf a /booking/form.php
req a POST /booking/save.php "_csrf=$TOKEN" "id=" "version=" "client_name=Catalog Client" "event_date=2027-05-05" "guests=50" "per_head_rate=1000" \
  "lines[c$VENUE_CHARGE][present]=1" "lines[c$VENUE_CHARGE][selected]=1" "lines[c$VENUE_CHARGE][rate]=40000"
CID=${LOC##*=}
csrf a "/booking/form.php?id=$CID"
req a POST /booking/confirm.php "_csrf=$TOKEN" "id=$CID" "version=$(ver $CID)" 2>/dev/null
req a GET "/documents/invoice.php?id=$CID"; contains "Venue Charges" "old invoice shows the original label"
csrf a /admin/catalog.php
req a POST /admin/catalog.php "_csrf=$TOKEN" "action=update" "item_id=$VENUE_CHARGE" "name=Hall Hire" "unit=per head" "default_rate=250" "sort_order=10" "is_active=1"
req a GET /admin/catalog.php; contains "Catalog item saved." "catalog item renamed and re-united"
req a GET "/documents/invoice.php?id=$CID"; contains "Venue Charges" "old invoice still shows Venue Charges (checkpoint)"
contains "Rs. 40,000" "old invoice keeps the original amount"
lacks "Hall Hire" "new name doesn't leak into the old invoice"
req a GET /booking/form.php; contains "Hall Hire" "new bookings offer the new name"
csrf a /admin/catalog.php
req a POST /admin/catalog.php "_csrf=$TOKEN" "action=update" "item_id=$VENUE_CHARGE" "name=Hall Hire" "unit=per head" "default_rate=250" "sort_order=10"
req a GET /booking/form.php; lacks "Hall Hire" "retired item is no longer offered"
req a GET "/booking/form.php?id=$CID"; contains "Venue Charges" "retired item stays on the booking that has it"
csrf a /admin/catalog.php
req a POST /admin/catalog.php "_csrf=$TOKEN" "action=create" "section=charge" "name=Fireworks" "unit=fixed" "default_rate=75,000" "sort_order=90" "is_active=1"
req a GET /admin/catalog.php; contains "Fireworks" "new catalog item added"
csrf a /admin/catalog.php
req a POST /admin/catalog.php "_csrf=$TOKEN" "action=create" "section=charge" "name=" "unit=fixed"
req a GET /admin/catalog.php; contains "Enter the item name." "empty name refused"

echo "== Venues"
req a GET /admin/venues.php; expect "$CODE" "200" "venues page opens"
contains "used by 1 booking" "usage count shown for Lawn A"
csrf a /admin/venues.php
req a POST /admin/venues.php "_csrf=$TOKEN" "action=update" "venue_id=$LAWN_A" "name=Lawn A (East)" "sort_order=10" "is_active=1"
req a GET /admin/venues.php; contains "can&#039;t be renamed" "used venue can't be renamed"
contains "Lawn A" "name unchanged"
csrf a /admin/venues.php
req a POST /admin/venues.php "_csrf=$TOKEN" "action=update" "venue_id=$LAWN_A" "name=Lawn A" "sort_order=10"
req a GET /admin/venues.php; contains "Venue saved." "used venue can be deactivated"
expect "$($MYSQL -e "SELECT is_active FROM venues WHERE id=$LAWN_A")" "0" "deactivated"
csrf a /admin/venues.php
req a POST /admin/venues.php "_csrf=$TOKEN" "action=create" "name=Terrace" "sort_order=60"
req a GET /admin/venues.php; contains "Added venue" "venue added"
TERRACE=$($MYSQL -e "SELECT id FROM venues WHERE name='Terrace'")
csrf a /admin/venues.php
req a POST /admin/venues.php "_csrf=$TOKEN" "action=create" "name=Terrace" "sort_order=61"
req a GET /admin/venues.php; contains "already a venue called" "duplicate name refused"
csrf a /admin/venues.php
req a POST /admin/venues.php "_csrf=$TOKEN" "action=update" "venue_id=$TERRACE" "name=Roof Terrace" "sort_order=60" "is_active=1"
req a GET /admin/venues.php; contains "Roof Terrace" "unused venue can be renamed"
csrf a /admin/venues.php
req a POST /admin/venues.php "_csrf=$TOKEN" "action=delete" "venue_id=$TERRACE"
req a GET /admin/venues.php; contains "Deleted venue" "unused venue deleted"
csrf a /admin/venues.php
req a POST /admin/venues.php "_csrf=$TOKEN" "action=delete" "venue_id=$LAWN_A"
req a GET /admin/venues.php; contains "can&#039;t be deleted" "used venue can't be deleted"
req v GET /admin/catalog.php; expect "$CODE" "404" "vendor: 404 on the catalog page"
req v GET /admin/venues.php; expect "$CODE" "404" "vendor: 404 on the venues page"

echo "== Audit"
$MYSQL -e "SELECT action, COUNT(*) FROM audit_log WHERE action IN ('attachment_add','attachment_void','catalog_change','venue_change') GROUP BY action"

# Remove the files this run uploaded into storage/uploads/. On Windows the running PHP server keeps
# files it has served open, so those can only be removed once the server is stopped.
PROJECT=$(cd "$(dirname "$0")/.." && pwd)
LEFT=0
for stored in $($MYSQL -e "SELECT stored_name FROM attachments" | tr -d ''); do
  rm -f "$PROJECT/storage/uploads/$stored" 2>/dev/null
  [ -f "$PROJECT/storage/uploads/$stored" ] && LEFT=$((LEFT+1))
done
[ $LEFT -gt 0 ] && echo "note: $LEFT test upload(s) are still open by the server; delete storage/uploads/* after stopping it"

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ $FAIL -eq 0 ]
