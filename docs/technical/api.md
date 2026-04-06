# REST API Reference

All endpoints are registered under the `yuno/v1` namespace via the WordPress REST API.

**Base URL:** `{site_url}/wp-json/yuno/v1`

## Authentication

### Customer-facing endpoints

All customer-facing endpoints use **WP REST nonce verification**:
- Header: `X-WP-Nonce` (value from `wp_create_nonce('wp_rest')`)
- Parameter: `order_key` (validated inside each handler via `yuno_get_order_from_request()`)

This provides CSRF protection while supporting guest checkout (WordPress generates valid nonces for anonymous sessions via cookies).

### Webhook endpoint

Server-to-server authentication with three-layer verification:
- `x-api-key` header
- `x-secret` header
- `x-hmac-signature` header (HMAC-SHA256, checked in both hex and base64 formats)

---

## Endpoints

### GET `/yuno/v1/public-api-key`

Returns the public API key. This is a fallback endpoint -- the key is also injected server-side via `wp_localize_script`.

**Response:**
```json
{
  "publicApiKey": "sandbox_abc123..."
}
```

---

### POST `/yuno/v1/customer`

Creates a Yuno customer for the given WooCommerce order. Uses a per-order customer strategy (`merchant_customer_id = woo_order_{order_id}`).

**Request body:**
```json
{
  "order_id": 123,
  "order_key": "wc_order_abc123"
}
```

**Response (success):**
```json
{
  "customer_id": "yuno-customer-uuid"
}
```

**Behavior:**
- Caches customer ID in `_yuno_customer_id` order meta
- Handles `CUSTOMER_ID_DUPLICATED` error by searching for existing customer
- Returns cached customer if already created for this order
- Graceful degradation: payment continues even if customer creation fails

---

### POST `/yuno/v1/checkout-session`

Creates a Yuno checkout session using the Full SDK / `SDK_CHECKOUT` workflow.

**Request body:**
```json
{
  "order_id": 123,
  "order_key": "wc_order_abc123",
  "customer_id": "yuno-customer-uuid"
}
```

**Response (success):**
```json
{
  "checkout_session": "session-uuid",
  "country": "CO",
  "language": "es",
  "amount": "50000.00",
  "currency": "COP"
}
```

**Behavior:**
- Includes billing/shipping addresses in Yuno format
- Sets `callback_url` for 3DS return (omitted on localhost)
- Handles `CUSTOMER_NOT_FOUND` by clearing stale meta, recreating customer, and retrying
- Stores session ID in `_yuno_checkout_session` order meta

---

### POST `/yuno/v1/payments`

Creates a payment via the Yuno API using the one-time token from SDK tokenization.

**Request body:**
```json
{
  "order_id": 123,
  "order_key": "wc_order_abc123",
  "one_time_token": "ott-uuid"
}
```

**Response (success):**
```json
{
  "payment_id": "payment-uuid",
  "status": "SUCCEEDED"
}
```

**Behavior:**
- Protected by `yuno_pay_lock_{order_id}` transient (30s TTL) to prevent duplicates
- Sends `x-idempotency-key: wc-{order_id}-{hash}` header to Yuno API
- Includes split marketplace data if split payments are enabled
- Stores payment ID in `_yuno_payment_id` order meta

**Split payload (when enabled):**
```json
{
  "split_marketplace": [
    { "type": "PURCHASE", "recipient_id": "...", "amount": 45000 },
    { "type": "COMMISSION", "recipient_id": "...", "amount": 5000 }
  ]
}
```

---

### POST `/yuno/v1/confirm`

Server-side payment verification. Queries the Yuno API to verify payment status before updating the WooCommerce order.

**Request body:**
```json
{
  "order_id": 123,
  "order_key": "wc_order_abc123",
  "checkout_session": "session-uuid"
}
```

**Response (success):**
```json
{
  "success": true,
  "redirect": "https://store.com/checkout/order-received/123/?key=wc_order_abc123"
}
```

**Response (failure):**
```json
{
  "failed": true,
  "message": "Payment was not successful"
}
```

**Behavior:**
- Always queries Yuno API -- never trusts client-reported status
- PENDING/PROCESSING/REQUIRES_ACTION treated as success (auto-capture), calls `payment_complete()`
- Uses transient lock to prevent race condition with webhook
- Checks `$order->is_paid()` for idempotency

---

### POST `/yuno/v1/check-order-status`

Preflight check on page load. Determines if the order is already paid, failed, or ready for payment.

**Request body:**
```json
{
  "order_id": 123,
  "order_key": "wc_order_abc123"
}
```

**Response (already paid):**
```json
{
  "status": "paid",
  "redirect": "https://store.com/checkout/order-received/123/..."
}
```

**Response (failed):**
```json
{
  "status": "failed"
}
```

**Response (pending):**
```json
{
  "status": "pending"
}
```

---

### POST `/yuno/v1/duplicate-order`

Creates a new WooCommerce order after a failed payment attempt, allowing the customer to retry.

**Request body:**
```json
{
  "order_id": 123,
  "order_key": "wc_order_abc123"
}
```

**Response (success):**
```json
{
  "new_order_id": 456,
  "redirect": "https://store.com/checkout/order-pay/456/?pay_for_order=true&key=wc_order_xyz",
  "reused": false
}
```

**Behavior:**
- Checks for existing duplicate via `_yuno_duplicate_order_id` meta before creating
- Reuses existing duplicate if in `pending` or `on-hold` status (`reused: true`)
- Links original and duplicate orders via meta keys

---

### POST `/yuno/v1/webhook`

Receives asynchronous payment events from Yuno. Uses HMAC signature verification instead of nonce.

**Supported event types:**

| Event | Handler | Behavior |
|---|---|---|
| `payment.succeeded`, `payment.purchase` | `yuno_webhook_handle_payment_succeeded` | Verifies with Yuno API, calls `payment_complete()`, transient lock |
| `payment.pending` | Inline | Logs event, adds order note, no status change |
| `payment.failed`, `payment.rejected`, `payment.declined` | `yuno_webhook_handle_payment_failed` | Marks order as failed (no auto-duplicate) |
| `payment.chargeback` | `yuno_webhook_handle_chargeback` | Sets order to `on-hold`, adds note for manual review |
| `payment.refunds`, `payment.refund`, `refunds` | `yuno_webhook_handle_refund` | Creates WC refund; full -> `refunded` status; partial -> note only |

**Payload formats:**
- Format 1: `{ "type_event": "payment.succeeded", "data": {...} }`
- Format 2: `{ "payment": {...} }` (event type inferred from `payment.status`)

**Response:** HTTP 200 on success, HTTP 400/403 on verification failure.

**Order lookup:** Primary by `_yuno_checkout_session` meta (HPOS-compatible), fallback by parsing `merchant_order_id` (format `WC-{order_id}`).
