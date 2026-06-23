(function (window, document) {
  'use strict';

  var cfg = window.RZFT_ORDER_FORM || {};
  if (!cfg.rest_url) {
    return;
  }

  var FIELD_LABELS = {
    billing_first_name: 'পুরো নাম',
    billing_phone: 'মোবাইল নাম্বার',
    billing_address_1: 'ঠিকানা',
    product_id: 'প্রোডাক্ট',
    quantity: 'পরিমাণ',
    general: ''
  };

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function getSessionId() {
    if (window.RZOG && typeof window.RZOG.getSessionId === 'function') {
      return window.RZOG.getSessionId();
    }
    // Fallback only -- in normal operation rz-order-guard's lead-capture.js
    // is enqueued site-wide and already exposes this.
    return 'rzft_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
  }

  function clearFieldErrors(form) {
    var fields = form.querySelectorAll('.rzft-field.is-invalid');
    for (var i = 0; i < fields.length; i++) {
      fields[i].classList.remove('is-invalid');
    }
  }

  function showMessage(box, text, isList) {
    box.classList.remove('is-hidden');
    box.classList.add('is-error');
    box.innerHTML = isList ? text : '<p>' + text + '</p>';
  }

  function hideMessage(box) {
    box.classList.add('is-hidden');
    box.innerHTML = '';
  }

  function renderErrors(form, box, errors) {
    clearFieldErrors(form);

    var items = [];
    for (var key in errors) {
      if (!Object.prototype.hasOwnProperty.call(errors, key)) {
        continue;
      }
      var label = FIELD_LABELS[key] || key;
      items.push('<li>' + (label ? label + ': ' : '') + errors[key] + '</li>');

      var input = form.querySelector('[name="' + key + '"]');
      if (input) {
        var field = input.closest('.rzft-field');
        if (field) {
          field.classList.add('is-invalid');
        }
      }
    }

    showMessage(box, '<ul>' + items.join('') + '</ul>', true);
  }

  function showContactModal() {
    var modal = document.getElementById('rzft-contact-modal');
    if (!modal) {
      return;
    }

    var links = modal.querySelector('.rzft-modal__links');
    var contact = cfg.contact || {};
    var html = '';
    if (contact.whatsapp) {
      html += '<a href="' + contact.whatsapp + '">WhatsApp</a>';
    }
    if (contact.phone) {
      html += '<a href="tel:' + contact.phone + '">' + contact.phone + '</a>';
    }
    if (contact.messenger) {
      html += '<a href="' + contact.messenger + '">Messenger</a>';
    }
    links.innerHTML = html;

    modal.classList.remove('is-hidden');
  }

  function hideContactModal() {
    var modal = document.getElementById('rzft-contact-modal');
    if (modal) {
      modal.classList.add('is-hidden');
    }
  }

  function redirectToThankYou(orderId, orderKey) {
    if (!cfg.thankyou_url) {
      return;
    }
    var url = cfg.thankyou_url.replace('RZFT_ORDER_ID', orderId) + '?key=' + encodeURIComponent(orderKey || '');
    window.location.href = url;
  }

  function bindQuantityStepper(form) {
    var input = form.querySelector('#rzft-qty-input');
    if (!input) {
      return;
    }

    form.querySelectorAll('[data-rzft-qty]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var current = parseInt(input.value, 10) || 1;
        var next = btn.getAttribute('data-rzft-qty') === 'increase' ? current + 1 : current - 1;
        input.value = Math.max(1, next);
      });
    });
  }

  function bindModalClose() {
    var modal = document.getElementById('rzft-contact-modal');
    if (!modal) {
      return;
    }
    var closeBtn = modal.querySelector('.rzft-modal__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', hideContactModal);
    }
  }

  function bindForm() {
    var form = document.getElementById('rzft-order-form');
    if (!form) {
      return;
    }

    var messageBox = document.getElementById('rzft-form-message');
    var submitBtn = form.querySelector('.rzft-order__submit');

    bindQuantityStepper(form);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      hideMessage(messageBox);
      clearFieldErrors(form);

      var payload = {
        billing_first_name: form.billing_first_name.value.trim(),
        billing_last_name: form.billing_last_name.value,
        billing_phone: form.billing_phone.value.trim(),
        billing_address_1: form.billing_address_1.value.trim(),
        billing_country: form.billing_country.value,
        product_id: form.product_id.value,
        variation_id: form.variation_id.value,
        quantity: form.quantity.value,
        session_id: getSessionId(),
        source_url: window.location.href,
        fbp: getCookie('_fbp'),
        fbc: getCookie('_fbc')
      };

      submitBtn.disabled = true;

      window.fetch(cfg.rest_url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
        .then(function (response) {
          return response.json().then(function (data) {
            return { status: response.status, data: data };
          });
        })
        .then(function (result) {
          var data = result.data;

          if (data.blocked) {
            showContactModal();
            return;
          }

          if (data.order_id) {
            redirectToThankYou(data.order_id, data.order_key);
            return;
          }

          if (data.errors) {
            renderErrors(form, messageBox, data.errors);
            return;
          }

          showMessage(messageBox, 'দুঃখিত, অর্ডারটি সম্পন্ন করা যায়নি। আবার চেষ্টা করুন।');
        })
        .catch(function () {
          showMessage(messageBox, 'নেটওয়ার্ক সমস্যা, আবার চেষ্টা করুন।');
        })
        .finally(function () {
          submitBtn.disabled = false;
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      bindForm();
      bindModalClose();
    }, { once: true });
  } else {
    bindForm();
    bindModalClose();
  }
})(window, document);
