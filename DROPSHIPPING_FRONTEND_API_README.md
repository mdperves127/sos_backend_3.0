# Dropshipping Frontend API Integration Guide

How to wire the frontend to AffSell's dropshipping backend (Laravel 9, multi-tenant). Everything here was checked against the routes, controllers, Form Requests and services in this repository.

Labels used in this document:

- **CONFIRMED** – verified in code.
- **NOT IMPLEMENTED** – no backend support exists.
- **NOT VERIFIED** – the code path exists but its exact behaviour or response shape was not confirmed.

---

## 1. Overview

| Feature | Who uses it | Status |
|---|---|---|
| Assign a product, a category, or all of a category's products to a dropshipper | Merchant | CONFIRMED |
| List and revoke assignments | Merchant | CONFIRMED |
| Private products (only assigned dropshippers can see or use them) | Merchant sets it; the dropshipper sees the effect | CONFIRMED |
| Auto-approval (product-level, category-level, inheritance) | Merchant | CONFIRMED |
| Product requests (apply, pending, approve or reject) | Both | CONFIRMED |
| Dropshipper custom selling price with merchant rules (min, max, fixed, on/off) | Merchant sets the rules; the dropshipper sets the price | CONFIRMED |
| Platform fee on delivery, plus an earnings snapshot on the order | Admin config; read by merchant and dropshipper | CONFIRMED |
| A dedicated "my private products" list for dropshippers | Dropshipper | **NOT IMPLEMENTED** (see §12) |

> **Migrations are required.** Run the new tenant and central migrations (`php artisan migrate` and `php artisan tenants:migrate`) before the features work. Until then, the merchant endpoints return `503` with `"Dropshipping access controls are not available yet. Please run tenant migrations."`

---

## 2. Backend/API Architecture

- **Tenancy:** every merchant and every dropshipper is a *tenant* with its own database and its own domain or subdomain.
  - The tenant is identified by the **request host**, through the `InitializeTenancyByDomain` middleware.
  - Merchant APIs must be called on the merchant's domain; dropshipper APIs on the dropshipper's domain.
- **Where data lives:**

| Data | Database | Notes |
|---|---|---|
| Products and categories, including the new dropshipping settings | Merchant | `products`, `categories` |
| Assignments | Merchant | `dropshipper_product_assignments` |
| Product requests or listings | Dropshipper | `product_details`. `tenant_id` holds the merchant's tenant ID, and `status` is `1` = active/approved, `2` = pending, `3` = rejected/revoked |
| Dropshipper selling price | Dropshipper | `product_details.selling_price` and `product_details.profit_amount` |
| Orders and their settlement snapshot | Merchant | `orders` |
| Dropshipper wallet balance | Central | `tenants.balance` |

- **Route prefixes (all start with `/api`):**

| Prefix | Audience | Controller(s) |
|---|---|---|
| `/api/dropshipper/...` | **Merchant** (despite the name) | `Tenant\RequestProductController`, `Tenant\DropshipperAssignmentController` |
| `/api/tenant-dropshipper/...` | **Dropshipper** | `API\Affiliate\*` |
| `/api/tenant-category`, `/api/tenant-product` | Merchant | existing category and product CRUD |
| `/api/tenant-product-order/...` | Merchant | existing order management |
| `/api/admin/...` | AffSell admin (central domain) | settings and subscription plans |

---

## 3. Authentication and Tenant Context

### 3.1 Base URL

Use the **tenant's own domain**:

```js
// Merchant app: the merchant's domain. Dropshipper app: the dropshipper's domain.
const API_BASE_URL = `https://${tenantDomain}/api`;
```

### 3.2 Login (CONFIRMED)

`POST {API_BASE_URL}/auth/login` (public, on the tenant domain)

```json
{ "email": "owner@example.com", "password": "********" }
```

Success:

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { "id": 1, "name": "Owner", "email": "owner@example.com", "role_type": "...", "vendor_role_id": null, "usersubscription": { "...": "..." } },
    "token": "12|xxxxxxxxxxxxxxxx",
    "tenant_id": "mymerchant",
    "tenant_type": "merchant"
  }
}
```

Failure: `{ "success": false, "message": "Invalid credentials" }` or `{ "success": false, "message": "Validation failed", "errors": {...} }`.

Use `data.tenant_type` (`merchant` or `dropshipper`) to decide which app or screens to show.

### 3.3 Authenticated requests

Every endpoint in this document goes through `App\Http\Middleware\TenantMiddleware`, so send:

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

The token must belong to **the same tenant as the request host**. Possible auth failures:

| HTTP | Body |
|---|---|
| 404 | `{"message":"Tenant not found."}` (unknown host) |
| 401 | `{"message":"No bearer token provided."}`, `Invalid token format.`, `Token not found.`, `Invalid token.`, or `User not found in tenant.` |

No extra tenant header is needed; the host *is* the tenant context.

### 3.4 Employee permissions (merchant side)

Merchant employees (`role_type === "employee"`) need role permissions. Owners and tenant admins always pass.

| Endpoint group | Permission key |
|---|---|
| Assignments list, dropshipper lookup, create assignment, product or category settings | `active_request` |
| Revoke assignment | `reject_request` |
| Pending request list | `pending_request` |
| Active request list | `active_request` |
| Rejected request list | `reject_request` |

When permission is missing, the response is HTTP 200 with `{"status":400,"message":"No permission"}`.

---

## 4. API Endpoint Reference

### 4.1 Merchant endpoints (call on the merchant domain)

All endpoints below require merchant authentication. The new endpoints (marked **NEW**) also reject non-merchant tenants with `403 {"status":403,"message":"Only merchants can manage dropshipper access."}`.

