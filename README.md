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
- 🔐 Secure keys (PUBLIC / PRIVATE / ACCOUNT CODE)
- ⚙️ Integration via **REST API**
- 🧠 Prevents double SDK initialization
- ♻️ Compatible with WooCommerce re-render (`updated_checkout`)
- 🧪 **Sandbox** support
- 📦 Modular and extensible code

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

## 🔐 Required Environment Variables

These variables **should NOT be versioned**.

### Option A – `wp-config.php`

```php
define('ACCOUNT_CODE', 'your_account_code');
define('PUBLIC_API_KEY', 'sandbox_xxx');
define('PRIVATE_SECRET_KEY', 'xxx');
```

### Option B – Environment (Docker / wp-env)

Copy `.wp-env.json.example` to `.wp-env.json` and fill in your credentials:

```json
{
  "config": {
    "ACCOUNT_CODE": "your-yuno-account-code",
    "PUBLIC_API_KEY": "sandbox_your-public-api-key",
    "PRIVATE_SECRET_KEY": "your-private-secret-key"
  }
}
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

5. Enable the payment method

### Development with wp-env

1. Install dependencies:
   ```bash
   npm install
   ```

2. Copy the environment file:
   ```bash
   cp .wp-env.json.example .wp-env.json
   ```

3. Add your Yuno credentials to `.wp-env.json`

4. Start the environment:
   ```bash
   npx wp-env start
   ```

5. Access WordPress at `http://localhost:8888`

---

## 🧪 Sandbox Mode (Testing)

Yuno detects the environment based on the Public API Key prefix:

| Prefix     | Environment |
|------------|-------------|
| `sandbox_` | Sandbox     |
| `staging_` | Staging     |
| `dev_`     | Development |
| `prod_`    | Production  |

---

## 🧾 Current Payment Flow (MVP)

1. User enters checkout
2. Yuno SDK initializes
3. `checkout_session` is created from the cart
4. User enters card details
5. Yuno generates `oneTimeToken`
6. WordPress creates payment via Yuno API
7. Yuno processes the payment

> ⚠️ **Note:** Currently the payment is processed before creating the final WooCommerce order (MVP behavior).

---

## 🛠 REST Endpoints

| Method | Endpoint                          | Description                        |
|--------|-----------------------------------|------------------------------------|
| GET    | `/thix-yuno/v1/public-api-key`    | Returns public key for the SDK     |
| POST   | `/thix-yuno/v1/checkout-session`  | Creates a checkout session in Yuno |
| POST   | `/thix-yuno/v1/payments`          | Creates payment using oneTimeToken |

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

**Miguel Atencia**

---

## 📄 License

ISC
