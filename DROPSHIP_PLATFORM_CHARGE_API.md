# Dropshipping Platform Charge — API README

How AffSell’s **platform charge** (platform fee) works for dropshipper orders: who configures it, when it is applied, how it is calculated, and which API fields/endpoints expose it.

Related code: `app/Services/PlatformFeeService.php`, settlement in `app/Services/ProductOrderService.php` (`creditDropshipperCommission`).

---

## 1. What is charged

| Item | Field / wallet effect | Charged a platform fee? |
|---|---|---|
| **Merchant commission** paid to the dropshipper | Order `afi_amount` → becomes `dropshipper_gross_amount` at settlement | **Yes** — fee is taken from this |
| Dropshipper **margin** (selling price − base) | Order `profit_amount` | **No** — informational only; AffSell does not credit or fee it |
| Product / shipping totals | `product_amount`, `due_amount`, etc. | **No** |

Formula at delivery:

```text
gross = pending commission (afi_amount)
fee   = PlatformFeeService::calculate(dropshipper_tenant_id, gross)
net   = gross − fee          # never negative (fee capped at gross)

wallet += net
```

Payment history on the dropshipper tenant:

- `+gross` — `"Product commission"`
- `-fee` — `"Platform fee (order {order_id})"` (only if fee &gt; 0)

---

## 2. When the charge runs

| Moment | What happens |
|---|---|
| Add to cart / checkout | Commission (`afi_amount`) is computed and stored. **No platform fee yet.** |
| Order status → `received` | Merchant balance is debited for the commission (pending). Still **no fee**. |
| Order status → `delivered` | Fee is calculated once, wallet credited with **net**, order snapshot fields written, `platform_fee_settled_at` set. |
| `cancel` / `return` **before** delivery | Pending commission marked `cancel`. **No fee, no wallet credit.** |
| Repeat `delivered` / courier webhook | **Idempotent** — locked pending row; already-`success` commissions are not credited or charged again. |

Direct website orders (`order_media` = `website`, `website-guest`, `Direct`) are **not** settled as dropshipper commission and do not take this fee.

---

## 3. How the fee is configured (Admin APIs)

Central admin domain + admin token.

### 3.1 Global mode — platform settings

| Method | URL |
|---|---|
| GET | `/api/admin/settings` |
| POST | `/api/admin/settings-update/{id}` |

Fields:

| Field | Values | Notes |
|---|---|---|
| `dropship_fee_type` | `none`, `fixed`, `percentage`, `plan` | Global mode |
| `dropship_fee_value` | number ≥ 0, max 2 decimals | Required with type; for `percentage` must be ≤ 100 |

Meaning of global mode:

| `dropship_fee_type` | Fee used at delivery |
|---|---|
| `none` | `0` |
| `fixed` | `dropship_fee_value` **per order** (flat) |
| `percentage` | `dropship_fee_value` **% of commission** (`afi_amount`) |
| `plan` | Use the dropshipper’s **active subscription plan** fee (`subscriptions.dropship_fee_*`). If no active plan or plan has no fee → `0` (`platform_fee_source` = `plan:none`) |

### 3.2 Per-plan fee (used only when global mode = `plan`)

| Method | URL |
|---|---|
| GET | `/api/admin/subscription` |
| PUT / PATCH | `/api/admin/subscription/{id}` |

Plan fields:

| Field | Values |
|---|---|
| `dropship_fee_type` | `fixed`, `percentage`, or `null` (no fee on that plan) |
| `dropship_fee_value` | number ≥ 0; ≤ 100 when type is `percentage` |

“Active plan” = latest `user_subscriptions` row for the dropshipper `tenant_id` that is not expired (`expire_date`).

---

## 4. Calculation rules (backend)

Implemented by `PlatformFeeService::calculate($dropshipperTenantId, $grossAmount)`.

```text
resolve rule from settings (+ plan if mode = plan)
if type = fixed:      amount = rate
if type = percentage: amount = gross × min(rate, 100) / 100
else:                 amount = 0

amount = min(amount, gross)     # cap
net    = gross − amount
```

