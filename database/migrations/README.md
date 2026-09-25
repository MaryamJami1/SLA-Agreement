# Database migrations

`../schema.sql` always carries **version 1 plus every migration in this folder**, and records each of those versions in `schema_version`. A fresh install imports `schema.sql` and then `seed.sql`; no migration file is needed.

An existing database is upgraded by importing the migration files it has not recorded yet. Check with:

```sql
SELECT version, applied_at FROM schema_version ORDER BY version;
```

Every schema change after launch is a new numbered file here, starting at `002_<short-name>.sql`:

1. Take a phpMyAdmin export of the live database first.
2. Import the migration file through phpMyAdmin.
3. The file's last statement records it: `INSERT INTO schema_version (version) VALUES (2);`
4. Apply the same change to `schema.sql`, and add the new version to its `INSERT INTO schema_version` list, so a fresh install always equals "version 1 + all migrations".

Never edit a migration that has already been applied anywhere; write a new one.
