# Yuno WooCommerce Gateway

Custom integration of **Yuno Payments** as a payment gateway in **WooCommerce**, developed as a WordPress plugin.

This plugin uses the **Yuno Web SDK** (v1.5) and a PHP REST API layer to:
- Create checkout sessions (SDK_SEAMLESS workflow)
- Create and manage per-order customers
- Process payments via the Yuno SDK
- Handle HMAC-verified webhooks for payment status updates
- Support marketplace split payments
- Integrate with both legacy and block-based WooCommerce checkout

---

## Features

- **Multiple payment methods** via Yuno SDK (cards, wallets, local methods)
- **Block checkout support** — works with WooCommerce block-based checkout (default since WC 8.3)
- **Marketplace split payments** with percent-based or fixed commission
- **Automatic retry** — failed payments auto-duplicate the order for seamless retry
- **HMAC webhook verification** — three-layer check (x-api-key, x-secret, HMAC-SHA256 signature)
- **Server-side payment verification** — never trusts client-reported payment status
- **Multi-environment support** — auto-detects from public key prefix (sandbox_, staging_, dev_, prod_)
- **Per-order customer creation** for clean payment isolation
- **Virtual/Downloadable products** auto-complete to `completed` status
- **3DS/Authentication flow** handling with proper status management
- **PII redaction** — phone numbers, emails, and full API payloads are never logged
- **Production logging** — error/warning levels always logged regardless of debug setting

---

## Requirements

- PHP 8.2+
- WordPress 5.0+
- WooCommerce 5.0+ (block checkout requires 8.0+)
- Node.js (only for development / rebuilding block checkout assets)
- Yuno merchant account with API credentials
- HTTPS enabled (required for production)

---

## Installation

### Manual Installation

1. Upload the `yuno-woocommerce` folder to `wp-content/plugins/`
2. Activate the plugin in WordPress Admin
3. Ensure WooCommerce is active
4. Go to **WooCommerce > Settings > Payments > Yuno**
5. Configure your Yuno credentials and enable the payment method

### Development with wp-env

Requires **Docker Desktop** running locally.

```bash
npm install          # Install @wordpress/env
npm run env:start    # Create and start Docker containers
npm run env:stop     # Stop containers (data preserved)
npm run env:restart  # Stop + start (after .wp-env.json changes)
npm run env:destroy  # Remove all containers and data
npm run env:clean    # Reset WordPress to clean state
```

- **WordPress:** http://localhost:8888
- **Credentials:** `admin` / `password`
- **Plugin:** auto-installed and activated from `./yuno-woocommerce`

---

## Configuration

All settings configured via **WooCommerce > Settings > Payments > Yuno**:

| Setting | Description |
|---------|-------------|
| Enable | Enable/disable the payment method |
| Checkout Title | Name displayed at checkout (default: "Yuno") |
| Account ID | Yuno merchant account ID |
| Public API Key | Frontend SDK key (determines environment) |
| Private Secret Key | Backend-only API key (never exposed to frontend) |
| Webhook API Key | `x-api-key` header for webhook authentication |
| Webhook X-Secret | `x-secret` header for webhook authentication |
| Webhook HMAC Secret | HMAC secret for webhook signature verification |
| Split Enabled | Enable marketplace split payments |
| Recipient ID | Seller recipient ID for split payments |
| Commission % | Platform commission percentage (0-100, overrides fixed) |
| Fixed Amount | Fixed commission in minor currency units |
| Debug | Enable WC debug logging (`info`/`debug` levels; errors always log) |

Credentials can also be set via environment variables or PHP constants (`wp-config.php`). WP options take priority.

### Webhook Configuration

Configure in your Yuno Dashboard:

**URL:** `https://your-site.com/wp-json/yuno/v1/webhook`

**Supported Events:**
- `payment.succeeded` / `payment.purchase` — marks order as paid
- `payment.failed` / `payment.rejected` / `payment.declined` — marks order as failed
- `payment.chargeback` — marks order as on-hold for review
- `payment.refunds` / `payment.refund` — creates WC refund (full or partial)

