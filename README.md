# Yuno WooCommerce Gateway

Custom integration of **Yuno Payments** as a payment gateway in **WooCommerce**, developed as a WordPress plugin.

This plugin uses the **Yuno Web SDK** (v1.5) and a PHP REST API layer to:
- Create checkout sessions (Full SDK / SDK_CHECKOUT workflow)
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
- **In-place retry** — customers can retry a failed payment without leaving the page; order duplication only occurs when revisiting a failed order's pay page
- **HMAC webhook verification** — three-layer check (x-api-key, x-secret, HMAC-SHA256 signature)
- **Server-side payment verification** — never trusts client-reported payment status
- **Multi-environment support** — auto-detects from public key prefix (sandbox_, staging_, dev_, prod_)
- **Per-order customer creation** for clean payment isolation
- **Virtual/Downloadable products** auto-complete to `completed` status
- **3DS/Authentication support** — handles 3D Secure and additional authentication challenges during payment
- **PII redaction** — phone numbers, emails, and full API payloads are never logged
- **Debug logging** — optional WooCommerce-integrated logging for troubleshooting payments and webhooks
- **Clean uninstall** — `uninstall.php` removes plugin settings on deletion

---

## Requirements

- PHP 7.4+
- WordPress 6.0+
- WooCommerce 8.0+
- Node.js (only for development / rebuilding block checkout assets)
- Yuno merchant account with API credentials
- HTTPS enabled (required for production)

---

## Installation

### Manual Installation

1. Upload the `yuno-payment-gateway` folder to `wp-content/plugins/`
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
- **Plugin:** auto-installed and activated from `./yuno-payment-gateway`
- **WooCommerce:** not included in `.wp-env.json` — must be installed manually

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
- `payment.pending` — logs event, adds order note (no status change)
- `payment.failed` / `payment.rejected` / `payment.declined` — marks order as failed
- `payment.chargeback` — marks order as on-hold for review
- `payment.refunds` / `payment.refund` — creates WC refund (full or partial)

---

## Architecture

```
WooCommerce Checkout
        | process_payment() -> redirect to order-pay page
        v
class-wc-gateway-yuno.php
        | enqueue_scripts() -> loads Yuno SDK + api.js + checkout.js
        | wp_localize_script() -> injects YUNO_WC config (incl. publicApiKey)
        v
checkout.js (state machine)
        | checkOrderStatus() -> createCustomer() + getPublicApiKey() [parallel]
        | -> getCheckoutSession() -> startCheckout() -> mountCheckout()
        | -> user pays -> yunoCreatePayment(token) -> continuePayment()
        | -> yunoPaymentResult() -> confirmOrder()
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
5. Checkout session created (Full SDK / `SDK_CHECKOUT` workflow)
6. Yuno SDK initializes via `startCheckout()` and mounts via `mountCheckout()`
7. User selects payment method -> pay button shown
8. User clicks Pay -> SDK tokenizes -> `yunoCreatePayment(oneTimeToken)` -> backend POST `/yuno/v1/payments` (includes split data if configured) -> `continuePayment()`
9. `yunoPaymentResult` fires -> `confirmOrder` verifies with Yuno API
10. SUCCESS/PENDING -> `confirmOrder` verifies -> redirect to thank-you page; FAILURE -> `resetSdkState()` + in-place retry

---

## REST Endpoints

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/yuno/v1/public-api-key` | Returns public API key |
| POST | `/yuno/v1/customer` | Creates Yuno customer for order |
| POST | `/yuno/v1/checkout-session` | Creates checkout session (Full SDK / `SDK_CHECKOUT`) |
| POST | `/yuno/v1/payments` | Creates payment via Yuno API (one_time_token, split data) |
| POST | `/yuno/v1/confirm` | Server-side payment verification + order update (PENDING treated as success) |
| POST | `/yuno/v1/check-order-status` | Pre-flight status check (prevents double-pay) |
| POST | `/yuno/v1/duplicate-order` | Creates retry order after failure |
| POST | `/yuno/v1/webhook` | Receives HMAC-verified Yuno events |

All endpoints (except webhook) require WP REST nonce and mandatory `order_key` for authentication.

---

## Security