| # | Method | URL | Purpose |
|---|---|---|---|
| M1 | GET | `/api/dropshipper/assignable-dropshippers?search=` | **NEW** Search approved dropshippers |
| M1b | GET | `/api/dropshipper/assignable-dropshippers/{tenantId}` | **NEW** Look up one dropshipper by exact tenant ID |
| M2 | GET | `/api/dropshipper/assignments` | **NEW** List assignments (paginated, 20 per page) |
| M3 | POST | `/api/dropshipper/assignments` | **NEW** Create an assignment (product, category, or category_products) |
| M4 | POST | `/api/dropshipper/assignments/{id}/revoke` | **NEW** Revoke an assignment |
| M5 | GET | `/api/dropshipper/product-settings/{productId}` | **NEW** Read product dropshipping settings and price rules |
| M6 | POST | `/api/dropshipper/product-settings/{productId}` | **NEW** Update private, auto-approval and price rules |
| M7 | POST | `/api/dropshipper/category-settings/{categoryId}` | **NEW** Update category auto-approval |
| M8 | GET | `/api/dropshipper/request/product/pending` | Pending requests from dropshippers |
| M9 | GET | `/api/dropshipper/request/product/active` | Approved requests |
| M10 | GET | `/api/dropshipper/request/product/rejected` | Rejected requests |
| M11 | GET | `/api/dropshipper/request/product/all` | All requests |
| M12 | GET | `/api/dropshipper/request-count-{status}` | Count; `status` is `pending`, `active` or `rejected` |
| M13 | POST | `/api/dropshipper/request/product-update/{tenant_id}/{id}` | Approve or reject a request |
| M14 | GET | `/api/tenant-category` and `/api/tenant-category/active` | Category lists (source of `category_id`) |
| M15 | GET | `/api/tenant-product/{status?}` | Product list (source of `product_id`) |
| M16 | GET | `/api/tenant-product-order/all-orders` and `order/view/{id}` | Orders (settlement fields, §7) |
| M17 | POST/PUT/PATCH | `/api/tenant-product-order/status/{id}` | Change order status; `delivered` triggers settlement |

### 4.2 Dropshipper endpoints (call on the dropshipper domain)

| # | Method | URL | Purpose |
|---|---|---|---|
| D1 | GET | `/api/tenant-dropshipper/products` | Marketplace listing (private products filtered) |
| D2 | GET | `/api/tenant-dropshipper/single/product/{tenant_id}/{id}` | Product detail plus `dropshipper_pricing` |
| D3 | POST | `/api/tenant-dropshipper/request/product/{tenant_id}/{id}` | Request or add a product (auto-approval aware) |
| D4 | GET | `/api/tenant-dropshipper/request/pending/product` | My pending requests |
| D5 | GET | `/api/tenant-dropshipper/request/active/product` | My active listings |
| D6 | GET | `/api/tenant-dropshipper/request/reject/product` | My rejected or revoked requests |
| D7 | POST | `/api/tenant-dropshipper/single/product/add-profit/{id}` | Set my selling price or margin |
| D8 | POST | `/api/tenant-dropshipper/add-to-cart` | Add to cart (re-checks access) |
| D9 | GET | `/api/tenant-dropshipper/cart` | View cart |
| D10 | DELETE | `/api/tenant-dropshipper/delete-cartitem/{cart_id}` | Remove a cart item |
| D11 | POST | `/api/tenant-dropshipper/order-create` | Checkout (re-checks access) |
| D12 | GET | `/api/tenant-dropshipper/all-orders`, `pending-orders`, `delivered-orders`, … | Orders (paginated) |
| D13 | GET | `/api/tenant-dropshipper/order/view/{id}` | Order detail |
| D14 | GET | `/api/tenant-dropshipper/pending-balance` / `active-balance` | Balances |

### 4.3 Admin endpoints (central admin domain, admin token)

| Method | URL | Purpose |
|---|---|---|
| GET | `/api/admin/settings` | Read settings, including `dropship_fee_type` and `dropship_fee_value` |
| POST | `/api/admin/settings-update/{id}` | Update the global fee: `dropship_fee_type` is `none`, `fixed`, `percentage` or `plan`; `dropship_fee_value` must be ≥ 0, at most 2 decimals, and ≤ 100 for percentage |
| GET | `/api/admin/subscription` | List plans, including `dropship_fee_type` and `dropship_fee_value` |
| PUT/PATCH | `/api/admin/subscription/{id}` | Update a plan. Send the existing required fields (`card_symbol_icon`, `subscription_amount`, `card_time`, `card_heading`, `card_feature_title`, `card_facilities_title`) plus optional `dropship_fee_type` (`fixed` or `percentage`, or null for no fee) and `dropship_fee_value` |

---

## 5. Merchant Integration Guide

### 5.1 Find a dropshipper to assign (M1)

`GET /api/dropshipper/assignable-dropshippers?search=acme`

- Requires at least 2 characters in `search`; otherwise it returns an empty `data` array.
- Matches the exact tenant ID or a partial company name.
- Returns at most 20 results, and only approved dropshippers (tenant status `active`).

```json
{ "status": 200, "data": [ { "id": "acmeshop", "company_name": "Acme Shop" } ] }
```

**Look up by exact tenant ID (M1b):** `GET /api/dropshipper/assignable-dropshippers/acmeshop`

```json
{
  "status": 200,
  "data": { "id": "acmeshop", "company_name": "Acme Shop", "domain": "acmeshop.example.com", "status": "active", "assignable": true }
}
```

- Returns `404 {"status":404,"message":"Dropshipper not found."}` when the ID doesn't exist or isn't a dropshipper.
- Unapproved dropshippers are still returned, with `assignable: false` and their `status` (`pending` or `blocked`). Disable the "Assign" action for them, because M3 would reject them with 422.
- `status` may be `null` on installs without the tenant status column; those count as assignable.
- Requires the same merchant guard and `active_request` employee permission as M1.

Use `id` as `dropshipper_tenant_id`. The merchant request lists (M8–M11) also expose `dropshipper_tenant_id` for dropshippers who have already applied.

### 5.2 Create an assignment (M3)

`POST /api/dropshipper/assignments`

| Field | Rule |
|---|---|
| `dropshipper_tenant_id` | required, string; must be an approved dropshipper |
| `type` | required: `product`, `category`, or `category_products` |
| `product_id` | required when `type=product`; integer ≥ 1 |
| `category_id` | required when `type` is `category` or `category_products`; integer ≥ 1 |

