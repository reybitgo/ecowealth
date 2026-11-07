/**
 * EcoWealth — commission detail drawer (plan §7 Phase 10)
 * ------------------------------------------------------
 * Every award row on /earnings and /admin/commissions carries its
 * record as a JSON `data-commission` attribute. Clicking the row's
 * "Details" button fills the shared offcanvas and opens it — no round
 * trip, and values are written with textContent so a username can
 * never inject markup.
 *
 * The links inside the drawer follow the record: a skipped award has
 * no wallet movement, so its wallet link is hidden rather than left
 * pointing at the wrong row.
 */
(function () {
  'use strict';

  var PANEL_ID = 'commissionDetail';

  function text(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
  }

  function fill(panel, payload) {
    panel.querySelectorAll('[data-detail]').forEach(function (element) {
      var key = element.getAttribute('data-detail');

      if (!Object.prototype.hasOwnProperty.call(payload, key)) {
        return;
      }

      element.textContent = text(payload[key]);
    });

    panel.querySelectorAll('[data-detail-link]').forEach(function (element) {
      var key = element.getAttribute('data-detail-link');
      var url = payload[key + '_url'] || '';

      if (url) {
        element.setAttribute('href', url);
      } else {
        /* No destination (a skipped award never wrote a wallet row):
         * keep the label readable as plain text instead of a dead link. */
        element.removeAttribute('href');
      }
    });

    var reasonRows = panel.querySelectorAll('[data-detail-row="reason"]');

    reasonRows.forEach(function (element) {
      if (payload.reason) {
        element.classList.remove('d-none');
      } else {
        element.classList.add('d-none');
      }
    });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-commission]');

    if (!trigger) {
      return;
    }

    var panel = document.getElementById(PANEL_ID);

    if (!panel) {
      return;
    }

    var payload = null;

    try {
      payload = JSON.parse(trigger.getAttribute('data-commission') || '{}');
    } catch (error) {
      payload = null;
    }

    if (!payload) {
      return;
    }

    event.preventDefault();
    fill(panel, payload);

    if (window.bootstrap && window.bootstrap.Offcanvas) {
      window.bootstrap.Offcanvas.getOrCreateInstance(panel).show();
    }
  });
})();
