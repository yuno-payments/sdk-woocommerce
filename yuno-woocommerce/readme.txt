=== Yuno Payment Gateway ===
Contributors: yunocheckout
Tags: payments, checkout, payment gateway, credit card, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through Yuno's payment orchestration platform in your WooCommerce store.

== Description ==

Yuno Payment Gateway integrates Yuno's payment orchestration platform with your
WooCommerce store, enabling you to accept payments from multiple providers through a single
integration.

**Features:**

* Connect multiple payment providers through one plugin
* Intelligent payment routing
* Support for cards, wallets, bank transfers, and local payment methods
* Seamless checkout experience with Yuno's SDK
* Block-based checkout support
* Marketplace split payments

= External Services =

This plugin connects to the [Yuno payment platform](https://www.y.uno/) to process payments.
When a customer completes checkout, order and payment data is sent to Yuno's servers for
payment processing.

**Yuno API:**
This plugin communicates with Yuno's API servers to create checkout sessions, process
payments, and verify payment status. The specific server used depends on your API key
configuration (production: `https://api.y.uno`, sandbox: `https://api-sandbox.y.uno`).

**Yuno Web SDK:**
This plugin loads the Yuno Web SDK (`https://sdk-web.y.uno/v1.5/main.js`) on the checkout
page to render the secure payment form. This SDK is loaded from Yuno's servers for PCI
compliance and security purposes — the SDK must be served from Yuno's infrastructure to
maintain PCI DSS compliance and ensure secure payment data handling.

* [Yuno Terms of Service](https://y.uno/terms-and-conditions)
* [Yuno Privacy Policy](https://www.y.uno/privacy)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/yuno-payment-gateway`, or
   install through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to WooCommerce > Settings > Payments to configure the Yuno gateway.
4. Enter your Yuno API credentials (available from your Yuno dashboard at https://dashboard.y.uno).

== Frequently Asked Questions ==

= Do I need a Yuno account? =

Yes. You need to register at [https://www.y.uno/](https://www.y.uno/) to obtain API credentials.

= Which payment methods are supported? =

Yuno supports credit cards, debit cards, bank transfers, digital wallets, and many local
payment methods depending on your region and provider configuration.

= Is this plugin compatible with WooCommerce Block Checkout? =

Yes. The plugin fully supports both the classic and block-based WooCommerce checkout.

== Screenshots ==

1. Yuno payment settings page in WooCommerce.
2. Checkout page with Yuno payment form.
3. Order confirmation with payment details.

== Changelog ==

= 1.0.0 =
* Initial release on WordPress Plugin Directory.
* WooCommerce payment gateway integration via Yuno.
* Support for card payments, wallets, and local payment methods.
* Block-based checkout support.
* Webhook handling for asynchronous payment status updates.
* Marketplace split payment support.

== Upgrade Notice ==

= 1.0.0 =
Initial release of Yuno Payment Gateway.
