# Running the tests

All tests run locally against XAMPP. They never touch the live site, and the database-backed ones
build a throwaway database and drop it again.

Paths below assume XAMPP at `C:\xampp` and the project at `…\Desktop\project`.

## 1. Unit tests — no database needed

```bash
C:/xampp/php/php.exe tests/run.php
```

Pure functions only: money in paisa, Rs. and lakh/crore formatting, amounts in words, the totals chain,
the refund suggestion, input validation, passwords and device cookies, the authorization rules, the
direct-edit/amendment classification, document helpers and the admin-data rules.

## 2. Database checks

Start MariaDB first (XAMPP Control Panel → MySQL; it runs on **port 3307** here, because MySQL 8 has 3306).

```bash
C:/xampp/php/php.exe tests/db_check.php
```

Builds `<DB_NAME>_test` from `database/schema.sql` + `seed.sql`, exercises the SLA counter, totals,
login throttling, the booking save, the whole lifecycle, payments, attachments, venue locations, the
calendar, the approvals queue and the admin screens, then drops the database. It refuses to run if
`APP_ENV` is `production`.

It also checks that `schema.sql` records every migration in `database/migrations/`, so adding a
migration without folding it into `schema.sql` fails the suite.

## 3. Concurrency checks

```bash
C:/xampp/php/php.exe tests/concurrency_check.php
```

Runs real parallel PHP processes against `<DB_NAME>_race`:

- two admins confirming the same venue and date at the same moment → exactly one succeeds;
- two amendments swapping venues at the same moment → both succeed, no deadlock.

## 4. End-to-end checks over HTTP

Create the test database and start the app's own server:

```bash
MYSQL="C:/xampp/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3307"
$MYSQL -e "DROP DATABASE IF EXISTS aomess_e2e; CREATE DATABASE aomess_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$MYSQL aomess_e2e < database/schema.sql
$MYSQL aomess_e2e < database/seed.sql
AOMESS_DB_NAME=aomess_e2e C:/xampp/php/php.exe -S 127.0.0.1:8093 -t public
```

Then, in another terminal, run any of these (each expects a **freshly imported** `aomess_e2e`, so
re-import between runs):

| Script | Covers |
|---|---|
| `tests/e2e.sh` | sign-in, throttling, sessions, CSRF, vendor approval |
| `tests/e2e_booking.sh` | the booking form, saving, validation, access rules |
| `tests/e2e_lifecycle.sh` | registry, confirm, complete, cancel, delete, amendments |
| `tests/e2e_payments.sh` | payments, refunds, voids, the refund cap |
| `tests/e2e_documents.sh` | agreement, invoice, operations sheet |
| `tests/e2e_attachments.sh` | uploads, downloads, voiding, catalog and venue admin |

```bash
bash tests/e2e_lifecycle.sh
```

Afterwards: stop the server, then `$MYSQL -e "DROP DATABASE aomess_e2e"`.

### Two Windows quirks

- The end-to-end scripts use the **Windows** build of curl, which cannot read `/tmp/...` paths. File
  uploads therefore go through `cygpath -w` (already handled inside `e2e_attachments.sh`).
- A running PHP server keeps files it has served **open**, so test uploads in `storage/uploads/` can
  only be deleted once the server is stopped. The script says so and cleans up the rest.

## 5. On the deployed site

Sign in as the admin and open **Checks** (`/admin/preflight.php`), then work through
`docs/GO_LIVE_CHECKLIST.md`.