Returned structure (also written onto the order at settlement):

| Key | Meaning |
|---|---|
| `type` | `none` / `fixed` / `percentage` |
| `rate` | Fixed amount or percent value used |
| `amount` | Fee withheld |
| `source` | `global`, `plan:{subscription_id}`, or `plan:none` |
| `gross` | Commission before fee |
| `net` | Amount credited to wallet |

**Frontend should display these fields; do not recalculate client-side for settlement truth.**

---

## 5. Order fields (merchant `orders` table)

Returned on dropshipper and merchant order list/detail APIs (e.g. D12, D13, M16).

### Set at checkout

| Field | Meaning |
|---|---|
| `afi_amount` | Expected **gross** commission for the dropshipper |
| `profit_amount` | Dropshipper margin × qty (not fee’d) |

### Set at delivery (settlement snapshot)

| Field | Meaning |
|---|---|
| `platform_fee_type` | `none` / `fixed` / `percentage` |
| `platform_fee_rate` | Rate used |
| `platform_fee_source` | `global` / `plan:{id}` / `plan:none` |
| `platform_fee_amount` | Fee withheld (default `0` before settlement) |
| `dropshipper_gross_amount` | Gross commission settled |
| `dropshipper_net_amount` | Credited to dropshipper wallet |
| `platform_fee_settled_at` | When settlement ran (`null` = not settled yet) |

---

## 6. Balances (dropshipper)

| Endpoint | Value |
|---|---|
| `GET /api/tenant-dropshipper/pending-balance` | Sum of `afi_amount` on undelivered orders (**gross, before fee**) |
| `GET /api/tenant-dropshipper/active-balance` | Tenant wallet balance (**net**, after fees already settled) |

UI guidance:

- Before delivery: show expected commission from `afi_amount`; label fee as “applied on delivery”.
- After delivery: show `platform_fee_amount`, `dropshipper_gross_amount`, `dropshipper_net_amount`.

---

## 7. Example scenarios

### Global fixed fee

- Settings: `dropship_fee_type=fixed`, `dropship_fee_value=10`
- Order commission `afi_amount=100`
- At delivery: fee `10`, net `90`, source `global`

### Global percentage

- Settings: `dropship_fee_type=percentage`, `dropship_fee_value=5`
- Commission `200`
- Fee `10`, net `190`, source `global`

### Plan mode

- Settings: `dropship_fee_type=plan`
- Dropshipper active plan: `dropship_fee_type=percentage`, `dropship_fee_value=8`
- Commission `100`
- Fee `8`, net `92`, source `plan:12` (example plan id)

### Cap at commission

- Fixed fee `50`, commission `30` → fee `30`, net `0` (never negative)

### Cancel before delivery

- Pending balance → `cancel`
- No wallet credit, no platform fee rows

---

## 8. Integration checklist

- [ ] Admin can set global `dropship_fee_type` / `dropship_fee_value` via settings API.
- [ ] When mode is `plan`, each dropshipper plan has optional `dropship_fee_type` / `dropship_fee_value`.
- [ ] Order detail shows fee fields as `null`/0 until `platform_fee_settled_at` is set.
- [ ] After delivery, wallet increased by `dropshipper_net_amount`, not full `afi_amount`.
- [ ] Payment history shows `+` commission and `-` platform fee.
- [ ] Re-marking delivered or courier webhook does not double-charge or double-credit.
- [ ] Cancel/return before delivery never applies a fee.

---

## 9. Quick mental model

```text
Admin sets fee mode (none | fixed | percentage | plan)
        ↓
Checkout stores commission (afi_amount) only
        ↓
Delivered → PlatformFeeService.calculate(dropshipper, afi_amount)
        ↓
Wallet += net; order stores fee snapshot; pending → success
```

For broader dropshipping APIs (assign, pricing, cart), see `DROPSHIPPING_FRONTEND_API_README.md` §7.
