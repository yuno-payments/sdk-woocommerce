(function() {
  'use strict';

  // Prevent double initialization
  if (window.THIX_YUNO_CHECKOUT_LOADED) {
    console.warn("[YUNO] checkout.js already loaded, skipping re-initialization");
    return;
  }
  window.THIX_YUNO_CHECKOUT_LOADED = true;

  console.log("THIX YUNO checkout.js loaded ", window.THIX_YUNO_WC);

  // Guard: Check if API functions are available
  if (!window.THIX_YUNO_API) {
    console.error("[YUNO] THIX_YUNO_API not found. api.js not loaded.");
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
  } = window.THIX_YUNO_API;

  let yunoInstance = null;

const ctx = window.THIX_YUNO_WC || {};

const state = {
  starting: false,
  started: false,
  paying: false,
  paid: false,

  orderId: Number(ctx.orderId || 0),
  orderKey: String(ctx.orderKey || ""),

  // order-pay context
  payForOrder:
    Boolean(ctx.payForOrder) ||
    (Number(ctx.orderId || 0) > 0 && window.location.href.includes("order-pay")),

  checkoutSession: null,
  countryCode: String(ctx.country || "CO"),

  // Selected payment method (CARD, PSE, NEQUI, etc.)
  selectedPaymentMethod: null,

  // Filled after /payments
  lastPaymentId: null,

  // Render mode: "modal" or "element"
  renderMode: "modal",
};

function setLoaderVisible(visible) {
  const loader = document.getElementById("thix-yuno-loader");
  if (!loader) return;
  loader.style.display = visible ? "block" : "none";
}

function setPayButtonVisible(visible) {
  const btn = document.getElementById("thix-yuno-button-pay");
  if (!btn) return;
  btn.style.display = visible ? "block" : "none";  // block for full width
}

function setPayButtonDisabled(disabled) {
  const btn = document.getElementById("thix-yuno-button-pay");
  if (!btn) return;
  btn.disabled = !!disabled;
  btn.style.opacity = disabled ? "0.5" : "1";
  btn.style.cursor = disabled ? "not-allowed" : "pointer";
  btn.style.backgroundColor = disabled ? "#666666" : "#000000";
}

// Add hover effects to Pay button
function initPayButtonHoverEffects() {
  const btn = document.getElementById("thix-yuno-button-pay");
  if (!btn) return;

  btn.addEventListener("mouseenter", () => {
    if (!btn.disabled) {
      btn.style.backgroundColor = "#333333";  // Lighter on hover
    }
  });

  btn.addEventListener("mouseleave", () => {
    if (!btn.disabled) {
      btn.style.backgroundColor = "#000000";  // Back to black
    }
  });
}

// Initialize hover effects once
setTimeout(() => initPayButtonHoverEffects(), 500);

function resolvePayButtonTarget(e) {
  const t = e.target;

  // Your custom button (order-pay receipt)
  const custom = t.closest?.("#thix-yuno-button-pay");
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
  console.log("[YUNO] Reinitializing with new order", {
    oldOrderId: state.orderId,
    newOrderId,
  });

  // Destroy current SDK instance
  if (yunoInstance) {
    try {
      yunoInstance.unmountCheckout?.();
    } catch (e) {
      console.warn("[YUNO] Error unmounting checkout:", e);
    }
    yunoInstance = null;
  }

  // Update state with new order
  state.orderId = newOrderId;
  state.orderKey = newOrderKey;
  state.started = false;
  state.starting = false;
  state.paying = false;
  state.paid = false;
  state.checkoutSession = null;
  state.lastPaymentId = null;

  // Update visible order information in the UI
  const orderNumberEl = document.getElementById("thix-yuno-order-number");
  const orderTotalEl = document.getElementById("thix-yuno-order-total");

  if (orderNumberEl) {
    orderNumberEl.textContent = newOrderId;
    console.log("[YUNO] Updated order number in UI:", newOrderId);
  }

  if (orderTotalEl && formattedTotal) {
    orderTotalEl.innerHTML = formattedTotal;
    console.log("[YUNO] Updated order total in UI:", formattedTotal);
  }

  // Update browser URL to match new order (prevents reload issues)
  if (payUrl) {
    console.log("[YUNO] Redirecting to new order");
    window.location.href = payUrl;
    return; // Stop execution, page will reload
  }

  // Restart checkout flow (only reached if payUrl is missing)
  await startYunoCheckout();
}

async function startYunoCheckout() {
  if (state.starting || state.started) return;
  state.starting = true;

  try {
    console.log("[YUNO] startYunoCheckout ", {
      payForOrder: state.payForOrder,
      orderId: state.orderId,
    });

    guardContext();

    //  CRITICAL: Check order status first to prevent double payment
    if (state.payForOrder && state.orderId) {
      try {
        const statusRes = await checkOrderStatus({
          orderId: state.orderId,
          orderKey: state.orderKey,
        });

        console.log("[YUNO] check-order-status ", statusRes);

        // If order is already paid, redirect to order-received
        if (statusRes.is_paid && statusRes.redirect) {
          console.log("[YUNO] Order already paid, redirecting to order-received...");
          window.location.href = statusRes.redirect;
          return;
        }

        //  RACE CONDITION FIX: If order has payment_id, redirect immediately to order-received
        // This handles when user clicks "back" before frontend completes /confirm
        // Trust that payment is processing and let order-received show the correct status
        if (statusRes.has_payment_id) {
          console.log("[YUNO] Order has payment in progress, redirecting to order-received...");

          // Build order-received URL (same format as WooCommerce uses)
          const orderReceivedUrl = statusRes.redirect ||
            `${window.location.origin}/checkout/order-received/${state.orderId}/?key=${state.orderKey}`;

          window.location.href = orderReceivedUrl;
          return;
        }

        //  AUTO-DUPLICATE: If order is failed, automatically create new order
        // This handles F5 reload on a failed order
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

              // Redirect to new order (will trigger full page reload)
              await reinitializeWithNewOrder(
                duplicateRes.new_order_id,
                duplicateRes.new_order_key,
                duplicateRes.formatted_total,
                duplicateRes.pay_url
              );
              return; // Stop execution, reinitialize will redirect
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

    // 1) Create checkout session (order-based)
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

    // 2) Public API key
    const publicApiKey = await getPublicApiKey();
    if (!publicApiKey) {
      console.error(" publicApiKey empty. Check /public-api-key");
      return;
    }

    // 3) Initialize SDK
    yunoInstance = await window.Yuno.initialize(publicApiKey);

    let isPayingInsideSdk = false;
    setLoaderVisible(true);

    //  SDK requires explicit render mode type
    // "modal" = opens popup with payment fields
    // "element" = embeds payment fields directly in page
    const RENDER_MODE_TYPE = "modal";
    state.renderMode = RENDER_MODE_TYPE; // Store in state for access elsewhere

    await yunoInstance.startCheckout({
      checkoutSession: state.checkoutSession,
      elementSelector: "#thix-yuno-root",
      countryCode: state.countryCode,
      language: ctx.language || "es", // Use WordPress language, fallback to Spanish
      showLoading: true,
      keepLoader: true,

      onLoading: () => {
        if (!isPayingInsideSdk) setLoaderVisible(false);
      },

      renderMode: {
        type: RENDER_MODE_TYPE,
        elementSelector: {
          apmForm: "#thix-yuno-apm-form",
          actionForm: "#thix-yuno-action-form",
        },
      },

      card: {
        type: "extends",
        styles: "",
        hideCardholderName: false,  // Ensure cardholder name field is shown
        cardholderName: {
          required: true  // Enforce cardholder name validation
        },
        //  CARD VALIDATION: Enable "Pay" button only when card fields are valid
        // Note: In modal mode, this validation is skipped because fields are inside the modal
        onChange: ({ error, data, isDirty }) => {
          console.log("[YUNO]  Card onChange event - DETAILED:", {
            hasError: !!error,
            errorValue: error,
            dataKeys: data ? Object.keys(data) : [],
            fullData: data,
            isDirty,
            selectedMethod: state.selectedPaymentMethod,
            isPaying: state.paying,
            renderMode: RENDER_MODE_TYPE,
            timestamp: new Date().toISOString()
          });

          //  In MODAL mode: only re-enable button when fields are valid
          // Don't disable on validation errors (SDK handles that on submit)
          // Allow this logic even during payment (state.paying=true) to re-enable after user fixes fields
          if (RENDER_MODE_TYPE === "modal") {
            console.log("[YUNO]  Modal mode: checking if should re-enable button");
            // Only re-enable when fields are valid (for retry after error)
            if (!error && (!state.selectedPaymentMethod || state.selectedPaymentMethod === 'CARD')) {
              console.log("[YUNO]  Card fields valid in modal, re-enabling button and resetting state");
              state.paying = false; // Reset paying state so user can click Pay again
              setPayButtonDisabled(false);
            }
            return;
          }

          // Skip validation in ELEMENT mode if payment is in progress
          if (state.paying) {
            console.log("[YUNO]  Payment in progress, skipping element mode validation");
            return;
          }

          //  Apply full validation for ELEMENT mode with CARD method
          if (!state.selectedPaymentMethod || state.selectedPaymentMethod === 'CARD') {
            //  Simplified validation: Trust Yuno SDK primarily

            if (error) {
              console.log("[YUNO] Card validation error");
              setPayButtonDisabled(true);
            } else {
              console.log("[YUNO] Card valid");
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
        console.log("[YUNO]  Payment method selected:", {
          type: data?.type,
          name: data?.name,
          renderMode: RENDER_MODE_TYPE,
          timestamp: new Date().toISOString()
        });

        // Reset payment state when user changes payment method
        // This allows switching between payment methods without getting stuck
        state.paying = false;

        // Store selected method
        state.selectedPaymentMethod = data?.type;

        // If APM (not CARD), enable button immediately
        // APMs don't have field validation like cards
        if (data?.type && data.type !== 'CARD') {
          console.log("[YUNO]  APM selected, enabling button (no validation needed)");
          setPayButtonDisabled(false);
        } else if (data?.type === 'CARD') {
          if (RENDER_MODE_TYPE === "modal") {
            //  MODAL mode: Enable button immediately
            // Fields will be inside the modal (opened after clicking Pay button)
            console.log("[YUNO]  Card selected (modal mode), enabling button to open modal");
            setPayButtonDisabled(false);
          } else {
            //  ELEMENT mode: Disable button until fields are valid
            // Button will be enabled by card.onChange when valid
            console.log("[YUNO]  Card selected (element mode), button will be enabled when fields are valid");
            setPayButtonDisabled(true);
          }
        }
      },

      /**
       * Called by SDK when it has a oneTimeToken
       * Here we call our backend /payments (which calls Yuno API)
       */
      async yunoCreatePayment(oneTimeToken) {
        console.log("[YUNO] yunoCreatePayment called");

        if (state.paid) return;

        state.paying = true;
        isPayingInsideSdk = true;
        setPayButtonDisabled(true);
        setLoaderVisible(true);

        try {
          const payload = {
            oneTimeToken,
            checkoutSession: state.checkoutSession,

            orderId: state.orderId,
            orderKey: state.orderKey,
            order_id: state.orderId,
            order_key: state.orderKey,
          };

          console.log("[YUNO] Creating payment...");

          const paymentRes = await createPayment(payload);

          console.log("[THIX YUNO] /payments split response:", paymentRes?.split || "No split data");

          if (paymentRes?.handled) {
            console.warn("[YUNO] createPayment returned 409 (handled)", paymentRes);
            return;
          }

          //  Source of truth (more reliable than yunoPaymentResult payload)
          state.lastPaymentId = paymentRes?.payment_id || paymentRes?.response?.id || null;

          console.log("[YUNO] createPayment ", paymentRes);
        } catch (e) {
          console.error("[YUNO]  createPayment failed", e);
          state.paying = false;
          setLoaderVisible(false);

          // Re-enable button to allow retry (for both APM and CARD)
          // If card fields are invalid, SDK will show validation errors on next click
          // If card fields are valid, user can retry immediately without modifying fields
          console.log("[YUNO]  Payment error, re-enabling button for retry");
          setPayButtonDisabled(false);

          throw e;
        } finally {
          yunoInstance.continuePayment();
        }
      },

      /**
       * Payment result from SDK UI flow
       * We confirm the Woo order based on what our backend returned from /payments
       */
      yunoPaymentResult: async (result) => {
        console.log("[YUNO] yunoPaymentResult ", result);

        try {
          const paymentId = state.lastPaymentId || null;

          console.log("[YUNO] confirm payload", {
            orderId: state.orderId,
            orderKey: state.orderKey,
            paymentId,
          });

          if (state.payForOrder && state.orderId) {
            //  SECURITY: Only send payment_id, backend verifies status with Yuno API
            const confirmRes = await confirmOrder({
              // both formats (defensive)
              orderId: state.orderId,
              orderKey: state.orderKey,
              order_id: state.orderId,
              order_key: state.orderKey,

              paymentId,
              payment_id: paymentId,
            });

            console.log("[YUNO] confirmOrder ", confirmRes);

            //  Backend verifies with Yuno API and updates order status
            if (confirmRes?.ok) {
              state.paid = true;
              setPayButtonDisabled(true);

              // Direct redirect to order-received (no reload to avoid interrupting SDK modal)
              if (confirmRes.redirect) {
                console.log("[YUNO] Payment confirmed, redirecting to order-received...");
                window.location.href = confirmRes.redirect;
                return;
              }

              // Fallback: reload if no redirect URL provided
              console.log("[YUNO] Payment confirmed but no redirect, reloading...");
              window.location.reload();
              return;
            }

            // Payment failed => create new order and reinitialize
            if (confirmRes?.failed) {
              console.log("[YUNO] Payment failed, creating new order...");

              try {
                const duplicateRes = await duplicateOrder({
                  orderId: state.orderId,
                  orderKey: state.orderKey,
                });

                if (duplicateRes?.ok && duplicateRes?.new_order_id) {
                  console.log("[YUNO] New order created:", duplicateRes.new_order_id);

                  // Hide SDK loader before reinitializing
                  setLoaderVisible(false);
                  yunoInstance.hideLoader();

                  // Reinitialize with new order (Option B: no redirect, same page)
                  await reinitializeWithNewOrder(
                    duplicateRes.new_order_id,
                    duplicateRes.new_order_key,
                    duplicateRes.formatted_total,
                    duplicateRes.pay_url
                  );

                  console.log("[YUNO] Checkout reinitialized with new order. User can retry payment.");
                  return;
                }
              } catch (e) {
                console.error("[YUNO] Failed to create new order", e);
              }

              // Fallback: if duplication fails, just allow retry on same order
              console.log("[YUNO] Fallback: allowing retry on same order");
              state.paying = false;
              setPayButtonDisabled(false);
              setLoaderVisible(false);
              yunoInstance.hideLoader();
              return;
            }

            // Other errors => allow retry
            state.paying = false;
            setPayButtonDisabled(false);
          }
        } catch (e) {
          console.error("[YUNO] confirmOrder error", e);
          state.paying = false;
          setPayButtonDisabled(false);
        } finally {
          setLoaderVisible(false);
          yunoInstance.hideLoader();
        }
      },

      yunoError: (error) => {
        console.error("[YUNO]  yunoError", {
          error,
          selectedMethod: state.selectedPaymentMethod,
          timestamp: new Date().toISOString()
        });

        state.paying = false;
        setLoaderVisible(false);
        yunoInstance.hideLoader();

        // Re-enable button to allow retry (for both APM and CARD)
        // If card fields are invalid, SDK will show validation errors on next click
        // If card fields are valid, user can retry immediately without modifying fields
        console.log("[YUNO]  Payment error, re-enabling button for retry");
        setPayButtonDisabled(false);
      },
    });

    yunoInstance.mountCheckout();
    state.started = true;

    //  Show button now that SDK is mounted, but keep it disabled until card fields are valid
    setPayButtonVisible(true);
    setPayButtonDisabled(true);

    console.log("[YUNO] SDK mounted successfully", {
      country: state.countryCode
    });
  } catch (e) {
    console.error("[YUNO] startYunoCheckout error", e);
  } finally {
    state.starting = false;
  }
}

async function handlePayClick(e) {
  const btn = resolvePayButtonTarget(e);
  if (!btn) return;

  console.log("[YUNO]  Pay button clicked", {
    buttonId: btn.id,
    buttonName: btn.name,
    disabled: btn.disabled,
    timestamp: new Date().toISOString()
  });

  // Prevent Woo default submit to avoid double flow
  if (btn.name === "woocommerce_pay" || btn.id === "place_order") {
    e.preventDefault();
    e.stopPropagation();
  }

  //  Check if button is actually disabled (defensive check)
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

  console.log("[YUNO]  CLICK pay - proceeding");

  if (!yunoInstance || !state.started) {
    await startYunoCheckout();
  }

  if (!yunoInstance) {
    console.error("[YUNO] yunoInstance is still null. Aborting.");
    return;
  }

  //  MODAL MODE: Don't disable button on click
  // The SDK will handle validation inside the modal
  // Button will be disabled in yunoCreatePayment when payment actually starts
  // This prevents blocking the user if SDK shows validation error without calling yunoCreatePayment
  if (state.renderMode === "modal") {
    console.log("[YUNO]  Modal mode: button stays enabled, SDK will validate inside modal");
    // Don't set state.paying or disable button here
  } else {
    //  ELEMENT MODE: Disable button immediately
    // Fields are on the page, so validation is already done
    console.log("[YUNO]  Element mode: disabling button to prevent double-click");
    state.paying = true;
    setPayButtonDisabled(true);
  }

  console.log("[YUNO] Starting payment");
  yunoInstance.startPayment();
}

// Prevent Enter key from submitting form/triggering payment
function handleKeyPress(e) {
  if (e.key === "Enter" || e.keyCode === 13) {
    console.log("[YUNO]  Enter key pressed, checking if should allow...");

    const btn = document.getElementById("thix-yuno-button-pay");

    // Only allow Enter if button is enabled
    if (!btn || btn.disabled || state.paying) {
      console.log("[YUNO]  Blocking Enter key (button disabled or payment in progress)");
      e.preventDefault();
      e.stopPropagation();
      return false;
    }

    // If button is enabled, Enter can proceed (will trigger click event)
    console.log("[YUNO]  Enter key allowed (button is enabled)");
  }
}

// Bind once
if (!window.__THIX_YUNO_BINDINGS__) {
  window.__THIX_YUNO_BINDINGS__ = true;
  document.addEventListener("click", handlePayClick);

  //  Prevent Enter key from submitting with invalid fields
  document.addEventListener("keydown", handleKeyPress, true);  // true = capture phase
  document.addEventListener("keypress", handleKeyPress, true);  // Backup for older browsers
}

// Prefer event, keep fallback
window.addEventListener("yuno-sdk-ready", () => startYunoCheckout());
setTimeout(() => startYunoCheckout(), 400);

})();
