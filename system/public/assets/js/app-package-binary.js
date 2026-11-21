/**
 * EcoWealth — package binary card readout (plan Phase 23B-S1)
 * ------------------------------------------------------------
 * The *Package binary* card on the package form shows the points per
 * activation and the pair equivalent at the current global preset while
 * the operator toggles the switch and types the points. This script only
 * ever **re-renders** what the server already rendered: with JavaScript
 * off the same card is still there, the form still saves and every rule
 * is still enforced by `Package` on the server.
 *
 * No pair-size, pair-rate, cap or hold control exists here — those are
 * global settings (plan D-06). The pair equivalent is `floor(points / PP)`
 * and the money is `pairs × pair value`, both computed from the shared
 * preset the server already rendered into data attributes.
 */
(function () {
  'use strict';

  function points(value) {
    return Math.max(0, Math.floor(parseFloat(value) || 0));
  }

  function render(card) {
    var switchInput = document.getElementById('packageBinaryEnabled');
    var pointsInput = document.getElementById('packageBinaryPoints');
    var pointsText  = card.querySelector('[data-binary-points]');
    var pairsText   = card.querySelector('[data-binary-pairs]');
    var pairPoints  = parseInt(card.dataset.binaryPairPoints || '1000', 10);
    var pairValue   = parseFloat(card.dataset.binaryPairValue || '0');
    var enabled     = switchInput ? switchInput.checked : false;
    var n           = points(pointsInput ? pointsInput.value : 0);
    var pairs       = pairPoints > 0 ? Math.floor(n / pairPoints) : 0;
    var money       = pairs * pairValue;

    if (pointsText) {
      pointsText.textContent = enabled ? String(n) : '\u2014';
    }

    if (pairsText) {
      pairsText.textContent = enabled ? String(pairs) : '\u2014';
    }

    var moneyText = card.querySelector('[data-binary-money]');
    if (moneyText) {
      moneyText.textContent = enabled ? money.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }) : '\u2014';
    }

    var zeroHint = card.querySelector('.form-text.mb-0.text-danger');
    if (zeroHint) {
      zeroHint.style.display = (enabled && n <= 0) ? '' : 'none';
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

    ['packageBinaryEnabled', 'packageBinaryPoints'].forEach(function (id) {
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