---

## Architecture

```
WooCommerce Checkout
        | process_payment() -> redirect to order-pay page
        v
class-wc-gateway-yuno-card.php
        | enqueue_scripts() -> loads Yuno SDK + api.js + checkout.js
        | wp_localize_script() -> injects YUNO_WC config (incl. publicApiKey)
        v
checkout.js (state machine)
        | checkOrderStatus() -> createCustomer() + getPublicApiKey() [parallel]
        | -> getCheckoutSession() -> startSeamlessCheckout() -> mountSeamlessCheckout()
        | -> user pays -> yunoPaymentResult() -> confirmOrder()
        v
api.js (fetch layer)
        | WP REST nonce + order_key on every request
        v
rest-api.php (REST endpoints)
        | server-side verification against Yuno API
        v
Yuno API (https://api[-env].y.uno)
        |
        +-> Webhook -> /yuno/v1/webhook -> rest-api.php
```

Both block and legacy checkout converge at the order-pay page — a single payment flow.

### Payment Flow

1. User submits checkout -> WC order created in `pending` (stock held but not reduced — `payment_complete()` reduces stock)
2. Redirect to `/order-pay/{id}/`
3. `checkout.js` runs preflight checks (redirects if already paid, auto-duplicates if failed)
4. Customer created + public API key resolved in parallel
5. Checkout session created (SDK_SEAMLESS workflow, includes split data if configured)
6. Yuno SDK initializes and mounts seamless checkout
7. User selects payment method -> pay button shown
8. User clicks Pay -> SDK tokenizes and creates payment server-side
9. `yunoPaymentResult` fires -> `confirmOrder` verifies with Yuno API
10. SUCCESS -> redirect to thank-you page; FAILURE -> SDK retry mechanism; PENDING -> stay on page (3DS)

---

## REST Endpoints

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/yuno/v1/public-api-key` | Returns public API key |
| POST | `/yuno/v1/customer` | Creates Yuno customer for order |
| POST | `/yuno/v1/checkout-session` | Creates checkout session (SDK_SEAMLESS) |
| POST | `/yuno/v1/confirm` | Server-side payment verification + order update |
| POST | `/yuno/v1/check-order-status` | Pre-flight status check (prevents double-pay) |
| POST | `/yuno/v1/duplicate-order` | Creates retry order after failure |
| POST | `/yuno/v1/update-checkout-session` | Updates session ID after PAYMENT_RETRY |
| POST | `/yuno/v1/webhook` | Receives HMAC-verified Yuno events |

All endpoints (except webhook) require WP REST nonce and mandatory `order_key` for authentication.

---

## Security

- **Server-side verification** — payment status always verified against Yuno API before updating WC order
- **HMAC webhook verification** — three-layer check: `x-api-key`, `x-secret`, HMAC-SHA256 signature (hex + base64)
- **Mandatory order key** — all REST endpoints require `order_key`; missing key returns HTTP 400
- **Idempotent processing** — transient locks (30s TTL) and `is_paid()` checks prevent duplicate processing
- **No sensitive data in responses** — raw API responses and exception messages never returned to client
- **Redirect origin validation** — all frontend redirects validate URL origin before navigating
- **PII redaction in logs** — phone numbers as last 4 digits, emails as boolean, full payloads never logged
- **Private keys never exposed** — `PRIVATE_SECRET_KEY` is backend-only

---

## Plugin Structure

```
sdk-woocommerce/
├── .wp-env.json                            # Docker/WordPress config
├── package.json                            # npm scripts for wp-env
├── CLAUDE.md                               # Detailed dev documentation
├── yuno-woocommerce/
│   ├── yuno-woocommerce.php                # Plugin entry point + constants
│   ├── package.json                        # Build tooling (@wordpress/scripts)
│   ├── webpack.config.js                   # Custom webpack config (WC externals)
│   ├── includes/
│   │   ├── class-wc-gateway-yuno-card.php  # WC_Payment_Gateway subclass
│   │   ├── class-wc-gateway-yuno-blocks.php # Block checkout integration
│   │   └── rest-api.php                    # REST endpoints + webhook handling
│   ├── src/
│   │   └── blocks/
│   │       └── yuno-blocks.js              # Block checkout React source
│   └── assets/
│       ├── js/
│       │   ├── api.js                      # Frontend REST fetch wrappers
│       │   ├── checkout.js                 # SDK orchestration + state machine
│       │   └── blocks/                     # Compiled block output (committed)
│       ├── css/
│       │   └── checkout.css                # SDK container styles
│       └── images/                         # Card brand SVG icons
```

---

## Block Checkout Support

The plugin supports both **legacy shortcode checkout** and **WooCommerce block-based checkout** (default since WC 8.3).

Both checkout types use the same redirect-to-order-pay approach — `process_payment()` redirects the user to the order-pay page where the Yuno SDK handles payment.

### Building Block Checkout Assets

Compiled assets are committed to the repo — **no build step needed for production**. Only rebuild if you modify `src/blocks/yuno-blocks.js`:

```bash
cd yuno-woocommerce
npm install              # First time only
npm run build            # Production build
npm run start            # Development watch mode
```

### Compatibility

- **WooCommerce 8.0+:** Block checkout supported
- **WooCommerce < 8.0:** Block code silently skipped, legacy checkout works as before
- The plugin declares `cart_checkout_blocks` compatibility via `FeaturesUtil`

---

## Order Status Flow

```
pending
  |
  +-> (payment approved) -> processing (physical) / completed (virtual/downloadable)
  |
  +-> (payment pending 3DS) -> pending (waits for webhook/SDK callback)
  |                              |
  |                              +-> (webhook: succeeded) -> processing/completed
  |                              +-> (webhook: failed) -> failed
  |
  +-> (payment rejected) -> failed -> (auto-duplicate for retry)
