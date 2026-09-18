#!/bin/bash
# Phase 2 end-to-end checks over HTTP (local only). Setup:
#   mysql -P 3307 -e "CREATE DATABASE aomess_e2e"; import database/schema.sql + seed.sql into it
#   AOMESS_DB_NAME=aomess_e2e php -S 127.0.0.1:8093 -t public
# Then: bash tests/e2e.sh   (drop aomess_e2e afterwards)
B=http://127.0.0.1:8093
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3307 -N aomess_e2e"
T=$(mktemp -d)
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1  -- $2"; }
# req JAR METHOD PATH [data...] -> sets CODE, LOC
req() { local jar=$1 m=$2 p=$3; shift 3
  local args=(-s -b "$T/$jar" -c "$T/$jar" -o "$T/body" -D "$T/hdr" -w "%{http_code}")
  if [ "$m" = POST ]; then for d in "$@"; do args+=(--data-urlencode "$d"); done; fi
  CODE=$(curl "${args[@]}" -X "$m" "$B$p")
  LOC=$(grep -i '^location:' "$T/hdr" | tr -d '\r' | cut -d' ' -f2); }
csrf() { req "$1" GET "$2"; TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$T/body" | head -1 | sed 's/.*value="//; s/"//'); }
expect() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], expected [$2]"; fi; }
contains() { if grep -q -- "$1" "$T/body"; then ok "$2"; else bad "$2" "body lacks [$1]"; fi; }

echo "== Guest"
req g GET /;                         expect "$CODE $LOC" "303 /auth/login.php" "home redirects a guest to sign-in"
req g GET /admin/vendors.php;        expect "$CODE $LOC" "303 /auth/login.php" "admin page redirects a guest to sign-in"
grep -qi '^content-security-policy:' "$T/hdr" && ok "CSP header sent" || bad "CSP header" "missing"
grep -qi '^x-frame-options: DENY' "$T/hdr" && ok "X-Frame-Options DENY" || bad "X-Frame-Options" "missing"
rm -f "$T/fresh"; req fresh GET /auth/login.php
SC=$(grep -i '^set-cookie: AOMESSID' "$T/hdr")
echo "$SC" | grep -qi httponly && ok "session cookie HttpOnly" || bad "HttpOnly" "$SC"
echo "$SC" | grep -qi 'samesite=lax' && ok "session cookie SameSite=Lax" || bad "SameSite" "$SC"
if echo "$SC" | grep -qi '; secure'; then bad "Secure flag on local http" "$SC"; else ok "no Secure flag on local http (login works on XAMPP)"; fi
req g POST /auth/logout.php "_csrf=wrong";   expect "$CODE" "400" "POST without a valid CSRF token is rejected"
req g GET /auth/logout.php;                  expect "$CODE" "405" "logout by GET is refused"

echo "== Checkpoint 1: admin is forced to change the default password"
csrf a /auth/login.php
req a POST /auth/login.php "_csrf=$TOKEN" "username=admin" "password=ChangeMe-2026"
expect "$CODE $LOC" "303 /auth/change_password.php" "default admin login goes straight to change-password"
req a GET /;                         expect "$CODE $LOC" "303 /auth/change_password.php" "home is blocked until the password is changed"
req a GET /admin/vendors.php;        expect "$CODE $LOC" "303 /auth/change_password.php" "admin page is blocked until the password is changed"
csrf a /auth/change_password.php
req a POST /admin/vendors.php "_csrf=$TOKEN" "vendor_id=1" "action=approve"; expect "$CODE" "403" "POST to another page is refused while the change is forced"
req a POST /auth/change_password.php "_csrf=$TOKEN" "current_password=wrong-one" "new_password=AdminPass-2026" "new_password_confirm=AdminPass-2026"
contains "current password is not correct" "wrong current password rejected"
req a POST /auth/change_password.php "_csrf=$TOKEN" "current_password=ChangeMe-2026" "new_password=ChangeMe-2026" "new_password_confirm=ChangeMe-2026"
contains "must be different" "new password must differ from the temporary one"
req a POST /auth/change_password.php "_csrf=$TOKEN" "current_password=ChangeMe-2026" "new_password=short" "new_password_confirm=short"
contains "at least 10 characters" "short password rejected"
req a POST /auth/change_password.php "_csrf=$TOKEN" "current_password=ChangeMe-2026" "new_password=AdminPass-2026" "new_password_confirm=AdminPass-2026"
expect "$CODE $LOC" "303 /index.php" "valid change accepted"
req a GET /;                         expect "$CODE" "200" "home opens after the change"
contains "Welcome, AO Mess Administrator" "admin sees the home page"

