(function() {
  'use strict';

  // Prevent double initialization
  if (window.YUNO_CHECKOUT_LOADED) {
    console.warn("[YUNO] checkout.js already loaded, skipping re-initialization");
    return;
  }
  window.YUNO_CHECKOUT_LOADED = true;

  // Hide SDK loaders globally to prevent "One moment please" flashes
  const style = document.createElement('style');
  style.textContent = `
    .yuno-loader,
    [class*="yuno-loading"],
    [class*="YunoLoader"],
    [id*="yuno-loader"],
    [id*="yuno-loading"] {
      display: none !important;
      opacity: 0 !important;
      visibility: hidden !important;
    }
  `;
  document.head.appendChild(style);

  // Guard: Check if API functions are available
  if (!window.YUNO_API) {
    console.error("[YUNO] YUNO_API not found. api.js not loaded.");
    return;
  }

  // Get API functions from global scope (loaded from api.js)
  const {
    getCheckoutSession,
    getPublicApiKey,
    createCustomer,
    createPayment,
    confirmOrder,
    checkOrderStatus,
    duplicateOrder,
  } = window.YUNO_API;

  let yunoInstance = null;

  const yunoConfig = window.YUNO_WC || {};

  const state = {
    starting: false,
    started: false,
    paid: false,

    orderId: Number(yunoConfig.orderId || 0),
    orderKey: yunoConfig.orderKey || "",

    selectedPaymentMethod: null,
  };

  const is3dsReturn = new URLSearchParams(window.location.search).has('yuno_3ds_return');
  if (is3dsReturn) {
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('yuno_3ds_return');
    history.replaceState(null, '', cleanUrl.href);
  }

  const PAYMENT_STATUS = {
    SUCCEEDED: 'SUCCEEDED',
    VERIFIED:  'VERIFIED',
    APPROVED:  'APPROVED',
    PAYED:     'PAYED',
    PENDING:   'PENDING',
    REJECTED:  'REJECTED',
    DECLINED:  'DECLINED',
    CANCELED:  'CANCELED',   // Yuno SDK spelling (one L)
    CANCELLED: 'CANCELLED',  // Backend/Yuno API spelling (two L's)
    ERROR:     'ERROR',
    EXPIRED:   'EXPIRED',
    FAILED:    'FAILED',     // Explicit failure status returned by backend
  };

  const SUCCESS_STATUSES  = [PAYMENT_STATUS.SUCCEEDED, PAYMENT_STATUS.VERIFIED, PAYMENT_STATUS.APPROVED, PAYMENT_STATUS.PAYED];
  const PENDING_STATUSES  = [PAYMENT_STATUS.PENDING];
  const FAILURE_STATUSES  = [
    PAYMENT_STATUS.REJECTED, PAYMENT_STATUS.DECLINED,
    PAYMENT_STATUS.CANCELED, PAYMENT_STATUS.CANCELLED,
    PAYMENT_STATUS.ERROR,    PAYMENT_STATUS.EXPIRED,
    PAYMENT_STATUS.FAILED,
  ];

  function setPayButtonVisible(visible) {
    const btn = document.getElementById("yuno-button-pay");
    if (!btn) return;
    btn.style.display = visible ? "block" : "none";
  }

  function setPayButtonDisabled(disabled) {
    const btn = document.getElementById("yuno-button-pay");
    if (!btn) return;
    btn.disabled = !!disabled;
    btn.style.opacity = disabled ? "0.5" : "1";
    btn.style.cursor = disabled ? "not-allowed" : "pointer";
    btn.style.backgroundColor = disabled ? "#666666" : "#000000";
  }

  function showProcessingOverlay() {
    if (document.getElementById("yuno-processing-overlay")) return;

    const overlay = document.createElement("div");
    overlay.id = "yuno-processing-overlay";
    overlay.className = "yuno-processing-overlay";
    overlay.innerHTML = `
      <div class="yuno-processing-content">
        <p class="yuno-processing-title">One moment, please...</p>
        <p class="yuno-processing-subtitle">We are processing your payment</p>
        <div class="yuno-processing-bar-track">
          <div class="yuno-processing-bar-fill"></div>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
  }

  function hideProcessingOverlay() {
    const overlay = document.getElementById("yuno-processing-overlay");
    if (overlay) overlay.remove();
  }

  function resolvePayButtonTarget(e) {
    const t = e.target;

    // Your custom button (order-pay receipt)
    const custom = t.closest?.("#yuno-button-pay");
    if (custom) return custom;

    // Woo "Pay for order" button (varies by template)
    const wooPay = t.closest?.('button[name="woocommerce_pay"]');
    if (wooPay) return wooPay;

    // Checkout "Place order"
    const placeOrder = t.closest?.("#place_order");
    if (placeOrder) return placeOrder;

    return null;
  }

  /**
   * Wait until SDK is available (prevents init too early)
   */
  async function waitForYunoSdk(maxMs = 6000) {
    const start = Date.now();
    while (Date.now() - start < maxMs) {
      if (window.Yuno && typeof window.Yuno.initialize === "function") return true;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    return false;
  }

  /**
   * Pre-flight check before SDK init:
   * - Redirects if order is already paid or has a backend-provided redirect URL
   * - Auto-duplicates order if payment previously failed (handles reload)
   * Returns true to continue with SDK init, false to abort (navigation already handled).
   * Fail-open: errors are logged but do not block SDK initialization.
   */
  async function runPreflightChecks() {
    try {
      const statusRes = await checkOrderStatus({
        orderId: state.orderId,
        orderKey: state.orderKey,
      });

      // Redirect whenever the backend provides a redirect URL (covers paid, failed-to-receive, etc.)
      if (statusRes.redirect) {
        try {
          const redirectUrl = new URL(statusRes.redirect, window.location.origin);
          if (redirectUrl.origin !== window.location.origin) {
            console.error("[YUNO] Blocked redirect to external origin:", redirectUrl.origin);
            return true;
          }
          window.location.href = redirectUrl.href;
        } catch {
          console.error("[YUNO] Invalid redirect URL");
          return true;
        }
        return false;
      }

      // Payment still in progress (e.g. 3DS, OTP) — show overlay, don't init SDK
      if (statusRes.is_pending) {
        showProcessingOverlay();
        return false;
      }

      // If order payment previously failed, auto-duplicate so the user can retry
      const hasFailed = statusRes.should_duplicate ||
                        statusRes.is_failed ||
                        (statusRes.verified_status && FAILURE_STATUSES.includes(statusRes.verified_status));

      if (hasFailed && !is3dsReturn) {
        try {
          const duplicateRes = await duplicateOrder({
            orderId: state.orderId,
            orderKey: state.orderKey,
          });

          if (duplicateRes?.ok && duplicateRes?.new_order_id) {
            try {
              const payUrl = new URL(duplicateRes.pay_url, window.location.origin);
              if (payUrl.origin === window.location.origin) {
                window.location.href = payUrl.href;
              }
            } catch { /* ignore invalid URL */ }
            return false;
          }
        } catch (e) {
          console.error("[YUNO] Auto-duplicate failed", e);
          // Continue anyway (fail-open for better UX)
        }
      }
    } catch (e) {
      console.error("[YUNO] check-order-status failed", e);
      // Continue anyway (fail-open for better UX)
    }

    return true;
  }

  /**
   * Reset SDK state so startYunoCheckout() can re-initialize from scratch.
   * Clears SDK containers, instance reference, and state flags.
   */
  function resetSdkState() {
    yunoInstance = null;
    state.started = false;
    state.starting = false;
    state.selectedPaymentMethod = null;

    ['yuno-root', 'yuno-apm-form', 'yuno-action-form'].forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        const fresh = document.createElement('div');
        fresh.id = id;
        el.replaceWith(fresh);
      }
    });
  }

  async function startYunoCheckout({ skipPreflight = false } = {}) {
    if (state.starting || state.started) return;
    state.starting = true;

    try {
      showProcessingOverlay();

      if (!skipPreflight) {
        // Run pre-flight checks before initializing SDK and creating sessions. This handles edge cases like:
        // - User reloads page after failed payment (auto-duplicate)
        // - Order is already paid or has a redirect URL (redirect immediately)
        const shouldContinue = await runPreflightChecks();
        if (!shouldContinue) return;
      }

      // Wait for SDK to be available (prevents init too early)
      const isSdkAvailable = await waitForYunoSdk();
      if (!isSdkAvailable) {
        console.error("[YUNO] SDK not available (Yuno.initialize missing). Check yuno-sdk script.");
        hideProcessingOverlay();
        return;
      }

      // Parallelize customer creation with public API key resolution
      const [customerId, publicApiKey] = await Promise.all([
        createCustomer({ orderId: state.orderId, orderKey: state.orderKey }),
        yunoConfig.publicApiKey ? Promise.resolve(yunoConfig.publicApiKey) : getPublicApiKey(),
      ]);

      if (!publicApiKey) {
        console.error("[YUNO] publicApiKey empty. Check /public-api-key");
        hideProcessingOverlay();
        return;
      }

      // Create checkout session (depends on customerId)
      const sessionRes = await getCheckoutSession({
        orderId: state.orderId,
        orderKey: state.orderKey,
        customer_id: customerId,
      });

      const checkoutSession = sessionRes?.checkout_session;

      if (!checkoutSession) {
        console.error("[YUNO] Failed to create checkout session");
        hideProcessingOverlay();
        return;
      }

      yunoInstance = await window.Yuno.initialize(publicApiKey);

      const RENDER_MODE_TYPE = "modal";

      const countryCode = String(sessionRes?.country || yunoConfig.country || "CO");

      await yunoInstance.startCheckout({
        checkoutSession,
        elementSelector: "#yuno-root",
        countryCode: countryCode,
        language: yunoConfig.language || "es", // Use WordPress language, fallback to Spanish
        showLoading: false,
        showPaymentStatus: true,
        onLoading: ({ isLoading, type }) => {
          if (isLoading || (type === 'ONE_TIME_TOKEN' || type === 'CREATE_PAYMENT')) {
            setPayButtonDisabled(true);
            showProcessingOverlay();
          } else {
            setPayButtonDisabled(false);
            hideProcessingOverlay();
          }
        },
        /**
         * Full SDK callback: application must create the payment via backend
         * Called by SDK after tokenization, before payment can proceed.
         * Must call continuePayment() when done (in finally block).
         */
        yunoCreatePayment: async (oneTimeToken) => {
          if (state.paid) return;
          setPayButtonDisabled(true);
          showProcessingOverlay();
          try {
            await createPayment({
              oneTimeToken,
              checkoutSession,
              orderId: state.orderId,
              orderKey: state.orderKey,
            });
          } catch (e) {
            console.error("[Yuno] Payment creation failed:", e);
            setPayButtonDisabled(false);
          } finally {
            yunoInstance.continuePayment();
            hideProcessingOverlay();
          }
        },
        renderMode: {
          type: RENDER_MODE_TYPE,
          elementSelector: {
            apmForm: "#yuno-apm-form",
            actionForm: "#yuno-action-form",
          },
        },
        card: {
          type: "extends",
          styles: "",
          hideCardholderName: false,
          cardholderName: {
            required: true
          },
          onChange: () => {}
        },
        /**
         * Called when user selects a payment method
         */
        yunoPaymentMethodSelected: (data) => {
          state.selectedPaymentMethod = data?.type;

          // Show Pay button now that a method is selected
          setPayButtonVisible(true);
        },

        /**
         * Payment result from SDK UI flow (Full SDK)
         * result is a status string: "SUCCEEDED", "PENDING", "REJECTED", etc.
         * We confirm the Woo order by verifying with our backend.
         */
        yunoPaymentResult: async (result) => {
          // Show processing overlay for all statuses
          showProcessingOverlay();

          const paymentStatus = typeof result === 'string' ? result : String(result || '');
          const isPositive = SUCCESS_STATUSES.includes(paymentStatus) || PENDING_STATUSES.includes(paymentStatus);
          const isFailure = FAILURE_STATUSES.includes(paymentStatus);

          // Confirm with backend (all branches call confirmOrder)
          let confirmRes = null;
          try {
            confirmRes = await confirmOrder({
              orderId: state.orderId,
              orderKey: state.orderKey,
              paymentStatus,
            });
          } catch (e) {
            const logFn = isFailure ? console.warn : console.error;
            logFn(`[YUNO] confirmOrder error (${paymentStatus})`, e);
          }

          // Handle success/pending confirmation
          if (isPositive && confirmRes?.ok) {
            // Payment still processing (e.g. 3DS in progress), don't redirect
            if (confirmRes.pending) {
              showProcessingOverlay();
              return;
            }
            state.paid = true;
            if (confirmRes.redirect) {
              try {
                const rUrl = new URL(confirmRes.redirect, window.location.origin);
                if (rUrl.origin === window.location.origin) {
                  window.location.href = rUrl.href;
                  return;
                }
              } catch { /* ignore invalid redirect */ }
            }
            window.location.reload();
            return;
          }

          // Handle backend disagreement (SDK said positive but backend says FAILED)
          if (isPositive && confirmRes?.failed) {
            console.warn("[YUNO] SDK reported positive status but backend verification says FAILED. Resetting SDK for retry.");
            resetSdkState();
            startYunoCheckout({ skipPreflight: true });
            return;
          }

          // Positive fallthrough (confirmRes neither ok nor failed) — return silently
          if (isPositive) {
            return;
          }

          // FAILURE: reset SDK and re-mount for a clean retry
          if (isFailure) {
            resetSdkState();
            startYunoCheckout({ skipPreflight: true });
            return;
          }

          // Unknown status fallback
          console.warn("[YUNO] Unknown payment status:", paymentStatus);
        },

        yunoError: async (error, data) => {
          console.error("[YUNO]  yunoError", {
            error,
            data,
            selectedMethod: state.selectedPaymentMethod,
            timestamp: new Date().toISOString()
          });

          yunoInstance?.hideLoader();
          resetSdkState();
          startYunoCheckout({ skipPreflight: true });

        },
      });

      yunoInstance.mountCheckout();
      state.started = true;

      // Show button now that SDK is mounted; onLoading controls disabled state
      setPayButtonVisible(true);
      hideProcessingOverlay();
    } catch (e) {
      console.error("[YUNO] startYunoCheckout error", e);
      hideProcessingOverlay();
    } finally {
      state.starting = false;
    }
  }

  async function handlePayClick(e) {
    const btn = resolvePayButtonTarget(e);
    if (!btn) return;

    // Prevent Woo default submit to avoid double flow
    if (btn.name === "woocommerce_pay" || btn.id === "place_order") {
      e.preventDefault();
      e.stopPropagation();
    }

    if (state.paid) {
      console.warn("[YUNO]  Order already paid. Blocking.");
      return;
    }

    if (!yunoInstance || !state.started) {
      await startYunoCheckout();
    }

    if (!yunoInstance) {
      console.error("[YUNO] yunoInstance is still null. Aborting.");
      return;
    }

    yunoInstance.startPayment();
  }

  // Prevent Enter key from submitting form/triggering payment
  function handleKeyPress(e) {
    if (e.key === "Enter" || e.keyCode === 13) {
      const btn = document.getElementById("yuno-button-pay");

      // Only allow Enter if button is enabled
      if (!btn || btn.disabled) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }

      // If button is enabled, Enter can proceed (will trigger click event)
    }
  }

  // Bind once
  if (!window.__YUNO_BINDINGS__) {
    window.__YUNO_BINDINGS__ = true;
    document.addEventListener("click", handlePayClick);

    // Prevent Enter key from submitting with invalid fields
    document.addEventListener("keydown", handleKeyPress, true);  // true = capture phase
  }

  // Prefer event, keep fallback
  window.addEventListener("yuno-sdk-ready", () => startYunoCheckout());
  setTimeout(() => startYunoCheckout(), 400);

})();
