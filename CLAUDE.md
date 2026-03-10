# CLAUDE.md — Yuno WooCommerce Gateway

## Project Overview

WordPress plugin that integrates **Yuno Payments** as a WooCommerce payment gateway. It uses the **Yuno Web SDK** (v1.5) for frontend payment collection and a PHP REST API layer for server-side payment lifecycle management.

- **Plugin version:** 1.0.0
- **Plugin slug:** `yuno-payment-gateway`
- **Gateway ID:** `yuno` (defined as `YUNO_GATEWAY_ID` constant)
- **Text domain:** `yuno-payment-gateway`
- **License:** GPLv2 or later
- **PHP:** 7.4+ | **WordPress:** 6.0+ | **WooCommerce:** 8.0+

### Global Constants (defined in `yuno-woocommerce.php`)

| Constant | Value | Usage |
|----------|-------|-------|
| `YUNO_GATEWAY_ID` | `'yuno'` | Gateway ID used everywhere |
| `YUNO_PLUGIN_FILE` | `__FILE__` | Main plugin file path |
| `YUNO_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` | Plugin directory path |
| `YUNO_PLUGIN_URL` | `plugin_dir_url(__FILE__)` | Plugin URL |
| `YUNO_STATUS_SUCCESS` | `['SUCCEEDED','VERIFIED','APPROVED','PAYED']` | Payment success statuses |
| `YUNO_STATUS_FAILURE` | `['REJECTED','DECLINED','CANCELLED','ERROR','EXPIRED','FAILED']` | Payment failure statuses |
| `YUNO_STATUS_PENDING` | `['PENDING','PROCESSING','REQUIRES_ACTION']` | Payment pending statuses |
| `YUNO_DEFAULT_COUNTRY` | `'CO'` | Default country code fallback |

---

## Dev Environment

**Prerequisites:** Docker Desktop running locally, Node.js installed.

```bash
npm install          # Install @wordpress/env
npm run env:start    # Create and start Docker containers (first run downloads images)
npm run env:stop     # Stop containers (data preserved)
npm run env:restart  # Stop + start (use after .wp-env.json changes)
npm run env:destroy  # Remove all containers and data
npm run env:clean    # Reset WordPress to clean state
```

- **WordPress URL:** http://localhost:8888
- **Default credentials:** `admin` / `password`
- **Plugin:** `./yuno-woocommerce` is auto-installed and activated
- **WP_DEBUG:** enabled, logs to `wp-content/debug.log`

---

## Project Structure

```
sdk-woocommerce/
├── .wp-env.json                          # wp-env Docker/WordPress config
├── package.json                          # npm scripts for wp-env
├── yuno-woocommerce/
│   ├── yuno-woocommerce.php              # Plugin bootstrap (entry point)
│   ├── package.json                      # Build tooling (@wordpress/scripts)
│   ├── webpack.config.js                 # Custom webpack config (WC externals)
│   ├── includes/
│   │   ├── class-wc-gateway-yuno.php  # WC_Payment_Gateway subclass
│   │   ├── class-wc-gateway-yuno-blocks.php # AbstractPaymentMethodType (block checkout)
│   │   └── rest-api.php                    # All REST endpoints + webhook (~2400 lines)
│   ├── src/
│   │   └── blocks/
│   │       └── yuno-blocks.js            # Block checkout React source
│   └── assets/
│       ├── js/
│       │   ├── api.js                    # Frontend REST bridge (fetch wrappers)
│       │   ├── checkout.js               # SDK orchestration + payment state machine
│       │   └── blocks/                   # Compiled block checkout output
│       │       ├── yuno-blocks.js        # GENERATED — compiled React
│       │       └── yuno-blocks.asset.php # GENERATED — dependency manifest
│       └── css/
│           └── checkout.css              # Theme isolation + SDK container styles
```

---

## Architecture

