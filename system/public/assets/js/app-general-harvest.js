/**
 * EcoWealth — General Harvest board preview (plan §6 Phase 21B)
 * -----------------------------------------------------------
 * The *General Harvest bonus* card on /admin/settings → Commissions shows
 * the cumulative column, the full-ladder total, the per-depth total and an
 * "if the queue reaches N" calculator while the operator types an amount.
 *
 * This script only ever **re-renders what the server already rendered**:
 * with JavaScript off the same numbers are there from the stored ladder, the
 * form still saves and every rule is still enforced by
 * AdminSettingsController and GeneralHarvestLevel on the server (Rule 12).
 *
 * The arithmetic is the plan's closed form, copied from Appendix F and
 * nothing else:
 *
 *     paid(L, N)    = max(0, floor((N − (3^L − 1)/2) / 3^L))
 *     boardTotal(N) = Σ_L amount(L) · paid(L, N)
 *
 * The reference-ladder buttons, their state badge and their confirmation
 * live in `app-ladder-toolbar.js`; this file only paints the numbers, and
 * repaints them when that toolbar fills or clears the inputs. Centavos,
 * never floats, are summed: a typed ladder is money.
 */
(function () {
  'use strict';

  /* The plan's typo guard (GeneralHarvestLevel::MAX_TOTAL). Only the warning
   * tone is client-side: the server refuses the save either way. */
  var MAX_TOTAL = 25000000;
  var LEVELS = 10;

  function cents(input) {
    var value = parseFloat(input.value);

    return isNaN(value) ? 0 : Math.round(value * 100);
  }

  function money(centavos) {
    return '\u20B1' + (centavos / 100).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function inputs(card) {
    return Array.prototype.slice.call(card.querySelectorAll('[data-general-harvest-amount]'));
  }

  function slots(level) {
    return Math.pow(3, level);
  }

  /* paid(L, N) — the number of nodes whose level-L block is full. */
  function paid(level, n) {
    var size = slots(level);
    var half = Math.floor((size - 1) / 2);

    return Math.max(0, Math.floor((Math.max(0, n) - half) / size));
  }

  function renderLadder(card) {
    var total = 0;

    inputs(card).forEach(function (input) {
      var level = parseInt(input.getAttribute('data-general-harvest-amount'), 10);

      total += cents(input);

      var cumulative = card.querySelector('[data-general-harvest-cumulative="' + level + '"]');

      if (cumulative) {
        cumulative.textContent = money(total);
      }
    });

    /* The badge and the sentence carry the same number, so a slipped zero
     * is visible in both places. */
    card.querySelectorAll('[data-general-harvest-total]').forEach(function (node) {
      node.textContent = money(total);
    });

    var badge = card.querySelector('[data-general-harvest-total-badge]');

    if (badge) {
      var over = total > MAX_TOTAL * 100;

      badge.classList.toggle('bg-label-danger', over);
      badge.classList.toggle('bg-label-success', !over);
      badge.setAttribute('title', over
        ? 'The full ladder cannot exceed \u20B125,000,000.00; the server will refuse this save.'
        : 'Full ladder total');
    }

    return total;
  }

  /* What the ladder pays at the configured depth: the levels the operator
   * typed the depth for, priced. */
  function renderDepth(card) {
    var depthInput = card.querySelector('[name="general_harvest_max_levels"]');
    var depth = depthInput ? parseInt(depthInput.value, 10) : LEVELS;

    if (isNaN(depth)) {
      depth = LEVELS;
    }

    depth = Math.max(1, Math.min(LEVELS, depth));

    var amount = {};
    var total = 0;

    inputs(card).forEach(function (input) {
      amount[parseInt(input.getAttribute('data-general-harvest-amount'), 10)] = cents(input);
    });

    for (var level = 1; level <= depth; level++) {
      total += amount[level] || 0;
    }

    card.querySelectorAll('[data-general-harvest-depth]').forEach(function (node) {
      node.textContent = String(depth);
    });

    card.querySelectorAll('[data-general-harvest-depth-total]').forEach(function (node) {
      node.textContent = money(total);
    });
  }

  function renderCalculator(card) {
    var input = card.querySelector('[data-general-harvest-calculator-input]');

    if (!input) {
      return;
    }

    var n = parseInt(input.value, 10);

    if (isNaN(n) || n < 0) {
      n = 0;
    }

    var amount = {};
    var total = 0;
    var awards = 0;

    inputs(card).forEach(function (field) {
      amount[parseInt(field.getAttribute('data-general-harvest-amount'), 10)] = cents(field);
    });

    for (var level = 1; level <= LEVELS; level++) {
      var rows = paid(level, n);

      if (rows === 0) {
        continue;
      }

      total += (amount[level] || 0) * rows;
      awards += rows;
    }

    card.querySelectorAll('[data-general-harvest-calculator-total]').forEach(function (node) {
      node.textContent = money(total);
    });

    card.querySelectorAll('[data-general-harvest-calculator-awards]').forEach(function (node) {
      node.textContent = awards.toLocaleString('en-PH');
    });
  }

  function render(card) {
    renderLadder(card);
    renderDepth(card);
    renderCalculator(card);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var card = document.querySelector('[data-general-harvest-card]');

    if (!card) {
      return;
    }

    card.addEventListener('input', function () {
      render(card);
    });

    card.addEventListener('change', function () {
      render(card);
    });

    /* The first render is a no-op on a page that has not been typed into:
     * the server already printed the stored ladder and the calculator's
     * default for the current queue size. */
    render(card);
  });
})();