What each `type` means:

- `product` assigns one product.
- `category` assigns the whole category, **including products added to it later**. Access follows the product's current category.
- `category_products` creates one product assignment for each **eligible** product currently in the category (`status=active` and `is_affiliate=1`). Products added later are **not** included.

```json
{ "dropshipper_tenant_id": "acmeshop", "type": "product", "product_id": 42 }
```

Success (HTTP 200):

```json
{
  "status": 200,
  "message": "Assigned successfully.",
  "summary": { "created": 1, "reactivated": 0, "skipped": 0, "approved_pending_requests": 1 }
}
```

- `message` is `"Already assigned."` when nothing new was created or reactivated, which is how duplicates are reported.
- `approved_pending_requests` is the number of that dropshipper's pending requests that were auto-approved by this assignment.

Errors:

| HTTP | Body |
|---|---|
| 422 | `{"status":422,"errors":{"type":["..."]}}` (validation) |
| 422 | `{"status":422,"message":"Dropshipper not found or not approved."}` |
| 422 | `{"status":422,"message":"No eligible products in this category."}` (`category_products` only) |
| 404 | `{"status":404,"message":"Product not found."}` or `"Category not found."` |
| 503 | Migrations not run |

### 5.3 List assignments (M2)

`GET /api/dropshipper/assignments?dropshipper_tenant_id=acmeshop&status=active&type=product&page=1`

All filters are optional. `status` is `active` or `revoked`; `type` is `product` or `category`.

```json
{
  "status": 200,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 7,
        "dropshipper_tenant_id": "acmeshop",
        "assignable_type": "product",
        "product_id": 42,
        "category_id": null,
        "target_key": "product:42",
        "status": "active",
        "assigned_by": 1,
        "revoked_by": null,
        "revoked_at": null,
        "created_at": "2026-10-11T10:00:00.000000Z",
        "updated_at": "2026-10-11T10:00:00.000000Z",
        "product": { "id": 42, "name": "T-Shirt", "category_id": 3, "is_private": 1 },
        "category": null,
        "dropshipper_name": "Acme Shop"
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1,
    "next_page_url": null,
    "prev_page_url": null
  }
}
```

The `data` object is a standard Laravel paginator, so it also contains `from`, `to`, `links`, `path`, `first_page_url` and `last_page_url`.

### 5.4 Revoke an assignment (M4)

`POST /api/dropshipper/assignments/{id}/revoke` (no body)

```json
{ "status": 200, "message": "Assignment revoked.", "revoked_listings": 1 }
```

- If it was already revoked: `{"status":200,"message":"Assignment already revoked."}`.
- If not found: `404 {"status":404,"message":"Assignment not found."}`.
- For **private** products only, the dropshipper's existing listings (`product_details` with status `1` or `2`) are moved to status `3` with reason `"Access revoked by merchant"`.
- Public products keep their listings.
- Existing orders are not changed.
- Re-assigning the same target later **reactivates** the same row (`summary.reactivated`).

### 5.5 Product dropshipping settings (M5 / M6)

**Read:** `GET /api/dropshipper/product-settings/{productId}`

```json
{
  "status": 200,
  "data": {
    "product_id": 42,
    "is_private": true,
    "auto_approve_dropshipper": null,
    "category_auto_approve_dropshipper": true,
    "effective_auto_approve_dropshipper": true,
    "pricing": {
      "allow_custom": true,
      "mode": "range",
      "base_price": 1000,
      "min_price": 1100,
      "max_price": 1500,
      "fixed_price": null,
      "enforced_price": null,
      "min_allowed": 1100,
      "max_allowed": 1500
    }
  }
}
```

**Update:** `POST /api/dropshipper/product-settings/{productId}`. Send only the fields you want to change; omitted fields are left as they are.

| Field | Accepted values | Meaning |
|---|---|---|
| `is_private` | boolean | Only assigned dropshippers can see or use the product |
| `auto_approve_dropshipper` | `true`, `false`, `1`, `0`, `"true"`, `"false"`, `"inherit"` or `null` | `null` or `"inherit"` means use the category setting |
| `allow_custom_selling_price` | boolean | Custom pricing on or off |
| `dropshipper_price_mode` | `range` or `fixed` | |
| `dropshipper_min_price`, `dropshipper_max_price`, `dropshipper_fixed_price` | `null` or a positive number with at most 2 decimals | |

Server-side rules (returned as `422 {"status":422,"message":"..."}`):

- `fixed` mode requires `dropshipper_fixed_price`: `"A fixed selling price is required in fixed price mode."`
- Min, max and fixed cannot be below the product's base price: `"The minimum dropshipper price cannot be lower than the product price (1000.00)."`
- Max must be ≥ min: `"The maximum dropshipper price must be greater than or equal to the minimum."`
- Field format errors come back as `422 {"status":422,"errors":{...}}`.

```json
{
  "is_private": true,
  "auto_approve_dropshipper": "inherit",
  "allow_custom_selling_price": true,
  "dropshipper_price_mode": "range",
  "dropshipper_min_price": "1100.00",
  "dropshipper_max_price": "1500.00"
}
```

Success:

```json
{ "status": 200, "message": "Dropshipping settings updated.", "revoked_listings": 0, "data": { "...same shape as GET...": true } }
```

`revoked_listings` is greater than 0 when the product **just became private**. In that case every dropshipper without an assignment has had their listing moved to status `3`. This step loops over all dropshipper databases, so it can be slow; show a loading state.

### 5.6 Category auto-approval (M7)

`POST /api/dropshipper/category-settings/{categoryId}`

```json
{ "auto_approve_dropshipper": true }
```

```json
{ "status": 200, "message": "Category dropshipping settings updated.", "data": { "category_id": 3, "auto_approve_dropshipper": true } }
```

Possible errors: `422 {"status":422,"errors":{...}}` (the field is required and must be a boolean), `404 Category not found.`, and `503` (migrations not run).

