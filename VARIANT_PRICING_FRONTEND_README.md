# Variant-Specific Pricing — Frontend Integration Guide

Each product variant can have its own sell price. If a variant has no price (`0` / empty), the product price is used.

**Scope:** Tenant Panel product create/edit + storefront cart / guest checkout.

---

## 1. Quick rules

| Rule | Behavior |
|------|----------|
| Variant price field | Send as `price` **or** `rate` (both accepted) |
| Value | Number ≥ `0` |
| `0` or omitted | Fallback to product `discount_price`, else `selling_price` |
| Product-level price | Still required (`selling_price`, optional `discount_price`) |
| Cart / order | Uses the **selected** `variant_id` price |
| Mixed variants in one cart line | Backend stores a qty-weighted unit price so totals stay correct |

---

## 2. Tenant Panel — Product APIs

Base path (tenant domain, authenticated):

```
/tenant-product
```

| Action | Method | Path |
|--------|--------|------|
| Create | `POST` | `/tenant-product/store` |
| Edit (load) | `GET` | `/tenant-product/edit/{id}` |
| Update | `POST` | `/tenant-product/update/{id}` |

### 2.1 Create / Update — `variants` payload

Send one object per variant. Include attributes (`unit` / `size` / `color` or `*_id`), stock `qty`, and the variant price.

```json
{
  "name": "Premium T-Shirt",
  "sku": "TSHIRT-001",
  "selling_price": 1000,
  "discount_price": 900,
  "original_price": 1200,
  "variants": [
    {
      "unit_id": 1,
      "size_id": 2,
      "color_id": 5,
      "qty": 10,
      "price": 850
    },
    {
      "unit": { "id": 1 },
      "size": { "id": 3 },
      "color": { "id": 5 },
      "qty": 8,
      "rate": 950
    }
  ]
}
```

Notes:

- Prefer `price` in new UI code; `rate` still works (same DB column).
- Nested `{ "id": ... }` and flat `*_id` forms are both supported.
- On update, include `id` or `variant_id` when editing an existing variant so stock/price map correctly.

```json
{
  "id": 42,
  "variant_id": 42,
  "unit_id": 1,
  "size_id": 2,
  "color_id": 5,
  "qty": 10,
  "price": 875
}
```

### 2.2 Validation (backend)

| Field | Rules |
|-------|-------|
| `variants` | optional array |
| `variants.*.qty` | required when variants present; integer ≥ `0` |
| `variants.*.price` | optional; numeric ≥ `0` |
| `variants.*.rate` | optional; numeric ≥ `0` |

### 2.3 Edit response — load prices into the form

`GET /tenant-product/edit/{id}` returns:

1. `product.variants` (JSON array) — includes `rate` and `price`
2. `product.productVariant` (relation rows) — includes `rate`

Example (abbreviated):

```json
{
  "status": 200,
  "product": {
    "id": 15,
    "selling_price": 1000,
    "discount_price": 900,
    "variants": [
      {
        "id": 42,
        "variant_id": 42,
        "unit_id": 1,
        "size_id": 2,
        "color_id": 5,
        "qty": 10,
        "rate": 850,
        "price": 850
      }
    ],
    "productVariant": [
      {
        "id": 42,
        "product_id": 15,
        "unit_id": 1,
        "size_id": 2,
        "color_id": 5,
        "qty": 10,
        "rate": 850
      }
    ]
  }
}
```

**UI tip:** bind each variant row’s price input to `variant.price ?? variant.rate ?? ''`.

### 2.4 Suggested Tenant Panel UI

For each variant row show:

- Attributes (unit / size / color)
- Qty
- **Price** input (number, min `0`, step `0.01`)

Placeholder / helper text:

> Leave empty or `0` to use the product price.

Do **not** remove the main product `selling_price` / `discount_price` fields — they remain the fallback and are still required by the API.

---

## 3. Storefront — product detail (`GET product/{slug}`)

**Primary endpoint for variant data on the storefront:**

```
GET /product/{slug}
```

Defined in `routes/tenant.php` → `MerchantFrontendController@product`.

### Response fields (per variant)

Returned on both:

- `product.productVariant[]` (DB rows + relations)
- `product.variants[]` (JSON mirror)

| Field | Meaning |
|-------|---------|
| `id` / `variant_id` | Use this as `variant_id` in cart |
| `rate` / `price` | Stored variant price (`0` = no override) |
| `effective_price` | **Price to show / charge** for this variant |
| `product_fallback_price` | Product `discount_price ?? selling_price` |
| `qty`, `color`, `size`, `unit` | Stock / attributes |

