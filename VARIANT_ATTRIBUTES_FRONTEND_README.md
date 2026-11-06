# Tenant Variant Attributes API (Name + Values)

Create reusable variant groups for the tenant panel, for example:

- **Size** → `s`, `m`, `l`, `xl`
- **Color** → `red`, `green`, `yellow`

This is separate from legacy `tenant-variant` (Size-only) and `tenant-color` APIs.

---

## Setup

Run tenant migrations:

```bash
php artisan tenants:migrate
```

---

## Endpoints

Base path (tenant domain, authenticated):

```
/tenant-variants
```

| Action | Method | Path |
|--------|--------|------|
| List | `GET` | `/tenant-variants` |
| List active only | `GET` | `/tenant-variants?status=active` |
| Create | `POST` | `/tenant-variants/store` |
| Edit | `GET` | `/tenant-variants/edit/{id}` |
| Update | `POST` | `/tenant-variants/update/{id}` |
| Delete attribute | `DELETE` | `/tenant-variants/delete/{id}` |
| Add values | `POST` | `/tenant-variants/{id}/values` |
| Delete one value | `DELETE` | `/tenant-variants/{id}/values/{valueId}` |

Also available on product create helpers:

```
GET /tenant-product/create
→ data.variant_attributes
```

---

## Create example

`POST /tenant-variants/store`

```json
{
  "name": "Size",
  "status": "active",
  "values": ["s", "m", "l", "xl"]
}
```

```json
{
  "name": "Color",
  "status": "active",
  "values": ["red", "green", "yellow"]
}
```

Values can also be objects:

```json
{
  "name": "Material",
  "values": [
    { "value": "cotton" },
    { "value": "polyester" }
  ]
}
```

### Success response

```json
{
  "status": 200,
  "message": "Variant added successfully.",
  "variant": {
    "id": 1,
    "name": "Size",
    "slug": "size",
    "status": "active",
    "values": [
      { "id": 1, "variant_attribute_id": 1, "value": "s", "sort_order": 0 },
      { "id": 2, "variant_attribute_id": 1, "value": "m", "sort_order": 1 },
      { "id": 3, "variant_attribute_id": 1, "value": "l", "sort_order": 2 },
      { "id": 4, "variant_attribute_id": 1, "value": "xl", "sort_order": 3 }
    ]
  }
}
```

---

## Update (replace values list)

`POST /tenant-variants/update/{id}`

```json
{
  "name": "Size",
  "status": "active",
  "values": ["s", "m", "l", "xl", "xxl"]
}
```

- Matching values (case-insensitive) are kept and reordered
- New values are created
- Removed values are soft-deleted

---

## Append values only

`POST /tenant-variants/{id}/values`

```json
{
  "values": ["xxl", "3xl"]
}
```

---

## Delete one value

`DELETE /tenant-variants/{id}/values/{valueId}`

A variant must keep **at least one** value.

---

## List response

`GET /tenant-variants`

```json
{
  "status": 200,
  "variants": [
    {
      "id": 1,
      "name": "Size",
      "values": [
        { "id": 1, "value": "s", "sort_order": 0 },
        { "id": 2, "value": "m", "sort_order": 1 }
      ]
    },
    {
      "id": 2,
      "name": "Color",
      "values": [
        { "id": 10, "value": "red", "sort_order": 0 },
        { "id": 11, "value": "green", "sort_order": 1 }
      ]
    }
  ]
}
```

---

## Frontend UI suggestion

1. Form fields: **Name** + multi-value input/tags for **Values**
2. Save via `POST /tenant-variants/store`
3. On product form, load `/tenant-variants?status=active` (or `variant_attributes` from product create)
4. Let merchants pick attribute + values when building product variants

---

## Notes

- Duplicate attribute names are blocked (per tenant DB)
- Duplicate values inside one attribute are ignored (case-insensitive)
- No `user_id` / `vendor_id` fields
- Existing `tenant-variant` (Size) and `tenant-color` routes are unchanged
- Product `product_variants` still uses `size_id` / `color_id` / `unit_id` today; this API manages the reusable option lists