To display the current value, read `auto_approve_dropshipper` from the category rows returned by `GET /api/tenant-category` (`categories.data[]`). That endpoint returns all columns. The column only exists after migrations; this is **NOT VERIFIED** on live data.

### 5.7 How auto-approval is decided (backend rule)

When a dropshipper requests a product, it is approved immediately if the first rule that applies says so:

1. The dropshipper has an **active assignment** covering the product (by product or by category). This always approves.
2. Otherwise, the product has an explicit `auto_approve_dropshipper` of `true` or `false`.
3. Otherwise, the category's `auto_approve_dropshipper` decides.
4. Otherwise, the request goes to **pending**.

> `effective_auto_approve_dropshipper` from M5 shows rules 2 and 3 only. It does not account for assignments, which always auto-approve the assigned dropshipper.

Auto-approval never makes a private product public. A private product can only be requested by dropshippers with an assignment.

### 5.8 Product requests: review, approve, reject (M8–M13)

List: `GET /api/dropshipper/request/product/pending?page=1&search=shirt&order_id=12`

```json
{
  "status": 200,
  "product": {
    "data": [
      {
        "id": 15,
        "product_id": 42,
        "user_id": 0,
        "vendor_id": 0,
        "status": 2,
        "reason": null,
        "uniqid": "6528...",
        "created_at": "...",
        "updated_at": "...",
        "stored_tenant_id": "mymerchant",
        "dropshipper_tenant_id": "acmeshop",
        "dropshipper_tenant_name": "Acme Shop",
        "tenant_id": "acmeshop",
        "tenant_name": "Acme Shop",
        "tenant_owner": "Owner Name",
        "product": { "id": 42, "name": "T-Shirt", "selling_price": "1000", "image": "...", "product_image": [] }
      }
    ],
    "current_page": 1,
    "per_page": 10,
    "total": 1,
    "last_page": 1,
    "from": 1,
    "to": 1,
    "next_page_url": null,
    "prev_page_url": null
  }
}
```

In these list rows, `tenant_id` is the **dropshipper's** tenant ID, and `stored_tenant_id` is your merchant ID.

Count: `GET /api/dropshipper/request-count-pending` returns `{"status":200,"count":3}`.

**Approve or reject (M13):** `POST /api/dropshipper/request/product-update/{tenant_id}/{id}`

- `tenant_id` is the row's `dropshipper_tenant_id`.
- `id` is the row's `id`.

| Field | Rule |
|---|---|
| `status` | required: `1` (approve) or `3` (reject) |
| `reason` | required when `status=3` |

```json
{ "status": 3, "reason": "Out of stock for dropshipping" }
```

| Result | HTTP | Body |
|---|---|---|
| Success | 200 | `{"status":200,"message":"updated successfully"}` |
| Not found, or the request belongs to another merchant | 200 | `{"status":404,"message":"Not found"}` |
| Approving a private product that isn't assigned to that dropshipper | 422 | `{"status":422,"message":"This product is private. Assign it to this dropshipper before approving."}` |
| Product deleted | 404 | `{"status":404,"message":"Product not found"}` |
| Validation | 200 | `{"success":false,"message":"Validation errors","data":{...}}` |

> `GET /api/dropshipper/request/product/view/{id}` exists but queries the merchant's own database with legacy filters. Dropshipper requests are stored in the dropshipper databases, so this is **NOT VERIFIED** to return requests; use the list rows instead.

---

## 6. Dropshipper Integration Guide

### 6.1 Marketplace listing (D1)

`GET /api/tenant-dropshipper/products?page=1`

Optional filters: `search`, `warranty`, `category_id`, `start_stock` + `end_stock`, `start_price` + `end_price`, `start_commission` + `end_commission`, and `high_to_low` or `low_to_high`.

```json
{
  "status": 200,
  "products": {
    "current_page": 1,
    "data": [
      {
        "id": 42,
        "name": "T-Shirt",
        "selling_price": "1000",
        "discount_price": null,
        "discount_rate": "10",
        "discount_type": "percent",
        "is_affiliate": 1,
        "is_private": 1,
        "category_id": 3,
        "tenant_id": "mymerchant",
        "tenant_name": "My Merchant",
        "tenant_domain": "mymerchant.example.com"
      }
    ],
    "per_page": 10,
    "total": 1,
    "last_page": 1
  }
}
```

The `data` rows carry **all** product columns, plus the merchant's tenant info (`tenant_id`, `tenant_domain`, `tenant_name`, `tenant_type`, `tenant_email`, `tenant_owner`, `tenant_phone`). The `products` object is a standard Laravel `LengthAwarePaginator`.

How the backend filters this list:

- Only `status=active` and `is_affiliate=1`.
- Private products appear **only** for dropshippers with an active product or category assignment.
- Products this dropshipper already has a `product_details` row for (any status: pending, active or rejected) are **excluded**.

**Showing private products separately:** filter the rows on `is_private === 1` (or `"1"`) to put them in an "Assigned to you" section. There is **no dedicated endpoint**; see §12.

### 6.2 Product detail (D2)

`GET /api/tenant-dropshipper/single/product/{tenant_id}/{id}`, where `tenant_id` is the merchant's tenant ID from the listing row.

```json
{
  "status": 200,
  "product": {
    "id": 42,
    "name": "T-Shirt",
    "selling_price": "1000",
    "discount_price": null,
    "...": "all product columns and relations (productImage, productVariant, marketplaceCategory, ...)",
    "dropshipper_pricing": {
      "allow_custom": true,
      "mode": "range",
      "base_price": 1000,
      "min_price": 1100,
      "max_price": 1500,
      "fixed_price": null,
      "enforced_price": null,
      "min_allowed": 1100,
      "max_allowed": 1500
    }
  }
}
```

If the product doesn't exist, is inactive, or is private without an assignment, the response is HTTP 200 with `{"status":404,"message":"No Product Id Found"}`. Unknown and private products return the same response on purpose, so IDs can't be probed.

### 6.3 Request or add a product (D3)

