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
    confirmOrder,
    checkOrderStatus,
    duplicateOrder,
  } = window.YUNO_API;

  let yunoInstance = null;

  const order_ctx = window.YUNO_WC || {};

  const state = {
    starting: false,
    started: false,
    paid: false,

    orderId: Number(order_ctx.orderId || 0),
    orderKey: order_ctx.orderKey || "",

    checkoutSession: null,
    selectedPaymentMethod: null,
  };

  const PAYMENT_STATUS = {
    SUCCEEDED: 'SUCCEEDED',
    VERIFIED:  'VERIFIED',
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

  const SUCCESS_STATUSES  = [PAYMENT_STATUS.SUCCEEDED, PAYMENT_STATUS.VERIFIED, PAYMENT_STATUS.PAYED];
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

      console.log('[YUNO] runPreflightChecks → statusRes:', statusRes);

      // Redirect whenever the backend provides a redirect URL (covers paid, failed-to-receive, etc.)
      if (statusRes.redirect) {
        window.location.href = statusRes.redirect;
        return false;
      }

      // If order payment previously failed, auto-duplicate so the user can retry
      const hasFailed = statusRes.should_duplicate ||
                        statusRes.is_failed ||
                        (statusRes.verified_status && FAILURE_STATUSES.includes(statusRes.verified_status));

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
            window.location.href = duplicateRes.pay_url;
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

  async function startYunoCheckout() {
    if (state.starting || state.started) return;
    state.starting = true;

    try {
      // Run pre-flight checks before initializing SDK and creating sessions. This handles edge cases like:
      // - User reloads page after failed payment (auto-duplicate)
      // - Order is already paid or has a redirect URL (redirect immediately)
      const shouldContinue = await runPreflightChecks();
      if (!shouldContinue) return;

      // Wait for SDK to be available (prevents init too early)
      const isSdkAvailable = await waitForYunoSdk();
      if (!isSdkAvailable) {
        console.error("[YUNO] SDK not available (Yuno.initialize missing). Check yuno-sdk script.");
        return;
      }

      // Create or get existing customer ID for the order
      const customerId = await createCustomer({
        orderId: state.orderId,
        orderKey: state.orderKey,
      });

      // Create checkout session
      const sessionRes = await getCheckoutSession({
        orderId: state.orderId,
        orderKey: state.orderKey,
        customer_id: customerId,
      });

      const checkoutSession = sessionRes?.checkout_session;
      
      if (!checkoutSession) {
        console.error("Failed to create checkout session");
        return;
      }

      const publicApiKey = await getPublicApiKey();

      if (!publicApiKey) {
        console.error(" publicApiKey empty. Check /public-api-key");
        return;
      }

      yunoInstance = await window.Yuno.initialize(publicApiKey);

      const RENDER_MODE_TYPE = "modal";

      const countryCode = String(sessionRes?.country || order_ctx.country || "CO");

      console.log("[YUNO] startSeamlessCheckout →", {
        checkoutSession: checkoutSession,
        countryCode: countryCode,
        renderMode: RENDER_MODE_TYPE,
      });


      await yunoInstance.startSeamlessCheckout({
        checkoutSession: checkoutSession,
        elementSelector: "#yuno-root",
        countryCode: countryCode,
        language: order_ctx.language || "es", // Use WordPress language, fallback to Spanish
        showLoading: true, // Disable SDK loader to prevent "One moment please" flash
        issuersFormEnable: true,
        showPaymentStatus: true,
        onLoading: async ({ isLoading, type }) => {
          console.log("[YUNO] onLoading →", { isLoading, type });

          // PAYMENT_RETRY: SDK signals that payment failed and a retry is needed.
          // Duplicate the order first to avoid inconsistent WooCommerce statuses.
          if (type === 'PAYMENT_RETRY') {
            setPayButtonDisabled(true);
            try {
              const duplicateRes = await duplicateOrder({
                orderId: state.orderId,
                orderKey: state.orderKey,
              });
              if (duplicateRes?.ok && duplicateRes?.new_order_id) {
                console.log("[YUNO] PAYMENT_RETRY — new order created:", duplicateRes.new_order_id);
                window.location.href = duplicateRes.pay_url;
                return;
              }
            } catch (e) {
              console.error("[YUNO] PAYMENT_RETRY — failed to duplicate order", e);
            }
            return;
          }

          if (isLoading || type === 'ONE_TIME_TOKEN' || type === 'CREATE_PAYMENT') {
            setPayButtonDisabled(true);
          } else {
            setPayButtonDisabled(false);
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
          onChange: ({ error, data, isDirty }) => {
            console.log("[YUNO] card.onChange →", { error, data, isDirty });
          }
        },
        /**
         * Called when user selects a payment method
         * In seamless SDK, must call mountSeamlessCheckout with the selected type
         */
        yunoPaymentMethodSelected: (data) => {
          console.log("[YUNO] yunoPaymentMethodSelected →", data);

          state.selectedPaymentMethod = data?.type;

          // Show Pay button now that a method is selected
          setPayButtonVisible(true);
        },

        /**
         * Payment result from SDK UI flow (Seamless SDK handles payment internally)
         * result is a status string: "SUCCEEDED", "PENDING", "REJECTED", etc.
         * We confirm the Woo order by verifying with our backend.
         */
        yunoPaymentResult: async (result) => {
          // result is a plain status string from the Yuno SDK
          const paymentStatus = typeof result === 'string' ? result : String(result || '');
          console.log("[YUNO] yunoPaymentResult →", paymentStatus);

          // ── PENDING (3DS / async flow in progress) ─────────────────────────
          // Do NOT show the full-page overlay — the 3DS modal must remain visible.
          if (PENDING_STATUSES.includes(paymentStatus)) {
            console.log("[YUNO] Payment PENDING — staying on page for 3DS/authentication flow...");
            try {
              const confirmRes = await confirmOrder({
                orderId: state.orderId,
                orderKey: state.orderKey,
                paymentStatus,
              });
              if (confirmRes?.pending) {
                console.log("[YUNO] Backend confirmed PENDING, SDK continues handling flow");
                return;
              }
              // If backend unexpectedly returns ok+redirect, honour it
              if (confirmRes?.ok && confirmRes?.redirect) {
                window.location.href = confirmRes.redirect;
                return;
              }
            } catch (e) {
              console.error("[YUNO] confirmOrder error (PENDING)", e);
            }
            return;
          }

          // Show full-page loader for SUCCESS and FAILURE statuses
          document.body.innerHTML = `
            <div style="
              position: fixed;
              top: 0;
              left: 0;
              width: 100%;
              height: 100%;
              background: #f7f7f7;
              z-index: 9999;
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
            <div id="yuno-checkout"></div>
          `;

          // ── SUCCESS (SUCCEEDED or VERIFIED) ────────────────────────────────
          if (SUCCESS_STATUSES.includes(paymentStatus)) {
            try {
              const confirmRes = await confirmOrder({
                orderId: state.orderId,
                orderKey: state.orderKey,
                paymentStatus,
              });

              if (confirmRes?.ok) {
                if (confirmRes.pending) {
                  console.log("[YUNO] Payment is PENDING, staying on page for async flow...");
                  return;
                }
                state.paid = true;
                if (confirmRes.redirect) {
                  window.location.href = confirmRes.redirect;
                  return;
                }
                window.location.reload();
                return;
              }

              if (confirmRes?.failed) {
                try {
                  const duplicateRes = await duplicateOrder({
                    orderId: state.orderId,
                    orderKey: state.orderKey,
                  });
                  if (duplicateRes?.ok && duplicateRes?.new_order_id) {
                    console.log("[YUNO] New order created:", duplicateRes.new_order_id);
                    window.location.href = duplicateRes.pay_url;
                    return;
                  }
                } catch (e) {
                  console.error("[YUNO] Failed to create new order", e);
                }
                return;
              }
            } catch (e) {
              console.error("[YUNO] confirmOrder error (SUCCESS)", e);
            }
            return;
          }

          // ── FAILURE (REJECTED, DECLINED, CANCELED, ERROR, EXPIRED) ─────────
          if (FAILURE_STATUSES.includes(paymentStatus)) {
            try {
              // Notify backend so it can mark the order as failed
              try {
                await confirmOrder({
                  orderId: state.orderId,
                  orderKey: state.orderKey,
                  paymentStatus,
                });
              } catch (e) {
                console.warn("[YUNO] confirmOrder error (FAILURE, continuing with duplicate)", e);
              }

              try {
                const duplicateRes = await duplicateOrder({
                  orderId: state.orderId,
                  orderKey: state.orderKey,
                });
                if (duplicateRes?.ok && duplicateRes?.new_order_id) {
                  console.log("[YUNO] New order created:", duplicateRes.new_order_id);
                  window.location.href = duplicateRes.pay_url;
                  return;
                }
              } catch (e) {
                console.error("[YUNO] Failed to create new order", e);
              }

            } catch (e) {
              console.error("[YUNO] Error handling FAILURE status", e);
            }
            return;
          }

          // Unknown status — log a warning, allow retry
          console.warn("[YUNO] Unknown payment status:", paymentStatus);
        },

        yunoError: async (error, data) => {
          console.error("[YUNO]  yunoError", {
            error,
            data,
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
                window.location.href = duplicateRes.pay_url;
                return;
              }
            } catch (e) {
              console.error("[YUNO] Failed to create new order after cancellation", e);
              // Fallback: reload page to restore payment form
              window.location.reload();
              return;
            }
          }

          console.log("[YUNO] hideLoader (yunoError)");
          yunoInstance?.hideLoader();
        },

        // Modal events: track 3DS and other modal interactions
        yunoModalOpened: (data) => {
          console.log("[YUNO] yunoModalOpened →", data);
        },

        yunoModalClosed: (data) => {
          console.log("[YUNO] yunoModalClosed →", data);
        },
      });

      yunoInstance.mountSeamlessCheckout();
      state.started = true;

      // Show button now that SDK is mounted; onLoading controls disabled state
      setPayButtonVisible(true);
      console.log("[YUNO] startSeamlessCheckout ✓ — checkout mounted");
    } catch (e) {
      console.error("[YUNO] startYunoCheckout error", e);
    } finally {
      state.starting = false;
    }
  }

  async function handlePayClick(e) {
    console.log('[YUNO] handlePayClick →', { target: e.target });
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
    document.addEventListener("keypress", handleKeyPress, true);  // Backup for older browsers
  }

  // Prefer event, keep fallback
  window.addEventListener("yuno-sdk-ready", () => startYunoCheckout());
  setTimeout(() => startYunoCheckout(), 400);

})();
