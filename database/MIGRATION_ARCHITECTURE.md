# AffSell Migration Architecture

## Executive finding

Merchant and Dropshipper are **tenant types** (`tenants.type`), not separate database schemas.

Every tenant (merchant or dropshipper) receives the **same shared tenant migration path**:

`database/migrations/tenant/*.php`

Application code gates features by `tenant()->type`. Schema stays compatible across roles so cross-tenant dropshipping (merchant product DB ↔ dropshipper `product_details`) keeps working.

**Do not move already-executed migration files.** Laravel stores migration basenames in each DB’s `migrations` table; renaming/moving them re-runs or breaks rollback.

---

## 1. Previous migration structure

```
database/migrations/                 # Central / platform (mysql) — ~295 files
database/migrations/tenant/          # Shared tenant schema — ~233 files
```

No merchant/dropshipper folders existed. `php artisan tenants:migrate` and Stancl `MigrateDatabase` both used only `migrations/tenant`.

---

## 2. New migration folder structure

```
database/migrations/                          # Central (unchanged)
database/migrations/tenant/                   # SHARED tenant schema (existing files STAY)
database/migrations/tenant/merchant/          # Future merchant-only migrations (empty)
database/migrations/tenant/dropshipper/       # Future dropshipper-only migrations (empty)
config/tenant_migrations.php                  # Path + table ownership catalog
database/MIGRATION_ARCHITECTURE.md            # This document
```

Subfolders are **not** picked up by Laravel’s non-recursive `--path=database/migrations/tenant` glob, so existing `tenants:migrate` behavior is unchanged.

---

## 3. Tenant-specific / shared tables (tenant DB)

Used by both merchant and dropshipper tenants (users, CMS, storefront, orders, payments, etc.):

`users`, `password_resets`, `personal_access_tokens`, roles/permissions, `vendor_*`, `settings`, `cms_settings`, `media`, `page_builders`, `pages`, `banners`, `offers`, `news`, messaging, geo tables, `orders` + related, `carts`, `customers`, coupons, delivery/courier, payment tables, `woocommerce_*`, `tenant_contact_form_data`, …

Full list: `config/tenant_migrations.php` → `tables.shared`.

---

## 4. Merchant-specific tables (usage)

Canonical catalog / inventory / POS / dropship assignment **source of truth** on merchant tenant DBs:

`products`, `product_images`, `product_variants`, purchases, bundles, pre-orders, categories/brands/colors/sizes/units, `warehouses`, `suppliers`, POS/sales/damages, `dropshipper_product_assignments`, balance tables, …

These remain in the **shared** migration path so dropshipper DBs stay schema-compatible; code only uses them heavily when `type=merchant`.

---

## 5. Dropshipper-specific tables (usage)

`product_details` — dropshipper’s catalog rows referencing merchant products  
`pending_products`, `product_ratings`

Also still created via shared migrations (not duplicated into a dropshipper-only create migration).

---

## 6. Shared tables used by both roles

See §3. Orders, carts, CMS, users, payments, Woo credentials structure, etc. are intentionally shared.

**Usage nuance (confirmed by code analysis):**
- Wallet = central `tenants.balance` + `payment_histories` / `withdraws` (no `wallets` table).
- Product/dropship **order rows** are written on the **merchant** tenant DB; dropshippers read them cross-tenant.
- Dropshipper catalog writes go to **dropshipper** `product_details` (`tenant_id` = merchant).
- `dropshipper_product_assignments` is migrated everywhere but only merchants write it.

---

## 7. Duplicates / consolidation decisions

| Finding | Action | Reason |
|--------|--------|--------|
| ~163 same filenames in central + tenant | **Kept** | Historical dual schema (platform + tenant). Central still has `products`/`orders`. Dropping requires a separate admin-legacy audit. |
| Merchant DB 111 tables vs dropshipper 96 | **Not role design** | Dropshipper tenant is behind on `tenants:migrate` (178 vs 222 migration rows). |
| Separate merchant/dropshipper *create* tables for products/orders | **Rejected** | Would break cross-tenant dropshipping and double schema maintenance. |
| Moving historical files into merchant/dropshipper folders | **Rejected** | Breaks recorded migrations on all existing tenant DBs. |

**Nothing was dropped from production databases.**

---

## 8. New artifacts created

- `config/tenant_migrations.php`
- `database/migrations/tenant/merchant/` (+ README)
- `database/migrations/tenant/dropshipper/` (+ README)
- `app/Services/TenantMigrationPathResolver.php`
- `app/Jobs/MigrateTenantRoleMigrations.php`
- `app/Console/Commands/MigrateTenantsStructuredCommand.php`
- Pipeline hook in `TenancyServiceProvider`
- This architecture doc

No schema-changing SQL migrations were added (role folders are empty by design).

---

## 9. Modified files

- `app/Providers/TenancyServiceProvider.php` — run role migrations after shared
- `config/tenancy.php` — documented shared-only `migration_parameters`

---

## 10. Commands / tests

```bash
# Existing (unchanged) — shared schema for all tenants
php artisan tenants:migrate

# New — shared + role folder (safe; role folders empty today)
php artisan tenants:migrate-structured
php artisan tenants:migrate-structured --type=merchant
php artisan tenants:migrate-structured --type=dropshipper --role-only
php artisan tenants:migrate-structured --pretend
```

Central:

```bash
php artisan migrate
```

Local observation: dropshipper sample DB missing newer tables (`page_builders`, `media`, …) because migrations were not applied — run `tenants:migrate` / `tenants:migrate-structured` when approved.

---

## 11. Manual production steps (approval required)

1. Confirm `TENANCY_DB_PREFIX` / `MAIN_DOMAIN` in `.env`.
2. Deploy code (no destructive migration).
3. Optionally run `php artisan tenants:migrate` to catch up lagging dropshipper DBs (**review first**; not auto-run).
4. Put future merchant-only ALTERs under `tenant/merchant/`, dropshipper-only under `tenant/dropshipper/`.
5. Do **not** delete central duplicate catalog tables without a dedicated deprecation project.
6. Do **not** rename/move files already present in any `migrations` table.

---

## Runtime flow (new tenants)

1. `CreateDatabase`
2. `MigrateDatabase` → `database/migrations/tenant` (shared)
3. `MigrateTenantRoleMigrations` → `tenant/merchant` or `tenant/dropshipper` if files exist
4. `SeedDatabase` → `TenantSetupSeeder`
5. `CreateTenantUser`
