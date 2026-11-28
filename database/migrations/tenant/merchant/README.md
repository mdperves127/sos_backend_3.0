# Merchant-only tenant migrations

Put **new** migrations here only when the change must apply to `tenants.type = merchant` and must **not** run on dropshipper tenant databases.

## Rules

- Do **not** move existing files from `database/migrations/tenant/`.
- Prefer shared migrations when both roles need the same schema.
- Merchant and dropshipper share one schema today; most product/order tables stay shared.
- Filenames must be unique across shared + merchant + dropshipper paths.

## Run

Automatically after shared migrations on merchant tenant create, or:

```bash
php artisan tenants:migrate-structured --type=merchant
```
