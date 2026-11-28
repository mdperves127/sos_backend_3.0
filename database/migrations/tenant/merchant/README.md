# Merchant-only tenant migrations

These migrations run **only** for `tenants.type = merchant` (after the shared `database/migrations/tenant` path).

Dropshipper registrations do **not** get products, POS, warehouses, WooCommerce, assignments, etc.

## Commands

```bash
php artisan tenants:migrate
# or
php artisan tenants:migrate-structured --type=merchant
```
