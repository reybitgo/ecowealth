/**
 * EcoWealth — product binary card readout (plan §6 Phase 22B-S2)
 * --------------------------------------------------------------
 * The *Product binary points* card on the product form shows the points
 * per unit (`P.V. × quantity, rounded`) while the operator toggles the
 * switch and edits the P.V. This script only ever **re-renders** what
 * the server already rendered: with JavaScript off the same card is
 * still there, the form still saves and every rule is still enforced by
 * `Product` on the server.
 *
 * No pair-size, pair-rate or self-rate control exists here — those are
 * global settings (plan D-09). The self/pair sentence is server-rendered
 * at the global preset; this script only keeps the points readout live.
 */
(function () {
  'use strict';

  function points(pv) {
    return Math.round(parseFloat(pv) || 0);
  }

  function render(card) {
    var pvInput = document.getElementById('productPv');
    var switchInput = document.getElementById('productBinaryEnabled');
    var pointsText = card.querySelector('[data-binary-points]');
    var pointsBadge = document.getElementById('productBinaryPoints');
    var zeroHint = card.querySelector('.form-text.mb-0.text-danger');
    var pvValue = pvInput ? parseFloat(pvInput.value) : 0;
    var enabled = switchInput ? switchInput.checked : false;
    var n = points(pvValue);

    if (pointsText) {
      pointsText.textContent = enabled ? String(n) : '\u2014';
    }

    if (pointsBadge) {
      pointsBadge.textContent = enabled ? String(n) : '\u2014';
    }

    if (zeroHint) {
      /* The server already rendered the right hint; only toggle its
       * visibility when the switch moves so the readout never lies. */
      zeroHint.style.display = (enabled && pvValue <= 0) ? '' : 'none';
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var card = document.querySelector('[data-binary-card]');

    if (!card) {
      return;
    }

    card.addEventListener('change', function () {
      render(card);
    });

    card.addEventListener('input', function () {
      render(card);
    });

    ['productPv', 'productBinaryEnabled'].forEach(function (id) {
      var input = document.getElementById(id);

      if (input) {
        input.addEventListener('input', function () {
          render(card);
        });
        input.addEventListener('change', function () {
          render(card);
        });
      }
    });

    render(card);
  });
})();
