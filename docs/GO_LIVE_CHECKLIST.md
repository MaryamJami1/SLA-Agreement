# Go-live checklist

Work through this on the deployed site (staging first, then live). It is the plan's Section 15
verification list, with a note of what is already covered by the automated tests.

**Already automated** (run locally against a throwaway database, see `docs/TESTING.md`):
`php tests/run.php` (266 checks), `php tests/db_check.php` (211), `php tests/concurrency_check.php` (7),
and the six `tests/e2e_*.sh` HTTP suites (297). They cover the money rules, the lifecycle, amendments,
payments, documents, attachments and the admin screens.

**This list is what those can't prove**: that the real server is configured correctly, that printing
looks right on paper, and that the whole thing behaves for real people.

---

## A. Server and access

- [ ] **Checks page** (`/admin/preflight.php`) shows no FAIL.
- [ ] Opening `http://` (no s) lands on `https://`.
- [ ] These URLs are **not** reachable from a browser (each must give 403/404, never a file):
  - [ ] `/app/bootstrap.php`
  - [ ] `/config/config.php`
  - [ ] `/database/schema.sql`
  - [ ] `/storage/uploads/` and a known file inside it
  - [ ] `/.user.ini` and `/.htaccess`
- [ ] Browsing a folder with no index (e.g. `/assets/`) gives no file listing.
- [ ] The seeded admin had to change its password at first sign-in.
- [ ] Six wrong passwords in a row give the lockout message; the attempts appear in `login_attempts`.

## B. Vendors

- [ ] A new vendor registers from the sign-in page and is told to wait for approval.
- [ ] That vendor cannot sign in until approved.
- [ ] After approval they can sign in, and see only their own bookings.
- [ ] Disabling a signed-in vendor signs them out on their next click.
- [ ] A password reset shows a temporary password once, and the vendor must change it at next sign-in.

## C. A real booking, end to end

- [ ] Create a booking as a vendor; the first one of the year is `SLA-YYYY-0001`.
- [ ] Reopen and edit it; the totals match: per-head × guests + charges − discount.
- [ ] Two browser tabs editing the same booking: the second save is refused, nothing is lost.
- [ ] Two drafts for the same venue and date both warn; the first can still be confirmed, the second
      is then blocked unless the admin gives an override reason.
- [ ] Confirm the booking as the admin; the vendor can now only view and print it.
- [ ] Record two payments, then void one: the balance goes back up.
- [ ] Overpay the booking: the balance shows as overpaid.
- [ ] Amend a confirmed booking (change guests): a reason is required, the signed copy must be on file,
      the revision becomes Rev 1 and the signatures are cleared.
- [ ] Cancel a booking with payments: the balance becomes 0 and the amount retained is shown; a refund
      above what is refundable is refused.
- [ ] Delete an unpaid draft: it disappears from the registry but its history stays in `audit_log`.

## D. Documents (print on paper or to PDF)

- [ ] Agreement: the letterhead repeats on **every** page of a multi-page print.
- [ ] A draft prints with the DRAFT watermark; a confirmed one prints without it.
- [ ] The agreement day and month print exactly as typed ("14th", "September, 2026").
- [ ] Invoice: `INV-` number, the amount in words with lakh/crore, the payments table, and the same
      invoice date on every reprint.
- [ ] An amended booking prints `Rev 1` on both documents, with "supersedes Rev 0".
- [ ] The operations sheet prints the ops items, quantities and the tick column.
- [ ] Page breaks don't split a table row or a signature block awkwardly.

## E. Files

- [ ] Upload a PDF and a photo to a draft; both download correctly.
- [ ] Upload a `.php` file renamed to `.pdf`: refused.
- [ ] Upload something larger than 5 MB: refused with a clear message.
- [ ] A vendor cannot open another vendor's attachment (404).
- [ ] Voiding a file hides it from the vendor and makes the amendment check ask for a new signed copy.

## F. Admin data

- [ ] Renaming a catalog charge does not change an old invoice.
- [ ] Retiring a catalog item keeps it on existing bookings but removes it from new ones.
- [ ] A venue used by a booking cannot be renamed or deleted, only deactivated.

## G. Backups and handover

- [ ] A manual phpMyAdmin export has been taken and downloaded.
- [ ] Hostinger's automatic backups are on, and AO Mess knows how to restore one.
- [ ] `storage/uploads/` is included in whatever backup routine is agreed.
- [ ] The admin password and the database credentials are stored somewhere safe (not only in email).
- [ ] AO Mess has been shown: the registry, creating and confirming a booking, recording a payment,
      printing the three documents, and approving a vendor.

## H. Open questions to settle with AO Mess

- [ ] Contract wording reviewed (the agreement currently prints "pending legal review").
- [ ] Charge list and default rates entered in the catalog.
- [ ] Venue list matches reality.
- [ ] Who confirms bookings and records payments (currently the admin only).
- [ ] Office address, phone and email set in `config.php` for the letterhead.
