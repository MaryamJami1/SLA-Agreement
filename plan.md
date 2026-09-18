# AO Mess Event Booking & SLA System (PHP + MySQL, Hostinger)

> Status: **IMPLEMENTATION-READY — Rev 5** (Rev 4 plus final clarifications: bootstrap path by folder depth, refunds on cancelled bookings only, one booking per venue per day; refund cap). Implementation not started; waiting for "start coding".
> No PHP/SQL/CSS will be written until you explicitly say "start coding".

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
| Lifecycle | `draft → confirmed → completed`, or `→ cancelled`. Only drafts can be deleted; confirmed bookings are locked for vendors |
| Hosting layout | Only `public/` is web-accessible; app code, config, SQL and uploads live above `public_html` |

## 3. What's reused from the mockups vs rebuilt

**Reused (ported, not redesigned):**
- CSS palette, typography and layout → `public/assets/css/style.css`.
- The print trick: the page is wrapped in a single-row `<table class="page-table">` with the letterhead in `<thead>`, so the letterhead reprints on every printed page.
- Client-side conveniences: live totals preview, event-day autofill, cheque-field toggle, print button → `public/assets/js/app.js`. These are cosmetic only; the server recomputes everything.
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
    admin/      vendors.php (approve / disable / reset password)  catalog.php  venues.php
    booking/    list.php  form.php  save.php  confirm.php  cancel.php  complete.php  delete.php
    payments/   add.php  void.php
    documents/  agreement.php  invoice.php  vendor_sheet.php  download.php
    assets/     css/style.css  js/app.js  img/logo.png
  app/                            -> never web-accessible
    bootstrap.php                 -> timezone, config, session, DB, CSRF check on every POST
    db.php  auth.php  csrf.php  helpers.php  money.php  counters.php
    bookings.php                  -> load_booking_for_user(), totals, venue conflict, lifecycle rules
    audit.php
    views/      layout_top.php  layout_bottom.php
  config/       config.sample.php  config.php (real credentials, never committed)
  database/     schema.sql  seed.sql  migrations/001_initial.sql
  storage/      uploads/  logs/
  tests/        run.php                -> plain-PHP tests for pure functions
```

Every page in `public/` starts by requiring the bootstrap. The relative path depends on how deep the file sits (the depths are the same after upload to `public_html`):
- Files directly in `public/` (e.g. `index.php`): `require __DIR__ . '/../app/bootstrap.php';`
- Files in a subfolder (e.g. `booking/form.php`, `auth/login.php`): `require __DIR__ . '/../../app/bootstrap.php';`

If an account can't place files above `public_html`, the fallback is `Require all denied` `.htaccess` files in `app/`, `config/`, `database/` and `storage/`.

The empty skeleton folders created earlier (`config/`, `includes/`, `auth/`, `booking/`, `documents/`, `assets/`, `database/`, `uploads/`) get reorganized into this layout at the start of Phase 1.

## 5. Database schema

All tables use `ENGINE=InnoDB`, `utf8mb4_unicode_ci`, and `DECIMAL(12,2)` for money. The SQL stays portable between MySQL 8 and MariaDB: no `JSON` column type (store JSON as `TEXT`) and no reliance on `CHECK` constraints.

### `users`
`id`, `username` (unique), `password_hash`, `role` ENUM('admin','vendor'), `name`, `firm_name`, `rep_name`, `contact`, `status` ENUM('pending','active','disabled'), `must_change_password` TINYINT, `last_login_at`, `created_at`.
A vendor's firm, rep name and contact live here and are copied onto each booking at save time.

### `login_attempts`
`id`, `username`, `ip`, `attempted_at`. Indexed on `(username, attempted_at)` and `(ip, attempted_at)`. Used for throttling (Section 9).

### `counters`
`year_key` (PK), `seq`. Holds the SLA number sequence (Section 7).

### `venues`
`id`, `name`, `is_active`, `sort_order`. Seeded with Lawn A, Lawn B, Lawn C, Pool side, Hall, managed by the admin. A real table (not free text) is what makes the double-booking check reliable.

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

The event day of the week is **not stored**; it's calculated from `event_date` when displayed.
Indexes: `event_date`, `(vendor_id, status)`, `status`, `client_name`, `(venue_id, event_date)`.

### `booking_line_items`
`id`, `booking_id` FK (ON DELETE CASCADE, which only fires for draft deletion), `catalog_id` FK (nullable, ON DELETE SET NULL), `section` (same values as the catalog), `label` (**snapshot** of the catalog name), `unit_snapshot` ('fixed','per unit','per head' — **snapshot** of the catalog unit), `is_selected`, `qty`, `rate` (**snapshot**, copied from `default_rate` when the line is added), `amount`, `notes`, `sort_order`.
Label, unit and rate are all copied when the line is added, so later catalog edits never change an existing booking's lines or totals.
**Only `section = 'charge'` rows carry money**; their `amount` is computed server-side from `unit_snapshot` using the rules in Section 6. Decor and ops rows record inclusion, quantity and notes only, matching the references, where those lists have no per-line prices.

### `payments`
`id`, `booking_id` FK (ON DELETE RESTRICT), `kind` ENUM('payment','refund'), `amount`, `paid_on` DATE, `method` ENUM('cash','bank_transfer','cheque','online'), `bank_name`, `reference_no` (cheque or transaction number), `notes`, `recorded_by` FK, `created_at`, `voided_at`, `voided_by`, `void_reason`.
Rows are never deleted, and the only update ever made is setting the `void*` fields, once. A mistake is voided and re-entered. Every payment write is a single transaction (Section 6).

### `attachments`
`id`, `booking_id` FK (ON DELETE RESTRICT — rows are removed explicitly by the draft-deletion flow in Section 8, which also deletes the files), `original_name`, `stored_name` (random hex), `mime`, `size_bytes`, `signed_revision` SMALLINT (nullable; set when the file is the signed copy of that revision, see Section 8), `uploaded_by`, `uploaded_at`. This replaces the single "Upload Invoice" field and allows several files per booking.

### `audit_log`
`id`, `user_id`, `booking_id` (nullable; a plain column, deliberately **not** a foreign key, so a booking's history survives when a draft is deleted), `action` (create, update, amend, confirm, complete, cancel, delete_draft, payment_add, payment_void, attachment_add, vendor_approve, vendor_disable, password_reset, login_ok, login_fail), `details` TEXT (JSON of the changed fields, old → new), `ip`, `created_at`.

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
```

