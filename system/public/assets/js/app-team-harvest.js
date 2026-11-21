/**
 * EcoWealth — Team Harvest ladder preview (plan §6 Phase 20B)
 * ----------------------------------------------------------
 * The *Team Harvest bonus* card on /admin/settings → Commissions shows the
 * cumulative column and the full-ladder total while the operator types an
 * amount. This script only ever **re-renders what the server already
 * rendered**: with JavaScript off the same table is there with the stored
 * amounts and the stored cumulative, the form still saves and every rule is
 * still enforced by AdminSettingsController and TeamHarvestLevel on the
 * server (plan Rule 12).
 *
 * Two buttons help the operator fill the ladder:
 *   • Load reference ladder — ₱1,000 … ₱3,500,000 (₱5,095,000 in total).
 *   • Clear — every level back to blank (which saves as 0.00).
 *
 * The preset values are read from each input's `data-reference` attribute,
 * so the reference ladder lives in exactly one place — TeamHarvestLevel —
 * and this file carries no copy of it.
 */
(function () {
  'use strict';

  /* The plan's typo guard (TeamHarvestLevel::MAX_TOTAL). Only the warning
   * tone is client-side: the server refuses the save either way. */
  var MAX_TOTAL = 25000000;

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
    return Array.prototype.slice.call(card.querySelectorAll('[data-team-harvest-amount]'));
  }

  function render(card) {
    var total = 0;

    /* Document order is level order: every amount input sits in its own
     * row of the generated ladder table, 1 … 10. */
    inputs(card).forEach(function (input) {
      var level = input.getAttribute('data-team-harvest-amount');

      total += cents(input);

      var cumulative = card.querySelector('[data-team-harvest-cumulative="' + level + '"]');

      if (cumulative) {
        cumulative.textContent = money(total);
      }
    });

    /* The table footer's badge and the sentence under it carry the same
     * number, so a typo is visible in both places. */
    card.querySelectorAll('[data-team-harvest-total]').forEach(function (node) {
      node.textContent = money(total);
    });

    var badge = card.querySelector('[data-team-harvest-total-badge]');

    if (badge) {
      var over = total > MAX_TOTAL * 100;

      badge.classList.toggle('bg-label-danger', over);
      badge.classList.toggle('bg-label-success', !over);
      badge.setAttribute('title', over
        ? 'The full ladder cannot exceed \u20B125,000,000.00; the server will refuse this save.'
        : 'Full ladder total');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var card = document.querySelector('[data-team-harvest-card]');

    if (!card) {
      return;
    }

    card.addEventListener('input', function () {
      render(card);
    });

    card.addEventListener('change', function () {
      render(card);
    });

    var presetButton = card.querySelector('[data-team-harvest-preset]');

    if (presetButton) {
      presetButton.addEventListener('click', function () {
        inputs(card).forEach(function (input) {
          input.value = input.getAttribute('data-reference') || '';
        });
        render(card);
      });
    }

    var clearButton = card.querySelector('[data-team-harvest-clear]');

    if (clearButton) {
      clearButton.addEventListener('click', function () {
        inputs(card).forEach(function (input) {
          input.value = '';
        });
        render(card);
      });
    }

    /* The first render is a no-op on a page that has not been typed into:
     * the server already printed the stored amounts and cumulative. */
    render(card);
  });
})();