```
WooCommerce Checkout
        │ process_payment() → redirect to order-pay page
        ▼
class-wc-gateway-yuno.php
        │ enqueue_scripts() → loads Yuno SDK + api.js + checkout.js
        │ wp_localize_script() → injects YUNO_WC config object
        ▼
checkout.js (state machine)
        │ checkOrderStatus() → createCustomer() → getCheckoutSession()
        │ → startSeamlessCheckout() → mountSeamlessCheckout() → startPayment()
        │ → yunoPaymentResult() → confirmOrder()
        ▼
api.js (fetch layer)
        │ WP REST nonce + order_key on every request
        ▼
rest-api.php (REST endpoints)
        │ server-side verification against Yuno API
        ▼
Yuno API (https://api[-env].y.uno)
        │
        └─→ Webhook → /yuno/v1/webhook → rest-api.php
```

### Payment Flow Summary

1. User submits checkout → WC order created in `pending` (stock held but not reduced — `payment_complete()` reduces stock)
2. Redirect to `/order-pay/{id}/`
3. `checkout.js` calls `checkOrderStatus()` — redirects if already paid, auto-duplicates if failed
4. Creates customer via `createCustomer()` → POST `/yuno/v1/customer`
5. Creates checkout session via `getCheckoutSession()` → POST `/yuno/v1/checkout-session` (workflow: `SDK_SEAMLESS`)
6. `Yuno.initialize(publicApiKey)` → `yunoInstance.startSeamlessCheckout({...})` → `yunoInstance.mountSeamlessCheckout()`
7. Pay button shown after `yunoPaymentMethodSelected` fires; user interacts with mounted SDK form
8. User clicks Pay → `yunoInstance.startPayment()` → SDK tokenizes and creates payment server-side (SDK_SEAMLESS: no backend involvement in payment creation)
9. `yunoPaymentResult(result)` callback fires with a status string → `confirmOrder()` → POST `/yuno/v1/confirm` verifies with Yuno API
10. SUCCESS → redirect to `/order-received`; FAILURE → `duplicateOrder()` + redirect to new order; PENDING → stay on page (3DS/async)

---

## Key Files Reference

| File | Responsibility |
|------|---------------|
| `yuno-woocommerce.php` | Registers gateway via `woocommerce_payment_gateways`, order status filter (physical vs downloadable/virtual), block checkout registration, `cart_checkout_blocks` compatibility declaration |
| `class-wc-gateway-yuno.php` | Admin settings UI, script enqueuing, `process_payment()`, `receipt_page()`, split config validation |
| `class-wc-gateway-yuno-blocks.php` | `AbstractPaymentMethodType` — registers Yuno with WC Blocks payment method registry |
| `rest-api.php` | REST routes, customer creation, checkout session, confirm, webhook handling |
| `api.js` | `getPublicApiKey`, `getCheckoutSession`, `createCustomer`, `confirmOrder`, `checkOrderStatus`, `duplicateOrder` |
| `checkout.js` | `startYunoCheckout()`, `runPreflightChecks()`, `yunoPaymentResult()`, `handlePayClick()` |
| `checkout.css` | Theme style resets for `#yuno-root`, `#yuno-apm-form`, `#yuno-action-form` |
| `src/blocks/yuno-blocks.js` | React component source for block checkout (compiled to `assets/js/blocks/`) |

---

## REST Endpoints

| Method | Route | Handler |
|--------|-------|---------|
| GET | `/yuno/v1/public-api-key` | Returns public API key |
| POST | `/yuno/v1/customer` | Creates Yuno customer for order |
| POST | `/yuno/v1/checkout-session` | Creates Yuno checkout session (SDK_SEAMLESS workflow) |
| POST | `/yuno/v1/confirm` | Server-side payment verification + order update |
| POST | `/yuno/v1/check-order-status` | Status check on page load (prevents double-pay, triggers auto-duplicate) |
| POST | `/yuno/v1/duplicate-order` | Creates new order after failed payment |
| POST | `/yuno/v1/update-checkout-session` | Updates stored checkout session ID (PAYMENT_RETRY) |
| POST | `/yuno/v1/webhook` | Receives Yuno events (HMAC-verified) |