`POST /api/tenant-dropshipper/request/product/{tenant_id}/{id}`

- `tenant_id` is the merchant tenant ID.
- `id` is the product ID. It is optional in the route but **required** in practice.
- Optional body: `{ "reason": "text" }`.

| Result | HTTP | Body |
|---|---|---|
| Auto-approved | 200 | `{"status":200,"message":"Product added to your store.","auto_approved":true,"product_detail_status":1}` |
| Sent for approval | 200 | `{"status":200,"message":"Product Request Successfully Please Wait","auto_approved":false,"product_detail_status":2}` |
| Already active | 409 | `{"status":409,"message":"This product is already in your store.","product_detail_status":1}` |
| Already pending | 409 | `{"status":409,"message":"A request for this product is already pending.","product_detail_status":2}` |
| Account not approved | 403 | `{"status":403,"message":"Only approved dropshipper accounts can request products."}` |
| Product not found, not eligible, or private | **200** | `{"status":200,"data":"success","message":"Product not fount"}` |

> **Quirk:** the "not found" response reuses the old success envelope. Treat `message === "Product not fount"` as a failure.

A previously rejected request is reused: it becomes pending, or active if auto-approved.

### 6.4 My requests and listings (D4–D6)

- `GET /api/tenant-dropshipper/request/pending/product` (status 2)
- `GET /api/tenant-dropshipper/request/active/product` (status 1)
- `GET /api/tenant-dropshipper/request/reject/product` (status 3)

All three accept `?page=&search=&order_id=`.

```json
{
  "status": 200,
  "products": {
    "data": [
      {
        "id": 15,
        "product_id": 42,
        "status": 1,
        "reason": null,
        "tenant_id": "mymerchant",
        "uniqid": "6528...",
        "profit_amount": "150.00",
        "selling_price": "1150.00",
        "product": { "id": 42, "name": "T-Shirt", "selling_price": "1000", "discount_price": null, "image": "...", "product_image": [] }
      }
    ],
    "current_page": 1,
    "per_page": 10,
    "total": 1,
    "last_page": 1
  }
}
```

- `id` is the `product_details` ID; use it for D7.
- Revoked access shows up in the rejected list with `reason: "Access revoked by merchant"`.

### 6.5 Set my selling price (D7)

`POST /api/tenant-dropshipper/single/product/add-profit/{id}`, where `id` is the `product_details.id` from D4–D6.

Send **one** of:

```json
{ "selling_price": "1250.50" }
```

```json
{ "profit_amount": "250.50" }
```

`profit_amount` is a margin on top of the base price. The backend converts it to `selling_price = base + margin`, then applies the same rules.

Success:

```json
{
  "status": 200,
  "message": "Profit amount updated successfully",
  "productDetails": { "id": 15, "selling_price": "1250.50", "profit_amount": "250.50", "...": "..." },
  "pricing": { "allow_custom": true, "mode": "range", "base_price": 1000, "min_allowed": 1100, "max_allowed": 1500, "...": "..." }
}
```

| Error | HTTP | Body |
|---|---|---|
| Neither field sent | 422 | `{"status":422,"message":"selling_price or profit_amount is required."}` |
| Bad `profit_amount` | 422 | `"Profit amount must be a non-negative amount with at most 2 decimal places."` |
| Price rule violated | 422 | `{"status":422,"message":"<reason>","pricing":{...}}` |
| Listing or product not found, or access lost | 404 | `{"status":404,"message":"Product details not found"}` |

Possible `<reason>` messages:

- `Selling price must be a positive amount with at most 2 decimal places.`
- `Selling price must be at least 1100.00.`
- `Selling price must not exceed 1500.00.`
- `This product has a fixed selling price of 1200.00.`
- `The merchant does not allow a custom selling price for this product.`

### 6.6 Cart and checkout (D8–D11)

**Add to cart (D8):** `POST /api/tenant-dropshipper/add-to-cart`

```json
{
  "product_id": 42,
  "tenant_id": "mymerchant",
  "purchase_type": "single",
  "cartItems": [ { "qty": 2, "variant_id": 5, "color": 1, "size": 2, "unit": null } ]
}
```

- In dropshipper context, `tenant_id` is optional; it is filled from the active listing.
- The product must have an **active (status 1) listing**, have `is_show_website = 1`, and, if private, an active assignment.

| Result | HTTP | Body |
|---|---|---|
| Success | 200 | `{"status":201,"message":"Added to Cart"}` |
| Already in cart | 200 | `{"status":409,"message":"T-Shirt already added to cart."}` |
| Validation, including "Product not found!" for revoked or private products | 200 | `{"success":false,"message":"Validation errors","data":{"product_id":["Product not found!"]}}` |
| Stock | 200 | `{"data":"fail","message":"Quantity not available"}` |

**Checkout (D11):** `POST /api/tenant-dropshipper/order-create`

```json
{
  "cart_id": 10,
  "payment_type": "COD",
  "datas": [
    { "name": "Customer", "phone": "01700000000", "email": "c@example.com", "address": "House 1, Road 2, City", "city": "Dhaka", "variants": [ { "variant_id": 5, "qty": 2 } ] }
  ]
}
```

`payment_type` is one of `aamarpay`, `eps`, `uddoktapay`, `my-wallet` or `COD`.

- **Access re-check (CONFIRMED):** if the product became private or was revoked, the response is `{"data":"fail","message":"You no longer have access to this product. Please remove it from your cart."}`. This check runs **before** any wallet debit or payment.
- **Success** (wallet path, CONFIRMED in `ProductCheckoutService`): `{"status":200,"message":"Checkout successfully!","order_id":"...","orders":[{"id":1,"order_id":"..."}]}`.
- The gateway paths (`aamarpay` and others) return a gateway redirect response; that shape is **NOT VERIFIED** here.

The **storefront** checkout on the dropshipper's own website (`POST /api/frontend-order-create` and the guest variant) also re-checks private access. It returns `"This product is no longer available."` inside its failure list.

---

## 7. Pricing, Profit, and Platform Fee