- Discount can't exceed `sub_total`.
- On a cancelled booking, the refund is recorded as a `refund` payment, so `paid_total` shows what AO Mess actually kept.
- The invoice shows the full chain plus a table of the payments received.

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
4. Recompute `paid_total` and `balance` from a `SUM` over the booking's non-voided payment rows (never by adding to the old value), and update the booking.
5. Write the `audit_log` row (`payment_add` or `payment_void`), then commit.

Booking saves lock the same booking row and recompute `paid_total` from the payments table inside their own transaction, so a form save and a payment can't overwrite each other's totals.

## 7. Unique ID generation (corrected)

```sql
INSERT INTO counters (year_key, seq) VALUES (:year, LAST_INSERT_ID(1))
ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1);
```

Then read `$pdo->lastInsertId()` and format it as `SLA-{year}-{seq:04d}`.

- `LAST_INSERT_ID(1)` in `VALUES` fixes the earlier bug, where the first booking of each year would have been numbered `0000`.
- The counter statement and the `bookings` INSERT run in **one transaction**. A failed save rolls both back, so no numbers are lost.
- The year is the **creation** year, taken from PHP `date('Y')` in Asia/Karachi. It's passed in as a parameter, never computed with MySQL `NOW()`.
- The ID is assigned on first save only, never when a blank form is opened. Saves use Post/Redirect/Get, so a refresh can't create a duplicate.
- The invoice number is the same number with an `INV-` prefix. The invoice date is `confirmed_at`, so reprints show the same date.

## 8. Booking lifecycle and permissions

| Status | Vendor (owner) | Admin | Allowed transitions |
|---|---|---|---|
| draft | view, edit, attach files | everything | → confirmed (admin), → cancelled, or deleted (only if no payments exist) |
| confirmed | view and print only | direct edits or amendments (see "Editing a confirmed booking" below), payments | → completed, → cancelled |
| completed | view and print only | payments only (no refunds) | none |
| cancelled | view and print only | refunds only | none |

- **Refunds are allowed only on cancelled bookings.** Draft, confirmed and completed bookings accept payments but not refunds. An overpaid confirmed or completed booking shows a negative balance; refunding it in the system isn't supported unless it's requested later.

- **Confirming requires:** `vendor_id`, client name, `event_date`, a venue, `grand_total > 0`, and no venue conflict.
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

**Saving a change to any amendment field** (a save that mixes both groups counts as an amendment):
1. The admin must enter an amendment reason; the save is refused without one.
2. **Signed copy on file first.** If the current revision was signed (any `vendor_sign_*` or `client_sign_*` value is filled in), an attachment with `signed_revision` = the current revision must already exist. Otherwise the save is refused with "Upload the signed copy of Rev N before amending." This is checked with the booking row locked, in the same transaction as the amendment. The scan is uploaded beforehand through the normal attachment upload, with a "signed copy of Rev N" option that sets `signed_revision`. A revision that was never signed needs no scan. The rule follows AO Mess policy through the config setting `REQUIRE_SIGNED_COPY_FOR_AMENDMENT` (default: on).
3. `revision` goes up by 1 and `revised_at` is set.
4. `vendor_sign_*` and `client_sign_*` are cleared, so the amended agreement prints with blank signature lines and must be signed again.
5. An `audit_log` row is written with action `amend`, the reason, and old → new values for every changed field.
6. If `venue_id` or `event_date` changed, the save goes through the locked venue check in Section 9.
7. Payments are untouched; `balance` is recomputed and can go negative (money owed back to the client).

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
- An admin can load any booking. A vendor can load only bookings where `vendor_id` is their own id; for `edit` intent the booking must also be a draft.
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
3. Run the conflict check as a **locking read**: `SELECT id FROM bookings WHERE venue_id = :venue_id AND event_date = :event_date AND status IN ('confirmed','completed') AND id <> :id FOR UPDATE`. A locking read always sees the latest committed rows, so it sees a confirmation the other transaction just committed. A plain `SELECT` could read an older snapshot under InnoDB's default REPEATABLE READ.
4. If a conflict is found and there's no admin override with a reason → roll back.
5. `UPDATE` the booking (`status = 'confirmed'`, `confirmed_at`, `version + 1`), write the `audit_log` row, commit.

