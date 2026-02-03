console.log("THIX YUNO checkout.js loaded ✅", window.THIX_YUNO_WC);

import { getCheckoutSession, createPayment, getPublicApiKey, confirmOrder, checkOrderStatus, duplicateOrder } from "./api.js";

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

  // Filled after /payments
  lastPaymentStatus: null,
  lastPaymentId: null,
};

function setLoaderVisible(visible) {
  const loader = document.getElementById("loader");
  if (!loader) return;
  loader.style.display = visible ? "block" : "none";
}

function setPayButtonDisabled(disabled) {
  const btn = document.getElementById("button-pay");
  if (!btn) return;
  btn.disabled = !!disabled;
  btn.style.opacity = disabled ? "0.6" : "1";
  btn.style.cursor = disabled ? "not-allowed" : "pointer";
}

function resolvePayButtonTarget(e) {
  const t = e.target;

  // Your custom button (order-pay receipt)
  const custom = t.closest?.("#button-pay");
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
  state.lastPaymentStatus = null;
  state.lastPaymentId = null;

  // Update visible order information in the UI
  const orderNumberEl = document.getElementById("yuno-order-number");
  const orderTotalEl = document.getElementById("yuno-order-total");

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
    // TODO: Implement Yuno webhooks for better consistency and eliminate F5 issues
    // Old approach: window.history.pushState({}, '', payUrl);
    // New approach: Full redirect to ensure page state is consistent
    console.log("[YUNO] Redirecting to new order URL:", payUrl);
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
    console.log("[YUNO] startYunoCheckout ✅", {
      payForOrder: state.payForOrder,
      orderId: state.orderId,
    });

    guardContext();

    // ✅ CRITICAL: Check order status first to prevent double payment
    if (state.payForOrder && state.orderId) {
      try {
        const statusRes = await checkOrderStatus({
          orderId: state.orderId,
          orderKey: state.orderKey,
        });

        console.log("[YUNO] check-order-status ✅", statusRes);

        // If order is already paid, redirect immediately
        if (statusRes.is_paid && statusRes.redirect) {
          console.log("[YUNO] Order already paid, redirecting...");
          window.location.href = statusRes.redirect;
          return;
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
      console.error("❌ checkout_session empty. Check /checkout-session", sessionRes);
      return;
    }

    state.checkoutSession = checkoutSession;
    state.countryCode = String(country || state.countryCode || "CO");

    // 2) Public API key
    const publicApiKey = await getPublicApiKey();
    if (!publicApiKey) {
      console.error("❌ publicApiKey empty. Check /public-api-key");
      return;
    }

    // 3) Initialize SDK
    yunoInstance = await window.Yuno.initialize(publicApiKey);

    let isPayingInsideSdk = false;
    setLoaderVisible(true);

    await yunoInstance.startCheckout({
      checkoutSession: state.checkoutSession,
      elementSelector: "#root",
      countryCode: state.countryCode,
      language: "es",
      showLoading: true,
      keepLoader: true,

      onLoading: () => {
        if (!isPayingInsideSdk) setLoaderVisible(false);
      },

      renderMode: {
        type: "modal",
        elementSelector: {
          apmForm: "#form-element",
          actionForm: "#action-form-element",
        },
      },

      card: { type: "extends", styles: "" },

      /**
       * Called by SDK when it has a oneTimeToken
       * Here we call our backend /payments (which calls Yuno API)
       */
      async yunoCreatePayment(oneTimeToken) {
        if (state.paid) return;

        state.paying = true;
        isPayingInsideSdk = true;
        setPayButtonDisabled(true);
        setLoaderVisible(true);

        try {
          const payload = {
            oneTimeToken,
            checkoutSession: state.checkoutSession,

            // both formats (defensive)
            orderId: state.orderId,
            orderKey: state.orderKey,
            order_id: state.orderId,
            order_key: state.orderKey,
          };

          console.log("[THIX YUNO] createPayment payload -> backend:", payload);

          const paymentRes = await createPayment(payload);

          console.log("[THIX YUNO] /payments split response:", paymentRes?.split || "No split data");

          if (paymentRes?.handled) {
            console.warn("[YUNO] createPayment returned 409 (handled)", paymentRes);
            return;
          }

          // ✅ Source of truth (more reliable than yunoPaymentResult payload)
          state.lastPaymentStatus = paymentRes?.response?.status || "UNKNOWN";
          state.lastPaymentId = paymentRes?.payment_id || paymentRes?.response?.id || null;

          console.log("[YUNO] createPayment ✅", paymentRes);
        } catch (e) {
          console.error("[YUNO] createPayment failed", e);
          state.paying = false;
          setPayButtonDisabled(false);
          setLoaderVisible(false);
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
        console.log("[YUNO] yunoPaymentResult ✅", result);

        try {
          const paymentId = state.lastPaymentId || null;

          console.log("[YUNO] confirm payload", {
            orderId: state.orderId,
            orderKey: state.orderKey,
            paymentId,
          });

          if (state.payForOrder && state.orderId) {
            // ✅ SECURITY: Only send payment_id, backend verifies status with Yuno API
            const confirmRes = await confirmOrder({
              // both formats (defensive)
              orderId: state.orderId,
              orderKey: state.orderKey,
              order_id: state.orderId,
              order_key: state.orderKey,

              paymentId,
              payment_id: paymentId,
            });

            console.log("[YUNO] confirmOrder ✅", confirmRes);

            // ✅ Backend verifies with Yuno API and updates order status
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
        console.error("[YUNO] yunoError", error);
        state.paying = false;
        setPayButtonDisabled(false);
        setLoaderVisible(false);
        yunoInstance.hideLoader();
      },
    });

    yunoInstance.mountCheckout();
    state.started = true;

    console.log("[YUNO] mountCheckout ✅ ready");
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

  if (state.paid) {
    console.warn("[YUNO] Order already paid. Blocking.");
    return;
  }

  if (state.paying) {
    console.warn("[YUNO] Payment in progress. Blocking double click.");
    return;
  }

  console.log("[YUNO] CLICK pay ✅");

  if (!yunoInstance || !state.started) {
    await startYunoCheckout();
  }

  if (!yunoInstance) {
    console.error("[YUNO] yunoInstance is still null. Aborting.");
    return;
  }

  state.paying = true;
  setPayButtonDisabled(true);
  setLoaderVisible(true);

  yunoInstance.startPayment();
}

// Bind once
if (!window.__THIX_YUNO_BINDINGS__) {
  window.__THIX_YUNO_BINDINGS__ = true;
  document.addEventListener("click", handlePayClick);
}

// Prefer event, keep fallback
window.addEventListener("yuno-sdk-ready", () => startYunoCheckout());
setTimeout(() => startYunoCheckout(), 400);