---

## Coding Patterns & Invariants

### Security (never break these)
- **Server-side verification always** — never trust client-reported payment status. Always query Yuno API (`/v1/checkout/sessions/{session}/payment`) in `/confirm` before updating order status.
- **HMAC webhook verification** — three-layer check: `x-api-key`, `x-secret`, and `x-hmac-signature` (HMAC-SHA256 in both hex and base64 formats). Implemented in `yuno_verify_webhook_signature()`. Credential mismatches log at `error` level.
- **Order key validation** — all REST endpoints **require** `order_key` via `yuno_get_order_from_request()`. Missing `order_key` returns HTTP 400. The `check-order-status` endpoint also enforces mandatory `order_key`.
- **Idempotency checks** — always check `$order->is_paid()` before calling `payment_complete()` in both webhook handlers and `/confirm`. Transient locks prevent race conditions.
- **No raw API responses to clients** — Yuno API responses (`$res['raw']`) are never included in JSON responses to the frontend. They are logged server-side only.
- **No exception messages to clients** — `$e->getMessage()` is never returned in REST responses. Generic error messages are used instead.
- **Redirect origin validation** — all `window.location.href` redirects in `checkout.js` validate that the URL origin matches `window.location.origin` before navigating.
- **PII redaction in logs** — phone numbers are logged as last 4 digits only. Email addresses logged as boolean `has_email`. Full payloads are never logged.

### Order Status After Payment
- **Post-payment status filter** — `woocommerce_payment_complete_order_status` filter (priority 999) determines final order status after successful payment.
- **Physical = `processing`** — a product is considered physical only if `needs_shipping() && !is_downloadable()`. Orders with at least one physical product get `processing` status.
- **Non-physical = `completed`** — orders containing only virtual and/or downloadable products go straight to `completed`.
- **Downloadable products** — treated as non-physical even if not marked as virtual (i.e. `is_virtual()` is false but `is_downloadable()` is true → still `completed`).

### Concurrency
- **Transient locks** — use WP transients (`yuno_webhook_lock_{order_id}`, 30s TTL) to prevent duplicate webhook/payment processing — race condition between frontend `/confirm` and webhook delivery.
- **State guards in checkout.js** — always check `state.starting`, `state.started`, `state.paid` before acting to prevent double-init or double-submit.

### Customer Management
- **Per-order customer strategy** — each WC order creates a fresh Yuno customer with `merchant_customer_id = woo_order_{order_id}`. Never reuse customers across orders.
- **Cached per order** — customer ID cached in `_yuno_customer_id` order meta to avoid duplicate creation within the same order lifecycle.
- **Graceful degradation** — payment continues even if customer creation fails (`null` customer_id is allowed by the API).
- **CUSTOMER_NOT_FOUND recovery** — if the checkout session returns `CUSTOMER_NOT_FOUND` (e.g. after API key rotation), the plugin clears stale meta, recreates the customer, and retries the session automatically.

### WordPress Conventions
- **HPOS-compatible queries** — use `wc_get_orders(['meta_key' => ..., 'meta_value' => ...])` instead of direct `$wpdb` queries for order lookups.
- **Settings retrieval** — use `yuno_get_env($key, $default)` which checks WP options → environment variables → PHP constants in that priority order. The WP option key is `woocommerce_yuno_settings` (auto-derived from `$this->id = 'yuno'` by `WC_Settings_API`). Uses static caching for the settings array and key map (per-request).
- **Variable naming** — all PHP variables use `snake_case`. No camelCase variables in PHP code.
- **No frontend form fields** — Yuno SDK owns all payment field rendering. WC fields are billing/shipping info only.
- **Checkout field validation** — `validate_checkout_fields()` in `class-wc-gateway-yuno.php` requires at least first OR last name, email, and valid phone. Phone is formatted via `yuno_format_phone_number($phone, $country)` which returns `{ country_code, number }`. Error messages are in Spanish.

