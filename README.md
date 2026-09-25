# AO Mess — Event Booking & SLA System

A booking register for AO Mess / ASK Organizers (event planning and catering, Karachi): vendors and
admins record bookings, print the SLA agreement, the customer invoice and an operations sheet, and
keep track of payments.

Plain PHP 8.1+ with PDO and MySQL/MariaDB. No framework, no Composer, no build step — it runs on
Hostinger shared hosting.

`plan.md` is the specification the code follows; it is worth reading before changing anything.

## Layout

```
public/      the only web-accessible folder (its CONTENTS become public_html)
  auth/      sign in, register, change password
  booking/   registry, calendar, form, save, confirm, complete, cancel, delete
  payments/  add and void payments and refunds
  documents/ agreement, invoice, operations sheet, upload, download
  admin/     approvals, vendors, catalog, venues, deployment checks
  assets/    css, js, logo
app/         all application code — never web-accessible
  bootstrap.php   config, errors, web-root guard, session, CSRF, current user
  db, money, counters, helpers, audit, auth, csrf
  bookings, lifecycle, payments, attachments, documents, admin_data
  views/     layout and page views
config/      config.sample.php (config.php holds real credentials, never committed)
database/    schema.sql, seed.sql, migrations/
storage/     uploads/, logs/, sessions/   (never web-accessible)
tests/       unit, database, concurrency and end-to-end suites
docs/        DEPLOY.md, GO_LIVE_CHECKLIST.md, TESTING.md
```

## How the important parts work

- **Money** is handled in whole paisa, never floating point. One function,
  `recompute_booking_totals()`, writes a booking's six total columns; nothing else may.
- **SLA numbers** (`SLA-2026-0001`) are reserved inside the same transaction as the booking insert,
  so a failed save leaves no gap.
- **Access** to a booking always goes through `load_booking_for_user()`, which answers 404 rather
  than revealing that a record exists.
- **Catalog snapshots**: a booking copies an item's label, unit and rate when the line is added, so
  later catalog edits never change existing bookings or old invoices.
- **Lifecycle**: `draft → confirmed → completed`, or cancelled from draft or confirmed. Confirmed
  bookings are read-only for vendors; the admin can make direct edits, while anything that changes
  the agreed terms is an amendment (reason required, signed copy on file, `Rev N`, signatures cleared).
- **Payments** are never edited or deleted, only voided, and refunds only apply to cancelled bookings.
- **Venue locations** are snapshotted onto the booking, like catalog labels: the Venues page can move
  a venue at any time without rewriting the paperwork of bookings already taken.
- **The calendar** is deliberately not scoped to the signed-in vendor — a vendor who cannot see that
  Lawn A is taken will promise it anyway. Other vendors' bookings show the venue and status only.
- **Approvals** gathers what is waiting on the admin: vendor accounts asking to join (decided there)
  and draft bookings, each with the reason it cannot be confirmed yet. Confirming still happens on
  the booking, where the venue is re-checked at that moment.
- **The audit log is append-only.**

## Local development

1. Start MariaDB (XAMPP Control Panel → MySQL). It listens on **port 3307** here.
2. Copy `config/config.sample.php` to `config/config.php` and adjust if needed.
3. Create the database once:
   ```bash
   C:/xampp/mysql/bin/mysql.exe -u root -P 3307 -e "CREATE DATABASE aomess CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   C:/xampp/mysql/bin/mysql.exe -u root -P 3307 aomess < database/schema.sql
   C:/xampp/mysql/bin/mysql.exe -u root -P 3307 aomess < database/seed.sql
   ```
4. Run the app:
   ```bash
   C:/xampp/php/php.exe -S 127.0.0.1:8080 -t public
   ```
5. Open http://127.0.0.1:8080 and sign in as `admin` / `ChangeMe-2026` (you must then change it).

Tests: see `docs/TESTING.md`. Deployment: see `docs/DEPLOY.md`.