- **Server-side verification** — payment status always verified against Yuno API before updating WC order (PENDING/PROCESSING/REQUIRES_ACTION treated as auto-capture success)
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
├── Dockerfile                              # PHP 8.2 Apache image
├── package.json                            # npm scripts for wp-env
├── CLAUDE.md                               # Detailed dev documentation
├── yuno-payment-gateway/
│   ├── yuno-payment-gateway.php              # Plugin entry point + constants
│   ├── uninstall.php                       # Cleanup on plugin deletion
│   ├── package.json                        # Build tooling (@wordpress/scripts)
│   ├── webpack.config.js                   # Custom webpack config (WC externals)
│   ├── readme.txt                          # WordPress.org plugin directory readme
│   ├── LICENSE                             # GPLv2 full license text
│   ├── .gitattributes                      # export-ignore rules for git archive ZIPs
│   ├── includes/
│   │   ├── class-wc-gateway-yuno.php       # WC_Payment_Gateway subclass
│   │   ├── class-wc-gateway-yuno-blocks.php # Block checkout integration
│   │   └── rest-api.php                    # REST endpoints + webhook handling
│   ├── src/
│   │   └── blocks/
│   │       └── yuno-blocks.js              # Block checkout React source
│   ├── languages/
│   │   └── yuno-payment-gateway.pot        # Translation template
│   ├── wordpress_org_assets/               # WP.org directory marketing assets
│   │   ├── banner-*.png                    # Plugin banners
│   │   └── icon-*.png                      # Plugin icons
│   └── assets/
│       ├── js/
│       │   ├── api.js                      # Frontend REST fetch wrappers
│       │   ├── checkout.js                 # SDK orchestration + state machine
│       │   └── blocks/                     # Compiled block output (committed)
│       ├── css/
│       │   └── checkout.css                # SDK container + processing overlay styles
│       └── images/                         # Card brand SVG icons
```

---

## Block Checkout Support

The plugin supports both **legacy shortcode checkout** and **WooCommerce block-based checkout** (default since WC 8.3).

Both checkout types use the same redirect-to-order-pay approach — `process_payment()` redirects the user to the order-pay page where the Yuno SDK handles payment.

### Building Block Checkout Assets

Compiled assets are committed to the repo — **no build step needed for production**. Only rebuild if you modify `src/blocks/yuno-blocks.js`:

```bash
cd yuno-payment-gateway
npm install              # First time only
npm run build            # Production build
npm run start            # Development watch mode
```

### Compatibility

- **WooCommerce 8.0+:** Block checkout supported
- **WooCommerce < 8.0:** Block code silently skipped, legacy checkout works as before
- The plugin declares `cart_checkout_blocks` and `custom_order_tables` (HPOS) compatibility via `FeaturesUtil`

---

## Order Status Flow

```
pending
  |
  +-> (payment approved/pending) -> processing (physical) / completed (virtual/downloadable)
  |       Note: PENDING status is treated as auto-capture success in /confirm
  |
  +-> (webhook: succeeded) -> processing/completed
  +-> (webhook: pending) -> pending (note added, no status change)
  +-> (webhook: failed) -> failed
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

## Releasing to WordPress.org

