// Access dynamically to avoid capturing undefined values before wp_localize_script runs
function getRestBase() {
  return window.YUNO_WC?.restBase;
}

function getNonce() {
  return window.YUNO_WC?.nonce;
}

function assertBase() {
  const REST_BASE = getRestBase();
  if (!REST_BASE) {
    throw new Error("REST_BASE not defined. Check wp_localize_script(YUNO_WC).");
  }
  return REST_BASE;
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
    "X-WP-Nonce": getNonce(),
    ...extra,
  };
}

async function getPublicApiKey() {
  const REST_BASE = assertBase();

  const res = await fetch(`${REST_BASE}/public-api-key`, {
    method: "GET",
    headers: wpHeaders(),
  });

  if (!res.ok) throw new Error(`public-api-key failed: ${res.status} ${await res.text()}`);
  const json = await res.json();
  return json.publicApiKey;
}

async function getCheckoutSession({ orderId, orderKey, customer_id }) {
  const REST_BASE = assertBase();

  const res = await fetch(`${REST_BASE}/checkout-session`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      order_id: orderId,
      order_key: orderKey,
      customer_id: customer_id || null,
    }),
  });

  if (!res.ok) {
    const payload = await safeJson(res);
    throw new Error(`checkout-session failed: ${res.status} ${JSON.stringify(payload)}`);
  }

  return res.json();
}

async function createCustomer({ orderId, orderKey }) {
  const REST_BASE = assertBase();

  const res = await fetch(`${REST_BASE}/customer`, {
    method: 'POST',
    headers: wpHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify({
      order_id: orderId,
      order_key: orderKey,
    }),
  });

  const json = await safeJson(res);

  if (!res.ok) {
    console.warn('[YUNO] createCustomer failed:', res.status, json);
    return null;  // graceful degradation
  }

  return json.customer_id || null;
}

async function confirmOrder({ orderId, orderKey, paymentStatus }) {
  const REST_BASE = assertBase();

  const res = await fetch(`${REST_BASE}/confirm`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      order_id:       orderId,
      order_key:      orderKey,
      payment_status: paymentStatus || null,
    }),
  });

  const json = await safeJson(res);

  if (!res.ok) {
    throw new Error(`confirm failed: ${res.status} ${JSON.stringify(json)}`);
  }

  return json;
}

async function checkOrderStatus({ orderId, orderKey }) {
  const REST_BASE = assertBase();

  const res = await fetch(`${REST_BASE}/check-order-status`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      order_id: orderId,
      order_key: orderKey,
    }),
  });

  const json = await safeJson(res);

  if (!res.ok) {
    throw new Error(`check-order-status failed: ${res.status} ${JSON.stringify(json)}`);
  }

  return json;
}

async function createPayment({ oneTimeToken, checkoutSession, orderId, orderKey }) {
  const REST_BASE = assertBase();

  const res = await fetch(`${REST_BASE}/payments`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      one_time_token: oneTimeToken,
      checkout_session: checkoutSession,
      order_id: orderId,
      order_key: orderKey,
    }),
  });

  const json = await safeJson(res);

  if (!res.ok) {
    throw new Error(`Payment creation failed: ${res.status} ${JSON.stringify(json)}`);
  }

  return json;
}

async function duplicateOrder({ orderId, orderKey }) {
  const REST_BASE = assertBase();

  const res = await fetch(`${REST_BASE}/duplicate-order`, {
    method: "POST",
    headers: wpHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({
      order_id: orderId,
      order_key: orderKey,
    }),
  });

  const json = await safeJson(res);

  if (!res.ok) {
    throw new Error(`duplicate-order failed: ${res.status} ${JSON.stringify(json)}`);
  }

  return json;
}

// Expose functions globally for checkout.js
window.YUNO_API = {
  getPublicApiKey,
  getCheckoutSession,
  createCustomer,
  createPayment,
  confirmOrder,
  checkOrderStatus,
  duplicateOrder,
};
