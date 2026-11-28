# Dropshipper-only tenant migrations

These migrations run **only** for `tenants.type = dropshipper` (after the shared path).

Includes listing/request tables such as `product_details`, `pending_products`, `product_ratings`.

Merchant catalog tables (`products`, POS, warehouses, …) are **not** created here.

## Commands

```bash
php artisan tenants:migrate
# or
php artisan tenants:migrate-structured --type=dropshipper
```
