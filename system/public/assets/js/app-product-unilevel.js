/**
 * EcoWealth — repeat-purchase ladder preview (plan §6 Phase 17B)
 * --------------------------------------------------------------
 * The *Unilevel repeat-purchase bonus* card on the product form shows
 * the ladder total and each level's per-unit amount while the operator
 * types. This script only ever **re-renders** what the server already
 * rendered: with JavaScript off the same table is still there, the
 * form still saves and every rule is still enforced by Product and
 * ProductUnilevel on the server.
 *
 * Two buttons help the operator fill the ladder:
 *   • Load reference ladder — 5/5/5/3/3/2/2/2/1/1 (29%), the plan's preset.
 *   • Clear — every level back to blank (which saves as 0.000).
 *
 * The unit base follows the *Bonus base* select: P.V. when it is above
 * zero for Auto, the price when it is not. It has to match
 * ProductUnilevel::baseFor() exactly or the preview would lie.
 */
(function () {
  'use strict';

  var PRESET = [5, 5, 5, 3, 3, 2, 2, 2, 1, 1];
  var MAX_SUM = 100;

  function number(input) {
    if (!input) {
      return 0;
    }

    var value = parseFloat(input.value);

    return isNaN(value) ? 0 : value;
  }

  function money(value) {
    return '\u20B1' + value.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function percent(value) {
    var text = value.toFixed(3);

    return text.replace(/\.?0+$/, '') || '0';
  }

  function render(card) {
    var price = number(document.getElementById('productPrice'));
    var pv = number(document.getElementById('productPv'));
    var baseSelect = document.getElementById('productUnilevelBase');
    var base = baseSelect ? baseSelect.value : 'auto';
    var unit = base === 'price' ? price : (base === 'pv' ? pv : (pv > 0 ? pv : price));
    var basis = base === 'price' ? 'price' : (base === 'pv' ? 'P.V.' : (pv > 0 ? 'P.V.' : 'price'));
    var total = 0;

    card.querySelectorAll('[data-unilevel-rate]').forEach(function (input) {
      var level = input.getAttribute('data-unilevel-rate');
      var rate = number(input);
      var amount = card.querySelector('[data-unilevel-amount="' + level + '"]');

      total += rate;

      if (amount) {
        amount.textContent = rate > 0 ? money(Math.round(unit * rate) / 100) : money(0);
      }
    });

    var baseText = card.querySelector('[data-unilevel-base]');

    if (baseText) {
      baseText.textContent = money(unit) + ' (' + basis + ')';
    }

    var totalText = card.querySelector('[data-unilevel-total]');

    if (totalText) {
      totalText.textContent = percent(total) + '%';

      /* Over 100% is refused on save; show it before the round trip. */
      totalText.classList.toggle('bg-label-danger', total > MAX_SUM);
      totalText.classList.toggle('bg-label-success', total <= MAX_SUM);
    }

    var totalAmount = card.querySelector('[data-unilevel-total-amount]');

    if (totalAmount) {
      totalAmount.textContent = money(Math.round(unit * total) / 100);
    }
  }

  function rates(card) {
    return Array.prototype.slice.call(card.querySelectorAll('[data-unilevel-rate]'));
  }

  document.addEventListener('DOMContentLoaded', function () {
    var card = document.querySelector('[data-unilevel-card]');

    if (!card) {
      return;
    }

    card.addEventListener('input', function () {
      render(card);
    });

    card.addEventListener('change', function () {
      render(card);
    });

    /* The base may also change from the pricing card (price, P.V.). */
    ['productPrice', 'productPv', 'productUnilevelBase'].forEach(function (id) {
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

    var presetButton = card.querySelector('[data-unilevel-preset]');

    if (presetButton) {
      presetButton.addEventListener('click', function () {
        rates(card).forEach(function (input, index) {
          input.value = PRESET[index] === undefined ? '' : String(PRESET[index]);
        });
        render(card);
      });
    }

    var clearButton = card.querySelector('[data-unilevel-clear]');

    if (clearButton) {
      clearButton.addEventListener('click', function () {
        rates(card).forEach(function (input) {
          input.value = '';
        });
        render(card);
      });
    }

    render(card);
  });
})();
