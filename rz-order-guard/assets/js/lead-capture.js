(function (window, document) {
  'use strict';

  var cfg = window.RZOG_LEAD_CAPTURE || {};
  if (!cfg.ajax_url || !cfg.nonce) {
    return;
  }

  var SESSION_KEY = 'rzog_session_id';
  var FBC_KEY = 'rzog_fbc';
  var SENT_KEY = 'rzog_lead_last_payload';
  var debounceTimer = null;
  var inFlight = false;

  function getSessionId() {
    var existing = '';
    try {
      existing = window.localStorage.getItem(SESSION_KEY) || '';
    } catch (e) {}

    if (existing) {
      return existing;
    }

    existing = 'rzog_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
    try {
      window.localStorage.setItem(SESSION_KEY, existing);
    } catch (e) {}

    return existing;
  }

  // Exposed so the order-intake submit (4.1) can reuse the same session_id
  // -- that's what makes its idempotency check work across a double submit.
  window.RZOG = window.RZOG || {};
  window.RZOG.getSessionId = getSessionId;

  function valueOf(selectors) {
    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el && typeof el.value !== 'undefined' && String(el.value).trim() !== '') {
        return String(el.value).trim();
      }
    }
    return '';
  }

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function setCookie(name, value) {
    if (!value) {
      return;
    }
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + (90 * 24 * 60 * 60) + '; SameSite=Lax';
  }

  function getFbclidFromUrl() {
    var match = (window.location.search || '').match(/[?&]fbclid=([^&]+)/);
    return match ? decodeURIComponent(match[1]).replace(/[\r\n\0]/g, '').trim() : '';
  }

  function isValidFbc(value) {
    return /^fb\.1\.\d{10,13}\.[^.]+$/.test(String(value || ''));
  }

  function getFbcValue() {
    var fbc = getCookie('_fbc');
    if (isValidFbc(fbc)) {
      return fbc;
    }

    var stored = '';
    try {
      stored = window.localStorage.getItem(FBC_KEY) || '';
    } catch (e) {}
    if (isValidFbc(stored)) {
      setCookie('_fbc', stored);
      return stored;
    }

    var fbclid = getFbclidFromUrl();
    if (!fbclid) {
      return '';
    }

    var generated = 'fb.1.' + Date.now() + '.' + fbclid;
    try {
      window.localStorage.setItem(FBC_KEY, generated);
    } catch (e) {}
    setCookie('_fbc', generated);
    return generated;
  }

  function hasMarker() {
    return !!document.querySelector(cfg.marker || '#dp-order-now');
  }

  function buildPayload() {
    return {
      action: 'rzog_save_lead',
      nonce: cfg.nonce,
      session_id: getSessionId(),
      source_url: window.location.href,
      billing_first_name: valueOf(['#billing_first_name', 'input[name="billing_first_name"]']),
      billing_last_name: valueOf(['#billing_last_name', 'input[name="billing_last_name"]']),
      billing_phone: valueOf(['#billing_phone', 'input[name="billing_phone"]']),
      billing_email: valueOf(['#billing_email', 'input[name="billing_email"]']),
      billing_address_1: valueOf(['#billing_address_1', 'input[name="billing_address_1"]']),
      product_id: valueOf(['input[name="product_id"]']),
      value: valueOf(['input[name="rzog_cart_value"]']),
      currency: cfg.currency || '',
      fbp: getCookie('_fbp'),
      fbc: getFbcValue()
    };
  }

  function hasMeaningfulValue(payload) {
    return (payload.billing_phone && payload.billing_phone.replace(/[^0-9]/g, '').length >= 6)
      || (payload.billing_email && payload.billing_email.indexOf('@') !== -1)
      || (payload.billing_first_name && payload.billing_first_name.length >= 3)
      || (payload.billing_address_1 && payload.billing_address_1.length >= 3);
  }

  function serialize(payload) {
    return [payload.billing_email, payload.billing_phone, payload.billing_first_name, payload.billing_address_1].join('|');
  }

  function sendCapture() {
    if (inFlight) {
      return;
    }

    var payload = buildPayload();
    if (!hasMeaningfulValue(payload)) {
      return;
    }

    var serialized = serialize(payload);
    try {
      if (window.sessionStorage.getItem(SENT_KEY) === serialized) {
        return;
      }
      window.sessionStorage.setItem(SENT_KEY, serialized);
    } catch (e) {}

    inFlight = true;
    window.fetch(cfg.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(payload).toString()
    }).finally(function () {
      inFlight = false;
    });
  }

  function scheduleCapture() {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(sendCapture, 900);
  }

  function bind() {
    if (!hasMarker()) {
      return;
    }

    document.addEventListener('input', function (event) {
      if (event.target && event.target.matches('input, textarea, select')) {
        scheduleCapture();
      }
    }, true);

    document.addEventListener('change', function (event) {
      if (event.target && event.target.matches('input, textarea, select')) {
        scheduleCapture();
      }
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind, { once: true });
  } else {
    bind();
  }
})(window, document);
