const REST_BASE = window.THIX_YUNO_WC?.restBase;
const NONCE = window.THIX_YUNO_WC?.nonce;

function assertBase() {
  if (!REST_BASE) {
    throw new Error("REST_BASE not defined. Check wp_localize_script(THIX_YUNO_WC).");
  }
}

async function safeJson(res) {
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    return { raw: text };
  }
}

function wpHeaders(extra = {}) {
  return {
    "X-WP-Nonce": NONCE,
    ...extra,
  };
}

export async function getPublicApiKey() {
  assertBase();

  const res = await fetch(`${REST_BASE}/public-api-key`, {
    method: "GET",
    headers: wpHeaders(),
  });

  if (!res.ok) throw new Error(`public-api-key failed: ${res.status} ${await res.text()}`);
  const json = await res.json();
  return json.publicApiKey;
}

export async function getCheckoutSession({ orderId, orderKey }) {
  assertBase();

  const res = await fetch(`${REST_BASE}/checkout-session`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      order_id: orderId,
      order_key: orderKey,
    }),
  });

  if (!res.ok) {
    const payload = await safeJson(res);
    throw new Error(`checkout-session failed: ${res.status} ${JSON.stringify(payload)}`);
  }

  return res.json();
}

export async function createPayment({ oneTimeToken, checkoutSession, orderId, orderKey }) {
  assertBase();

  const res = await fetch(`${REST_BASE}/payments`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      oneTimeToken,
      checkoutSession,
      order_id: orderId,
      order_key: orderKey,
      browser_info: {
        user_agent: navigator.userAgent,
        language: navigator.language,
        platform: "WEB",
        screen_height: window.screen?.height,
        screen_width: window.screen?.width,
        color_depth: window.screen?.colorDepth,
        javascript_enabled: true
      }
    }),
  });

  if (!res.ok) {
    const payload = await safeJson(res);
    throw new Error(`payments failed: ${res.status} ${JSON.stringify(payload)}`);
  }

  return res.json();
}

export async function confirmOrder({ orderId, orderKey, status, paymentId }) {
  assertBase();

  const res = await fetch(`${REST_BASE}/confirm`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      order_id: orderId,
      order_key: orderKey,
      status,
      payment_id: paymentId,
    }),
  });

  const json = await safeJson(res);

  if (!res.ok) {
    throw new Error(`confirm failed: ${res.status} ${JSON.stringify(json)}`);
  }

  return json;
}