### 7.1 Price vocabulary (do not mix these up)

| Concept | Field | Where it comes from |
|---|---|---|
| **Merchant/base price** | `dropshipper_pricing.base_price` (D2) or `pricing.base_price` (D7, M5). Raw form: `product.discount_price ?? product.selling_price` | Merchant product |
| **Dropshipper selling price** | `product_details.selling_price` (D4–D7) | Set by the dropshipper |
| **Dropshipper margin** | `product_details.profit_amount` = selling price − base price | Set by the dropshipper |
| Allowed range | `min_allowed` / `max_allowed` (`max_allowed` is `null` when there's no maximum) | Merchant rules |
| Forced price | `enforced_price` (non-null when the dropshipper cannot choose) | Merchant rules |

> ⚠️ `product.selling_price` is the **merchant's** price. `product_details.selling_price` is the **dropshipper's** price. Never write the dropshipper's price into the product, and never call a merchant product endpoint to "set" it. Only D7 sets the dropshipper's price, and the backend never modifies the merchant price.

### 7.2 Price editor logic

- If `enforced_price !== null`, show the price **read-only**:
  - When `mode === "fixed"`: "Fixed price set by merchant: {enforced_price}".
  - When `allow_custom === false`: "Custom pricing disabled; you sell at {enforced_price}".
- Otherwise, show an editable input. Set its minimum from `min_allowed` (which is always ≥ base price) and its maximum from `max_allowed` when present. Accept up to 2 decimals.
- Client-side checks are for UX only. Always show the server's 422 `message`.
- The backend re-checks the stored price against the merchant's **current** rules at checkout. If the merchant changed the rules, the order uses the clamped price.

### 7.3 Order amounts (merchant `orders` table; returned by D12, D13, M16)

| Field | Meaning | When set |
|---|---|---|
| `product_amount` | Base price × qty (what the merchant charges) | Checkout |
| `due_amount` | `product_amount` + delivery − advance − discount; what the courier collects | Checkout |
| `afi_amount` | **Merchant commission** for the dropshipper (gross amount settled through AffSell) | Checkout |
| `profit_amount` | Dropshipper **margin** × qty (informational; not settled by AffSell) | Checkout |
| `dropshipper_base_price` | Unit base price snapshot | Checkout (dropshipper orders) |
| `dropshipper_selling_price` | Unit selling price snapshot | Checkout (dropshipper orders) |
| `platform_fee_type` | `none`, `fixed` or `percentage` | Delivery |
| `platform_fee_rate` | Fixed amount, or percent | Delivery |
| `platform_fee_source` | `global`, `plan:{id}` or `plan:none` | Delivery |
| `platform_fee_amount` | AffSell fee withheld | Delivery (default `0`) |
| `dropshipper_gross_amount` | Commission before the fee | Delivery |
| `dropshipper_net_amount` | Amount credited to the dropshipper wallet | Delivery |
| `platform_fee_settled_at` | Settlement timestamp | Delivery |
| `status` | `pending`, `hold`, `received`, `processing`, `ready`, `progress`, `courier`, `delivered`, `cancel`, `return`, or `waiting_for_dropshipper_approval` | Order lifecycle |

### 7.4 Backend calculation rules (display them; don't recalculate)

- **Commission** (`afi_amount`) is computed at add-to-cart from the merchant's `discount_rate` (`discount_type` `percent` or flat), multiplied by qty. Bulk purchases use the bulk commission. The merchant's balance is debited when the merchant marks the order `received`.
- **Platform fee**, applied once when the order becomes `delivered`, to the commission (`afi_amount`). The global mode is set by the admin:
  - `none`: 0.
  - `fixed`: `dropship_fee_value` per order.
  - `percentage`: `dropship_fee_value`% of the commission.
  - `plan`: the dropshipper's **active** plan's `dropship_fee_type`/`dropship_fee_value`. No active plan, or no plan fee, means 0.
- The fee is capped at the commission, so `net = gross − fee` is never negative.
- The wallet is credited with **net**. The payment history shows `+gross "Product commission"` and, if there is a fee, `-fee "Platform fee (order …)"`.
- Settlement is **idempotent**: repeated "delivered" updates or courier webhooks never credit twice.
- Cancel or return **before** delivery marks the pending commission as `cancel`, so no fee or credit happens. `delivered`, `cancel` and `return` are final states.
- The dropshipper **margin** (`profit_amount`) is **not** charged a fee and is not credited by AffSell.

### 7.5 What to show

| UI value | Use |
|---|---|
| Base price | `dropshipper_base_price`, falling back to `product_amount / qty` for old orders |
| Selling price | `dropshipper_selling_price` (null on old orders or non-dropshipper orders) |
| Margin | `profit_amount` |
| Commission (gross) | `dropshipper_gross_amount` once settled, otherwise `afi_amount` labelled "expected" |
| Platform fee | `platform_fee_amount`; show "applied on delivery" while `platform_fee_settled_at` is null |
| Net earning | `dropshipper_net_amount`; null until delivered |
| Pending balance | D14 `pending-balance`: a bare number, the sum of `afi_amount` for undelivered orders (**gross, before fee**) |
| Wallet balance | D14 `active-balance`: a bare number, the tenant wallet balance (**net**, after fees) |

---

## 8. Request/Response Examples

### 8.1 API client (Axios is already a dependency in `package.json`)

```js
import axios from 'axios';

export function createApi({ tenantDomain, getToken }) {
  const api = axios.create({
    baseURL: `https://${tenantDomain}/api`,
    headers: { Accept: 'application/json' },
  });

  api.interceptors.request.use((config) => {
    const token = getToken();
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
  });

  return api;
}

// Many endpoints return HTTP 200 with an error inside the body. Normalize it:
export function unwrap(response) {
  const body = response.data;
  const bodyStatus = typeof body?.status === 'number' ? body.status : null;
  const failed =
    body?.success === false ||
    body?.data === 'fail' ||
    (bodyStatus !== null && bodyStatus >= 400) ||
    body?.message === 'Product not fount';
  if (failed) {
    const err = new Error(typeof body?.message === 'string' ? body.message : 'Request failed');
    err.status = bodyStatus ?? 400;
    err.fields = body?.errors ?? (body?.success === false ? body?.data : undefined);
    err.body = body;
    throw err;
  }
  return body;
}

