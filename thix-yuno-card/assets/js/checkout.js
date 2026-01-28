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
 * /checkout/order-pay/24/?pay_for_order=true&key=XXX
 * -> /checkout/order-received/24/?key=XXX
 */
function fallbackRedirectToOrderReceived() {
  try {
    const url = new URL(window.location.href);

    url.pathname = url.pathname.replace(/order-pay\/(\d+)/, "order-received/$1");
    url.searchParams.delete("pay_for_order");
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

    // ✅ IMPORTANT: Check if order is already paid before allowing payment
    // This prevents double-charge when user refreshes the page
    if (state.payForOrder && state.orderId) {
      try {
        const checkRes = await fetch(`${window.THIX_YUNO_WC?.restBase}/check-order-status`, {
          method: "POST",
          headers: {
            "X-WP-Nonce": window.THIX_YUNO_WC?.nonce,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            order_id: state.orderId,
            order_key: state.orderKey,
          }),
        });

        if (checkRes.ok) {
          const orderStatus = await checkRes.json();
          console.log("[YUNO] Order status check:", orderStatus);

          if (orderStatus.is_paid || ['processing', 'completed', 'on-hold'].includes(orderStatus.status)) {
            console.warn("[YUNO] ⚠️ Order already paid/processing, redirecting to order-received");
            alert("This order has already been paid.");
            window.location.href = orderStatus.redirect || fallbackRedirectToOrderReceived();
            return;
          }
        }
      } catch (e) {
        console.warn("[YUNO] Could not check order status, continuing:", e);
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

    console.log("[YUNO] ===== Starting SDK checkout with callbacks =====");

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

          console.log("[THIX YUNO] ===== createPayment CALLED ===== payload:", payload);

          const paymentRes = await createPayment(payload);

          console.log("[THIX YUNO] ===== createPayment RESPONSE ===== Full response:", paymentRes);
          console.log("[THIX YUNO] /payments split response:", paymentRes?.split || "No split data");

          if (paymentRes?.handled) {
            console.warn("[YUNO] createPayment returned 409 (handled)", paymentRes);
            return;
          }

          // ✅ Source of truth (more reliable than yunoPaymentResult payload)
          state.lastPaymentStatus = paymentRes?.response?.status || "UNKNOWN";
          state.lastPaymentId = paymentRes?.payment_id || paymentRes?.response?.id || null;

          console.log("[YUNO] ===== SAVED TO STATE =====", {
            lastPaymentStatus: state.lastPaymentStatus,
            lastPaymentId: state.lastPaymentId,
          });

          if (!state.lastPaymentId) {
            console.error("[YUNO] ❌ CRITICAL: payment_id not found in response!", {
              payment_id_field: paymentRes?.payment_id,
              response_id_field: paymentRes?.response?.id,
              full_response: paymentRes,
            });
            alert("ERROR: No payment_id in backend response!");
          }

          // ✅ WORKAROUND: Auto-confirm if payment is already SUCCEEDED
          // This handles cases where yunoPaymentResult callback doesn't fire
          if (state.lastPaymentStatus === "SUCCEEDED" && state.lastPaymentId) {
            console.log("[YUNO] 🔄 Payment SUCCEEDED, scheduling auto-confirm with retry...");

            const attemptConfirm = async (attemptNumber = 1, maxAttempts = 3) => {
              if (state.paid) {
                console.log("[YUNO] Already confirmed, skipping auto-confirm");
                return;
              }

              const delay = attemptNumber === 1 ? 5000 : 3000; // 5s first, then 3s
              console.log(`[YUNO] 🤖 Auto-confirm attempt ${attemptNumber}/${maxAttempts} in ${delay/1000}s...`);

              setTimeout(async () => {
                if (state.paid) {
                  console.log("[YUNO] Already confirmed during wait");
                  return;
                }

                try {
                  const confirmRes = await confirmOrder({
                    orderId: state.orderId,
                    orderKey: state.orderKey,
                    order_id: state.orderId,
                    order_key: state.orderKey,
                    paymentId: state.lastPaymentId,
                    payment_id: state.lastPaymentId,
                  });

                  console.log(`[YUNO] ===== AUTO-CONFIRM RESPONSE (attempt ${attemptNumber}) ✅ =====`, confirmRes);

                  if (confirmRes?.ok && !confirmRes?.pending) {
                    state.paid = true;
                    setPayButtonDisabled(true);
                    yunoInstance.hideLoader();

                    if (confirmRes?.redirect) {
                      console.log("[YUNO] Redirecting to:", confirmRes.redirect);
                      window.location.href = confirmRes.redirect;
                    } else {
                      fallbackRedirectToOrderReceived();
                    }
                  } else if (confirmRes?.pending && attemptNumber < maxAttempts) {
                    // Yuno still processing, retry
                    console.warn(`[YUNO] ⏳ Still pending, retrying (${attemptNumber}/${maxAttempts})...`);
                    attemptConfirm(attemptNumber + 1, maxAttempts);
                  } else if (confirmRes?.pending) {
                    console.error("[YUNO] ❌ Max retries reached, payment still pending");
                    alert("Payment is taking longer than expected. Please refresh the page.");
                  }
                } catch (e) {
                  console.error(`[YUNO] Auto-confirm attempt ${attemptNumber} failed:`, e);
                  if (attemptNumber < maxAttempts) {
                    console.log("[YUNO] Retrying...");
                    attemptConfirm(attemptNumber + 1, maxAttempts);
                  }
                }
              }, delay);
            };

            attemptConfirm();
          }
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
        console.log("[YUNO] ===== yunoPaymentResult CALLED ✅ =====", result);
        console.log("[YUNO] State before confirm:", {
          lastPaymentId: state.lastPaymentId,
          lastPaymentStatus: state.lastPaymentStatus,
          payForOrder: state.payForOrder,
          orderId: state.orderId,
          paid: state.paid,
        });

        // Prevent double confirmation
        if (state.paid) {
          console.log("[YUNO] ⏭️  Already confirmed, skipping yunoPaymentResult");
          return;
        }

        try {
          const paymentId = state.lastPaymentId || null;

          console.log("[YUNO] confirm payload", {
            orderId: state.orderId,
            orderKey: state.orderKey,
            paymentId,
          });

          // DEBUG: Check if payment_id exists
          if (!paymentId) {
            console.error("[YUNO] ❌ CRITICAL: Missing payment_id! Cannot confirm order.", {
              lastPaymentStatus: state.lastPaymentStatus,
              lastPaymentId: state.lastPaymentId,
            });
            alert("ERROR: No payment_id found. Check console.");
            return;
          }

          if (state.payForOrder && state.orderId) {
            console.log("[YUNO] Calling confirmOrder API...");

            // ✅ SECURITY: Only send payment_id, backend verifies status with Yuno
            const confirmRes = await confirmOrder({
              // both formats (defensive)
              orderId: state.orderId,
              orderKey: state.orderKey,
              order_id: state.orderId,
              order_key: state.orderKey,

              paymentId,
              payment_id: paymentId,
            });

            console.log("[YUNO] ===== confirmOrder RESPONSE ✅ =====", confirmRes);
            console.log("[YUNO] Response analysis:", {
              ok: confirmRes?.ok,
              pending: confirmRes?.pending,
              redirect: confirmRes?.redirect,
              status: confirmRes?.status,
              new_status: confirmRes?.new_status,
            });

            // Handle response based on backend verification
            if (confirmRes?.ok && !confirmRes?.pending) {
              console.log("[YUNO] ✅ Payment confirmed! Redirecting...");
              state.paid = true;
              setPayButtonDisabled(true);

              if (confirmRes?.redirect) {
                console.log("[YUNO] Redirecting to:", confirmRes.redirect);
                window.location.href = confirmRes.redirect;
                return;
              }

              console.log("[YUNO] No redirect URL, using fallback");
              fallbackRedirectToOrderReceived();
              return;
            }

            // Handle pending status (payment processing)
            if (confirmRes?.pending) {
              console.warn("[YUNO] ⏳ Payment is being processed", confirmRes);
              alert("Payment is being processed. Please wait.");
              // Could show a "processing" message to user here
              // For now, allow retry
            }

            // rejected/failed or pending => allow retry
            console.warn("[YUNO] Payment not confirmed, allowing retry");
            state.paying = false;
            setPayButtonDisabled(false);
          } else {
            console.error("[YUNO] ❌ Missing context:", {
              payForOrder: state.payForOrder,
              orderId: state.orderId,
            });
          }
        } catch (e) {
          console.error("[YUNO] ===== confirmOrder ERROR ❌ =====", e);
          alert("Error confirming payment: " + e.message);
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
