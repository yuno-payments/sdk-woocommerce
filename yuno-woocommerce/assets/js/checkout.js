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
    createPayment,
    getPublicApiKey,
    confirmOrder,
    checkOrderStatus,
    duplicateOrder,
  } = window.YUNO_API;

  let yunoInstance = null;

const ctx = window.YUNO_WC || {};

const state = {
  starting: false,
  started: false,
  paying: false,
  paid: false,

  orderId: Number(ctx.orderId || 0),
  orderKey: String(ctx.orderKey || ""),

  payForOrder:
    Boolean(ctx.payForOrder) ||
    (Number(ctx.orderId || 0) > 0 && window.location.href.includes("order-pay")),

  checkoutSession: null,
  countryCode: String(ctx.country || "CO"),
  selectedPaymentMethod: null,
  lastPaymentId: null,
  renderMode: "modal",
};

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

function initPayButtonHoverEffects() {
  const btn = document.getElementById("yuno-button-pay");
  if (!btn) return;

  btn.addEventListener("mouseenter", () => {
    if (!btn.disabled) {
      btn.style.backgroundColor = "#333333";
    }
  });

  btn.addEventListener("mouseleave", () => {
    if (!btn.disabled) {
      btn.style.backgroundColor = "#000000";
    }
  });
}

setTimeout(() => initPayButtonHoverEffects(), 500);

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

function guardContext() {
  if (state.payForOrder) {
    if (!state.orderId) throw new Error("[YUNO] Missing orderId in order-pay context.");
    if (!state.orderKey) console.warn("[YUNO] orderKey empty (recommended).");
  }
}

/**
 * Wait until SDK is available (prevents init too early)
 */
async function waitForYunoSdk(maxMs = 6000) {
  const start = Date.now();
  while (Date.now() - start < maxMs) {
    if (window.Yuno && typeof window.Yuno.initialize === "function") return true;
    await new Promise((r) => setTimeout(r, 100));
  }
  return false;
}

/**
 * Reinitialize checkout with a new order (after failed payment)
 * Destroys current SDK instance and creates new one with new order
 */
async function reinitializeWithNewOrder(newOrderId, newOrderKey, formattedTotal, payUrl) {
  if (yunoInstance) {
    try {
      yunoInstance.unmountCheckout?.();
    } catch (e) {
      console.warn("[YUNO] Error unmounting checkout:", e);
    }
    yunoInstance = null;
  }

  state.orderId = newOrderId;
  state.orderKey = newOrderKey;
  state.started = false;
  state.starting = false;
  state.paying = false;
  state.paid = false;
  state.checkoutSession = null;
  state.lastPaymentId = null;

  // Update visible order information in the UI
  const orderNumberEl = document.getElementById("yuno-order-number");
  const orderTotalEl = document.getElementById("yuno-order-total");

  if (orderNumberEl) {
    orderNumberEl.textContent = newOrderId;
  }

  if (orderTotalEl && formattedTotal) {
    orderTotalEl.innerHTML = formattedTotal;
  }

  // Update browser URL to match new order (prevents reload issues)
  if (payUrl) {
    console.log("[YUNO] Redirecting to new order");
    window.location.href = payUrl;
    return;
  }

  // Restart checkout flow (only reached if payUrl is missing)
  await startYunoCheckout();
}