Example (abbreviated):

```json
{
  "product": {
    "id": 15,
    "slug": "premium-t-shirt",
    "selling_price": 1000,
    "discount_price": 900,
    "productVariant": [
      {
        "id": 42,
        "qty": 10,
        "rate": 850,
        "price": 850,
        "effective_price": 850,
        "product_fallback_price": 900,
        "color": { "id": 5, "name": "Red" },
        "size": { "id": 2, "name": "M" }
      },
      {
        "id": 43,
        "qty": 8,
        "rate": 0,
        "price": 0,
        "effective_price": 900,
        "product_fallback_price": 900
      }
    ]
  }
}
```

### Display helper

Prefer backend-calculated `effective_price`:

```js
function getVariantPrice(variant) {
  return Number(variant?.effective_price ?? variant?.price ?? variant?.rate ?? 0);
}
```

On size/color change, set the UI price from the selected variant’s `effective_price`.

---

## 4. Cart — authenticated storefront

```
POST /add-to-cart
```

### Request

Always send the selected `variant_id` inside `cartItems`:

```json
{
  "tenant_id": "merchant_tenant_id",
  "product_id": 15,
  "purchase_type": "single",
  "cartItems": [
    {
      "variant_id": 42,
      "qty": 2,
      "color": 5,
      "size": 2,
      "unit": 1
    },
    {
      "variant_id": 43,
      "qty": 1,
      "color": 5,
      "size": 3,
      "unit": 1
    }
  ]
}
```

### How pricing is applied

- Backend loads each `variant_id`’s `rate`
- If `rate > 0` → that price is used for that line qty
- If `rate` is `0` / missing → product fallback price
- Cart stores:
  - `product_price` — weighted average unit price when variants differ
  - `totalproductprice` — sum of (variant price × qty)

### Update quantity

```
PUT /cart/{id}/quantity
```

Same logic applies after qty changes (variant prices are re-read from the merchant DB).

---

## 5. Guest checkout

Guest checkout paths also resolve price from selected `variant_id` the same way.

Ensure guest payloads include variants with `variant_id` (or `id`) and `qty`, for example:

```json
{
  "variants": [
    { "variant_id": 42, "qty": 2, "color": 5, "size": 2, "unit": 1 }
  ]
}
```

---

## 6. Frontend checklist

**Tenant Panel (merchant)**

- [ ] Price input per variant on create
- [ ] Price input per variant on edit (prefill from `rate` / `price`)
- [ ] Send `price` (or `rate`) with each variant on store/update
- [ ] Allow `0` / empty = use product price
- [ ] Validate client-side: number ≥ 0

**Storefront**

- [ ] Display selected variant price (fallback to product price)
- [ ] Always send `variant_id` in `cartItems`
- [ ] Recalculate UI total when variant or qty changes

**Do not**

- [ ] Assume all variants share `selling_price`
- [ ] Omit `variant_id` on cart add (stock + price both need it)
- [ ] Send negative prices

---

## 7. Example UI binding (React-style)

```jsx
{variants.map((v, index) => (
  <input
    key={v.id ?? index}
    type="number"
    min="0"
    step="0.01"
    value={v.price ?? v.rate ?? ''}
    placeholder="Use product price"
    onChange={(e) => {
      const value = e.target.value === '' ? 0 : Number(e.target.value);
      updateVariant(index, { price: value, rate: value });
    }}
  />
))}
```

---

## 8. Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Edit form shows no variant price | Read `product.productVariant[].rate` or `product.variants[].price` |
| Cart always uses product price | Missing `variant_id` in `cartItems`, or variant `rate` is `0` |
| Validation error on save | `price` / `rate` not numeric, or negative |
| Wrong total with multiple variants | Expected — backend uses weighted unit price; show line totals as `price × qty` per variant in UI |

---

## 9. Backend files (reference)

- `app/Service/Vendor/ProductVariantService.php` — save/load/resolve price
- `app/Http/Controllers/API/Vendor/ProductManageController.php` — tenant create/edit/update
- `app/Http/Controllers/Tenant/CartController.php` — cart pricing
- `app/Http/Controllers/Tenant/OrderController.php` — guest checkout pricing

No new migration is required: prices use the existing `product_variants.rate` column.
