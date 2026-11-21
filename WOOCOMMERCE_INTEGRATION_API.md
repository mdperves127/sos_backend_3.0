# WooCommerce Integration — AffSell API

Merchant (tenant) WooCommerce connection, sync settings, webhooks, history, and manual sync.

## Audit summary (existing → completed)

| Area | Before | Now |
|---|---|---|
| Credentials CRUD | Partial (plaintext secrets returned, no validate) | Validate on connect; secrets hidden; status/reconnect/disconnect |
| Product pull WC→AffSell | Partial (bugs, no updates, weak mapping) | Upsert by `wc_product_id`, images/categories/SKU/stock, variations |
| Product push AffSell→WC | Missing (`wcProductStore` missing) | Implemented |
| Order pull WC→AffSell | Partial (wrong product_id on lines) | Maps via `WC-{id}` / SKU; status map; dedupe by `wc_order_no` |
| Order status AffSell→WC | Partial (direct Guzzle) | Uses sync service + loop guard |
| Stock sync | Missing | Both directions + conflict/loop guards |
| Sync direction / toggles | Missing | On credential row |
| Webhooks | Missing | `POST /api/woocommerce/webhook` + signature |
| Sync history / retry | Missing | `woocommerce_sync_logs` + retry API |
| Dashboard API | Missing | `/api/woocommerce/*` |
| Fallback schedule | Missing | `woocommerce:fallback-sync` every 30 min |

**Architecture note:** One **active** store per merchant tenant (existing pattern). Credentials live in the **tenant** DB.

---

## Merchant dashboard APIs

Auth: tenant merchant token. Base: `https://{tenant-domain}/api`

| Method | Path | Purpose |
|---|---|---|
| GET | `/woocommerce` | Connection status + settings (secrets masked) |
| POST | `/woocommerce/connect` | Validate + save Store URL / key / secret |
| POST | `/woocommerce/disconnect` | Deactivate connection |
| POST | `/woocommerce/reconnect` | Re-validate existing credentials |
| POST | `/woocommerce/settings` | Sync direction + toggles |
| POST | `/woocommerce/sync-now` | Queue manual sync (`type`: `full`\|`products`\|`orders`) |
| GET | `/woocommerce/history` | Sync logs (`?status=failed`) |
| POST | `/woocommerce/history/{id}/retry` | Retry a failed log |

### Connect body

```json
{
  "wc_url": "https://mystore.com",
  "wc_key": "ck_xxx",
  "wc_secret": "cs_xxx"
}
```

Response includes `webhook.url` and `webhook.secret` for WooCommerce → Delivery URL setup.

### Settings body

```json
{
  "sync_direction": "two_way",
  "product_sync": true,
  "order_sync": true,
  "stock_sync": true,
  "auto_sync": true
}
```

`sync_direction`: `wp_to_affsell` | `affsell_to_wp` | `two_way`

---

## Webhook

`POST https://{tenant-domain}/api/woocommerce/webhook`

Configure in WooCommerce → Settings → Advanced → Webhooks:

- Topics: product created/updated/deleted, order created/updated
- Secret: value returned from connect
- Header verified: `X-WC-Webhook-Signature`

---

## Legacy routes (still available)

| Path | Purpose |
|---|---|
| `/tenant-woo-commerce-credential/*` | CRUD credentials |
| `/woo-commerce-product` | Pull all products now |
| `/woo-commerce-product/store` | Push one AffSell product (`product_id`) |
| `/woo-commerce-order` | Pull all orders now |
| `/woo-commerce-order/order-status/{id}/{status}` | Push WC status |

---

## Mapping

| WooCommerce | AffSell |
|---|---|
| Product ID | `products.wc_product_id` = `WC-{id}` |
| Variation ID | `product_variants.wc_variation_id` |
| Order number/ID | `orders.wc_order_no` = `WC-{number}` |
| pending/on-hold/processing/completed/cancelled/refunded | pending/hold/processing/delivered/cancel/return |

---

## Ops

```bash
# Tenant migration (per merchant DB)
php artisan tenants:migrate

# Fallback sync (also scheduled every 30 minutes)
php artisan woocommerce:fallback-sync
php artisan woocommerce:fallback-sync --tenant=mymerchant
```

Queue worker required for `WooCommerceSyncJob` and manual sync/retry.

---

## Security

- Tenant isolation via domain tenancy + vendor-owned credentials
- `wc_secret` / `webhook_secret` never returned in list/show payloads
- Webhook HMAC verification when secret is set
- Loop guards prevent AffSell↔WC infinite status/stock updates
- Store URL SSRF guard (HTTPS required except local/dev hosts; private IPs blocked)
- Legacy `wcCredential()` helper now uses `vendorId()` (not `Auth::id()`)
- Credentials are hidden in API responses; existing DB rows stay plaintext for compatibility — rotate keys if they were previously exposed

## Remaining limitations

- One active store per merchant (existing architecture)
- Merchant UI is API-ready (SPA should call `/api/woocommerce/*`); no Blade redesign
- Configure WooCommerce webhooks with the connect response URL + secret
- Queue worker required for sync-now / retry / webhook fallback
- Variation attribute mapping is best-effort for color/size names
