# Architecture Overview

## System Context

Yuno Payment Gateway is a WordPress/WooCommerce plugin that integrates Yuno's payment orchestration platform into WooCommerce stores. It acts as a bridge between the WooCommerce checkout flow and the Yuno API, using the Yuno Web SDK (v1.6) for frontend payment collection and a PHP REST API layer for server-side payment lifecycle management.

### Architecture Pattern

The plugin follows a **redirect-to-order-pay** pattern:

1. WooCommerce creates the order in `pending` status
2. The customer is redirected to the `/order-pay/{id}/` page
3. A JavaScript state machine orchestrates the Yuno SDK lifecycle
4. A PHP REST API layer mediates all communication with the Yuno API

This pattern ensures both legacy shortcode checkout and WooCommerce block-based checkout converge on the same payment flow -- no duplicate logic.

### Deployment Context

```
+-----------------+       +-----------------------+       +----------------+
|  WordPress      |       |  WooCommerce Store    |       |  Browser       |
|  (PHP 7.4+)     | <---> |  (WC 8.0+)           | <---> |  (Yuno SDK)    |
+-----------------+       +-----------------------+       +----------------+
                                    |                            |
                                    | REST API (PHP)             | Yuno Web SDK v1.6
                                    v                            v
                          +-----------------------+       +----------------+
                          |  Yuno API             | <---> |  Yuno Backend  |
                          |  (api[-env].y.uno)    |       |  (webhooks)    |
                          +-----------------------+       +----------------+
```

## Service Dependencies

| Dependency | Type | Purpose |
|---|---|---|
| WordPress 6.0+ | Platform | CMS runtime, hooks, REST API framework, nonce CSRF protection |
| WooCommerce 8.0+ | Platform | Payment gateway API, order management, HPOS, block checkout |
| Yuno Web SDK v1.6 | Frontend | Payment form rendering, tokenization, 3DS handling |
| Yuno REST API | Backend | Customer creation, checkout sessions, payment creation, verification |
| Yuno Webhooks | Backend | Async payment status updates (success, failure, chargeback, refund) |

### Yuno API URL Strategy

The API base URL is derived automatically from the public API key prefix:

| Key prefix | Environment | URL |
|---|---|---|
| `dev_` | Development | `https://api-dev.y.uno` |
| `staging_` | Staging | `https://api-staging.y.uno` |
| `sandbox_` | Sandbox | `https://api-sandbox.y.uno` |
| `prod_` | Production | `https://api.y.uno` |
| unknown | Fallback | `https://api-sandbox.y.uno` |

## Data Flow

### Payment Flow (Happy Path)

```
1. User submits checkout
   --> WC creates order (pending, stock held but not reduced)
   --> Redirect to /order-pay/{id}/

2. checkout.js initializes
   --> checkOrderStatus() (preflight: redirects if already paid, duplicates if failed)
   --> Promise.all([createCustomer(), getPublicApiKey()])
   --> getCheckoutSession() (Full SDK / SDK_CHECKOUT workflow)
   --> Yuno.initialize() --> startCheckout() --> mountCheckout()

3. User selects payment method, fills form
   --> yunoPaymentMethodSelected fires --> Pay button shown
   --> User clicks Pay --> startPayment()

4. SDK tokenizes
   --> yunoCreatePayment(oneTimeToken) callback
   --> POST /yuno/v1/payments (server creates payment via Yuno API)
   --> continuePayment() resumes SDK flow

5. SDK returns result
   --> yunoPaymentResult(status)
   --> confirmOrder() --> POST /yuno/v1/confirm (server-side verification)
   --> SUCCESS/PENDING: redirect to /order-received
   --> FAILURE: resetSdkState() + retry in-place

6. Webhook (async)
   --> POST /yuno/v1/webhook (HMAC-verified)
   --> Updates order status with transient lock (prevents race with /confirm)
```

### Security Data Flow

- **Frontend to REST API**: WP REST nonce (`X-WP-Nonce` header) + `order_key` parameter
- **REST API to Yuno**: Private secret key (server-side only, never exposed to frontend)
- **Yuno to Webhook**: Three-layer verification -- `x-api-key`, `x-secret`, `x-hmac-signature` (HMAC-SHA256)
- **Payment verification**: Server always queries Yuno API in `/confirm` before updating order status; never trusts client-reported status

### Configuration Data Flow

Settings are resolved via `yuno_get_env($key)` with priority:

1. WP options (`woocommerce_yuno_settings`)
2. Environment variables
3. PHP constants

Frontend receives a subset via `wp_localize_script` as the `YUNO_WC` global object (public key, nonce, order details -- never the private secret key).