async function startYunoCheckout() {
  if (state.starting || state.started) return;
  state.starting = true;

  try {
    guardContext();

    // Check order status first to prevent double payment
    if (state.payForOrder && state.orderId) {
      try {
        const statusRes = await checkOrderStatus({
          orderId: state.orderId,
          orderKey: state.orderKey,
        });

        // Only redirect if order is verified as paid OR backend explicitly provides redirect URL
        // Do NOT redirect on unverified payment (when Yuno API verification fails)
        if (statusRes.is_paid && statusRes.redirect) {
          window.location.href = statusRes.redirect;
          return;
        }

        // Redirect only if backend explicitly provides a redirect URL (verified payment state)
        // This prevents premature redirect when payment status is UNKNOWN due to API failure
        if (statusRes.redirect && !statusRes.is_paid) {
          window.location.href = statusRes.redirect;
          return;
        }

        // If order is failed, automatically create new order (handles F5 reload)
        const failedStatuses = ['REJECTED', 'DECLINED', 'CANCELLED', 'ERROR', 'EXPIRED', 'FAILED'];
        const hasFailed = statusRes.should_duplicate ||
                         statusRes.is_failed ||
                         (statusRes.verified_status && failedStatuses.includes(statusRes.verified_status));

        if (hasFailed) {
          console.log("[YUNO] Order/payment is failed, auto-duplicating...", {
            status: statusRes.status,
            verified_status: statusRes.verified_status,
          });

          try {
            const duplicateRes = await duplicateOrder({
              orderId: state.orderId,
              orderKey: state.orderKey,
            });

            if (duplicateRes?.ok && duplicateRes?.new_order_id) {
              console.log("[YUNO] New order created:", duplicateRes.new_order_id);

              await reinitializeWithNewOrder(
                duplicateRes.new_order_id,
                duplicateRes.new_order_key,
                duplicateRes.formatted_total,
                duplicateRes.pay_url
              );
              return;
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
    }

    const ok = await waitForYunoSdk();
    if (!ok) {
      console.error("[YUNO] SDK not available (Yuno.initialize missing). Check yuno-sdk script.");
      return;
    }

    const sessionRes = await getCheckoutSession({
      // send both styles for backend robustness
      orderId: state.orderId,
      orderKey: state.orderKey,
      order_id: state.orderId,
      order_key: state.orderKey,
    });

    const checkoutSession = sessionRes?.checkout_session;
    const country = sessionRes?.country;

    if (!checkoutSession) {
      console.error("Failed to create checkout session");
      return;
    }

    state.checkoutSession = checkoutSession;
    state.countryCode = String(country || state.countryCode || "CO");

    const publicApiKey = await getPublicApiKey();
    if (!publicApiKey) {
      console.error(" publicApiKey empty. Check /public-api-key");
      return;
    }

    yunoInstance = await window.Yuno.initialize(publicApiKey);

    const RENDER_MODE_TYPE = "modal";
    state.renderMode = RENDER_MODE_TYPE;

    await yunoInstance.startCheckout({
      checkoutSession: state.checkoutSession,
      elementSelector: "#yuno-root",
      countryCode: state.countryCode,
      language: ctx.language || "es", // Use WordPress language, fallback to Spanish
      showLoading: false, // Disable SDK loader to prevent "One moment please" flash
      keepLoader: false,

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
        // Card validation: controls "Pay" button state based on field validity
        onChange: ({ error, data, isDirty }) => {
          // Modal mode: SDK handles validation, only re-enable button when valid
          if (RENDER_MODE_TYPE === "modal") {
            // Prevent race condition: don't reset state.paying during active payment
            if (state.paying) {
              return;
            }

            // Only re-enable when fields are valid (for retry after error)
            if (!error && (!state.selectedPaymentMethod || state.selectedPaymentMethod === 'CARD')) {
              setPayButtonDisabled(false);
            }
            return;
          }

          // Skip validation in ELEMENT mode if payment is in progress
          if (state.paying) {
            return;
          }

          // Element mode: validate card fields
          if (!state.selectedPaymentMethod || state.selectedPaymentMethod === 'CARD') {
            if (error) {
              setPayButtonDisabled(true);
            } else {
              setPayButtonDisabled(false);
            }
          }
        }
      },

      /**
       * Called when user selects a payment method
       * Handles different logic for modal vs element mode
       */
      yunoPaymentMethodSelected: (data) => {
        // Reset payment state when user changes payment method
        // This allows switching between payment methods without getting stuck
        state.paying = false;

        state.selectedPaymentMethod = data?.type;

        // If APM (not CARD), enable button immediately
        // APMs don't have field validation like cards
        if (data?.type && data.type !== 'CARD') {
          setPayButtonDisabled(false);
        } else if (data?.type === 'CARD') {
          if (RENDER_MODE_TYPE === "modal") {
            // MODAL mode: Enable button immediately
            // Fields will be inside the modal (opened after clicking Pay button)
            setPayButtonDisabled(false);
          } else {
            // ELEMENT mode: Disable button until fields are valid
            // Button will be enabled by card.onChange when valid
            setPayButtonDisabled(true);
          }
        }
      },

      /**
       * Called by SDK when it has a oneTimeToken
       * Here we call our backend /payments (which calls Yuno API)
       */
      async yunoCreatePayment(oneTimeToken) {
        if (state.paid) return;

        state.paying = true;
        setPayButtonDisabled(true);

        // Show loader as overlay WITHOUT destroying DOM (allows error recovery)
        let loaderOverlay = document.createElement('div');
        loaderOverlay.id = 'yuno-payment-loader';
        loaderOverlay.innerHTML = `
          <div style="
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
          ">
            <div style="text-align: center;">
              <div style="
                border: 4px solid #f3f3f3;
                border-top: 4px solid #000;
                border-radius: 50%;
                width: 48px;
                height: 48px;
                animation: spin 1s linear infinite;
                margin: 0 auto 24px;
              "></div>
              <div style="font-size: 18px; font-weight: 600; color: #000; margin-bottom: 8px;">
                Processing payment...
              </div>
              <div style="font-size: 14px; color: #666;">
                Please wait
              </div>
            </div>
          </div>
          <style>
            @keyframes spin {
              0% { transform: rotate(0deg); }
              100% { transform: rotate(360deg); }
            }
          </style>
        `;
        document.body.appendChild(loaderOverlay);

        let paymentRes; // Declare outside try-catch to access in finally block
        try {
          const payload = {
            oneTimeToken,
            checkoutSession: state.checkoutSession,

            orderId: state.orderId,
            orderKey: state.orderKey,
            order_id: state.orderId,
            order_key: state.orderKey,
          };

          paymentRes = await createPayment(payload);

          if (paymentRes?.handled) {
            console.warn("[YUNO] createPayment returned 409 (handled)", paymentRes);
            return;
          }

          // Source of truth (more reliable than yunoPaymentResult payload)
          state.lastPaymentId = paymentRes?.payment_id || paymentRes?.response?.id || null;
        } catch (e) {
          console.error("[YUNO]  createPayment failed", e);
          state.paying = false;

          const overlay = document.getElementById('yuno-payment-loader');
          if (overlay) overlay.remove();

          // Re-enable button to allow retry (for both APM and CARD)
          // If card fields are invalid, SDK will show validation errors on next click
          // If card fields are valid, user can retry immediately without modifying fields
          setPayButtonDisabled(false);

          throw e;
        } finally {
          // Always call continuePayment to let SDK render its UI (3DS, PENDING, etc.)
          yunoInstance.continuePayment();
        }
      },

      /**
       * Payment result from SDK UI flow
       * We confirm the Woo order based on what our backend returned from /payments
       */
      yunoPaymentResult: async (result) => {
        try {
          const paymentId = state.lastPaymentId || null;

          if (state.payForOrder && state.orderId) {
            // Only send payment_id, backend verifies status with Yuno API
            const confirmRes = await confirmOrder({
              // both formats (defensive)
              orderId: state.orderId,
              orderKey: state.orderKey,
              order_id: state.orderId,
              order_key: state.orderKey,

              paymentId,
              payment_id: paymentId,
            });

            // Backend verifies with Yuno API and updates order status
            if (confirmRes?.ok) {
              // For PENDING payments (3DS, etc.), don't redirect
              // Let SDK handle the flow, webhook will confirm when payment completes
              if (confirmRes.pending) {
                console.log("[YUNO] Payment is PENDING, staying on page for 3DS/authentication flow...");
                return;
              }

              // Payment confirmed (SUCCEEDED, VERIFIED, APPROVED)
              state.paid = true;
              setPayButtonDisabled(true);

              // Direct redirect to order-received (no reload to avoid interrupting SDK modal)
              if (confirmRes.redirect) {
                window.location.href = confirmRes.redirect;
                return;
              }

              // Fallback: reload if no redirect URL provided
              window.location.reload();
              return;
            }

            // Payment failed => create new order and reinitialize
            if (confirmRes?.failed) {
              try {
                const duplicateRes = await duplicateOrder({
                  orderId: state.orderId,
                  orderKey: state.orderKey,
                });

                if (duplicateRes?.ok && duplicateRes?.new_order_id) {
                  console.log("[YUNO] New order created:", duplicateRes.new_order_id);

                  await reinitializeWithNewOrder(
                    duplicateRes.new_order_id,
                    duplicateRes.new_order_key,
                    duplicateRes.formatted_total,
                    duplicateRes.pay_url
                  );
                  return;
                }
              } catch (e) {
                console.error("[YUNO] Failed to create new order", e);
              }

              // Fallback: if duplication fails, just allow retry on same order
              state.paying = false;
              setPayButtonDisabled(false);
              return;
            }

            state.paying = false;
            setPayButtonDisabled(false);
          }
        } catch (e) {
          console.error("[YUNO] confirmOrder error", e);
          state.paying = false;
          setPayButtonDisabled(false);
        }
      },

      yunoError: async (error) => {
        console.error("[YUNO]  yunoError", {
          error,
          selectedMethod: state.selectedPaymentMethod,
          timestamp: new Date().toISOString()
        });

        // Handle 3DS modal cancellation (user closed modal without completing)
        // When user closes 3DS modal, page is stuck on "Processing payment..." loader
        // Solution: Create new order and redirect (same as payment failure flow)
        if (error === 'CANCELED_BY_USER') {
          try {
            const duplicateRes = await duplicateOrder({
              orderId: state.orderId,
              orderKey: state.orderKey,
            });

            if (duplicateRes?.ok && duplicateRes?.new_order_id) {
              console.log("[YUNO] New order created after cancellation:", duplicateRes.new_order_id);

              // Redirect to new order (will reload page and restore payment form)
              await reinitializeWithNewOrder(
                duplicateRes.new_order_id,
                duplicateRes.new_order_key,
                duplicateRes.formatted_total,
                duplicateRes.pay_url
              );
              return;
            }
          } catch (e) {
            console.error("[YUNO] Failed to create new order after cancellation", e);
            // Fallback: reload page to restore payment form
            window.location.reload();
            return;
          }
        }

        state.paying = false;

        // Re-enable button to allow retry (for both APM and CARD)
        // If card fields are invalid, SDK will show validation errors on next click
        // If card fields are valid, user can retry immediately without modifying fields
        setPayButtonDisabled(false);
      },

      // Modal events: track 3DS and other modal interactions
      yunoModalOpened: (data) => {
      },

      yunoModalClosed: (data) => {
        // Handle 3DS modal close without completion
        // If user closes 3DS modal, reset payment state to allow retry
        if (state.paying) {
          state.paying = false;
          setPayButtonDisabled(false);
        }
      },

      yunoLoading: (isLoading) => {
      },
    });

    yunoInstance.mountCheckout();
    state.started = true;

    // Show button now that SDK is mounted, but keep it disabled until card fields are valid
    setPayButtonVisible(true);
    setPayButtonDisabled(true);
  } catch (e) {
    console.error("[YUNO] startYunoCheckout error", e);
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

  // Check if button is actually disabled (defensive check)
  if (btn.disabled) {
    console.warn("[YUNO]  Button is disabled. Blocking click.");
    e.preventDefault();
    e.stopPropagation();
    return;
  }

  if (state.paid) {
    console.warn("[YUNO]  Order already paid. Blocking.");
    return;
  }

  if (state.paying) {
    console.warn("[YUNO]  Payment in progress. Blocking double click.");
    return;
  }

  if (!yunoInstance || !state.started) {
    await startYunoCheckout();
  }

  if (!yunoInstance) {
    console.error("[YUNO] yunoInstance is still null. Aborting.");
    return;
  }

  // MODAL MODE: Don't disable button on click
  // The SDK will handle validation inside the modal
  // Button will be disabled in yunoCreatePayment when payment actually starts
  // This prevents blocking the user if SDK shows validation error without calling yunoCreatePayment
  if (state.renderMode === "modal") {
    // Don't set state.paying or disable button here
    // Don't show loader here - wait for SDK to validate fields first
    // Loader will be shown in yunoCreatePayment only if fields are valid
  } else {
    // ELEMENT MODE: Disable button immediately
    // Fields are on the page, so validation is already done
    state.paying = true;
    setPayButtonDisabled(true);
  }

  yunoInstance.startPayment();
}

// Prevent Enter key from submitting form/triggering payment
function handleKeyPress(e) {
  if (e.key === "Enter" || e.keyCode === 13) {
    const btn = document.getElementById("yuno-button-pay");

    // Only allow Enter if button is enabled
    if (!btn || btn.disabled || state.paying) {
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
  document.addEventListener("keypress", handleKeyPress, true);  // Backup for older browsers
}

// Prefer event, keep fallback
window.addEventListener("yuno-sdk-ready", () => startYunoCheckout());
setTimeout(() => startYunoCheckout(), 400);

})();