echo "== Checkpoint 2: a pending vendor can't log in"
csrf v /auth/register.php
req v POST /auth/register.php "_csrf=$TOKEN" "firm_name=Uzair Caterers" "rep_name=Uzair Khan" "contact=0312-2159834" "username=Uzair.K" "password=VendorPass-01" "password_confirm=VendorPass-01"
expect "$CODE $LOC" "303 /auth/login.php" "vendor registration accepted"
csrf v /auth/login.php; contains "waiting for approval" "registration message shown"
req v POST /auth/login.php "_csrf=$TOKEN" "username=uzair.k" "password=VendorPass-01"
expect "$CODE" "200" "pending vendor stays on the sign-in page"
contains "waiting for approval by AO Mess" "pending vendor told to wait for approval"
csrf v2 /auth/register.php
req v2 POST /auth/register.php "_csrf=$TOKEN" "firm_name=X" "rep_name=Y" "contact=1" "username=uzair.k" "password=OtherPass-01" "password_confirm=OtherPass-01"
contains "already taken" "duplicate username rejected"

echo "== Admin approves; vendor signs in; vendor can't reach admin pages"
VID=$($MYSQL -e "SELECT id FROM users WHERE username='uzair.k'")
csrf a /admin/vendors.php; contains "uzair.k" "pending vendor listed for the admin"
req a POST /admin/vendors.php "_csrf=$TOKEN" "vendor_id=$VID" "action=approve"; expect "$CODE" "303" "approve submitted"
req a GET /admin/vendors.php; contains "is now active" "vendor approved"
csrf v /auth/login.php
req v POST /auth/login.php "_csrf=$TOKEN" "username=uzair.k" "password=VendorPass-01"
expect "$CODE $LOC" "303 /index.php" "approved vendor signs in"
req v GET /;                  contains "Signed in for <strong>Uzair Caterers" "vendor home page"
req v GET /admin/vendors.php; expect "$CODE" "404" "vendor gets 404 on the admin page"

echo "== Disabling signs the vendor out on the next click"
csrf a /admin/vendors.php
req a POST /admin/vendors.php "_csrf=$TOKEN" "vendor_id=$VID" "action=disable"
req v GET /;                  expect "$CODE $LOC" "303 /auth/login.php" "disabled vendor's next click is signed out"
req v GET /auth/login.php;    contains "no longer active" "disabled vendor told why"

echo "== Password reset issues a temporary password and forces a change"
csrf a /admin/vendors.php
req a POST /admin/vendors.php "_csrf=$TOKEN" "vendor_id=$VID" "action=approve"
csrf a /admin/vendors.php
req a POST /admin/vendors.php "_csrf=$TOKEN" "vendor_id=$VID" "action=reset"
req a GET /admin/vendors.php
TEMP=$(grep -o '<div class="secret-box">[^<]*' "$T/body" | sed 's/.*>//')
if [ ${#TEMP} -eq 12 ]; then ok "temporary password shown once to the admin"; else bad "temporary password" "[$TEMP]"; fi
req a GET /admin/vendors.php
if grep -q secret-box "$T/body"; then bad "temp password shown twice" ""; else ok "temporary password not shown again"; fi
csrf v3 /auth/login.php
req v3 POST /auth/login.php "_csrf=$TOKEN" "username=uzair.k" "password=VendorPass-01"; contains "Wrong username or password" "old password no longer works"
req v3 POST /auth/login.php "_csrf=$TOKEN" "username=uzair.k" "password=$TEMP"
expect "$CODE $LOC" "303 /auth/change_password.php" "temporary password forces a change"
AUD=$($MYSQL -e "SELECT COUNT(*) FROM audit_log WHERE details LIKE '%$TEMP%'")
expect "$AUD" "0" "temporary password never written to the audit log"

echo "== Sign out"
csrf a /
req a POST /auth/logout.php "_csrf=$TOKEN"; expect "$CODE $LOC" "303 /auth/login.php" "sign out"
req a GET /;                                 expect "$CODE $LOC" "303 /auth/login.php" "signed-out session can't open pages"

echo "== Audit trail"
$MYSQL -e "SELECT action, COUNT(*) FROM audit_log GROUP BY action ORDER BY action"

echo; echo "$PASS passed, $FAIL failed"; rm -rf "$T"; [ $FAIL -eq 0 ]
