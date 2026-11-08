/**
 * EcoWealth — cart behaviour (plan §5.2, Phase 4)
 * -------------------------------------------------
 * One script owns every cart interaction so the page, the navbar badge and
 * the off-canvas drawer can never disagree:
 *
 *   • `[data-cart-add]` forms      → POST /cart/add
 *   • `[data-cart-qty-form]` forms → POST /cart/update (change, Enter, ±)
 *   • `[data-cart-remove-form]`    → POST /cart/remove
 *   • `[data-cart-open]`           → open the drawer (the link still works
 *                                    without JavaScript, it navigates to /cart)
 *
 * The server always answers with the full drawer HTML plus its own totals,
 * so after a mutation we replace the drawer body rather than doing our own
 * arithmetic. Until the answer lands the UI has an optimistic state (badge
 * bumped, line total previewed); a failure resyncs from GET /cart/count.
 *
 * Every form is a real POST form: with JavaScript disabled the same buttons
 * submit, redirect and flash (P4-T12).
 */
(function () {
  'use strict';

  var configEl = document.getElementById('cartConfig');
  var config = configEl
    ? {
        countUrl: configEl.getAttribute('data-count-url') || '',
        addUrl: configEl.getAttribute('data-add-url') || '',
        updateUrl: configEl.getAttribute('data-update-url') || '',
        removeUrl: configEl.getAttribute('data-remove-url') || '',
        cartUrl: configEl.getAttribute('data-cart-url') || '',
        csrf: configEl.getAttribute('data-csrf') || ''
      }
    : null;

  if (!config || !config.addUrl) {
    return;
  }

  /* ------------------------------------------------------------------
   *  Toast helper — server text is inserted with textContent, so a product
   *  name that reached an error message can never become markup.
   * ---------------------------------------------------------------- */
  function showToast(message, type) {
    var tones = { success: 'success', danger: 'danger', error: 'danger', warning: 'warning', info: 'info' };
    var tone = tones[type] || 'info';
    var host = document.getElementById('toastContainer');

    if (!host) {
      return;
    }

    var el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + tone + ' border-0 mb-2';
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');

    var wrap = document.createElement('div');
    wrap.className = 'd-flex';

    var body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = String(message == null ? '' : message);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Close');

    wrap.appendChild(body);
    wrap.appendChild(close);
    el.appendChild(wrap);
    host.appendChild(el);

    if (window.bootstrap && window.bootstrap.Toast) {
      var toast = new window.bootstrap.Toast(el, { delay: 3500 });
      el.addEventListener('hidden.bs.toast', function () {
        el.remove();
      });
      toast.show();
    } else {
      window.setTimeout(function () {
        el.remove();
      }, 3500);
    }
  }

  window.showToast = window.showToast || showToast;

  /* ------------------------------------------------------------------
   *  Money formatting for optimistic previews only.
   * ---------------------------------------------------------------- */
  function money(value) {
    var amount = Number(value) || 0;

    try {
      return '₱' + amount.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    } catch (error) {
      return '₱' + amount.toFixed(2);
    }
  }

  /* ------------------------------------------------------------------
   *  Transport
   * ---------------------------------------------------------------- */
  function post(url, data) {
    var body = new URLSearchParams();
    body.set('_csrf', config.csrf);

    Object.keys(data).forEach(function (key) {
      body.set(key, String(data[key]));
    });

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        'X-CSRF-Token': config.csrf
      },
      body: body
    })
      .then(function (response) {
        return response
          .json()
          .catch(function () {
            return { ok: false, message: 'Unexpected response from the server.' };
          })
          .then(function (payload) {
            payload.status = response.status;
            return payload;
          });
      })
      .catch(function () {
        return { ok: false, message: 'Could not reach the server. Please try again.' };
      });
  }

  function resync() {
    if (!config.countUrl) {
      return;
    }

    fetch(config.countUrl, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (payload && payload.ok) {
          applyState(payload);
        }
      })
      .catch(function () {
        /* Offline: the page keeps the last server-rendered state. */
      });
  }

  /* ------------------------------------------------------------------
   *  Rendering server truth
   * ---------------------------------------------------------------- */
  function setBadge(count) {
    var value = Number(count) || 0;

    document.querySelectorAll('[data-cart-badge]').forEach(function (badge) {
      badge.textContent = String(value);
      badge.hidden = value <= 0;
    });
  }

  function setText(selector, text) {
    document.querySelectorAll(selector).forEach(function (node) {
      node.textContent = text;
    });
  }

  function applyState(payload) {
    if (!payload || !payload.ok) {
      return;
    }

    setBadge(payload.count);

    if (typeof payload.subtotal_formatted === 'string') {
      setText('[data-cart-subtotal]', payload.subtotal_formatted);
      setText('[data-cart-total]', payload.subtotal_formatted);
    }

    document.querySelectorAll('[data-cart-count]').forEach(function (node) {
      node.textContent = String(Number(payload.count) || 0);
    });

    if (typeof payload.drawer_html === 'string' && payload.drawer_html !== '') {
      var drawerBody = document.getElementById('cartOffcanvasBody');
      if (drawerBody) {
        drawerBody.innerHTML = payload.drawer_html;
        seedQuantities(drawerBody);
      }
    }
  }

  function rowFor(itemId) {
    return document.querySelector(
      '[data-cart-page] [data-cart-row][data-item-id="' + String(itemId) + '"]'
    );
  }

  /* Remember the last quantity the server confirmed, so a refused update can
   * roll the input back to a value that exists. Must run on every fragment
   * the server swaps in, not just on first load. */
  function seedQuantities(root) {
    (root || document).querySelectorAll('[data-cart-qty]').forEach(function (input) {
      input.setAttribute('data-last-qty', input.value);
    });
  }

  function previewLine(row, quantity) {
    if (!row) {
      return;
    }

    var price = Number(row.getAttribute('data-unit-price')) || 0;
    var cell = row.querySelector('[data-line-total]');

    if (cell) {
      cell.textContent = money(price * quantity);
    }
  }

  function removeRow(itemId) {
    var row = rowFor(itemId);

    if (row) {
      row.remove();
    }
  }

  function openDrawer() {
    var drawer = document.getElementById('cartOffcanvas');

    if (!drawer) {
      if (config.cartUrl) {
        window.location.assign(config.cartUrl);
      }
      return;
    }

    if (window.bootstrap && window.bootstrap.Offcanvas) {
      window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
    }
  }

  function bumpBadge(delta) {
    document.querySelectorAll('[data-cart-badge]').forEach(function (badge) {
      var current = parseInt(badge.textContent, 10) || 0;
      var next = Math.max(0, current + delta);
      badge.textContent = String(next);
      badge.hidden = next <= 0;
    });
  }

  function busy(form) {
    return form.getAttribute('data-cart-busy') === '1';
  }

  function lock(form) {
    form.setAttribute('data-cart-busy', '1');
    /* app-live.js holds its refreshes while any form is posting, so a poll
     * cannot replace a quantity that is still being saved. */
    form.setAttribute('data-live-busy', '1');
    form.querySelectorAll('button').forEach(function (button) {
      button.disabled = true;
    });
  }

  function unlock(form) {
    form.removeAttribute('data-cart-busy');
    form.removeAttribute('data-live-busy');
    form.querySelectorAll('button').forEach(function (button) {
      button.disabled = false;
    });
  }

  /**
   * Tell the live layer (and the member's other tabs) that a cart surface
   * changed. app-live.js is loaded after this script, so the call is
   * guarded; without it the 30s poll is the fallback.
   */
  function publishLive(keys) {
    if (!Array.isArray(keys) || keys.length === 0) {
      return;
    }

    if (window.EcoLive && typeof window.EcoLive.invalidate === 'function') {
      keys.forEach(function (key) {
        window.EcoLive.invalidate(String(key));
      });
    }
  }

  /* ------------------------------------------------------------------
   *  Verbs
   * ---------------------------------------------------------------- */
  function addFrom(form) {
    if (busy(form)) {
      return;
    }

    var productInput = form.querySelector('[name="product_id"]');
    var quantityInput = form.querySelector('[name="quantity"]');
    var quantity = Math.max(1, parseInt((quantityInput && quantityInput.value) || '1', 10) || 1);
    var productId = productInput ? productInput.value : '';

    lock(form);
    bumpBadge(quantity);

    post(config.addUrl, { product_id: productId, quantity: quantity }).then(function (payload) {
      unlock(form);

      if (!payload.ok) {
        showToast(payload.message || 'Could not add that to your cart.', 'error');
        resync();
        return;
      }

      applyState(payload);
      publishLive(payload.live);
      showToast(payload.message || 'Added to cart.', 'success');
      openDrawer();
    });
  }

  function updateFrom(form) {
    if (busy(form)) {
      return;
    }

    var itemInput = form.querySelector('[name="item_id"]');
    var quantityInput = form.querySelector('[name="quantity"]');

    if (!itemInput || !quantityInput) {
      return;
    }

    var itemId = itemInput.value;
    var quantity = Math.max(0, parseInt(quantityInput.value, 10) || 0);
    quantityInput.value = String(quantity);

    var row = rowFor(itemId);
    var previous = quantityInput.getAttribute('data-last-qty');

    if (quantity > 0) {
      previewLine(row, quantity);
    }

    lock(form);

    post(config.updateUrl, { item_id: itemId, quantity: quantity }).then(function (payload) {
      unlock(form);

      if (!payload.ok) {
        showToast(payload.message || 'Could not update that item.', 'error');

        if (previous !== null && previous !== '') {
          quantityInput.value = previous;
        }

        previewLine(row, parseInt(quantityInput.value, 10) || 0);
        resync();
        return;
      }

      quantityInput.setAttribute('data-last-qty', String(quantity));

      /* The server confirmed this quantity: make it the field's rendered
       * value so the live layer no longer treats it as unsaved input. */
      quantityInput.defaultValue = String(quantity);

      if (payload.removed) {
        removeRow(itemId);
      } else if (payload.item) {
        var updatedRow = rowFor(itemId);
        var cell = updatedRow ? updatedRow.querySelector('[data-line-total]') : null;
        if (cell && payload.item.line_total_formatted) {
          cell.textContent = payload.item.line_total_formatted;
        }
      }

      applyState(payload);
      publishLive(payload.live);

      if (payload.count === 0 && document.querySelector('[data-cart-page]')) {
        window.location.reload();
      }
    });
  }

  function removeFrom(form) {
    if (busy(form)) {
      return;
    }

    var itemInput = form.querySelector('[name="item_id"]');

    if (!itemInput) {
      return;
    }

    var itemId = itemInput.value;
    lock(form);

    post(config.removeUrl, { item_id: itemId }).then(function (payload) {
      unlock(form);

      if (!payload.ok) {
        showToast(payload.message || 'Could not remove that item.', 'error');
        resync();
        return;
      }

      removeRow(itemId);
      applyState(payload);
      publishLive(payload.live);
      showToast(payload.message || 'Item removed from your cart.', 'success');

      if (payload.count === 0 && document.querySelector('[data-cart-page]')) {
        window.location.reload();
      }
    });
  }

  /* ------------------------------------------------------------------
   *  Delegated events (the drawer body is replaced after every mutation)
   * ---------------------------------------------------------------- */
  document.addEventListener('submit', function (event) {
    var form = event.target;

    if (!form || !form.matches) {
      return;
    }

    if (form.matches('[data-cart-add]')) {
      event.preventDefault();
      addFrom(form);
      return;
    }

    if (form.matches('[data-cart-qty-form]')) {
      event.preventDefault();
      updateFrom(form);
      return;
    }

    if (form.matches('[data-cart-remove-form]')) {
      event.preventDefault();
      removeFrom(form);
    }
  });

  document.addEventListener('click', function (event) {
    var step = event.target.closest ? event.target.closest('[data-cart-step]') : null;

    if (step) {
      event.preventDefault();

      var form = step.closest('form');
      var input = form ? form.querySelector('[data-cart-qty]') : null;

      if (!form || !input) {
        return;
      }

      var delta = parseInt(step.getAttribute('data-cart-step'), 10) || 0;
      var min = parseInt(input.getAttribute('min'), 10);
      var max = parseInt(input.getAttribute('max'), 10);
      var current = parseInt(input.value, 10) || 0;
      var next = current + delta;

      if (!isNaN(min) && next < min) {
        next = min;
      }

      if (!isNaN(max) && next > max) {
        showToast('Insufficient stock. Only ' + max + ' available.', 'error');
        next = max;
      }

      if (next === current) {
        return;
      }

      input.value = String(next);

      if (form.matches('[data-cart-qty-form]')) {
        updateFrom(form);
      }

      return;
    }

    var opener = event.target.closest ? event.target.closest('[data-cart-open]') : null;

    if (opener) {
      event.preventDefault();
      openDrawer();
    }
  });

  document.addEventListener('change', function (event) {
    var input = event.target;

    if (!input || !input.matches || !input.matches('[data-cart-qty]')) {
      return;
    }

    var form = input.closest('[data-cart-qty-form]');

    if (form) {
      updateFrom(form);
    }
  });

  /* A cart or checkout surface refreshed by app-live.js (another tab added a
   * line, the catalogue moved a price, a checkout emptied the cart) leaves
   * the global drawer stale: the drawer sits outside the page's live
   * surface. Re-read the badge and the drawer from the server after each
   * applied payload. */
  document.addEventListener('ecowealth:live-applied', function (event) {
    var detail = event.detail || {};
    var surface = String(detail.surface || '');
    var tied = surface.indexOf('member.cart:') === 0
      || surface.indexOf('member.checkout:') === 0;

    if (!detail.changed || !tied) {
      return;
    }

    var meta = detail.payload && detail.payload.meta ? detail.payload.meta : null;

    if (meta && typeof meta.count === 'number') {
      setBadge(meta.count);
    }

    /* The swapped rows are fresh inputs: remember their server values so a
     * later refused update can still roll back to a quantity that exists. */
    seedQuantities(document);

    resync();
  });

  /* Seed the last server-confirmed quantity so a refused update can roll
   * the input back to a value that actually exists server-side. */
  document.addEventListener('DOMContentLoaded', function () {
    seedQuantities(document);
  });
})();
