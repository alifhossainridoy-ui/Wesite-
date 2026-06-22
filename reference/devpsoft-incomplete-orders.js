(function(window, document){
  'use strict';

  var cfg = window.DPFB_INCOMPLETE_ORDERS || {};
  if (!cfg.enabled || !cfg.ajax_url || !cfg.nonce) {
    return;
  }

  var STORAGE_KEY = 'dpfb_incomplete_session';
  var SENT_KEY = 'dpfb_incomplete_last_payload';
  var FBC_STORAGE_KEY = 'dpfb_incomplete_fbc';
  var debounceTimer = null;
  var inFlight = false;

  function getSessionId() {
    var existing = '';
    try {
      existing = window.localStorage.getItem(STORAGE_KEY) || '';
    } catch (e) {
      existing = '';
    }

    if (existing) {
      return existing;
    }

    existing = 'dpfb_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
    try {
      window.localStorage.setItem(STORAGE_KEY, existing);
    } catch (e) {}
    document.cookie = STORAGE_KEY + '=' + encodeURIComponent(existing) + '; path=/; max-age=' + (30 * 24 * 60 * 60) + '; SameSite=Lax';
    return existing;
  }

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
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function setCookie(name, value) {
    if (!value) {
      return;
    }
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + (90 * 24 * 60 * 60) + '; SameSite=Lax';
  }

  function getFbclidFromUrl(url) {
    var query = '';
    try {
      query = new URL(url || window.location.href).search || '';
    } catch (e) {
      query = window.location.search || '';
    }

    var match = query.match(/[?&]fbclid=([^&]+)/);
    var value = match ? decodeURIComponent(match[1] || '') : '';
    value = String(value || '').replace(/^(?:fbclid[=.:])+/i, '');
    value = value.replace(/^fbclid/i, '');
    value = value.replace(/^[=.:]+/, '');

    return value.replace(/[\r\n\0]/g, '').trim();
  }

  function normalizeFbc(value) {
    var match = String(value || '').match(/^fb\.1\.(\d+)\.(.+)$/);
    if (!match) {
      return '';
    }

    var fbclid = String(match[2] || '').replace(/^(?:fbclid[=.:])+/i, '');
    fbclid = fbclid.replace(/^fbclid/i, '');
    fbclid = fbclid.replace(/^[=.:]+/, '');
    fbclid = fbclid.replace(/[\r\n\0]/g, '').trim();

    if (!match[1] || !fbclid) {
      return '';
    }

    var creationTime = normalizeMetaCreationTime(match[1]);
    if (!creationTime) {
      return '';
    }

    return 'fb.1.' + creationTime + '.' + fbclid;
  }

  function normalizeMetaCreationTime(value) {
    var timestamp = String(value || '').replace(/\D+/g, '');
    if (timestamp.length === 10) {
      timestamp += '000';
    }

    if (timestamp.length !== 13) {
      return '';
    }

    var numericTimestamp = parseInt(timestamp, 10);
    if (!numericTimestamp || numericTimestamp < 1262304000000 || numericTimestamp > Date.now() + 300000) {
      return '';
    }

    return String(numericTimestamp);
  }

  function getStoredFbc() {
    try {
      return normalizeFbc(window.localStorage.getItem(FBC_STORAGE_KEY) || window.sessionStorage.getItem(FBC_STORAGE_KEY) || '');
    } catch (e) {
      return '';
    }
  }

  function persistFbc(value) {
    value = normalizeFbc(value);
    if (!value) {
      return '';
    }

    try {
      window.localStorage.setItem(FBC_STORAGE_KEY, value);
      window.sessionStorage.setItem(FBC_STORAGE_KEY, value);
    } catch (e) {}
    setCookie('_fbc', value);

    return value;
  }

  function getFbcValue() {
    var fbc = normalizeFbc(getCookie('_fbc'));
    if (fbc) {
      return persistFbc(fbc);
    }

    var fbclid = getFbclidFromUrl(window.location.href);
    if (fbclid) {
      return persistFbc('fb.1.' + Date.now() + '.' + fbclid);
    }

    return getStoredFbc();
  }

  function isCheckoutLikePage() {
    var markers = Array.isArray(cfg.checkout_markers) ? cfg.checkout_markers : [];
    for (var i = 0; i < markers.length; i++) {
      if (document.querySelector(markers[i])) {
        return true;
      }
    }
    return false;
  }

  function buildPayload() {
    var cart = cfg.cart && typeof cfg.cart === 'object' ? cfg.cart : {};
    var payload = {
      action: 'dpfb_save_incomplete_order',
      nonce: cfg.nonce,
      session_id: getSessionId(),
      source_url: window.location.href,
      landing_page: document.referrer || '',
      first_name: valueOf(['#billing_first_name', 'input[name="billing_first_name"]', '#billing_billing_name', 'input[name="billing_billing_name"]', '#billing_name', 'input[name="billing_name"]']),
      last_name: valueOf(['#billing_last_name', 'input[name="billing_last_name"]']),
      phone: valueOf(['#billing_phone', 'input[name="billing_phone"]', '#phone', 'input[name="phone"]']),
      email: valueOf(['#billing_email', 'input[name="billing_email"]', '#email', 'input[name="email"]']),
      address_1: valueOf(['#billing_address_1', 'input[name="billing_address_1"]', '#billing_billing_address', 'input[name="billing_billing_address"]', '#billing_address', 'input[name="billing_address"]']),
      city: valueOf(['#billing_city', 'input[name="billing_city"]']),
      state: valueOf(['#billing_state', 'input[name="billing_state"]', 'select[name="billing_state"]']),
      postcode: valueOf(['#billing_postcode', 'input[name="billing_postcode"]']),
      country: valueOf(['#billing_country', 'input[name="billing_country"]', 'select[name="billing_country"]']),
      value: cart.value || 0,
      currency: cfg.currency || '',
      products: JSON.stringify(cart.products || []),
      fbp: getCookie('_fbp'),
      fbc: getFbcValue(),
      event_id: getCookie('_dpfb_ic_eid') || ''
    };

    return payload;
  }

  function hasMeaningfulValue(payload) {
    return (payload.phone && payload.phone.replace(/[^0-9]/g, '').length >= 6)
      || (payload.email && payload.email.indexOf('@') !== -1)
      || (payload.first_name && payload.first_name.length >= 3)
      || (payload.address_1 && payload.address_1.length >= 3);
  }

  function serialize(payload) {
    return [payload.email, payload.phone, payload.first_name, payload.address_1, payload.value].join('|');
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
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams(payload).toString()
    }).finally(function(){
      inFlight = false;
    });
  }

  function scheduleCapture() {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(sendCapture, 900);
  }

  function bind() {
    getFbcValue();

    if (!isCheckoutLikePage()) {
      return;
    }

    document.addEventListener('input', function(event){
      if (event.target && event.target.matches('input, textarea, select')) {
        scheduleCapture();
      }
    }, true);

    document.addEventListener('change', function(event){
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
