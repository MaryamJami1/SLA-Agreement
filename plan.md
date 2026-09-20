# AO Mess Event Booking & SLA System (PHP + MySQL, Hostinger)

> Status: **Rev 6 (final) — BUILT**. This is the specification the code follows; the revision notes below record how it got here.
> Rev 5: bootstrap path by folder depth, refunds on cancelled bookings only, one booking per venue per day, refund cap.
> Rev 6 (from plan review + code review of the mockups): void permissions per status, cancelled-booking balance, staging/web-root guard, amendment lock order, booking ownership rules, completion rules, attachment voiding, catalog items on existing bookings, unsaved-changes warning, session/throttling/download hardening, extra audit actions, one totals-recompute function, attachment writes under the booking lock, cross-IP login slowdown, session file cleanup.
> Rev 6 final additions: transactional cancel/complete, venue history protection, append-only audit log, server-side numeric validation, forced-password-change lockdown, cancellation limited to draft/confirmed, `vendor_id` must be an active approved vendor, venue-changing amendments lock old + new venue rows in ascending id order.
> Rev 6 review fixes: attachment permissions per status, device cookie for login limit 3, amendment steps reordered (locks first), `Secure` cookie by HTTPS/`APP_ENV`, refund-percentage validation and hint base, confirm re-validation on the locked row, active venue required to confirm, Phase 4/7 signed-copy testing note, `realpath()` web-root guard.

> **Build status (20 Sep 2026): Phases 0–8 are built and tested locally. Deployment to Hostinger is
> the remaining step** — see `docs/DEPLOY.md` and `docs/GO_LIVE_CHECKLIST.md`. Local test totals:
> 271 unit, 211 database, 7 concurrency and 301 end-to-end HTTP checks.
> Still open with the client: contract wording, charge rates, the venue list, who confirms bookings
> and records payments, and the office address for the letterhead (Section 13).

## 1. Context

AO Mess / ASK Organizers (event planning & catering coordination, Karachi) has no working booking system. What exists:

- Two HTML mockups (`refrences-files/sla_form_system (16).html`, `(21).html`): the approved look (vintage ledger style) and structure: SLA form, records list, customer invoice, login. They save through a fake `window.storage` API, so nothing actually persists.
- Three documents from a **different business** (a vendor at "PIA Planetarium", co-branded CAA / Airport Hotel): an MS-Access-style entry screen, a printed customer invoice, and an operations/inventory sheet. They are **structural inspiration only**: itemized charges, decor checklist, ops sheet, payment breakdown. Their venue-specific charge names (CAA Charges, PSF Tax, Coldring 85/Person, Bridge Charges) are not carried over.

Goal: a real PHP + MySQL web app on Hostinger shared hosting where AO Mess admins and approved vendors register bookings, print the legal SLA agreement, print customer invoices and ops sheets, and record payments. It needs real persistence, real authentication, and a money model that holds up to audit.

## 2. Decisions

| Topic | Decision |
|---|---|
| Scope | Booking form, registry, SLA Agreement, Customer Invoice, vendor ops sheet, payments, attachments |
| Stack | Procedural PHP 8.1+ with PDO, no framework, no Composer at runtime; MySQL 8 or MariaDB 10.4+ |
| Style / branding | Vintage ledger design from the mockups; AO Mess / ASK Organizers branding |
| Charges | Admin-managed catalog with neutral, renameable labels; each booking stores a **snapshot** of the label and rate |
| Payments | **Multiple payments per booking** (installments + refunds); payments are never edited or deleted, only voided |
| Vendor signup | Self-registration creates a `pending` account; admin approval required |
| Ownership | `vendor_id` (who the booking belongs to) is separate from `created_by` (who typed it) |
| Lifecycle | `draft → confirmed → completed`; a draft or confirmed booking can instead go `→ cancelled`. Only drafts can be deleted; confirmed bookings are locked for vendors |
| Hosting layout | Only `public/` is web-accessible; app code, config, SQL and uploads live above `public_html` |

## 3. What's reused from the mockups vs rebuilt

**Reused (ported, not redesigned):**
- CSS palette, typography and layout → `public/assets/css/style.css`.
- The print trick: the page is wrapped in a single-row `<table class="page-table">` with the letterhead in `<thead>`, so the letterhead reprints on every printed page.
- Client-side conveniences: live totals preview, event-day autofill, cheque-field toggle, print button → `public/assets/js/app.js`. These are cosmetic only; the server recomputes everything.
- **New in `app.js`: unsaved-changes warning.** Once the booking form has been edited, leaving the page (a link, Back, closing the tab) triggers a `beforeunload` prompt; submitting the form clears it. The mockup silently discarded unsaved edits when another record was opened.
- `renderInvoice()` layout: `INV-` number derived from the SLA ID, Billed To / Event Reference boxes, amount in words, signature lines.
- Amount in words with **lakh/crore grouping**, and Rs. formatting with South Asian digit grouping (`12,34,567`).
- Logo: the base64 PNG embedded in the mockup is extracted to `public/assets/img/logo.png`.

**Rebuilt:**
- `window.storage` → PDO + MySQL.
- Plaintext passwords → `password_hash()` / `password_verify()`.
- Instant vendor access → pending + admin approval.
- A single "total package" number → itemized charges, discount, multiple payments.

## 4. Folder layout (Hostinger-safe)

Only `public/` is uploaded into `public_html`. Everything else sits one level above it (Hostinger path: `domains/<domain>/`), where the browser can't reach it.

```
project/
  public/                         -> contents uploaded into public_html (web root)
    index.php                     -> redirects to the list or the login page
    .htaccess                     -> force HTTPS, Options -Indexes
    .user.ini                     -> upload_max_filesize / post_max_size
    auth/       login.php  logout.php  register.php  change_password.php
    admin/      vendors.php (approve / disable / reset password)  catalog.php  venues.php  preflight.php
    booking/    list.php  form.php  save.php  confirm.php  cancel.php  complete.php  delete.php
    payments/   add.php  void.php
    documents/  agreement.php  invoice.php  vendor_sheet.php  download.php  upload.php  attachment_void.php
    assets/     css/style.css  js/app.js  img/logo.png
  app/                            -> never web-accessible
    bootstrap.php                 -> timezone, config, session, DB, CSRF check on every POST
    db.php  auth.php  csrf.php  helpers.php  money.php  counters.php  audit.php
    bookings.php                  -> load_booking_for_user(), field rules, form lines, draft save
    lifecycle.php                 -> confirm, complete, cancel, delete draft, amendments
    payments.php  attachments.php  documents.php  admin_data.php (venues, catalog)
    views/      layout_top.php  layout_bottom.php  booking_*.php  doc_*.php  form_helpers.php
  config/       config.sample.php  config.php (real credentials, never committed)
  database/     schema.sql (= schema version 1)  seed.sql  migrations/ (README; 002_… onwards after launch)
  storage/      uploads/  logs/  sessions/
  tests/        run.php                -> plain-PHP tests for pure functions
                db_check.php           -> local-only checks against a throwaway <DB_NAME>_test database
                concurrency_check.php  -> parallel processes: race-safe confirm, deadlock-free amendments
                e2e*.sh                -> end-to-end HTTP checks, one script per phase
  docs/         DEPLOY.md  GO_LIVE_CHECKLIST.md  TESTING.md
```

Every page in `public/` starts by requiring the bootstrap. The relative path depends on how deep the file sits (the depths are the same after upload to `public_html`):
- Files directly in `public/` (e.g. `index.php`): `require __DIR__ . '/../app/bootstrap.php';`
- Files in a subfolder (e.g. `booking/form.php`, `auth/login.php`): `require __DIR__ . '/../../app/bootstrap.php';`

If an account can't place files above `public_html`, the fallback is `Require all denied` `.htaccess` files in `app/`, `config/`, `database/` and `storage/`.