export async function call(promise) {
  try {
    return unwrap(await promise);
  } catch (e) {
    if (e.response) {
      // Non-2xx HTTP
      const body = e.response.data ?? {};
      const err = new Error(body.message ?? `HTTP ${e.response.status}`);
      err.status = e.response.status;
      err.fields = body.errors ?? body.data;
      err.body = body;
      throw err;
    }
    throw e;
  }
}
```

### 8.2 Merchant: assign, revoke, settings

```js
await call(api.post('/dropshipper/assignments', {
  dropshipper_tenant_id: 'acmeshop', type: 'category', category_id: 3,
}));

await call(api.post(`/dropshipper/assignments/${assignmentId}/revoke`));

const { data: settings } = await call(api.get(`/dropshipper/product-settings/${productId}`));

await call(api.post(`/dropshipper/product-settings/${productId}`, {
  is_private: true, auto_approve_dropshipper: 'inherit',
  dropshipper_price_mode: 'fixed', dropshipper_fixed_price: '1200.00',
}));

await call(api.post(`/dropshipper/category-settings/${categoryId}`, { auto_approve_dropshipper: true }));

await call(api.post(`/dropshipper/request/product-update/${row.dropshipper_tenant_id}/${row.id}`, { status: 1 }));
```

### 8.3 Dropshipper: request, price, checkout

```js
const res = await call(api.post(`/tenant-dropshipper/request/product/${merchantTenantId}/${productId}`));
// res.auto_approved === true  -> listing is active now
// res.auto_approved === false -> show "Pending merchant approval"

await call(api.post(`/tenant-dropshipper/single/product/add-profit/${productDetailId}`, { selling_price: '1250.00' }));

