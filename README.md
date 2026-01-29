# Thix Yuno WooCommerce Gateway

Custom integration of **Yuno Payments** as a **card** payment method in **WooCommerce**, developed as a WordPress plugin.

This plugin uses the **Yuno Web SDK** and an intermediate layer in WordPress (REST API) to:
- Create `checkout sessions`
- Tokenize cards
- Create payments in Yuno
- Integrate with WooCommerce checkout flow

---

## ✨ Features

- 💳 Card payment using **Yuno**
- 🔐 Secure keys configuration via WordPress Admin UI
- ⚙️ Integration via **REST API**
- 🧠 Prevents double SDK initialization
- ♻️ Compatible with WooCommerce re-render (`updated_checkout`)
- 🧪 **Sandbox** support with environment selector
- 📦 Modular and extensible code
- 🐛 Debug logging option

---

## 🧱 Architecture

```
WooCommerce Checkout
        │
        ▼
assets/js/checkout.js
        │
        ▼
  assets/js/api.js
        │
        ▼
WordPress REST API (rest-api.php)
        │
        ▼
  Yuno API (sandbox / prod)
```

---

## 📁 Plugin Structure

```
thix-yuno-card/
│
├── assets/
│   └── js/
│       ├── api.js              # REST calls to WordPress
│       └── checkout.js         # Initializes Yuno SDK and UI
│
├── includes/
│   ├── class-wc-gateway-thix-yuno-card.php  # WooCommerce Gateway class
│   └── rest-api.php            # REST endpoints (checkout / payments)
│
└── thix-yuno-card.php          # Plugin bootstrap
```

---

## 🚀 Installation

### Manual Installation

1. Clone the repository into:
   ```bash
   wp-content/plugins/thix-yuno-card
   ```

2. Activate the plugin from WordPress Admin

3. Ensure WooCommerce is active

4. Go to:
   ```
   WooCommerce → Settings → Payments → Card (Yuno)
   ```

5. Configure your Yuno credentials and enable the payment method

### Development with wp-env

1. Install dependencies:
   ```bash
   npm install
   ```

2. Start the environment:
   ```bash
   npx wp-env start
   ```

3. Access WordPress at `http://localhost:8888`

4. Configure the plugin via WordPress Admin UI

---

## ⚙️ Configuration

All settings are configured through the **WordPress Admin UI**:

```
WooCommerce → Settings → Payments → Card (Yuno)
```

### Available Settings

| Setting | Description |
|---------|-------------|
| **Enable** | Enable/disable the payment method |
| **Checkout Title** | Name displayed to users at checkout |
| **ACCOUNT_CODE** | Your Yuno account code |
| **PUBLIC_API_KEY** | Public API key - the prefix (sandbox_, prod_, etc.) determines the environment automatically |
| **PRIVATE_SECRET_KEY** | Private secret key (backend only) |
| **Debug** | Enable debug logs using WooCommerce logger |

### Alternative Configuration (Optional)

For development or advanced setups, credentials can also be set via:

**Option A – `wp-config.php`**

```php
define('ACCOUNT_CODE', 'your_account_code');
define('PUBLIC_API_KEY', 'sandbox_xxx');
define('PRIVATE_SECRET_KEY', 'xxx');
```

**Option B – Environment variables (Docker / wp-env)**

```bash
ACCOUNT_CODE=xxx
PUBLIC_API_KEY=sandbox_xxx
PRIVATE_SECRET_KEY=xxx
```

> **Note:** Settings in the WordPress Admin UI take priority over environment variables.

---

## 🧪 Environment Detection (Automatic)

The plugin automatically detects the environment based on your Public API Key prefix. No manual configuration needed.

| Prefix     | Environment |
|------------|-------------|
| `sandbox_` | Sandbox (Testing)     |
| `staging_` | Staging     |
| `dev_`     | Development |
| `prod_`    | Production  |

---

## 🧾 Current Payment Flow (Production-Ready)

1. User completes WooCommerce checkout → Order created in `pending` status
2. User redirected to `/order-pay/{ID}/?pay_for_order=true&key=...`
3. Yuno SDK initializes on order-pay page
4. `checkout_session` is created from existing WooCommerce order
5. User enters card details in Yuno SDK modal
6. Yuno generates `oneTimeToken`
7. Frontend calls `/thix-yuno/v1/payments` → WordPress creates payment via Yuno API
8. Yuno processes the payment
9. Frontend calls `/thix-yuno/v1/confirm` with `payment_id` only
10. **Backend verifies payment status with Yuno API** (server-side verification)
11. Order marked as paid/failed based on verified status
12. User redirected to `/order-received` (thank you page)

> ✅ **Security:** The `/confirm` endpoint performs server-side verification by querying Yuno's API to verify the payment status. The frontend cannot forge payment confirmations.

---

## 🛠 REST Endpoints

| Method | Endpoint                          | Description                        |
|--------|-----------------------------------|------------------------------------|
| GET    | `/thix-yuno/v1/public-api-key`    | Returns public key for the SDK     |
| POST   | `/thix-yuno/v1/checkout-session`  | Creates a checkout session in Yuno |
| POST   | `/thix-yuno/v1/payments`          | Creates payment using oneTimeToken |
| POST   | `/thix-yuno/v1/confirm`           | Verifies payment with Yuno API and confirms order |

---

## ⚠️ Current Limitations (Intentional)

- WooCommerce order is not created before payment
- No automatic confirmation (`order_received`)
- No Yuno webhooks integration
- No automatic retries

> 👉 All of this is planned for the next iteration

---

## 🧭 Recommended Next Steps

- [ ] Create WooCommerce order in `pending` status before payment
- [ ] Confirm payment and call `payment_complete()`
- [ ] Integrate Yuno Webhooks
- [ ] Validate `order_key`
- [ ] Save metadata (`payment_id`, `status`, raw response)
- [ ] Add error handling and user feedback
- [ ] Support multiple countries/currencies

---

## 📋 Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 8.0+
- Yuno merchant account

---

## 👨‍💻 Author

**YUNO**

---

## 📄 License

ISC
