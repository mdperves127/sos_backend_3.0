# Dropshipper-only tenant migrations

Put **new** migrations here only when the change must apply to `tenants.type = dropshipper` and must **not** run on merchant tenant databases.

## Rules

- Do **not** move existing files from `database/migrations/tenant/`.
- Prefer shared migrations when both roles need the same schema.
- `product_details` and assignment flows already live in the shared schema; do not recreate them here.
- Filenames must be unique across shared + merchant + dropshipper paths.

## Run

Automatically after shared migrations on dropshipper tenant create, or:

```bash
php artisan tenants:migrate-structured --type=dropshipper
```
