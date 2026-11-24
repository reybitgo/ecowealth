/**
 * EcoWealth — the reference-ladder toolbar (Settings → Commissions, product form)
 * ------------------------------------------------------------------------------
 * One panel, three ladders: Team Harvest, General Harvest and the product's
 * repeat-purchase ladder. It closes the three gaps the old pair of buttons
 * left open:
 *
 *   • What does it load?      the panel prints the preset total.
 *   • Is it already loaded?   the badge reads Matches reference / Custom
 *                             ladder / Empty ladder from the stored values,
 *                             and switches to Unsaved changes as soon as an
 *                             input drifts from what the form holds.
 *   • Can I take it back?     every fill and clear prints an aria-live
 *                             confirmation with a one-click Undo that restores
 *                             the exact values that were there.
 *
 * The toolbar carries its own wiring (`data-ladder-card`, `data-ladder-field`,
 * `data-ladder-saved`, `data-ladder-unit`, `data-ladder-save-word`), so this
 * one script serves a money ladder in centavos and a rate ladder in thousandths
 * of a percent without knowing which card it is on.
 *
 * Progressive enhancement only: the badge is server-rendered and the buttons
 * are inert without this script, so the form still saves and validates. The
 * preset values come from each input's `data-reference`, so the reference
 * ladder still lives only in its model.
 */
(function () {
  'use strict';

  function setup(toolbar) {
    var cardSelector  = toolbar.getAttribute('data-ladder-card');
    var fieldSelector = toolbar.getAttribute('data-ladder-field');
    var savedName     = toolbar.getAttribute('data-ladder-saved');
    var saveWord      = toolbar.getAttribute('data-ladder-save-word') || 'form';
    var isPercent     = toolbar.getAttribute('data-ladder-unit') === 'percent';
    var scale         = isPercent ? 1000 : 100;

    var card = cardSelector ? toolbar.closest(cardSelector) : null;

    if (!card || !fieldSelector) {
      return;
    }

    var fields = Array.prototype.slice.call(card.querySelectorAll(fieldSelector));

    if (fields.length === 0) {
      return;
    }

    var chip         = toolbar.querySelector('[data-ladder-state]');
    var feedback     = toolbar.querySelector('[data-ladder-feedback]');
    var presetButton = toolbar.querySelector('[data-ladder-preset]');
    var clearButton  = toolbar.querySelector('[data-ladder-clear]');
    var applying     = false;

    /* Ladder values as integers at the ladder's own precision: centavos for
     * money, thousandths of a percent for rates. Floats never sum money. */
    function units(value) {
      var number = parseFloat(value);

      return isNaN(number) ? 0 : Math.round(number * scale);
    }

    function total(totalUnits) {
      if (isPercent) {
        var text = (totalUnits / scale).toFixed(3).replace(/\.?0+$/, '');

        return (text === '' ? '0' : text) + '%';
      }

      return '\u20B1' + (totalUnits / 100).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function values() {
      return fields.map(function (field) {
        return field.value;
      });
    }

    /* Fill the inputs through an input event, so each card's own preview
     * script (the cumulative column, the total, the per-unit amounts)
     * redraws exactly as if the operator had typed. */
    function fill(next) {
      applying = true;

      fields.forEach(function (field, index) {
        field.value = next[index];
        field.dispatchEvent(new Event('input', { bubbles: true }));
      });

      applying = false;
    }

    function hideFeedback() {
      if (!feedback) {
        return;
      }

      feedback.textContent = '';
      feedback.classList.remove('text-success', 'text-body-secondary');
      feedback.classList.add('d-none');
    }

    /* `tone` is 'success' for a change the operator just made and muted for
     * a no-op, so a confirmation never looks louder than the change. */
    function say(message, undoValues, tone) {
      if (!feedback) {
        return;
      }

      feedback.textContent = '';
      feedback.classList.remove('d-none');
      feedback.classList.toggle('text-success', tone === 'success');
      feedback.classList.toggle('text-body-secondary', tone !== 'success');

      var icon = document.createElement('i');

      icon.className = 'bx me-1 ' + (tone === 'success' ? 'bx-check-circle' : 'bx-info-circle');
      feedback.appendChild(icon);

      var text = document.createElement('span');

      text.textContent = message;
      feedback.appendChild(text);

      if (!undoValues) {
        return;
      }

      var undo = document.createElement('button');

      undo.type = 'button';
      undo.className = 'btn btn-sm btn-link p-0 ms-2 align-baseline';
      undo.textContent = 'Undo';
      undo.addEventListener('click', function () {
        fill(undoValues);
        refresh();
        say('Reverted to the ten amounts from before.');

        if (presetButton) {
          presetButton.focus();
        }
      });
      feedback.appendChild(undo);
    }

    function refresh() {
      var matches = true;
      var empty   = true;
      var saved   = true;

      fields.forEach(function (field) {
        var value = units(field.value);

        if (value !== units(field.getAttribute('data-reference'))) {
          matches = false;
        }

        if (value !== 0) {
          empty = false;
        }

        if (savedName && value !== units(field.getAttribute(savedName))) {
          saved = false;
        }
      });

      if (chip) {
        var tone = !saved ? 'warning' : (matches ? 'success' : 'secondary');

        chip.className = 'badge bg-label-' + tone;
        chip.textContent = !saved ? 'Unsaved changes' : (matches ? 'Matches reference' : (empty ? 'Empty ladder' : 'Custom ladder'));
      }

      if (presetButton) {
        presetButton.title = matches
          ? 'The ten amounts already match the reference ladder.'
          : 'Fill the ten amounts with the reference ladder. Nothing is stored until you save.';
      }
    }

    if (presetButton) {
      presetButton.addEventListener('click', function () {
        var before  = values();
        var changed = false;

        fields.forEach(function (field) {
          if (units(field.getAttribute('data-reference')) !== units(field.value)) {
            changed = true;
          }
        });

        if (!changed) {
          refresh();
          say('Already showing the reference ladder — nothing changed.');

          return;
        }

        hideFeedback();
        fill(fields.map(function (field) {
          return field.getAttribute('data-reference') || '';
        }));
        refresh();

        var sum = fields.reduce(function (running, field) {
          return running + units(field.getAttribute('data-reference'));
        }, 0);

        say(
          'Reference ladder loaded — ' + total(sum) + ' across ten levels. Nothing is saved until you save this ' + saveWord + '.',
          before,
          'success'
        );
      });
    }

    if (clearButton) {
      clearButton.addEventListener('click', function () {
        var before = values();
        var had    = before.some(function (value) {
          return units(value) !== 0;
        });

        if (!had) {
          refresh();
          say('The ten amounts are already empty.');

          return;
        }

        hideFeedback();
        fill(fields.map(function () {
          return '';
        }));
        refresh();
        say('All ten amounts cleared. Nothing is saved until you save this ' + saveWord + '.', before, 'success');
      });
    }

    /* A confirmation belongs to the action that printed it: the moment the
     * operator edits a field by hand, it is stale and goes away. */
    card.addEventListener('input', function (event) {
      if (applying || fields.indexOf(event.target) === -1) {
        return;
      }

      hideFeedback();
      refresh();
    });

    refresh();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ladder-toolbar]'), setup);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
