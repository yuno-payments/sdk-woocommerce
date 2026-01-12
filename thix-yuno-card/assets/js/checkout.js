
console.log("THIX YUNO checkout.js loaded ✅", window.THIX_YUNO_WC);

import { getCheckoutSession, createPayment, getPublicApiKey } from "./api.js";

let yunoInstance = null;

const state = {
  started: false,
  starting: false,
};

function setLoaderVisible(visible) {
  const loader = document.getElementById("loader");
  if (!loader) return;
  loader.style.display = visible ? "block" : "none";
}

async function startYunoCheckout() {
  if (state.starting || state.started) return;
  state.starting = true;

  try {
    console.log("[YUNO] initCheckout running ✅");

    // 1) Checkout session from WP
    const { checkout_session: checkoutSession, country: countryCode } =
      await getCheckoutSession();

    console.log("[YUNO] checkoutSession received:", checkoutSession, "country:", countryCode);

    if (!checkoutSession) {
      console.error("❌ checkout_session empty. Check /checkout-session");
      return;
    }

    // 2) Public API Key
    const publicApiKey = await getPublicApiKey();
    console.log("[YUNO] publicApiKey received ✅");

    if (!publicApiKey) {
      console.error("❌ publicApiKey empty. Check /public-api-key");
      return;
    }

    // 3) Initialize SDK
    yunoInstance = await Yuno.initialize(publicApiKey);

    let isPaying = false;
    setLoaderVisible(true);

    await yunoInstance.startCheckout({
      checkoutSession,
      elementSelector: "#root",
      countryCode: countryCode || "CO",
      language: "es",
      showLoading: true,
      keepLoader: true,

      onLoading: () => {
        if (!isPaying) setLoaderVisible(false);
      },

      renderMode: {
        type: "modal",
        elementSelector: {
          apmForm: "#form-element",
          actionForm: "#action-form-element",
        },
      },

      card: {
        type: "extends",
        styles: "",
      },

      async yunoCreatePayment(oneTimeToken) {
        isPaying = true;
        setLoaderVisible(true);

        console.log("[YUNO] oneTimeToken ✅", oneTimeToken);

        // Create payment in WP -> Yuno
        const res = await createPayment({ oneTimeToken, checkoutSession });
        console.log("[YUNO] createPayment response ✅", res);

        // Continue SDK flow
        yunoInstance.continuePayment();
      },

      yunoPaymentMethodSelected(data) {
        console.log("[YUNO] yunoPaymentMethodSelected", data);
      },

      yunoPaymentResult(data) {
        console.log("[YUNO] yunoPaymentResult", data);
        setLoaderVisible(false);
        yunoInstance.hideLoader();
      },

      yunoError(error) {
        console.error("[YUNO] yunoError", error);
        setLoaderVisible(false);
        yunoInstance.hideLoader();
      },
    });

    yunoInstance.mountCheckout();
    state.started = true;

    console.log("[YUNO] mountCheckout ✅ ready");
  } catch (e) {
    console.error("[YUNO] initCheckout error", e);
  } finally {
    state.starting = false;
  }
}

function handlePayClick(e) {
  const btn = e.target.closest("#button-pay");
  if (!btn) return;

  console.log("[YUNO] CLICK pay now ✅");

  if (!yunoInstance) {
    console.warn("[YUNO] SDK not ready yet. Trying to start…");
    startYunoCheckout();
    return;
  }

  yunoInstance.startPayment();
}

// Avoid duplicating listeners if Woo re-renders
if (!window.__THIX_YUNO_BINDINGS__) {
  window.__THIX_YUNO_BINDINGS__ = true;

  document.addEventListener("click", handlePayClick);

  // Woo: re-renders checkout
  if (window.jQuery) {
    window.jQuery(document.body).on("updated_checkout", () => {
      console.log("[YUNO] updated_checkout (Woo) ✅");
      // don't restart if already started
      if (!state.started) startYunoCheckout();
    });
  }
}

// Preferred by Yuno
window.addEventListener("yuno-sdk-ready", () => {
  console.log("[YUNO] event yuno-sdk-ready ✅");
  startYunoCheckout();
});

// Fallback
setTimeout(() => {
  startYunoCheckout();
}, 400);