```

---

## Debugging

1. Enable **Debug** in WooCommerce > Settings > Payments > Yuno
2. View logs at **WooCommerce > Status > Logs > yuno-{date}.log**

**Note:** Error and warning level logs are always recorded regardless of the debug setting. The debug flag only gates `info`/`debug`/`notice` levels.

In local dev, logs also appear in `wp-content/debug.log` (WP_DEBUG_LOG enabled in `.wp-env.json`).

---

## Troubleshooting

### Orders stuck in "pending" status
- Verify webhook URL is configured in Yuno Dashboard
- Ensure webhook URL is publicly accessible (not localhost)
- Check webhook header values match plugin settings
- Enable debug logs and check for webhook verification errors

### SDK not loading on order-pay page
- Check browser console for errors
- Verify PUBLIC_API_KEY is configured
- Check network tab for failed API requests
- Ensure order is in `pending` status

### Payment succeeds but order stays "pending"
- Check webhook logs in Yuno Dashboard
- Verify HMAC secret matches
- Check WooCommerce logs for verification errors

---

## Changelog

### v0.5.2
- WooCommerce block-based checkout support
- `AbstractPaymentMethodType` integration with redirect-to-order-pay flow
- `@wordpress/scripts` build pipeline for React block component
- `cart_checkout_blocks` feature compatibility declaration
- Marketplace split payments (percent-based and fixed commission)
- Security hardening: mandatory order_key, PII redaction, redirect validation
- Production logging: error/warning always logged, structured metadata
- Performance: static caching, parallelized API calls, batched order saves
- Code quality: global constants, snake_case variables, console cleanup

### v0.5.0
- Per-order customer creation strategy
- Fixed INVALID_CUSTOMER_FOR_TOKEN error
- Improved webhook handling with API verification
- Virtual/downloadable products auto-complete to `completed` status
- 3DS/authentication flow handling
- Race condition protection on webhooks
- Duplicate order creation for failed payments

### v0.4.x
- Initial release with basic payment flow
- Checkout session creation
- Payment processing with oneTimeToken
- Basic webhook support

---

## Author

**YUNO**

## License

ISC