The plugin is distributed via the [WordPress Plugin Directory](https://wordpress.org/plugins/yuno-payment-gateway/). WordPress.org uses **SVN** (Subversion) as its distribution system. We use GitHub as our primary development repository and sync to SVN manually.

### How GitHub and SVN Work Together

- **GitHub** is the source of truth — all development happens here (branches, PRs, code review)
- **SVN** is the distribution channel — WordPress.org reads from SVN to serve the plugin to users
- **Sync is one-way:** GitHub → SVN, done manually after merging to `master`
- **Never edit SVN directly** (except rare readme-only hotfixes)

### Prerequisites

- **SVN installed:** `brew install subversion` (macOS)
- **SVN working copy:** `~/svn/yuno-payment-gateway/` (persistent checkout, created once)
- **SVN credentials:** Username `yunocheckout`, password generated at WordPress.org → Profile → Account & Security → SVN credentials

### SVN Repository Structure

```
https://plugins.svn.wordpress.org/yuno-payment-gateway/
├── trunk/          ← Latest plugin code (synced from GitHub master)
├── tags/
│   └── X.Y.Z/     ← Tagged releases (immutable snapshots)
└── assets/          ← Banners, icons, screenshots (NOT inside trunk)
    ├── banner-772x250.png
    ├── banner-1544x500.png
    ├── icon-128x128.png
    └── icon-256x256.png
```

### One-Time Setup

```bash
brew install subversion
mkdir -p ~/svn
svn checkout https://plugins.svn.wordpress.org/yuno-payment-gateway \
    ~/svn/yuno-payment-gateway --depth immediates --username yunocheckout
svn update ~/svn/yuno-payment-gateway/trunk --set-depth infinity
svn update ~/svn/yuno-payment-gateway/assets --set-depth infinity
```

### Publishing a New Version

1. **Develop** on feature branches, merge PRs to `master`
2. **Bump version** in all 4 files (must match):
   - `yuno-payment-gateway/yuno-payment-gateway.php` — plugin header (`Version: X.Y.Z`)
   - `yuno-payment-gateway/yuno-payment-gateway.php` — constant (`YUNO_WC_VERSION`)
   - `yuno-payment-gateway/readme.txt` — `Stable tag: X.Y.Z`
   - `yuno-payment-gateway/package.json` — `"version": "X.Y.Z"`
3. **Add changelog entry** in `readme.txt` under `== Changelog ==`
4. **Commit and push** to `master`
5. **Build the plugin:**
   ```bash
   cd yuno-payment-gateway && npm ci && npm run build
   ```
6. **Sync to SVN trunk:**
   ```bash
   rsync -avz --delete \
       --exclude='node_modules/' --exclude='wordpress_org_assets/' \
       --exclude='.gitattributes' --exclude='.gitignore' \
       --exclude='package-lock.json' --exclude='.DS_Store' \
       ./yuno-payment-gateway/ ~/svn/yuno-payment-gateway/trunk/
   ```
7. **Sync marketing assets to SVN:**
   ```bash
   rsync -avz --delete --exclude='.DS_Store' \
       ./yuno-payment-gateway/wordpress_org_assets/ ~/svn/yuno-payment-gateway/assets/
   ```
8. **Register new/deleted files with SVN:**
   ```bash
   cd ~/svn/yuno-payment-gateway
   svn status | grep '^\?' | awk '{print $2}' | xargs -I{} svn add "{}"
   svn status | grep '^\!' | awk '{print $2}' | xargs -I{} svn rm "{}"
   ```
9. **Set mime-type on any new image assets:**
   ```bash
   svn propset svn:mime-type image/png assets/banner-772x250.png
   svn propset svn:mime-type image/png assets/banner-1544x500.png
   svn propset svn:mime-type image/png assets/icon-128x128.png
   svn propset svn:mime-type image/png assets/icon-256x256.png
   ```
10. **Review and commit:**
    ```bash
    svn status
    svn commit -m "Release version X.Y.Z" --username yunocheckout
    ```
11. **Tag the release:**
    ```bash
    svn update tags --set-depth immediates
    svn copy trunk tags/X.Y.Z
    svn commit -m "Tag version X.Y.Z" --username yunocheckout
    ```
12. **Verify** at https://wordpress.org/plugins/yuno-payment-gateway/

### Readme-Only Updates (no version bump needed)

For documentation-only changes (typos, FAQ updates, "Tested up to" bumps):
1. Update `readme.txt` in `master` and push
2. Sync to SVN trunk using `rsync` (steps 6-8 above)
3. Commit to SVN — no new tag needed
4. Keep `Stable tag` pointing to the current version

### Marketing Assets

Source files live in `yuno-payment-gateway/wordpress_org_assets/`. They are synced to SVN `/assets/` during the release process.

| File | Dimensions | Purpose |
|------|-----------|---------|
| `banner-772x250.png` | 772x250 | Plugin page banner |
| `banner-1544x500.png` | 1544x500 | Hi-DPI banner |
| `icon-128x128.png` | 128x128 | Plugin icon |
| `icon-256x256.png` | 256x256 | Hi-DPI icon |
| `screenshot-N.png` | any | Screenshots (must match `== Screenshots ==` in readme) |

All image files in SVN `/assets/` must have `svn:mime-type` property set (e.g., `image/png`) to prevent browsers from downloading them instead of displaying them.

### Important Rules

- Each SVN commit rebuilds ALL version ZIPs — batch changes, don't commit frequently
- Tag names: numbers and periods only (e.g., `1.0.0`, not `v1.0.0`)
- `Stable tag` in `readme.txt` must point to a specific tag, never `trunk`
- Assets (banners, icons) go in SVN `/assets/`, NOT inside `trunk/` or `tags/`
- It may take up to 72 hours for WordPress.org search results to update after a new release

---

## Changelog

### v1.0.0
- Full SDK workflow (`SDK_CHECKOUT`) — backend-driven payment creation via `yunoCreatePayment` callback
- WordPress.org directory submission preparation (`readme.txt`, `.pot` translation template, deploy workflow)
- HPOS (`custom_order_tables`) compatibility declaration
- New `/yuno/v1/payments` REST endpoint for server-side payment creation with idempotency keys
- In-place retry on failure via `resetSdkState()` instead of order duplication
- Payment creation transient lock (`yuno_pay_lock_{order_id}`) for concurrency safety
- `early_redirect_paid_orders()` for instant redirect before page render
- Card form configuration with required cardholder name
- Processing overlay UI during payment creation
- Removed `/yuno/v1/update-checkout-session` endpoint (no longer needed with Full SDK)

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

GPLv2 or later
