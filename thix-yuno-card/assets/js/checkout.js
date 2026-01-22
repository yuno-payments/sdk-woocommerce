console.log("THIX YUNO checkout.js loaded ✅", window.THIX_YUNO_WC);

import { getCheckoutSession, createPayment, getPublicApiKey, confirmOrder } from "./api.js";

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
    // orderKey is recommended, but we do not hard fail
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
 * Fallback redirect:
 * If backend doesn't return redirect, we convert:
 *   /checkout/order-pay/24/?pay_for_order=true&key=XXX
 * to:
 *   /checkout/order-received/24/?key=XXX
 */
function fallbackRedirectToOrderReceived() {
  try {
    const url = new URL(window.location.href);

    url.pathname = url.pathname.replace(/order-pay\/(\d+)/, "order-received/$1");
    url.searchParams.delete("pay_for_order");
    // keep key param if present
    window.location.href = url.toString();
  } catch (e) {
    console.warn("[YUNO] fallbackRedirect failed, reloading as last resort", e);
    window.location.reload();
  }
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

    const ok = await waitForYunoSdk();
    if (!ok) {
      console.error("[YUNO] SDK not available (Yuno.initialize missing). Check yuno-sdk script.");
      return;
    }

    // 1) Create checkout session (order-based)
    const sessionRes = await getCheckoutSession({
      orderId: state.orderId,
      orderKey: state.orderKey,
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
          // 🔍 Build payload we send to backend (useful when Split is ON)
          const payload = {
            oneTimeToken,
            checkoutSession: state.checkoutSession,
            orderId: state.orderId,
            orderKey: state.orderKey,
          };

          // ✅ Debug: confirm exactly what we send to /payments
          console.log("[THIX YUNO] createPayment payload -> backend:", payload);

          const paymentRes = await createPayment(payload);

          // ✅ Debug: confirm split data
          console.log("[THIX YUNO] /payments split response:", paymentRes?.split || "No split data");

          if (paymentRes?.handled) {
            console.warn("[YUNO] createPayment returned 409 (handled)", paymentRes);
            return;
          }


          // ✅ Source of truth (more reliable than yunoPaymentResult payload)
          state.lastPaymentStatus = paymentRes?.response?.status || "UNKNOWN";
          state.lastPaymentId =
            paymentRes?.payment_id || paymentRes?.response?.id || null;

          console.log("[YUNO] createPayment ✅", paymentRes);
        } catch (e) {
          console.error("[YUNO] createPayment failed", e);
          // allow retry
          state.paying = false;
          setPayButtonDisabled(false);
          setLoaderVisible(false);
          throw e;
        } finally {
          // Continue SDK flow even if payment requires additional action
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
          const status = state.lastPaymentStatus || "UNKNOWN";
          const paymentId = state.lastPaymentId || null;

          console.log("[YUNO] confirm payload", {
            orderId: state.orderId,
            orderKey: state.orderKey,
            status,
            paymentId,
          });

          // Only confirm in order-pay context
          if (state.payForOrder && state.orderId) {
            const confirmRes = await confirmOrder({
              orderId: state.orderId,
              orderKey: state.orderKey,
              status,
              paymentId,
            });

            console.log("[YUNO] confirmOrder ✅", confirmRes);

            // If approved, block further payments and redirect
            if (confirmRes?.ok && (status === "SUCCEEDED" || status === "VERIFIED")) {
              state.paid = true;
              setPayButtonDisabled(true);

              if (confirmRes?.redirect) {
                window.location.href = confirmRes.redirect;
                return;
              }

              // ✅ fallback redirect if backend didn't send one
              fallbackRedirectToOrderReceived();
              return;
            }

            // If rejected/failed, allow retry
            state.paying = false;
            setPayButtonDisabled(false);
          }
        } catch (e) {
          console.error("[YUNO] confirmOrder error", e);
          // allow retry
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
