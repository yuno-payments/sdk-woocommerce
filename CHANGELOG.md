# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.2] - 2026-06-02

### Added

- **Release-flow demo helper** — Adds an isolated example helper used to validate the automated changelog publishing pipeline end to end; it has no effect on payments or checkout.

## [1.0.1] - 2026-04-13

### Added

- **Hide payment selection when Yuno is the only gateway** — Adds a Hide Payment Selection setting so the payment method radio buttons are hidden at checkout when Yuno is the only active gateway, presenting a cleaner one-method checkout experience. Applies to both legacy and block-based checkouts.

## [1.0.0] - 2026-04-07

### Added

- **Initial WordPress Plugin Directory release** — First public release of the Yuno WooCommerce payment gateway on the WordPress Plugin Directory, integrating Yuno's payment orchestration platform with WooCommerce through the Yuno Web SDK and a PHP REST API layer.
- **Card payments, wallets, and local payment methods** — Accepts cards, digital wallets, and local payment methods through Yuno's payment orchestration platform from a single integration, with intelligent payment routing across multiple providers.
- **WooCommerce block-based checkout support** — Supports both the legacy shortcode checkout and the WooCommerce block-based checkout (default since WC 8.3). Both flows converge at the order-pay page so the SDK orchestration is shared.
- **Webhook receiver for asynchronous payment status** — Receives Yuno payment events (succeeded, pending, failed, chargeback, refund) with three-layer HMAC verification and idempotent processing through transient locks.
- **Marketplace split payments** — Splits the order total between a seller recipient and a platform commission, configurable as a percentage or a fixed minor-unit amount.
- **In-place retry on failed payments** — Lets the customer retry a failed payment in place by remounting the SDK, instead of creating a new duplicate order on each retry attempt.
- **Auto-complete for virtual and downloadable orders** — Orders that contain only virtual or downloadable products skip the processing state and go straight to completed after payment.
- **3D Secure and additional authentication support** — Handles 3D Secure challenges and additional authentication flows during payment, including a return-from-3DS detection that prevents accidental order duplication.
- **HPOS compatibility** — Declares compatibility with WooCommerce High-Performance Order Storage (custom order tables) and uses HPOS-safe order queries throughout.
- **Per-order Yuno customer strategy** — Creates a fresh Yuno customer per WooCommerce order, with automatic recovery from CUSTOMER_ID_DUPLICATED and CUSTOMER_NOT_FOUND errors.
- **Server-side payment verification** — Verifies every reported payment status against the Yuno API before updating the WooCommerce order, never trusting client-reported status.
- **Multi-environment support** — Auto-detects the Yuno API environment (development, staging, sandbox, production) from the Public API Key prefix.
- **Configurable debug logging with PII redaction** — Optional WooCommerce-integrated logging for troubleshooting payments and webhooks. Phone numbers, email addresses, and full API payloads are never logged.
