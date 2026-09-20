# Deploying to Hostinger

This is the whole process, from an empty hosting account to a working site. Follow it in order.
Everything here is done through hPanel and an FTP/File Manager upload; no command line is required.

Plan reference: Section 11 (Hostinger notes) and Section 4 (folder layout).

---

## 1. Decide where it goes

| | Address | Database | Use |
|---|---|---|---|
| Staging | `staging.<your-domain>` | `<prefix>_aomess_stg` | Try everything here first |
| Live | `<your-domain>` | `<prefix>_aomess` | The real register |

Staging is strongly recommended for the first deployment. **Each installation needs its own folder,
its own copy of the app files and its own database.** Never point a subdomain at a folder inside the
live `public_html`: the app refuses to run if its code ends up inside the web root.

In hPanel, create the subdomain under **Domains → Subdomains**. Note the document root it gives you;
it is usually `domains/staging.<your-domain>/public_html`.

---

## 2. Set PHP to 8.2

**Advanced → PHP Configuration → PHP version: 8.2.**
Under **PHP extensions**, make sure these are ticked: `pdo_mysql`, `fileinfo`, `mbstring`, `json`, `session`.

---

## 3. Create the database

**Databases → Management → Create a new database.**

- Database name, user name and a long random password: write all three down.
- Note the host, which is normally `localhost` on Hostinger.

Then open **phpMyAdmin** for that database and import, in this order:

1. `database/schema.sql`
2. `database/seed.sql`

After importing, the database has 11 tables and one admin account.

---

## 4. Upload the files

The folder layout must end up like this, where `domains/<site>/` is the folder that contains
`public_html`:

```
domains/<site>/
  public_html/      <- the CONTENTS of the project's public/ folder (index.php, auth/, booking/, ...)
  app/              <- from the project
  config/           <- from the project
  database/         <- from the project
  storage/          <- from the project (uploads/, logs/, sessions/ — keep them empty)
```

Notes:

- **Upload the contents of `public/`, not the folder itself.** `index.php` must sit directly in `public_html`.
- Hidden files matter: `public/.htaccess` and `public/.user.ini` must be uploaded too. In File Manager,
  turn on "Show hidden files"; in FileZilla, Server → Force showing hidden files.
- `tests/` is not needed on the server. If you upload it anyway, it sits above the web root and is unreachable.
- `storage/uploads`, `storage/logs` and `storage/sessions` must exist and be writable (permission 755 is enough
  on Hostinger; the web server runs as your own user).

---

## 5. Write config.php

Copy `config/config.sample.php` to `config/config.php` and fill it in:

```php
'APP_ENV'              => 'production',
'BASE_URL'             => '',                       // '' when the app is at the domain root
'CANONICAL_HOST'       => 'staging.your-domain.pk', // this installation's own host name
'DB_HOST'              => 'localhost',
'DB_PORT'              => 3306,                     // Hostinger uses 3306, not the local 3307
'DB_NAME'              => 'u123456_aomess',
'DB_USER'              => 'u123456_aomess',
'DB_PASS'              => '…the password you noted…',
'DEVICE_COOKIE_SECRET' => '…64 random characters…',
'ORG_NAME'             => 'AO Mess / ASK Organizers',
'ORG_ADDRESS'          => '…office address for the letterhead…',
'ORG_PHONE'            => '…',
'ORG_EMAIL'            => '…',
```

Generate the secret with any random string generator, or in phpMyAdmin:
`SELECT SHA2(RAND(), 256);`

`config/config.php` is never committed to git. Keep a copy of its values somewhere safe.

---

## 6. Turn on HTTPS

**Security → SSL → install the free certificate** for the domain or subdomain, then wait until it is active.
The app redirects to HTTPS by itself once `APP_ENV` is `production`, and `.htaccess` does the same for
requests that never reach PHP.

---

## 7. First sign-in

1. Open the site. You should see the sign-in page.
2. Sign in as `admin` / `ChangeMe-2026`.
3. You are forced to set a new password immediately. Choose a strong one and store it safely.

---

## 8. Run the checks

Sign in as the admin and open **Checks** in the menu (`/admin/preflight.php`). Everything should be
PASS. Anything marked FAIL must be fixed before real use; WARN items are worth reading.

Then work through `docs/GO_LIVE_CHECKLIST.md`, which covers what that page cannot check by itself
(unreachable folders, printing, and the money and lifecycle rules end to end).

---

## 9. Set up the real data

As the admin:

- **Venues** — add or rename the venues so they match AO Mess (a venue that has been used by a booking
  can only be deactivated, so get the names right before the first booking).
- **Catalog** — set the default rates for the charges, and add or retire items.
- **Vendors** — ask each vendor to register from the sign-in page, then approve them.

---

## Backups

- **Automatic:** hPanel → Files → Backups. Hostinger keeps regular backups; check the schedule matches
  what AO Mess expects.
- **Before any change to the database:** phpMyAdmin → the database → Export → Quick → Go, and keep the
  `.sql` file. Do this before every migration, without exception.
- **Files:** the attachments in `storage/uploads/` are not in the database. Include them in any manual
  backup, or download that folder periodically.

## Applying a schema change later

1. Take a phpMyAdmin export of the live database (above).
2. Import the new numbered file from `database/migrations/` (for example `002_….sql`).
3. Upload the changed PHP files.
4. Open **Checks** again and confirm the schema version has increased.

## If something goes wrong

- The site shows "The application is not configured correctly": read `storage/logs/app-YYYY-MM.log`.
  The usual causes are a missing `config/config.php`, wrong database details, or `app/` having been
  uploaded inside `public_html`.
- A blank page or HTTP 500: check `storage/logs/php-errors.log`.
- You are locked out after too many wrong passwords: wait 15 minutes, or clear the recent rows from
  `login_attempts` in phpMyAdmin.
- You lost the admin password: in phpMyAdmin, run
  `UPDATE users SET password_hash = '<a bcrypt hash>', must_change_password = 1 WHERE username = 'admin';`
  A hash can be generated locally with `php -r "echo password_hash('temporary-pass', PASSWORD_DEFAULT);"`.