Rules around this sequence:
- **Lock order:** venue first, then booking, everywhere, to avoid deadlocks. If MySQL still reports a deadlock (error 1213), retry once, then ask the user to try again.
- **What blocks a confirmation:** step 3 checks only `confirmed` and `completed` bookings, which is why drafts can only warn (see the rules at the top of this section). Otherwise two drafts would block each other.
- **Amendments:** an amendment that changes `venue_id` or `event_date` on a confirmed booking uses the same locked sequence (Section 8).
- **`venue_other` bookings** skip steps 1 and 3.
- **One booking per venue per day.** The check is by date only; there are no time slots (e.g. separate lunch and dinner events).

## 10. Security

- **Database access:** PDO with `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, and `utf8mb4`. Every query is a prepared statement.
- **Timezone:** `SET time_zone = '+05:00'` on every connection, plus `date_default_timezone_set('Asia/Karachi')` in PHP.
- **Output:** every value is escaped with `h()` (`htmlspecialchars`).
- **CSRF:** a per-session token, checked centrally in `bootstrap.php` for every POST. Logout is a POST too.
- **Sessions:** custom session name, HttpOnly + Secure + SameSite=Lax cookie, `session_regenerate_id()` on login, 30-minute idle timeout, 12-hour absolute timeout.
- **Login throttling:** 5 failures per username or 20 per IP within 15 minutes → temporary lockout. Every attempt is logged.
- **Default admin:** the seeded admin has `must_change_password = 1` and can't do anything else until the password is changed.
- **Password resets:** there's no email, so the admin resets vendor passwords. The reset issues a temporary password with `must_change_password = 1`.
- **Uploads:** PDF, JPG and PNG only, verified with `finfo`; 5 MB maximum. Files are stored under `storage/uploads/` with random names and served only through `documents/download.php` after `load_booking_for_user()`.
- **CNIC:** format is validated and it's never shown in the list view. It appears only on the form and on documents.
- **Errors:** `display_errors` off in production; errors are logged to `storage/logs/`. An `APP_ENV` switch lives in the config.

## 11. Hostinger notes

- **PHP:** set 8.2 in hPanel to match local XAMPP (8.1 minimum).
- **Folders:** upload `app/`, `config/`, `database/`, `storage/` and `tests/` next to `public_html`, and the contents of `public/` into `public_html`.
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
| 4 | Registry + lifecycle | List with search, status filter and pagination; confirm (race-safe, Section 9) / complete / cancel / delete draft; amendments on confirmed bookings (Section 8) | Every row in the Section 8 table behaves as specified; simultaneous confirmation test passes |
| 5 | Payments | Add and void payments and refunds; balance recomputed | Void → balance restored; overpayment shows a negative balance |
| 6 | Documents | Agreement (DRAFT watermark), invoice (payment table, amount in words), vendor ops sheet | Letterhead repeats on multi-page prints; amended bookings print with the `Rev N` suffix |
| 7 | Attachments + admin screens | Upload / download; catalog and venue management | Renaming a catalog item doesn't change old invoices |
| 8 | Hardening + deploy | `.htaccess`, `.user.ini`, error logging, full audit of queries and escaping, backup instructions, UAT, go-live | Section 15 checklist fully passes on Hostinger |

## 15. Verification

**Automated (`php tests/run.php`, no database needed):**
- Number to words: 0; 1; 99; 100; 1,00,000 ("One Lakh"); 12,34,56,789 (crore); amounts with paisa.
- Rs. formatting: `1234567` → `Rs. 12,34,567`.
- Totals chain: discount; charges only; guests × rate; voided payments ignored; refunds subtracted.
- Charge line amounts: `fixed` ignores qty and guests; `per unit` = qty × rate; `per head` = guests × rate; unselected rows = 0.
- ID formatting and CNIC validation.

**Manual (on the test DB, then again on Hostinger):**
1. Seeded admin logs in and is forced to change the password.
2. Ten failed logins in a row → lockout message; the attempts appear in `login_attempts`.
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

## 16. Deferred (not in this build)

Reports and exports (the reference's "Report" button), email/SMS notifications, server-side PDF generation, record-by-record navigation buttons, venue time slots (multiple events per venue per day), Urdu interface.

## Next step

The plan is implementation-ready. Open questions in Section 13 have defaults and don't block Phase 0/1. Say **"start coding"** and work begins at Phase 0/1.
