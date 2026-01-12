// assets/js/api.js

const REST_BASE = window.THIX_YUNO_WC?.restBase;
const NONCE = window.THIX_YUNO_WC?.nonce;

function assertBase() {
  if (!REST_BASE) {
    throw new Error("REST_BASE not defined. Check wp_localize_script(THIX_YUNO_WC).");
  }
}

export async function getPublicApiKey() {
  assertBase();

  const res = await fetch(`${REST_BASE}/public-api-key`, {
    method: "GET",
    headers: {
      "X-WP-Nonce": NONCE,
    },
  });

  if (!res.ok) {
    const txt = await res.text();
    throw new Error(`public-api-key failed: ${res.status} ${txt}`);
  }

  const json = await res.json();
  return json.publicApiKey;
}

export async function getCheckoutSession() {
  assertBase();

  const res = await fetch(`${REST_BASE}/checkout-session`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-WP-Nonce": NONCE,
    },
    body: JSON.stringify({}),
  });

  if (!res.ok) {
    const txt = await res.text();
    throw new Error(`checkout-session failed: ${res.status} ${txt}`);
  }

  return res.json();
}

export async function createPayment({ oneTimeToken, checkoutSession }) {
  assertBase();

  const res = await fetch(`${REST_BASE}/payments`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-WP-Nonce": NONCE,
    },
    body: JSON.stringify({ oneTimeToken, checkoutSession }),
  });

  if (!res.ok) {
    const txt = await res.text();
    throw new Error(`payments failed: ${res.status} ${txt}`);
  }

  return res.json();
}