### Frontend (checkout.js)
- **`startYunoCheckout()`** — guarded by `state.starting || state.started`; parallelizes `createCustomer()` and `getPublicApiKey()` via `Promise.all`, then creates checkout session, calls `startSeamlessCheckout()`, then `mountSeamlessCheckout()`. The `publicApiKey` is injected server-side via `YUNO_WC`, eliminating an extra REST call when available.
- **`runPreflightChecks()`** — runs before SDK init; redirects if already paid, auto-duplicates and redirects if order is failed. Fail-open (errors don't block init).
- **`mountSeamlessCheckout()`** — called without arguments after `startSeamlessCheckout` resolves. The Pay button is shown after `yunoPaymentMethodSelected` fires.
- **`startPayment()` in `handlePayClick`** — triggers SDK tokenization and payment creation; NOT called at init.
- **`hideLoader()` cleanup** — always call `yunoInstance?.hideLoader()` in `yunoError` to prevent stuck SDK loaders.
- **Double-init pattern** — `startYunoCheckout` is called via both `window.addEventListener("yuno-sdk-ready")` AND `setTimeout(..., 400)` to handle race conditions; the guard ensures only one runs.
- **`renderMode`** — `{ type: 'modal' }` controls how APM forms and 3DS action flows render, NOT the card form itself. Selectors: `apmForm: "#yuno-apm-form"`, `actionForm: "#yuno-action-form"`.
- **`CANCELED_BY_USER` error** — when the user closes the 3DS modal, the `yunoError` callback fires with this code; the handler re-enables the pay button so the user can retry. The SDK fires `PAYMENT_RETRY` with a new checkout session.
- **Minimal console logging** — `checkout.js` uses `console.error` and `console.warn` only for actual errors/warnings. Verbose `console.log` calls have been removed for production cleanliness.

### Frontend State Machine (`checkout.js`)

State object (defined at top of IIFE):
- `state.starting` / `state.started` — SDK init guards (prevent double-init)
- `state.paid` — final state after confirmed payment (blocks further pay clicks)
- `state.orderId` / `state.orderKey` — from `window.YUNO_WC`
- `state.checkoutSession` — current Yuno session ID
- `state.selectedPaymentMethod` — last selected method type from `yunoPaymentMethodSelected`

Payment status constants (checkout.js):
- SUCCESS: `SUCCEEDED`, `VERIFIED`, `PAYED`
- PENDING: `PENDING` (3DS/async flows — stay on page, SDK continues)
- FAILURE: `REJECTED`, `DECLINED`, `CANCELED`, `CANCELLED`, `ERROR`, `EXPIRED`, `FAILED`

SDK callbacks used: `yunoPaymentMethodSelected`, `yunoPaymentResult`, `yunoError`, `yunoModalOpened`, `yunoModalClosed`, `onLoading`

### Performance Patterns
- **Static caching** — `yuno_get_env()` and `yuno_debug_enabled()` use PHP `static` variables to avoid repeated `get_option()` calls within a single request.
- **Batched order saves** — `$order->save()` is NOT called after `update_status()` or `payment_complete()` (both internally call `save()`). In `yuno_create_duplicate_order_internal()`, meta writes are batched before a single `save()`.
- **Parallelized frontend calls** — `createCustomer()` and `getPublicApiKey()` run in parallel via `Promise.all` in `checkout.js`.
- **Server-injected public key** — `publicApiKey` is injected via `wp_localize_script` to eliminate the `/public-api-key` REST call.

### Webhook Event Handling

`yuno_handle_webhook` dispatches to dedicated handlers by `type_event`. Supports two webhook payload formats:
- **Format 1:** `{ "type_event": "payment.succeeded", "data": {...} }` — explicit event type
- **Format 2:** `{ "payment": {...} }` — event type inferred from `payment.status`

| Event type(s) | Handler | Behavior |
|---|---|---|
| `payment.succeeded`, `payment.purchase` | `yuno_webhook_handle_payment_succeeded` | Verifies with Yuno API, calls `payment_complete()`, uses transient lock |
| `payment.failed`, `payment.rejected`, `payment.declined` | `yuno_webhook_handle_payment_failed` | Marks order failed, auto-creates duplicate order for retry |
| `payment.chargeback` | `yuno_webhook_handle_chargeback` | Sets order to `on-hold`, adds note for manual review |
| `payment.refunds`, `payment.refund`, `refunds` | `yuno_webhook_handle_refund` | Creates WC refund object; full refund → `refunded` status; partial → note only |

**Refund logic** — full vs partial determined by:
1. `status=REFUNDED AND sub_status=REFUNDED` → full, OR
2. `new_total_refunded >= order_total` (with 0.01 float margin) → full regardless of status fields

**Order lookup for webhooks** — `yuno_find_order_by_checkout_session_id()`:
1. Primary: `wc_get_orders` by `_yuno_checkout_session` meta (HPOS-compatible)
2. Fallback: parse `merchant_order_id` field (format `WC-{order_id}`)

### Split Payments

Split data is included only in the **checkout session** payload (`yuno_create_checkout_session`), not in any other endpoint. Applied to the **entire order total** (not per-product). Supports:
- **Percent-based:** `split_commission_percent` (0–100), takes priority over fixed
- **Fixed minor-unit:** `split_fixed_amount` (integer minor currency units)

The `split_marketplace` array always contains one `PURCHASE` entry (seller amount = total − commission) and optionally one `COMMISSION` entry (platform fee, omitted when 0).

If split is enabled but `yuno_recipient_id` is missing at request time, checkout session creation returns HTTP 400 and the payment form never loads. Admin-save validation in `process_admin_options()` auto-disables split if config is invalid to prevent this state.

---

## Yuno API URL Strategy

API base URL is derived from the `PUBLIC_API_KEY` prefix:

| Key prefix | Environment | API URL |
|-----------|-------------|---------|
| `dev_` | Development | `https://api-dev.y.uno` |
| `staging_` | Staging | `https://api-staging.y.uno` |
| `sandbox_` | Sandbox | `https://api-sandbox.y.uno` |
| `prod_` or none | Production | `https://api.y.uno` |

Function: `yuno_api_url_from_public_key($public_api_key)` in `rest-api.php`.

---

## Order Meta Keys

All data stored on WC orders under these meta keys:

| Meta Key | Set by | Value |
|----------|--------|-------|
| `_yuno_checkout_session` | `yuno_create_checkout_session` | Yuno checkout session ID |
| `_yuno_customer_id` | `yuno_get_or_create_customer` | Yuno customer ID (cached per order) |
| `_yuno_payment_id` | Webhook/confirm flow | Yuno payment ID (used for webhook order lookup) |
| `_yuno_duplicate_order_id` | `yuno_create_duplicate_order_internal` | ID of retry order created after failure |
| `_yuno_original_order_id` | `yuno_create_duplicate_order_internal` | ID of the original failed order |

---

## Configuration

All settings stored in `woocommerce_yuno_settings` WP option (key auto-derived by WooCommerce from `$this->id = 'yuno'`). Managed via:
`WooCommerce → Settings → Payments → Yuno`

| Setting key | Description |
|-------------|-------------|
| `account_id` | Yuno merchant account ID |
| `public_api_key` | Public key (used by frontend SDK) |
| `private_secret_key` | Backend-only, never exposed to frontend |
| `webhook_hmac_secret` | HMAC secret for webhook signature |
| `webhook_api_key` | `x-api-key` header value |
| `webhook_x_secret` | `x-secret` header value |
| `split_enabled` | Enable marketplace split payments |
| `yuno_recipient_id` | Seller recipient ID for split |
| `split_commission_percent` | Platform commission % (0–100), overrides fixed |
| `split_fixed_amount` | Fixed commission in minor currency units |
| `debug` | Enable WC debug logging |

Credentials can also be set via environment variables or PHP constants (WP options take priority).

`wp_localize_script` injects `YUNO_WC` into the page with: `restBase`, `nonce`, `orderId`, `orderKey`, `currency`, `total`, `country`, `email`, `language`, `publicApiKey`, `debug`.

---

## Debugging

Enable debug in plugin settings (`Debug = Yes`). Logs write to WooCommerce logger with source `yuno`.

**Logging behavior:**
- **Error/warning levels always log** — `emergency`, `alert`, `critical`, `error`, and `warning` are logged regardless of the debug setting. Only `info`, `debug`, and `notice` are gated by the debug flag.
- **`yuno_debug_enabled()` uses static caching** — the debug flag is resolved once per request and cached.
- **PII is redacted** — phone numbers logged as `phone_last4`, emails as `has_email` boolean, full API payloads are never logged. Checkout session logs include structural metadata only (session ID, country, amount, currency).

**View logs:**
```
WooCommerce → Status → Logs → yuno-{date}.log
```

In local dev, logs also appear in the Docker container at `wp-content/debug.log` (WP_DEBUG_LOG is enabled in `.wp-env.json`).

---

## Block Checkout Support

The plugin supports both **legacy shortcode checkout** and **WooCommerce block-based checkout** (default since WC 8.3).

### How It Works

Block checkout uses a redirect-to-order-pay approach: WC Blocks calls the same `process_payment()` method as legacy checkout, which returns a redirect to the order-pay page where the existing SDK orchestration handles payment.

```
Block Checkout → process_payment() → redirect → Order-Pay Page → SDK → Thank You
Legacy Checkout → process_payment() → redirect → Order-Pay Page → SDK → Thank You
```

Both flows converge at the order-pay page — no duplicate payment logic.

### Key Components

- **`class-wc-gateway-yuno-blocks.php`** — `AbstractPaymentMethodType` implementation. Methods: `initialize()`, `is_active()`, `get_payment_method_script_handles()`, `get_payment_method_data()`.
- **`src/blocks/yuno-blocks.js`** — React component that calls `registerPaymentMethod()`. Registers `onPaymentSetup` returning SUCCESS with empty `paymentMethodData` (WC Blocks handles the redirect).
- **Settings data key** — `getSetting('yuno_data')` (derived from `protected $name = 'yuno'`).

### Registration Flow

1. `before_woocommerce_init` → `FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true)`
2. `woocommerce_blocks_loaded` → guard `class_exists(AbstractPaymentMethodType)` → load `class-wc-gateway-yuno-blocks.php`
3. `woocommerce_blocks_payment_method_type_registration` → `$registry->register(new WC_Gateway_Yuno_Blocks_Support())`

### Build Tooling

Block checkout requires a build step to compile the React source:

```bash
cd yuno-woocommerce
npm install              # First time only
npm run build            # Production build (minified)
npm run start            # Development watch mode
```

- **Build tool:** `@wordpress/scripts` with custom `webpack.config.js` for WooCommerce externals
- **Source:** `src/blocks/yuno-blocks.js`
- **Output:** `assets/js/blocks/yuno-blocks.js` + `assets/js/blocks/yuno-blocks.asset.php`
- **Compiled assets are committed** to the repo — production works without a build step
- **Existing assets unchanged** — `api.js`, `checkout.js`, `checkout.css` are not part of the build pipeline

### Backward Compatibility

- All block-related code is guarded by `class_exists()` checks
- On WC < 8.0 (no blocks API), the block code is silently skipped — legacy checkout works as before
- No modifications to existing gateway class, REST API, or JS/CSS

---

## External Dependencies

- **Yuno Web SDK:** `https://sdk-web.y.uno/v1.5/main.js` (loaded via `wp_enqueue_script`)
- **@wordpress/env:** v10.37.0 (dev dependency, used only for local Docker environment)
- **@wordpress/scripts:** ^28.0.0 (dev dependency in `yuno-woocommerce/`, used to compile block checkout React code)
