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

const PAYMENT_STATUS = {
  SUCCEEDED: 'SUCCEEDED',
  VERIFIED:  'VERIFIED',
  PAYED:     'PAYED',
  PENDING:   'PENDING',
  REJECTED:  'REJECTED',
  DECLINED:  'DECLINED',
  CANCELED:  'CANCELED',
  ERROR:     'ERROR',
  EXPIRED:   'EXPIRED',
};

const SUCCESS_STATUSES  = [PAYMENT_STATUS.SUCCEEDED, PAYMENT_STATUS.VERIFIED, PAYMENT_STATUS.PAYED];
const PENDING_STATUSES  = [PAYMENT_STATUS.PENDING];
const FAILURE_STATUSES  = [PAYMENT_STATUS.REJECTED, PAYMENT_STATUS.DECLINED,
                           PAYMENT_STATUS.CANCELED, PAYMENT_STATUS.ERROR,
                           PAYMENT_STATUS.EXPIRED];

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

    // Create Yuno customer before checkout session (customer_id required by checkout session API)
    let customerId = null;
    if (state.payForOrder && state.orderId) {
      try {
        customerId = await createCustomer({
          orderId: state.orderId,
          orderKey: state.orderKey,
        });
        console.log('[YUNO] createCustomer →', customerId ?? 'null (graceful)');
      } catch (e) {
        console.warn('[YUNO] createCustomer error (continuing without customer_id):', e);
      }
    }

    console.log('customerId', customerId);

    const sessionRes = await getCheckoutSession({
      orderId: state.orderId,
      orderKey: state.orderKey,
      customer_id: customerId,
    });

    console.log('sessionRes', sessionRes);

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

    console.log("[YUNO] startSeamlessCheckout →", {
      checkoutSession: state.checkoutSession,
      countryCode: state.countryCode,
      renderMode: RENDER_MODE_TYPE,
    });

    await yunoInstance.startSeamlessCheckout({
      checkoutSession: state.checkoutSession,
      elementSelector: "#yuno-root",
      countryCode: state.countryCode,
      language: ctx.language || "es", // Use WordPress language, fallback to Spanish
      showLoading: true, // Disable SDK loader to prevent "One moment please" flash
      issuersFormEnable: true,
      showPaymentStatus: true,
      onLoading: (isLoading) => {
        console.log("[YUNO] onLoading →", isLoading);
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
       * In seamless SDK, must call mountSeamlessCheckout with the selected type
       */
      yunoPaymentMethodSelected: (data) => {
        console.log("[YUNO] yunoPaymentMethodSelected →", data);

        // Reset payment state when user changes payment method
        // This allows switching between payment methods without getting stuck
        state.paying = false;

        state.selectedPaymentMethod = data?.type;

        // Show Pay button now that a method is selected
        setPayButtonVisible(true);

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
            if (state.payForOrder && state.orderId) {
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
            if (state.payForOrder && state.orderId) {
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
                setPayButtonDisabled(true);
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
                state.paying = false;
                setPayButtonDisabled(false);
                return;
              }

              state.paying = false;
              setPayButtonDisabled(false);
            }
          } catch (e) {
            console.error("[YUNO] confirmOrder error (SUCCESS)", e);
            state.paying = false;
            setPayButtonDisabled(false);
          }
          return;
        }

        // ── FAILURE (REJECTED, DECLINED, CANCELED, ERROR, EXPIRED) ─────────
        if (FAILURE_STATUSES.includes(paymentStatus)) {
          try {
            if (state.payForOrder && state.orderId) {
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

              state.paying = false;
              setPayButtonDisabled(false);
            }
          } catch (e) {
            console.error("[YUNO] Error handling FAILURE status", e);
            state.paying = false;
            setPayButtonDisabled(false);
          }
          return;
        }

        // Unknown status — log a warning, allow retry
        console.warn("[YUNO] Unknown payment status:", paymentStatus);
        state.paying = false;
        setPayButtonDisabled(false);
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
        console.log("[YUNO] hideLoader (yunoError)");
        yunoInstance?.hideLoader();
      },

      // Modal events: track 3DS and other modal interactions
      yunoModalOpened: (data) => {
        console.log("[YUNO] yunoModalOpened →", data);
      },

      yunoModalClosed: (data) => {
        console.log("[YUNO] yunoModalClosed →", { data, wasPaying: state.paying });
        // Handle 3DS modal close without completion
        // If user closes 3DS modal, reset payment state to allow retry
        if (state.paying) {
          state.paying = false;
          setPayButtonDisabled(false);
        }
      },
    });

    // TODO: replace "CARD" with state.selectedPaymentMethod once yunoPaymentMethodSelected
    // is confirmed to fire in the WooCommerce order-pay context.
    const paymentMethodType = state.selectedPaymentMethod || "CARD";
    console.log("[YUNO] mountSeamlessCheckout →", { paymentMethodType });
    yunoInstance.mountSeamlessCheckout({ paymentMethodType });
    // yunoInstance.startPayment();
    state.started = true;

    // Show button now that SDK is mounted, but keep it disabled until card fields are valid
    setPayButtonVisible(true);
    setPayButtonDisabled(true);
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

  yunoInstance.startPayment();
  state.paying = true;
  setPayButtonDisabled(true);
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
