# Database migrations

`../schema.sql` is **schema version 1** (it inserts `version = 1` into `schema_version`). A fresh install imports `schema.sql` and then `seed.sql`; no migration is needed.

Every schema change after launch is a new numbered file here, starting at `002_<short-name>.sql`:

1. Take a phpMyAdmin export of the live database first.
2. Import the migration file through phpMyAdmin.
3. The file's last statement records it: `INSERT INTO schema_version (version) VALUES (2);`
4. Apply the same change to `schema.sql`, so a fresh install always equals "version 1 + all migrations".

Never edit a migration that has already been applied anywhere; write a new one.