**Web-root guard.** The `../` paths are only safe when the web root sits directly beside `app/`. A subdomain whose document root is nested (e.g. `public_html/staging/`) would put `app/` inside the public web root. So:
- Every installation (production and staging) gets its own folder with its own `app/`, `config/`, `database/`, `storage/` beside its web root. Staging uses `domains/<staging-subdomain>/public_html`, never a folder inside the production `public_html`.
- `bootstrap.php` refuses to run (HTTP 500 plus a log line) if its own folder is inside the document root, unless the config sets `ALLOW_APP_IN_WEBROOT = true` for the `.htaccess` fallback above. Both sides are resolved with `realpath()` first — `realpath($_SERVER['DOCUMENT_ROOT'])` and `realpath(__DIR__)` — and compared with a trailing `/` (so `/public_html2` isn't mistaken for being inside `/public_html`). On Hostinger `public_html` is sometimes a symlink to `domains/<domain>/public_html`; comparing unresolved paths could miss a real problem or block a correct install. If `realpath()` fails, the guard treats it as a failure and refuses to run.

The empty skeleton folders created earlier (`config/`, `includes/`, `auth/`, `booking/`, `documents/`, `assets/`, `database/`, `uploads/`) get reorganized into this layout at the start of Phase 1.

## 5. Database schema

All tables use `ENGINE=InnoDB`, `utf8mb4_unicode_ci`, and `DECIMAL(12,2)` for money. The SQL stays portable between MySQL 8 and MariaDB: no `JSON` column type (store JSON as `TEXT`) and no reliance on `CHECK` constraints. Shared row locks use `LOCK IN SHARE MODE`, not MySQL 8's `FOR SHARE`, which MariaDB 10.4 (local XAMPP) rejects as a syntax error.

### `users`
`id`, `username` (unique), `password_hash`, `role` ENUM('admin','vendor'), `name`, `firm_name`, `rep_name`, `contact`, `status` ENUM('pending','active','disabled'), `must_change_password` TINYINT, `last_login_at`, `created_at`.
A vendor's firm, rep name and contact live here and are copied onto each booking at save time. `status` and `must_change_password` are re-read on **every request**, not only at login (Section 10).

### `login_attempts`
`id`, `username`, `ip`, `success` TINYINT, `attempted_at`. Indexed on `(username, attempted_at)`, `(ip, attempted_at)` and `(username, ip, success)`. Every login attempt is logged, successful or not; successful rows identify an account's **known IPs**. Used for throttling (Section 10). Rows older than 90 days are purged on login.

### `counters`
`year_key` (PK), `seq`. Holds the SLA number sequence (Section 7).

### `venues`
`id`, `name`, `is_active`, `sort_order`. Seeded with Lawn A, Lawn B, Lawn C, Pool side, Hall, managed by the admin. A real table (not free text) is what makes the double-booking check reliable.

**Venue history is protected.** Bookings store `venue_id`, not a snapshot of the name, so a venue record must never change meaning:
- Once **any** booking references a venue (any status, drafts included), the venue can't be renamed or deleted. It can only be **deactivated** (`is_active = 0`); `sort_order` can still change. A venue under a different name is created as a new record.
- A venue no booking has ever referenced can still be renamed or deleted (e.g. to fix a typo right after creating it).
- The check runs in one transaction: lock the venue row (`SELECT … FROM venues WHERE id = ? FOR UPDATE`), check `EXISTS (SELECT 1 FROM bookings WHERE venue_id = ?)`, then rename/delete or refuse. A booking save that references the venue takes a shared lock on the venue row through the foreign key, so it can't slip in between the check and the change. `bookings.venue_id` is `ON DELETE RESTRICT` as a database-level backstop.
- Inactive venues aren't offered on new bookings or when changing a booking's venue; bookings that already use them keep showing the name.

### `item_catalog`
The master list the admin manages. It drives the booking form's charges, decor checklist and ops items.
`id`, `section` ENUM('charge','decor_general','decor_light','decor_generator','decor_flower','decor_extra','ops_item'), `name`, `unit` ('fixed','per unit','per head'), `default_rate` (nullable), `is_active`, `sort_order`.
Seeded from the references with neutral names: charges (Venue, Generator, Cleaning, Service, Stage, Lighting/Tracing, Valet, Misc), decor items (from the "GENERAL DECORD" checklist), and ops items (from the inventory sheet). The admin renames or retires items; history is unaffected because bookings store snapshots.

### `bookings`
One row per booking/agreement.

- **Identity & lifecycle:** `id`, `unique_id` VARCHAR(20) UNIQUE NOT NULL, `vendor_id` FK→users (nullable while drafting; required to confirm), `created_by` FK, `updated_by` FK, `status` ENUM('draft','confirmed','completed','cancelled') default 'draft', `version` INT (optimistic locking, bumped on every save), `revision` SMALLINT default 0 (legal amendment count, see Section 8), `revised_at` (nullable), `confirmed_at`, `completed_at`, `cancelled_at`, `cancelled_by`, `cancellation_reason`, `created_at`, `updated_at`.
- **Agreement:** `agreement_day`, `agreement_month`, `agreement_place` (default 'Karachi'). These stay **free text** ("14th", "September, 2026") because they are legal phrasing.
- **Client:** `client_name`, `client_relation` (S/o, W/o, D/o), `client_cnic` CHAR(15) (validated `#####-#######-#`), `client_contact`, `client_contact2`, `client_company`, `client_address`.
- **Reference:** `reference_name`, `reference_department` (who referred the client; optional).
- **Vendor snapshot:** `firm_name`, `rep_name`, `rep_contact` (copied from the vendor's profile; the admin can override).
- **Event:** `event_type`, `event_type_other`, `event_date` DATE, `alt_date` DATE (nullable), `venue_id` FK→venues (nullable), `venue_other`, `setup_time`, `start_time`, `guests`.
- **Catering:** `menu_type`, `menu_type_other`, `food_items` TEXT.
- **Decoration standards:** `theme`, `stage`, `stage_other`, `stage_desc`, `entrance`, `entrance_other`, `lighting`, `lighting_other`, `floor_covering`, `floor_other`, `addl_decor`, `decor_by`.
- **Furniture & manpower:** `sofas`, `chairs`, `tables_dining`, `tables_buffet`, `waiters`, `chefs`. Total staff is calculated, not stored.
- **Money inputs:** `per_head_rate`, `discount`.
- **Money totals** (denormalized for listing and reporting, recomputed by the server on every save and payment change): `guest_charges`, `charges_total`, `sub_total`, `grand_total`, `paid_total`, `balance`.
- **Terms:** `due_on` ('Event Day' / 'As agreed'), `refund_pct_30`, `refund_pct_7`, `special_commitments` TEXT.
- **Signatures:** `vendor_sign_name`, `vendor_sign_date`, `client_sign_name`, `client_sign_date`.
- **AO Mess record:** `received_by`, `received_date`, `received_time`.

**Ownership rules** (the mockup overwrote the owner on every save, so a vendor lost access to their own record once an admin re-saved it):
- `created_by` is written once, on the first INSERT, and is never part of any UPDATE.
- When a **vendor** creates a booking, the server sets `vendor_id` to that vendor's id; any `vendor_id` in the POST is ignored. Vendors can never change `vendor_id`.
- Only an **admin** can set or change `vendor_id`: freely on a draft, and only as an amendment on a confirmed booking (Section 8).
- **`vendor_id` must be an active, approved vendor.** Whenever `vendor_id` is set or changed, and again when a booking is confirmed, the server checks that the referenced user has `role = 'vendor'` and `status = 'active'` (pending and disabled vendors, and admin accounts, are refused). The check reads the user row with `SELECT … FROM users WHERE id = ? LOCK IN SHARE MODE` inside the save or confirm transaction, after the booking lock, so a vendor can't be disabled between the check and the commit. The admin's vendor dropdown lists only active vendors. A booking that already belongs to a vendor who is later disabled keeps its `vendor_id`, and saves that don't change it aren't blocked, but it can't be confirmed until it is reassigned to an active vendor.
- `updated_by` and `updated_at` are the only "who/when" fields a normal save changes.

The event day of the week is **not stored**; it's calculated from `event_date` when displayed.
Indexes: `event_date`, `(vendor_id, status)`, `status`, `client_name`, `(venue_id, event_date)`.

### `booking_line_items`
`id`, `booking_id` FK (ON DELETE CASCADE, which only fires for draft deletion), `catalog_id` FK (nullable, ON DELETE SET NULL), `section` (same values as the catalog), `label` (**snapshot** of the catalog name), `unit_snapshot` ('fixed','per unit','per head' — **snapshot** of the catalog unit), `is_selected`, `qty`, `rate` (**snapshot**, copied from `default_rate` when the line is added), `amount`, `notes`, `sort_order`.
Label, unit and rate are all copied when the line is added, so later catalog edits never change an existing booking's lines or totals.
**When lines are added:** the form shows the booking's existing lines plus every **active** catalog item that isn't on the booking yet (shown unselected, with the catalog's current default rate). A row for such an item is inserted only when it is saved as selected/included; unselected new items are not stored. On a confirmed booking, adding a line follows the amendment rules (ops items excepted). Retired catalog items stay on bookings that already have them but are not offered to new ones.
**Only `section = 'charge'` rows carry money**; their `amount` is computed server-side from `unit_snapshot` using the rules in Section 6. Decor and ops rows record inclusion, quantity and notes only, matching the references, where those lists have no per-line prices.

### `payments`
`id`, `booking_id` FK (ON DELETE RESTRICT), `kind` ENUM('payment','refund'), `amount`, `paid_on` DATE, `method` ENUM('cash','bank_transfer','cheque','online'), `bank_name`, `reference_no` (cheque or transaction number), `notes`, `recorded_by` FK, `created_at`, `voided_at`, `voided_by`, `void_reason`.
Rows are never deleted, and the only update ever made is setting the `void*` fields, once. A mistake is voided and re-entered. Every payment write is a single transaction (Section 6).

### `attachments`
`id`, `booking_id` FK (ON DELETE RESTRICT — rows are removed explicitly by the draft-deletion flow in Section 8, which also deletes the files), `original_name`, `stored_name` (random hex), `mime`, `size_bytes`, `signed_revision` SMALLINT (nullable; set when the file is the signed copy of that revision, see Section 8), `uploaded_by`, `uploaded_at`, `voided_at`, `voided_by`, `void_reason`. This replaces the single "Upload Invoice" field and allows several files per booking.
**Voiding an attachment** (admin only, reason required, audited as `attachment_void`): used for a wrong upload or a file wrongly marked as a signed copy. Like payments, attachment rows are never deleted outside the draft-deletion flow; a voided attachment is hidden from the booking page (admins can show it), still downloadable by admins, and **does not count** as a signed copy for the amendment check. The file stays on disk. Vendors can't void attachments.

**Attachment writes lock the booking row**, the same lock the amendment's signed-copy check holds, so a void or upload can't slip in between that check and the amendment's commit:
- **Upload:** validate the file (type, size) and move it into `storage/uploads/` under its random name first. Then, in one transaction: lock the booking (`SELECT … FROM bookings WHERE id = ? FOR UPDATE`, after `load_booking_for_user()`), check against the locked row that this user may upload in the booking's current status (Section 8, "Attachments"), and if "signed copy of Rev N" was chosen, check that N equals the booking's **current** `revision` (read under the lock); insert the row; write `attachment_add`; commit. If the transaction fails or rolls back, delete the just-moved file.
- **Void:** in one transaction: lock the booking row (admin only, any status), then `UPDATE attachments SET voided_at … WHERE id = ? AND booking_id = ? AND voided_at IS NULL`. If no row changes (already voided or wrong booking), abort. Write `attachment_void`, commit.
- Whichever of a void and an amendment gets the booking lock first finishes first. If the void wins, the amendment then sees no valid signed copy and is refused. If the amendment wins, the void runs afterwards against the amended booking, and the scan stays on record as voided.

### `audit_log`
`id`, `user_id`, `booking_id` (nullable; a plain column, deliberately **not** a foreign key, so a booking's history survives when a draft is deleted), `action` (create, update, amend, confirm, complete, cancel, delete_draft, payment_add, payment_void, attachment_add, attachment_void, vendor_register, vendor_approve, vendor_disable, password_reset, password_change, login_ok, login_fail, catalog_change, venue_change), `details` TEXT (JSON of the changed fields, old → new), `ip`, `created_at`. `catalog_change` and `venue_change` record old → new name, unit, default rate and active flag, because catalog rates feed into new bookings' money.
**Append-only.** The application only ever `INSERT`s into `audit_log`; no code path issues `UPDATE` or `DELETE` against it, including draft deletion, vendor disabling and any cleanup or purge (the 90-day purge applies to `login_attempts` only). The admin screens offer no way to edit or remove entries. All audit writes go through one function in `app/audit.php`, which is the only code that writes to the table; everywhere else may only `SELECT` from it.

### `schema_version`
`version`, `applied_at`. Every schema change after launch is a numbered file in `database/migrations/`, applied through phpMyAdmin after a backup.

## 6. Money model

One chain, always computed on the server by `app/money.php`, which does the arithmetic in integer paisa (never PHP floats):

```
guest_charges = per_head_rate × guests
charges_total = sum(amount) of selected charge line items
sub_total     = guest_charges + charges_total
grand_total   = sub_total − discount                        ("Net Amount" on the invoice)
paid_total    = sum(non-voided payments) − sum(non-voided refunds)
balance       = grand_total − paid_total                    (can go negative, e.g. overpaid)
              = 0 when status = 'cancelled'                 (nothing further is owed)
```

- Discount can't exceed `sub_total` (see "Server-side numeric validation" below).
- On a cancelled booking, the refund is recorded as a `refund` payment, so `paid_total` shows what AO Mess actually kept. The contract `grand_total` stays stored unchanged for the record, but `balance` is forced to 0 (by `money.php`, on cancellation and on every later payment write), so a cancelled booking never shows the client as owing the rest of the contract. Lists and documents label `paid_total` on a cancelled booking as **"Amount retained"**.
- **Refund policy hint.** The refund form on a cancelled booking shows a suggestion, never enforced: days between the `cancelled_at` date and `event_date` → ≥ 30 days uses `refund_pct_30`, ≥ 7 days uses `refund_pct_7`, 0–6 days uses 0%. The percentage applies to the **total of non-voided payments** (what the client paid, before any refunds), and refunds already made count against it: suggested refund = `max(0, min(refundable, pct × total_paid − already_refunded))`, where `refundable` is the refund cap (below). Shown as "Policy suggests refunding Rs. X (P% of Rs. T paid, Rs. R already refunded)". So a second refund never gets the full percentage again. No suggestion is shown, only "No policy suggestion — …" with the reason, when: `event_date` is empty (a draft cancelled before a date was set), the relevant refund percentage is empty, cancellation happened after the event date, or `refundable` is 0. The admin enters the actual amount; only the refund cap below is enforced.
- The invoice shows the full chain plus a table of the payments received.

**Server-side numeric validation.** Every numeric input is validated on the server before anything is written; browser checks (`min`, `type="number"`) are conveniences only. Parsing lives in `app/money.php` / `app/helpers.php`, so every page uses the same rules.

| Field(s) | Accepted |
|---|---|
| Money inputs: payment and refund amounts, `per_head_rate`, charge line `rate`, `discount` | Plain decimal: digits with an optional `.` and at most 2 decimals (commas and "Rs." stripped first). No negative sign, exponent, `NaN`/`INF` or empty string where a value is required. At most 9,99,99,99,999.99, the `DECIMAL(12,2)` limit. |
| Payment and refund `amount` | Greater than 0. |
| `per_head_rate`, charge line `rate`, `discount` | 0 or more. |
| `guests`, `qty`, and the furniture/manpower counts (`sofas`, `chairs`, `tables_dining`, `tables_buffet`, `waiters`, `chefs`) | Whole numbers, 0 or more, at most 1,00,000. |
| `refund_pct_30`, `refund_pct_7` | Empty (no policy), or a number from 0 to 100 with at most 2 decimals; stored as `DECIMAL(5,2)` NULL. |

Cross-field rules, checked on the server with the booking row locked, after the totals are computed from the validated inputs:
- **`discount ≤ sub_total`**, using the server-computed `sub_total` for this save, not a value from the form.
- **`refund ≤ refundable`**, the refund cap in step 2 of the payment transaction below.
- Every computed amount (each line `amount`, `guest_charges`, `sub_total`, `grand_total`) must also fit within the `DECIMAL(12,2)` limit; a save whose totals would overflow is rejected rather than truncated.

Any failure rejects the whole request: nothing is written (no booking update, payment row or audit row), and the form is shown again with the user's input and a message next to each bad field. Invalid values are never silently changed to 0 or made positive.

**Charge line amounts.** The server computes every charge row's `amount` from its `unit_snapshot` and `rate`. An amount sent in the POST is ignored.

| `unit_snapshot` | `amount` | `qty` |
|---|---|---|
| `fixed` | `rate` | not used (stored NULL) |
| `per unit` | `qty × rate` | required, 0 or more |
| `per head` | `guests × rate` | not used; the booking's `guests` is always the multiplier |

- **`per_head_rate` is the main catering rate** (food per guest) and produces `guest_charges`. Catalog charges may **also** be `per head` (for example a per-person drinks or service charge). There is one rule for all of them: every per-head amount in the system is the booking's `guests` × a rate. A per-head line never has its own guest count.
- **Changing `guests`** recomputes `guest_charges` and every `per head` line on the next save. `fixed` and `per unit` lines are unaffected.
- **Rates:** the rate is copied from the catalog's `default_rate` when the line is added. It can be entered or adjusted on the booking while the booking is editable (it must be entered when the catalog has no default). The booking's rate is the one used.
- **Unselected rows** (`is_selected = 0`) get amount 0 and are excluded from `charges_total`.

**Payment writes are one transaction.** Adding a payment, adding a refund, and voiding a payment all run the same way; any failure rolls back everything:
1. Begin the transaction and lock the booking row with `SELECT … FROM bookings WHERE id = ? FOR UPDATE`. The booking is first authorized through `load_booking_for_user()`, and its status must allow the operation (Section 8 table).
2. **Refund cap.** With the booking row still locked, compute `refundable = SUM(non-voided payments) − SUM(non-voided refunds)`. Non-voided refunds can never total more than non-voided payments. The row lock from step 1 makes the check race-safe, so two simultaneous operations can't both pass.
   - **Adding a refund:** if the refund amount is greater than `refundable`, roll back and reject with "Refund exceeds the refundable amount (Rs. X)".
   - **Voiding a payment** (`kind = 'payment'`): if the payment's amount is greater than `refundable`, roll back and reject with "Void the related refunds first — voiding this payment would leave refunds greater than payments".
   - **Voiding a refund** always passes this check, because it only increases `refundable`.
3. Insert the `payments` row, or for a void set `voided_at`, `voided_by` and `void_reason` with `WHERE id = ? AND voided_at IS NULL`. If no row is updated (already voided), abort.
4. Recompute the totals with `recompute_booking_totals($pdo, $booking_id)` (see below) and update the booking.
5. Write the `audit_log` row (`payment_add` or `payment_void`), then commit.

Booking saves lock the same booking row and recompute `paid_total` from the payments table inside their own transaction, so a form save and a payment can't overwrite each other's totals.

**One recompute function.** `recompute_booking_totals($pdo, $booking_id)` in `app/money.php` is the **only** code that writes `guest_charges`, `charges_total`, `sub_total`, `grand_total`, `paid_total` and `balance`. The caller must already hold the booking row lock. It:
1. Reads the booking's `status`, `per_head_rate`, `guests`, `discount` and its charge line items, and recomputes `guest_charges` … `grand_total` (the chain above).
2. Computes `paid_total` from a `SUM` over the booking's non-voided payment rows (never by adding to the old value).
3. Sets `balance = grand_total − paid_total`, **or 0 when `status = 'cancelled'`**.
4. Writes all six columns in one `UPDATE`.

It's called by every booking save, every payment add / refund / void, and by `cancel.php` after the status changes to `cancelled` (so the balance drops to 0 at cancellation). No other code computes `balance`.

## 7. Unique ID generation (corrected)

```sql
INSERT INTO counters (year_key, seq) VALUES (:year, LAST_INSERT_ID(1))
ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1);
```

Then read the value with an explicit `SELECT LAST_INSERT_ID()` on the same connection (clearer than relying on the driver's `lastInsertId()` after an upsert) and format it as `SLA-{year}-{seq:04d}`.

- `LAST_INSERT_ID(1)` in `VALUES` fixes the earlier bug, where the first booking of each year would have been numbered `0000`.
- The counter statement and the `bookings` INSERT run in **one transaction**. A failed save rolls both back, so no numbers are lost.
- The year is the **creation** year, taken from PHP `date('Y')` in Asia/Karachi. It's passed in as a parameter, never computed with MySQL `NOW()`.
- The ID is assigned on first save only, never when a blank form is opened. Saves use Post/Redirect/Get, so a refresh can't create a duplicate.
- The invoice number is the same number with an `INV-` prefix. The invoice date is `confirmed_at`, so reprints show the same date.

## 8. Booking lifecycle and permissions

| Status | Vendor (owner) | Admin | Payments / refunds / voids (admin only) | Allowed transitions |
|---|---|---|---|---|
| draft | view, edit, attach files, delete (if no payments) | everything | add payment; void | → confirmed (admin), → cancelled (admin), or deleted (owner vendor or admin; only if no payments exist) |
| confirmed | view and print only | direct edits or amendments (see "Editing a confirmed booking" below) | add payment; void | → completed (admin), → cancelled (admin) |
| completed | view and print only | no edits | add payment; void | none |
| cancelled | view and print only | no edits | add refund; void (payment voids subject to the refund cap, Section 6) | none |

- **Refunds are allowed only on cancelled bookings.** Draft, confirmed and completed bookings accept payments but not refunds. An overpaid confirmed or completed booking shows a negative balance; refunding it in the system isn't supported unless it's requested later.
- **Voids are allowed in every status**, by the admin, with a reason. A void corrects a data-entry mistake; it isn't a refund.
- **Attachments:**

  | Status | Vendor (owner) | Admin |
  |---|---|---|
  | draft | upload | upload, void |
  | confirmed | view/download only | upload, void |
  | completed | view/download only | upload, void (e.g. final receipts) |
  | cancelled | view/download only | upload, void (e.g. a signed cancellation letter) |

  Vendors never void attachments and never see voided ones. The "signed copy of Rev N" option is offered only while the booking is `confirmed` (the only status that can be amended); on other statuses an upload is a plain attachment. Both upload and void check these rules against the locked booking row (Section 5, attachments).
- **Cancelling:** only **draft** and **confirmed** bookings can be cancelled, and only by the admin, because a booking being cancelled may have payments and needs a reason (`cancellation_reason` is required). Completed and cancelled bookings can't be cancelled. A vendor who wants to abandon an unpaid draft deletes it instead.
- **Completing requires:** admin, status `confirmed`, and `event_date` ≤ today. A non-zero balance doesn't block completion but shows a warning ("Rs. X still outstanding" / "overpaid by Rs. X") that the admin must acknowledge; the balance at completion is written to the `complete` audit row.
- **Cancel and complete are single transactions.** `cancel.php` and `complete.php` each run, in one transaction (any failure rolls back everything, so no half-cancelled or half-completed booking can exist):
  1. After authorizing through `load_booking_for_user()`, lock the booking row **first**: `SELECT … FROM bookings WHERE id = ? AND version = ? FOR UPDATE`. A stale version aborts with the conflict message.
  2. Validate against the **locked** row: the current status allows the transition (Section 8 table), plus a non-empty `cancellation_reason` for cancel, or `event_date` ≤ today and the balance acknowledgement for complete.
  3. Update the booking: `status`, `cancelled_at` / `cancelled_by` / `cancellation_reason` or `completed_at`, `updated_by`, `version + 1`.
  4. Call `recompute_booking_totals()` (Section 6), which drops the balance to 0 for a cancelled booking.
  5. Write the `cancel` or `complete` audit row (old → new status, reason, balance), then commit.
  Neither takes a venue lock, so the "venue first, then booking" order (Section 9) is unaffected.

- **Confirming requires:** a `vendor_id` that is an active, approved vendor (Section 5, ownership rules), client name, `event_date`, a venue that is **active** (or a `venue_other` text), `grand_total > 0`, and no venue conflict. A draft whose venue was deactivated after it was saved can't be confirmed until it is moved to an active venue; the form says why. Confirmed bookings already on a venue that is later deactivated are unaffected, and an amendment that keeps the same venue is allowed; an amendment can't move a booking *to* an inactive venue.
- **Only the admin confirms bookings and records payments** by default. This is an open question for the client (Section 13).
- **Draft documents print with a "DRAFT" watermark.**
- **Optimistic locking:** the form carries a hidden `version`. The save runs `UPDATE … WHERE id = ? AND version = ?`. If no row is updated, someone else changed the booking first; the user sees a conflict message instead of silently overwriting their changes.

### Deleting a draft

Only drafts with no payment rows (voided ones included) can be deleted, as in the table above. The deletion runs in one transaction:
1. Lock the booking with `SELECT … FROM bookings WHERE id = ? AND version = ? FOR UPDATE`. Confirm it is still a draft and has no payments; otherwise abort.
2. Read the `stored_name` of every attachment for the booking.
3. Write the `delete_draft` audit row. Its `booking_id` keeps the deleted booking's id, and `details` records `unique_id`, client name, event date, venue, `grand_total` and the attachments' original file names, because the booking row won't exist afterwards.
4. Delete the booking's `attachments` rows, then the booking. Its `booking_line_items` are removed by the existing cascade.
5. Commit.
6. **After** the commit, delete each attachment file from `storage/uploads/`. Files are removed only after the commit, so a rolled-back deletion never leaves database rows pointing at missing files. If a file can't be deleted, the stored name is logged to `storage/logs/`; the leftover file can't be downloaded, because `download.php` needs its database row, and can be removed by hand.

**Audit history is preserved.** Deleting a draft never deletes `audit_log` rows. Every earlier entry for the booking (create, update, attachment_add, …) and the `delete_draft` entry stay, still carrying the booking's id. SLA numbers are never reused, so that history can't be confused with a later booking.

### Editing a confirmed booking (amendments)

Once a booking is confirmed, its SLA counts as issued. Only the admin can edit it (unchanged from above). Each field falls into one of two groups:

**Direct edits** — saved normally and audited (`update`); no revision, no re-signing. These fields don't change what the parties agreed:
- Client contact numbers and address.
- `reference_name`, `reference_department`, `decor_by`.
- `received_by`, `received_date`, `received_time`.
- `ops_item` line items (they appear only on the internal operations sheet).
- Attachments and payments, which keep their own flows (refunds apply only to cancelled bookings).

**Amendment fields** — anything printed on the SLA as an agreed term, or anything that changes money:
- **Parties:** client name, relation and CNIC; `vendor_id` and the firm/rep snapshot.
- **Event:** type, date, alternate date, venue, setup and start times, guests.
- **Services:** catering, decoration standards, decor checklist items, furniture and manpower.
- **Money:** `per_head_rate`, charge line items, `discount`, `due_on`.
- **Terms:** refund percentages, special commitments, agreement day/month/place.

**Saving a change to any amendment field** (a save that mixes both groups counts as an amendment). The steps run in this order, all in one transaction; **step 0 always comes first**, because its locks must be taken before anything else:

0. **Take the locks, venues before the booking.**
   1. Read the booking **without** a lock and compare its `venue_id` / `event_date` with the POST.
   2. If either changed, begin the transaction and lock **both** the old and the new venue rows — every one that is a real venue (not `venue_other`), without duplicates — in **ascending `id` order**, with one statement: `SELECT id FROM venues WHERE id IN (:old_venue_id, :new_venue_id) ORDER BY id FOR UPDATE`. If only the date changed, old and new are the same venue and one row is locked. The fixed order means two amendments moving bookings in opposite directions between the same two venues (A → B and B → A) lock them in the same order and can't deadlock. If neither changed, no venue is locked.
   3. Lock the booking (`SELECT … FROM bookings WHERE id = ? AND version = ? FOR UPDATE`). A stale version aborts, which also covers someone else changing the venue or date between sub-steps 1 and 3 (so the venues locked in sub-step 2 are still the right ones).
   4. If `vendor_id` is changing, lock the new vendor's `users` row with `LOCK IN SHARE MODE` and check it is an active vendor (Section 5, ownership rules).
   Locking the booking first and a venue second is never allowed; it can deadlock against a confirmation. Every check below reads the **locked** booking row.
1. The admin must enter an amendment reason; the save is refused without one.
2. **Signed copy on file first.** If the current revision was signed (any `vendor_sign_*` or `client_sign_*` value is filled in), a **non-voided** attachment with `signed_revision` = the current revision must already exist. Otherwise the save is refused with "Upload the signed copy of Rev N before amending." This is checked with the booking row locked, in the same transaction as the amendment. The scan is uploaded beforehand through the normal attachment upload, with a "signed copy of Rev N" option that sets `signed_revision`. A revision that was never signed needs no scan. The rule follows AO Mess policy through the config setting `REQUIRE_SIGNED_COPY_FOR_AMENDMENT` (default: on).
3. **Validation and venue check.** The numeric and cross-field validation (Section 6) runs. If `venue_id` or `event_date` changed, the new venue must be active (unless it is unchanged), and the locking conflict read from Section 9 step 3 runs against the new venue and date, with the venue(s) already locked in step 0. A conflict refuses the save unless the admin overrides with a reason.
4. `revision` goes up by 1 and `revised_at` is set; `vendor_sign_*` and `client_sign_*` are cleared, so the amended agreement prints with blank signature lines and must be signed again.
5. Save the fields (with `version + 1`), then call `recompute_booking_totals()` (Section 6). Payments are untouched; `balance` is recomputed and can go negative (money owed back to the client).
6. Write the `amend` audit row with the reason, any venue-conflict override reason, and old → new values for every changed field, then commit.

**Documents after an amendment:**
- The agreement and invoice keep their numbers and add a revision suffix: `SLA-2026-0001 Rev 1`, `INV-2026-0001 Rev 1`.
- The agreement is headed "Amended Agreement — supersedes Rev 0" and prints the amendment date and reason.
- The invoice date stays `confirmed_at`, with the revision date printed beside it.
- Revision 0 prints with no suffix.

**No document versioning.** The system always prints the current revision only; earlier revisions can't be reprinted. The record of an earlier revision is:
- the scanned signed copy, uploaded as an attachment with its `signed_revision` (required before amending a signed revision, step 2 above), and
- the `amend` entries in `audit_log`.

This is a deliberate simplicity trade-off. Completed and cancelled bookings can't be amended (unchanged). `version` (bumped on every save, for optimistic locking) and `revision` (bumped only by amendments) are separate counters.

**Single authorization chokepoint.** Every page, document, payment, attachment and download gets its booking only through `load_booking_for_user($pdo, $id, $user, $intent)` in `app/bookings.php`:
- An admin can load any booking. A vendor can load only bookings where `vendor_id` is their own id; for `edit` and `delete` intents the booking must also be a draft. Any intent that records money, voids, cancels, confirms or completes is admin-only.
- Any failure returns **404** (not 403), so the page doesn't reveal that the booking exists.
- List queries use the same rule through `booking_scope_sql($user)`.
- No other code queries `bookings` by id directly.

## 9. Venue double-booking check

A booking clashes with another when they share `venue_id` and `event_date` (excluding the booking itself). What happens depends on the **other** booking's status:

- **Drafts only warn.** Another draft on the same venue and date shows a warning, on save and on confirm, but never blocks anything.
- **Only confirmed or completed bookings block confirmation.** The admin can override with a reason, which is written to the audit log.
- **Cancelled bookings are ignored.**
- **Saving a draft never blocks.** It warns about any draft, confirmed or completed booking on the same venue and date.
- `venue_other` (free-text venues) can't be checked; the form says so.

**Race-safe confirmation.** The availability check and the confirmation run inside **one transaction**, with the venue row locked first. Two admins confirming different bookings for the same venue and date at the same moment can't both succeed.

1. `SELECT id FROM venues WHERE id = :venue_id FOR UPDATE` — locks the venue row. Any other confirmation for the same venue waits here until this transaction commits or rolls back.
2. `SELECT … FROM bookings WHERE id = :id AND version = :version FOR UPDATE` — re-reads the booking being confirmed. If the version is stale, abort.
   - **Validate against the locked row:** `status` is still `draft`, and every confirm requirement in Section 8 holds (client name, `event_date`, an active venue or `venue_other`, `grand_total > 0`). Then lock the vendor's `users` row with `SELECT … FROM users WHERE id = :vendor_id LOCK IN SHARE MODE` and check it is an active vendor. Any failure rolls back with a message naming the missing requirement.
3. Run the conflict check as a **locking read**: `SELECT id FROM bookings WHERE venue_id = :venue_id AND event_date = :event_date AND status IN ('confirmed','completed') AND id <> :id FOR UPDATE`. A locking read always sees the latest committed rows, so it sees a confirmation the other transaction just committed. A plain `SELECT` could read an older snapshot under InnoDB's default REPEATABLE READ.
4. If a conflict is found and there's no admin override with a reason → roll back.
5. `UPDATE` the booking (`status = 'confirmed'`, `confirmed_at`, `version + 1`), write the `audit_log` row, commit.

Rules around this sequence:
- **Lock order:** venue row(s) first, then the booking, then (where needed) the vendor's `users` row with `LOCK IN SHARE MODE`, everywhere, to avoid deadlocks. When more than one venue row is locked, they are locked in ascending `id` order in a single statement. If MySQL still reports a deadlock (error 1213), retry once, then ask the user to try again.
- **What blocks a confirmation:** step 3 checks only `confirmed` and `completed` bookings, which is why drafts can only warn (see the rules at the top of this section). Otherwise two drafts would block each other.
- **Amendments:** an amendment that changes `venue_id` or `event_date` on a confirmed booking uses the same locked sequence, with the unlocked pre-read described in Section 8 amendment step 0 so the venues are still locked before the booking. It locks both the old and the new venue rows, in ascending `id` order (amendment step 0).
- **`venue_other` bookings** skip steps 1 and 3. An amendment between a real venue and `venue_other` locks only the real one.
- **One booking per venue per day.** The check is by date only; there are no time slots (e.g. separate lunch and dinner events).

## 10. Security

- **Database access:** PDO with `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, and `utf8mb4`. Every query is a prepared statement.
- **Timezone:** `SET time_zone = '+05:00'` on every connection, plus `date_default_timezone_set('Asia/Karachi')` in PHP.
- **Output:** every value is escaped with `h()` (`htmlspecialchars`).
- **CSRF:** a per-session token, checked centrally in `bootstrap.php` for every POST. Logout is a POST too.
- **Cookie `Secure` flag:** set when the request arrived over HTTPS (`$_SERVER['HTTPS']` is on, or the port is 443), and **always** when `APP_ENV = 'production'`; if production is ever reached over plain HTTP, `bootstrap.php` redirects to HTTPS before starting the session. On local XAMPP (`APP_ENV = 'local'`, `http://localhost`) cookies are sent without `Secure`, otherwise the browser would drop the session cookie and login would silently fail. Applies to the session cookie and the device cookie below.
- **Sessions:** custom session name, HttpOnly + Secure (as above) + SameSite=Lax cookie, `session_regenerate_id()` on login, 30-minute idle timeout, 12-hour absolute timeout (both enforced from timestamps kept in the session, not left to PHP's garbage collector). Session files are stored in `storage/sessions/` via `session.save_path`, so other sites on the shared server's default session folder can't clean them up early or read them.
- **Session file cleanup.** The host's own cleanup only covers its default session folder, and shared hosts often set `session.gc_probability = 0`. So `bootstrap.php` sets, with `ini_set()` before `session_start()`: `session.save_path` = `storage/sessions`, `session.gc_probability = 1`, `session.gc_divisor = 100`, `session.gc_maxlifetime = 43200` (12 hours, the absolute timeout). About 1 in 100 requests then deletes session files untouched for 12 hours. Setting them in code rather than `.user.ini` means they apply to every page, whichever folder it's in. A 12-hour `gc_maxlifetime` never cuts a session short, because the app's own 30-minute and 12-hour timeouts are shorter or equal.
- **Account status on every request:** `bootstrap.php` re-reads the logged-in user's `status` and `must_change_password` on each request. A disabled vendor is logged out on their next click, not when the session expires; a password reset by the admin forces the change immediately.
- **Passwords:** minimum 10 characters, no maximum below 72 (the bcrypt limit); no other composition rules. Temporary passwords from admin resets are random, 12 characters.
- **Login throttling.** Three limits, all counted over the last 15 minutes from `login_attempts`. Checks run **before** the password is verified; a throttled attempt is refused without checking the password, is logged, and doesn't reset any timer.
  1. **Per username + IP:** 5 failures → that pair is locked out for 15 minutes. This is the normal "wrong password too often" lockout.
  2. **Per IP:** 20 failures (any usernames) → that IP is locked out for 15 minutes. Stops one machine trying many accounts.
  3. **Per username, across all IPs** (stops one account being guessed from many IPs): 10 failures → the account is **slowed, not locked**: from then on, login attempts for that username from **unknown IPs** are accepted at most once per 30 seconds (across all unknown IPs combined); faster attempts get "Too many attempts, try again in N seconds". **Known IPs** and **known devices** are exempt:
     - a known IP is one with a successful login to that username in the last 30 days;
     - a known device is a browser holding a valid **device cookie** for that username (below).
     This caps guessing from any number of IPs at about 30 tries per 15 minutes, which with the 10-character minimum is not a practical attack.
  - **Device cookie** (no new table). After every successful login, the server sets a long-lived cookie `aom_device` = `user_id | issued_at | HMAC-SHA256(user_id | issued_at | password_hash, DEVICE_COOKIE_SECRET)`, with the secret in `config.php`. It lasts 90 days and is HttpOnly, SameSite=Lax, and `Secure` as above. On a login attempt, the cookie counts only if the HMAC verifies, it hasn't expired, and its `user_id` is the account being logged into. Because the current `password_hash` is part of the signature, changing or resetting the password invalidates all of that user's device cookies. A device cookie only exempts the browser from limit 3; limits 1 and 2 still apply, and the password is always checked.
  - Why the device cookie is needed: while limit 3 is active, all unknown IPs share one attempt every 30 seconds, and an attacker sending one attempt every 30 seconds could take that slot every time. Mobile data connections in Pakistan share and change IPs often, so "known IP" alone doesn't protect an admin on their phone. A browser the admin has logged in from before is never affected by limit 3, whatever its IP. The remaining exposure is the admin's first login from a brand-new browser while an attack is running; they can log in from any browser they've used before, or wait for the attack to stop.
  - Keying the hard lockouts (1 and 2) on the IP means nobody can lock the admin out just by typing the admin username.
  - When limit 3 is active for an account, admins see a banner ("N failed logins for <username> from M IPs in the last 15 minutes") on every page until it clears.
- **Default admin:** the seeded admin has `must_change_password = 1` and can't do anything else until the password is changed (see the next point).
- **Forced password change.** While the logged-in user's `must_change_password = 1` (re-read on every request, as above), `bootstrap.php` allows only two endpoints: `auth/change_password.php` and `auth/logout.php`. Every other request, GET or POST, including documents, downloads, payments and admin pages, is stopped in `bootstrap.php` **before** the page's own code runs: a GET is redirected to the change-password page, and a POST is refused without being processed. This applies to admins and vendors alike (the seeded admin, and anyone whose password the admin has reset). A successful change sets `must_change_password = 0`, regenerates the session id, and writes the `password_change` audit row. The new password must meet the minimum length and differ from the temporary one.
- **Password resets:** there's no email, so the admin resets vendor passwords. The reset issues a temporary password with `must_change_password = 1`.
- **Uploads:** PDF, JPG and PNG only, verified with `finfo`; 5 MB maximum. Files are stored under `storage/uploads/` with random names and served only through `documents/download.php` after `load_booking_for_user()`. Downloads send the stored MIME type (never guessed from the name), `X-Content-Type-Options: nosniff`, and `Content-Disposition: inline` for PDF/JPG/PNG with a sanitized original file name; voided attachments are served to admins only.
- **CNIC:** format is validated and it's never shown in the list view. It appears only on the form and on documents.
- **Errors:** `display_errors` off in production; errors are logged to `storage/logs/`. An `APP_ENV` switch lives in the config.

## 11. Hostinger notes

- **PHP:** set 8.2 in hPanel to match local XAMPP (8.1 minimum).
- **Folders:** upload `app/`, `config/`, `database/` and `storage/` (with empty `uploads/`, `logs/`, `sessions/`) next to `public_html`, and the contents of `public/` into `public_html`. `tests/` stays local; it isn't needed on the server.
- **Staging subdomain:** create it with its own document root under `domains/<staging-subdomain>/public_html` and its own copy of the folders beside it (Section 4, web-root guard), plus its own database.
- **Database:** create the database in hPanel, then import `schema.sql` + `seed.sql` through phpMyAdmin.
- **HTTPS:** enable free SSL in hPanel; `.htaccess` forces HTTPS.
- **Backups:** Hostinger's automatic backups, plus a manual phpMyAdmin export before every migration.
- **No server-side PDF, email or cron:** browser print covers all documents.
- **Staging:** until Phase 2 (auth) is deployed, keep the site on a staging subdomain behind HTTP Basic Auth.

## 12. Coverage of the reference fields

| Reference field(s) | Where it lives |
|---|---|
| Invoice ID / Invoice Date | `unique_id` → `INV-` number; invoice date = `confirmed_at` |
| Venue, Status | `venue_id`, `status` |
| Date Availability, Available Date, Location | Venue double-booking check (not stored) |
| Customer Name, CNIC, Address, Contact 1 / 2, Company | `client_*` columns |
| Reference, Reference Name, Reference Department | `reference_name`, `reference_department` |
| Event Type, Event Time, Event Date, Other Date | `event_type`, `start_time`, `event_date`, `alt_date` |
| Food, Details/Food/Starter/Other Services | `menu_type`, `food_items`, `special_commitments` |
| Decor By | `decor_by` |
| Per Head, No of Guest / No of PAX, Guest Charges | `per_head_rate`, `guests`, `guest_charges` |
| Stage / Bridge / Tracing / Food / Venue / Generator / Cleaning / Service / Valet / CAA / PSF / Coldring charges | Charge catalog rows under neutral names |
| Total, Sub Total, Total Amount, Grand Total, Net Amount | `sub_total`, `grand_total` |
| Discount | `discount` |
| Advance Payment, Transaction Method, Bank, Ref | `payments` rows (`method`, `bank_name`, `reference_no`) |
| Upload Invoice | `attachments` |
| General Decor checklist (Light, Generator, Flower, Extra) | Decor sections of the catalog → line items |
| Ops inventory lines, Vendor Name | `ops_item` line items; `vendor_id` |
| Office address / phone / email footer | AO Mess details from config |
| **Not included:** first/prev/next/last record navigation | Replaced by list search |
| **Deferred:** Report button | Reports are a later phase (Section 16) |

## 13. Open questions for the client

Each question has a default; the build won't stall waiting for an answer.

1. **Contract wording**, needed before Phase 6. The mockup has form sections and one policy paragraph, not a full agreement, so AO Mess must supply or approve the clause text. Default: use the mockup's wording and mark it "to be legally reviewed".
2. **Charge list and default rates**, needed before Phase 1 seeding. Default: seed the neutral names above with no rates; the admin fills them in.
3. **Venues**, needed before Phase 3: the exact list. Default: Lawn A/B/C, Pool side, Hall. Scheduling is decided: one booking per venue per day, no time slots (Section 9).
4. **Who records payments and who confirms bookings?** Default: the admin only.
5. **AO Mess office address, phone and email** for the letterhead and document footers.
6. **Local database for testing.** XAMPP's MariaDB can run on port 3307 via a change to XAMPP's own `my.ini`, which doesn't touch the other MySQL service on this machine. Its data directory already shows startup errors and may need repair. Default: ask before changing anything; the fallback is testing on a Hostinger staging subdomain.

## 14. Build order

Each phase ends with a checkpoint that must pass before the next phase starts.

| # | Phase | Contents | Checkpoint |
|---|---|---|---|
| 0 | Test environment | Local DB on 3307 or Hostinger staging (question 6) | `SELECT 1` works through PDO |
| 1 | Foundations | Reorganize skeleton; **full** `schema.sql` with all tables; `seed.sql` (admin, venues, catalog); `bootstrap`, `db`, `config`; `helpers`, `money` (lakh/crore formatting, number to words); `counters`; `tests/run.php` | Tests pass; schema imports cleanly; first ID is `SLA-YYYY-0001` |
| 2 | Shell + auth | Port CSS, logo, layout partials with print wrapper; login, logout, forced password change, throttling, CSRF, session hardening; vendor registration + admin approve / disable / reset | Pending vendor can't log in; admin is forced to change the default password |
| 3 | Booking form + save | All form sections, including charges / decor / ops from the catalog; totals; venue warning; ID in transaction; PRG; optimistic lock; `load_booking_for_user`; audit log | Create, reopen and edit work; a stale version is rejected |
| 4 | Registry + lifecycle | List with search, status filter and pagination; confirm (race-safe, Section 9) / complete / cancel / delete draft; amendments on confirmed bookings (Section 8) | Every row in the Section 8 table behaves as specified; simultaneous confirmation test passes. Signed-copy uploads arrive in Phase 7, so amendments of **signed** bookings are tested here with `REQUIRE_SIGNED_COPY_FOR_AMENDMENT` off; the signed-copy part of test 19 is re-run at the Phase 7 checkpoint |
| 5 | Payments | Add and void payments and refunds; balance recomputed | Void → balance restored; overpayment shows a negative balance |
| 6 | Documents | Agreement (DRAFT watermark), invoice (payment table, amount in words), vendor ops sheet | Letterhead repeats on multi-page prints; amended bookings print with the `Rev N` suffix |
| 7 | Attachments + admin screens | Upload / download / void; catalog and venue management (audited) | Renaming a catalog item doesn't change old invoices; a voided signed copy no longer satisfies the amendment check; test 19 passes in full with `REQUIRE_SIGNED_COPY_FOR_AMENDMENT` on |
| 8 | Hardening + deploy | `.htaccess`, `.user.ini`, error logging, full audit of queries and escaping, backup instructions, UAT, go-live | Section 15 checklist fully passes on Hostinger |

## 15. Verification

**Automated (`php tests/run.php`, no database needed):**
- Number to words: 0; 1; 99; 100; 1,00,000 ("One Lakh"); 12,34,56,789 (crore); amounts with paisa.
- Rs. formatting: `1234567` → `Rs. 12,34,567`.
- Totals chain: discount; charges only; guests × rate; voided payments ignored; refunds subtracted; balance forced to 0 for a cancelled booking.
- Refund policy suggestion: ≥ 30 days, ≥ 7 days and < 7 days before the event; with Rs. 1,00,000 paid and 50%, a first suggestion of Rs. 50,000, and after a Rs. 30,000 refund a second suggestion of Rs. 20,000; never more than `refundable`.
- Charge line amounts: `fixed` ignores qty and guests; `per unit` = qty × rate; `per head` = guests × rate; unselected rows = 0.
- ID formatting and CNIC validation.
- Numeric parsing: `-1`, `1e5`, `abc`, `NaN`, `12.345` and the empty string are rejected; `1,00,000`, `Rs. 500` and `0.50` are accepted; guests/qty reject `2.5` and `-3`; a value above the `DECIMAL(12,2)` limit is rejected.

**Manual (on the test DB, then again on Hostinger):**
1. Seeded admin logs in and is forced to change the password.
2. Five failed logins for one username from one IP → the 6th attempt (even with the right password) gets the lockout message; the attempts appear in `login_attempts`. The same username from a different IP can still log in.
3. First booking of the year is `SLA-YYYY-0001`, the next is `0002`. Force a save error → the next successful save still gets the next unused number, with no gap.
4. Two browser tabs edit the same booking → the second save gets the conflict message.
5. Two drafts for the same venue and date → each shows a warning, and the first one can still be confirmed. After that, the second draft is blocked on confirm; an admin override is logged.
6. Totals: per-head × guests + charges − discount matches the invoice exactly.
7. Payments: add two installments, void one → balance updates; refund on a cancelled booking. Voiding the same payment twice → the second attempt is refused. With a temporary forced error before commit (dev only), a payment add leaves no payment row, no audit row and an unchanged balance.
8. Confirmed booking: the vendor sees no edit or delete; an admin edit appears in `audit_log` with old → new values.
9. Deleting a draft works; deleting a draft that has payments is refused; confirmed bookings have no delete option. Deleting a draft with two attachments → its `attachments` rows and both files in `storage/uploads/` are gone, while all its `audit_log` rows remain, plus a `delete_draft` row showing the SLA number and file names.
10. Vendor opening another vendor's booking by id (form, documents, download) → 404.
11. Direct browser requests to `/app/…`, `/config/…`, `/database/schema.sql` and `/storage/uploads/…` → not reachable.
12. Print the agreement: the letterhead repeats on every page, a draft shows the watermark, and the agreement day/month print as typed.
13. Invoice: `INV-` number, amount in words (lakh/crore), payment table, and a stable date across reprints.
14. Rename a catalog charge → old invoices still show the old label.
15. Upload a `.php` file renamed to `.pdf` → rejected by the MIME check.
16. Two drafts for the same venue and date, confirmed at the same moment (open a MySQL session, run `SELECT … FROM venues WHERE id = X FOR UPDATE` inside a transaction to hold the lock, click Confirm on both drafts, then release) → exactly one is confirmed; the other is refused with the conflict message.
17. Change a catalog charge's unit (e.g. `fixed` → `per head`) → existing bookings keep their original unit and amount; only lines added afterwards use the new unit.
18. On a booking with a `per head` charge line, change guests from 100 to 150 → `guest_charges` and the per-head line both recompute; `fixed` and `per unit` lines are unchanged.
19. On a confirmed booking: change a contact number → saved with no revision and signatures kept. Change guests without a reason → refused. With signatures recorded but no signed copy uploaded, change guests with a reason → refused with "Upload the signed copy of Rev 0". Upload the scan marked "signed copy of Rev 0", then change guests with a reason → `Rev 1`, signature fields cleared, an `amend` audit row with old → new values, and both documents print `Rev 1` with "supersedes Rev 0".
20. On a cancelled booking, attempt a refund greater than the total non-voided payments (minus any refunds already made) → rejected with the refundable-amount message, and no `payments` row, no change to `paid_total`/`balance`, and no `audit_log` row is written.
21. On a cancelled booking with a Rs. 1,00,000 payment fully refunded, attempt to void the payment → rejected with the "void the related refunds first" message, and the payment stays non-voided with no balance or audit change. Void the refund first → the payment can then be voided.
22. Ownership: a vendor creates a draft while posting another vendor's id as `vendor_id` → the booking belongs to the creating vendor. An admin re-saves that draft → `created_by` and `vendor_id` are unchanged and the vendor still sees it.
23. Cancel a confirmed booking (grand total Rs. 5,00,000) with Rs. 1,00,000 paid → balance shows 0 and "Amount retained Rs. 1,00,000"; the refund form shows the policy suggestion. Refund Rs. 40,000 → "Amount retained Rs. 60,000", balance still 0.
24. Complete a confirmed booking whose event date is tomorrow → refused. With the event date in the past and Rs. 10,000 outstanding → a warning must be acknowledged; the `complete` audit row records the balance.
25. Disable a vendor who is logged in → their next click logs them out. A vendor tries to cancel their own draft → not offered, and a direct POST gets 404; deleting their own unpaid draft works.
26. Void an attachment marked "signed copy of Rev 0" → an amendment on that signed booking is refused again until a new signed copy is uploaded. As a vendor, download the voided attachment by id → 404.
27. Add a new active catalog charge → it appears unselected on an existing draft's form; saving without selecting it stores no row; selecting it stores a row with the current default rate.
28. Edit the booking form, then click a link to the list → the browser's "leave page?" prompt appears; saving the form doesn't trigger it.
29. Place a copy of the app so that `app/` is inside the document root (with `ALLOW_APP_IN_WEBROOT` off) → every page returns 500 and the reason is logged.
30. Amendment that moves a confirmed booking to a venue/date already confirmed by another booking → refused (or admin override with a reason), same as on confirmation.
31. Login limit 3: 10 failed logins for `admin` spread over 3 different IPs (e.g. two phones on mobile data plus a VPN) → further attempts from new IPs are accepted only once per 30 seconds, with the wait message; the admin banner appears. Logging in as `admin` from an IP that logged in successfully before still works immediately.
32. Cancel a booking with payments → `balance` becomes 0 at cancellation; add a refund → `balance` is still 0 (check the database column, not just the screen).
33. Cancel a draft that has a payment but no event date → the refund form shows "No policy suggestion" with the reason and still accepts a refund within the cap.
34. Attachment race: in a MySQL session, hold `SELECT … FROM bookings WHERE id = X FOR UPDATE`; start an amendment on booking X in one tab and void its signed copy in another; release the lock → either the void commits and the amendment is refused, or the amendment commits and the void commits after it. An amendment must never commit after the void of the signed copy it relied on (check the order in `audit_log`).
35. Upload a file marked "signed copy of Rev 0" on a booking that is now at Rev 1 → refused; no row is inserted and the uploaded file isn't left in `storage/uploads/`.
36. After deploy, check `storage/sessions/` over a few days: files older than 12 hours are removed.
37. Cancel and complete are atomic: with a temporary forced error after the status update (dev only), cancelling leaves the booking's status, `version`, totals and `audit_log` unchanged; the same for completing. Two tabs cancelling the same booking → the second gets the conflict message and only one `cancel` audit row exists.
38. Venue history: a venue used only by a draft can't be renamed or deleted (refused with a message) but can be deactivated; it then no longer appears in the venue dropdown for new bookings, while the draft still shows it. A newly created, unused venue can be renamed and deleted.
39. Audit log: no admin screen offers editing or deleting entries; searching the code for `UPDATE audit_log` / `DELETE FROM audit_log` finds nothing; deleting a draft leaves all its audit rows.
40. Numeric validation by direct POST (bypassing the browser): a negative or zero payment, a negative refund, a negative rate, `guests = -5`, `qty = 1.5` and a discount greater than the sub total are each rejected with a field message, and nothing is written (booking `version`, totals, `payments` and `audit_log` unchanged). A refund above the refundable amount is rejected as in test 20.
41. Forced password change: log in as a user with `must_change_password = 1`, then open the booking list, a document, a download URL, and POST directly to `payments/add.php` → each GET redirects to the change-password page and the POST is refused with no payment row. Logout works. After changing the password, everything is available as normal.
42. Cancellation scope: on a completed booking and on a cancelled booking, no Cancel option is shown, and a direct POST to `cancel.php` is refused with nothing written. Draft and confirmed bookings can be cancelled by the admin.
43. Vendor must be active: as admin, POST a draft with `vendor_id` set to a pending vendor, a disabled vendor, and an admin account → each is refused. Assign an active vendor, then disable that vendor → the draft still saves (vendor unchanged) but confirming it is refused until it is reassigned to an active vendor.
44. Amendment venue locks: with confirmed booking 1 at venue A and booking 2 at venue B (different dates), amend 1 → B and 2 → A at the same moment (hold venue A's lock in a MySQL session, submit both, release) → both finish without a deadlock error; each amendment's `audit_log` row shows its old → new venue.
45. Attachments by status: the admin can upload to and void attachments on draft, confirmed, completed and cancelled bookings. The owner vendor can upload only to their draft; on a confirmed booking the upload option is missing and a direct POST is refused with no row and no file left behind. "Signed copy of Rev N" is offered only on confirmed bookings.
46. Device cookie: trigger login limit 3 for `admin` (test 31), then log in as `admin` from a browser that logged in before, using a new IP (e.g. switch mobile data on and off) → it works immediately. A fresh browser on a new IP gets the 30-second wait. Reset the admin's password → the old device cookie no longer exempts that browser. A cookie with one character changed is ignored.
47. Amendment step order: with query logging on (dev only), amend a confirmed booking's venue → the log shows the venue `FOR UPDATE` before the booking `FOR UPDATE`, and the signed-copy check and the `amend` audit insert come after both.
48. Local login over HTTP: with `APP_ENV = 'local'` on `http://localhost`, login works and the session cookie has no `Secure` flag. On Hostinger (`production`), the session and device cookies are `Secure`, and an `http://` request is redirected to HTTPS.
49. Refund percentages: `refund_pct_30 = 150`, `-5` or `abc` → rejected; empty is accepted and the refund form then shows "No policy suggestion".
50. Confirm re-validation: open a draft's confirm screen, then in another tab remove its client name and save → confirming from the first tab fails on the stale version. Disable the draft's vendor between loading and confirming → confirm is refused with the vendor message.
51. Inactive venue: save a draft on venue A, deactivate A → confirming the draft is refused until the venue is changed; a confirmed booking already on A can still be amended (e.g. guests changed) while keeping A, but no booking can be amended *to* A.
52. Web-root guard with symlinks: on Hostinger, where `public_html` may be a symlink, the correct install runs normally; a copy with `app/` inside the resolved document root returns 500 (test 29).

## 16. Deferred (not in this build)

Reports and exports (the reference's "Report" button), email/SMS notifications, server-side PDF generation, record-by-record navigation buttons, venue time slots (multiple events per venue per day), Urdu interface.

## Next step

The plan is implementation-ready. Open questions in Section 13 have defaults and don't block Phase 0/1. Say **"start coding"** and work begins at Phase 0/1.