await call(api.post('/tenant-dropshipper/order-create', { cart_id, payment_type: 'COD', datas }));
```

---

## 9. Error Handling

### 9.1 Response envelopes you will see

| Source | HTTP | Shape |
|---|---|---|
| New assignment and settings endpoints | real (200, 403, 404, 422, 503) | `{status, message}` or `{status:422, errors}` |
| Product request (D3) | real (200, 403, 409); not-found is 200 | `{status, message, auto_approved?, product_detail_status?}` |
| Price update (D7) | real (200, 404, 422) | `{status, message, pricing?}` |
| Form Request validation (add-to-cart, order-create, approve/reject) | **200** | `{success:false, message:"Validation errors", data:{field:[...]}}` |
| `responsejson(...,'fail')` (stock, access lost at checkout) | **200** | `{data:"fail", message}` |
| Legacy not-found or permission responses | **200** | `{status:404 or 400, message}` |
| Auth middleware | 401 or 404 | `{message}` |

**Rule:** always check both the HTTP status *and* the body (`success === false`, `data === "fail"`, numeric `status >= 400`). The `unwrap()` helper in §8.1 does this.

### 9.2 UI handling

- **Loading:** disable the submit button while a mutation is in flight.
- **Validation:** map `errors` or `data` (field → messages) to inputs. Show a plain `message` as a toast.
- **401:** clear the token and redirect to login.
- **403** (not a merchant, or dropshipper not approved): show an access message and hide the feature.
- **404 on product detail or price update:** the product is unavailable or access was revoked. Remove it from local state and refresh the lists.
- **409 on request:** treat it as informational; refresh the listing state.
- **503:** "Feature not available yet (backend migration pending)".

### 9.3 Security note

Hiding a product in the UI is **not** security. The backend enforces private access on:

- D1 (listing filter)
- D2 (detail returns 404)
- D3 (request)
- D7 (price update)
- D8 (add to cart)
- D11 and the storefront checkout
- merchant approval (M13)

Always rely on these responses. Never assume a product is usable just because it's cached in the UI.

---

## 10. Frontend Page-to-API Mapping

| Screen | APIs | Important UI states |
|---|---|---|
| **Merchant: Product edit, "Dropshipping" tab** | M5, M6 | Private toggle; auto-approve as Inherit/On/Off, with the inherited category value shown from `category_auto_approve_dropshipper`; price mode, min, max, fixed; 422 messages; "N listings revoked" notice |
| **Merchant: Category list/edit** | M14 (read), M7 (update) | Auto-approve toggle; 503 state |
| **Merchant: Dropshipper assignments** | M1, M2, M3, M4, plus M14/M15 for pickers | Search (2+ chars); type selector (Product / Whole category incl. future / Current products of category); result summary; duplicate ("Already assigned."); revoke confirm; filters; pagination |
| **Merchant: Dropshipper requests** | M8–M12, M13 | Tabs Pending/Active/Rejected with counts; approve; reject with a required reason; private-not-assigned 422 → link to the assignment screen |
| **Merchant: Orders / order detail** | M16, M17 | Show commission, fee and net after delivery; settlement error (HTTP 500 message) when delivery settlement fails |
| **Dropshipper: Marketplace** | D1 | "Assigned to you" section (`is_private == 1`) and public section; empty state; filters; pagination |
| **Dropshipper: Product detail** | D2, D3 | Base price, price rules, request button. After D3: "Added" (auto) or "Pending". 409 → already requested. 404 → unavailable |
| **Dropshipper: My products (Active/Pending/Rejected)** | D4–D6 | Revoked reason; link to the price editor |
| **Dropshipper: Price editor** | D2 (rules) + D7 | Read-only when `enforced_price` is set; min/max hints; server error message |
| **Dropshipper: Cart / Checkout** | D8–D11 | "No longer available" handling; prevent double submit; gateway redirect |
| **Dropshipper: Orders & earnings** | D12, D13, D14 | Gross/fee/net per order; pending (gross) vs wallet (net) balance |
| **Admin: Platform fee settings** | Admin settings GET/POST | Mode select; value ≤ 100 for percentage |
| **Admin: Plan fee** | Admin subscription PUT | Optional fee type and value per plan |

Work that needs a missing backend endpoint is listed in §12.

---

## 11. Testing Checklist

**Assignment (merchant)**

- [ ] Assigning a single product returns `created: 1`; repeating it returns `"Already assigned."` with `skipped: 1`.
- [ ] `type=category` creates one row with `assignable_type: "category"`; a private product added to that category later is visible to the dropshipper.
- [ ] `type=category_products` creates one row per eligible product; a product added later is **not** covered.
- [ ] `category_products` on an empty category returns 422 `No eligible products in this category.`
- [ ] Unknown or unapproved dropshipper returns 422; an unknown product or category returns 404.
- [ ] Revoking a private product's assignment returns `revoked_listings ≥ 1`; the dropshipper's listing moves to rejected with reason "Access revoked by merchant".
- [ ] Re-assigning after revoke returns `reactivated: 1`.
- [ ] Assigning a product with a pending request returns `approved_pending_requests ≥ 1`.

**Private products**

- [ ] An unassigned dropshipper doesn't see the private product in D1, D2 returns `status 404`, and D3 returns "Product not fount".
- [ ] An assigned dropshipper sees it (`is_private: 1`) and can request it (auto-approved).
- [ ] Making a product private returns `revoked_listings` for unassigned dropshippers.
- [ ] After revoke, D7 returns 404, D8 returns "Product not found!", and D11 returns "You no longer have access…".
- [ ] Merchant approval of an unassigned private request returns 422.

**Auto-approval**

- [ ] Product ON → D3 returns `auto_approved: true`, `product_detail_status: 1`.
- [ ] Product OFF while the category is ON → pending (an explicit product value wins).
- [ ] Product "inherit" with the category ON → auto-approved.
- [ ] OFF everywhere → pending; a second request returns 409 "already pending".
- [ ] Approve or reject via M13; reject without `reason` returns a validation error.

**Pricing**

- [ ] Below `min_allowed`, above `max_allowed`, negative, `0`, `abc` or `10.999` → 422 with a message.
- [ ] Fixed mode: only `enforced_price` is accepted.
- [ ] Custom disabled: only the base price is accepted.
- [ ] Merchant settings: min below base, or max < min → 422.
- [ ] The merchant product's `selling_price` is unchanged after a dropshipper price update.

**Fees and earnings**

- [ ] Before delivery, `platform_fee_settled_at` is null and the net is shown as pending.
- [ ] After delivery, `platform_fee_amount`, `dropshipper_gross_amount` and `dropshipper_net_amount` are set, and the wallet increases by the net amount.
- [ ] Repeating "delivered" (or a webhook) doesn't change the wallet a second time.
- [ ] A cancelled or returned order before delivery gets no fee and no credit.

**Security, cross-tenant access and errors**

- [ ] A missing or invalid token returns 401; a token used on another tenant's domain returns 401.
- [ ] A dropshipper token calling `/api/dropshipper/assignments` returns 403 "Only merchants…".
- [ ] A merchant calling M13 with another merchant's request ID returns `{status:404,"Not found"}`.
- [ ] An employee without permission gets `{status:400,"No permission"}`.
- [ ] Empty lists render empty states, and pagination works (`next_page_url` null on the last page).
- [ ] Network errors and 5xx show a retry option.

---

## 12. Missing APIs or Unverified Features

| Item | Status | Frontend workaround |
|---|---|---|
| Dedicated "private products assigned to me" endpoint for dropshippers | **NOT IMPLEMENTED** | Filter D1 rows by `is_private == 1`. Products already requested are excluded from D1; use D4–D6 for those |
| Dropshipper can see which assignments they have | **NOT IMPLEMENTED** | None. Only the merchant can list assignments |
| Re-requesting a rejected or revoked product from the marketplace | Limited: D1 hides any product with an existing request row | D3 works if the frontend knows the `tenant_id`/`id` (for example, from the rejected list D6) |
| Endpoint showing the current fee rule to dropshippers before delivery | **NOT IMPLEMENTED** | Show the fee only from the order snapshot after delivery |
| Earnings summary aggregating fees | **NOT IMPLEMENTED** | Sum the order fields client-side for display only, or show per-order values |
| Fee reversal on refund or return after delivery | **NOT IMPLEMENTED** (delivered is a final state) | — |
| Pricing-rule fields inside the D4–D6 list rows | Not included (only the product's `selling_price` and `discount_price`) | Call D2 for `dropshipper_pricing` |
| Pricing-rule fields in `GET /api/tenant-product` (merchant list) | **NOT VERIFIED** | Use M5 for authoritative values |
| `auto_approve_dropshipper` in `GET /api/tenant-category` rows | **NOT VERIFIED** (should appear after migration since all columns are returned) | Keep local state after M7 succeeds |
| `GET /api/dropshipper/request/product/view/{id}` | **NOT VERIFIED** (legacy query) | Use M8–M11 rows |
| Gateway checkout response shape (`aamarpay`, `eps`, `uddoktapay`) | **NOT VERIFIED** | Follow the existing gateway handling in the app |
| Notifications to the dropshipper on approve, reject or revoke | **NOT IMPLEMENTED** | Poll or refresh D4–D6 |
| Bulk assignment by a list of product IDs | **NOT IMPLEMENTED** (one `product_id` per call, or a category) | Loop sequentially and aggregate the results |

### Data refresh and duplicate-submission tips

- After M3 or M4, refetch M2. After M6 or M7, use the returned `data` (M6) or refetch.
- After M13, refetch the current tab and M12 counts.
- After D3, remove the product from the D1 list and refetch D4 or D5. After D7, update the row from `productDetails`.
- After D11, clear the cart (D9) and refetch the orders.
- Disable buttons while a request is in flight and ignore repeat clicks. For lists, cancel stale requests (`AbortController`) when the filters or page change.
- The backend also guards against duplicates: assignments are unique per dropshipper and target, and requests lock the existing row. The frontend should still avoid double submits.
