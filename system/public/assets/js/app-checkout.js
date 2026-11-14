/**
 * EcoWealth — checkout behaviour (Phase 6)
 * -------------------------------------------------
 * Two jobs, both about the member's own input.
 *
 * The terms checkbox gates the Place order button, so a member cannot
 * click a submit that the server would refuse. The server validation
 * stays the source of truth; this is only the immediate visual gate.
 *
 * The rest of the page keeps a draft of what the member typed. Leaving
 * the page — Back to cart, the menu, a reload — used to discard the
 * delivery details and the payment choice, so returning meant retyping
 * them. Every edit is mirrored into sessionStorage, scoped to this tab
 * and to the member's own live surface, and put back when the checkout
 * is opened again. The draft is dropped on submit, when the member
 * picks a saved address, and when they choose Start over.
 *
 * Both behaviours are re-applied on change/input and after every page
 * show, including a bfcache restore, so a failed submit followed by a
 * back navigation cannot leave the button enabled against unticked
 * terms.
 */
(function () {
  'use strict';

  var DRAFT_KEY = 'ecowealth:checkout-draft:';
  var SAVE_DELAY = 150;

  var bound = false;
  var gateSync = null;

  function checkoutPage() {
    return document.querySelector('[data-checkout-page]');
  }

  function checkoutForm() {
    return document.getElementById('checkoutForm');
  }

  /* ---------------------------------------------------------- */
  /*  Terms gate                                                */
  /* ---------------------------------------------------------- */

  function applyGate() {
    var terms = document.getElementById('terms');
    var button = document.querySelector('[data-checkout-submit]');
    var hint = document.querySelector('[data-terms-hint]');

    if (!terms || !button) {
      return;
    }

    function sync() {
      var accepted = terms.checked;

      button.disabled = !accepted;
      button.setAttribute('aria-disabled', accepted ? 'false' : 'true');

      if (hint) {
        hint.classList.toggle('d-none', accepted);
      }
    }

    if (!bound) {
      terms.addEventListener('change', sync);
      terms.addEventListener('input', sync);
      window.addEventListener('pageshow', sync);
      bound = true;
    }

    gateSync = sync;

    sync();
  }

  /* ---------------------------------------------------------- */
  /*  Checkout draft (the member's own input)                   */
  /* ---------------------------------------------------------- */

  var formEl = null;
  var dirty = false;
  var submitting = false;
  var saveTimer = null;

  function draftKey() {
    var surface = checkoutPage() ? checkoutPage().getAttribute('data-live-surface') || '' : '';

    return DRAFT_KEY + (surface || 'guest');
  }

  function readDraft() {
    try {
      var draft = JSON.parse(window.sessionStorage.getItem(draftKey()) || 'null');

      return draft && draft.v === 1 && draft.values && typeof draft.values === 'object'
        ? draft
        : null;
    } catch (error) {
      return null;
    }
  }

  function writeDraft(draft) {
    try {
      window.sessionStorage.setItem(draftKey(), JSON.stringify(draft));
    } catch (error) {
      /* Private mode / storage disabled: the form still works, it just
       * cannot survive the detour. */
    }
  }

  function clearDraft() {
    try {
      window.sessionStorage.removeItem(draftKey());
    } catch (error) {
      /* Nothing stored, or storage disabled. */
    }
  }

  function fieldsOf(form) {
    return form.querySelectorAll('input[name], textarea[name], select[name]');
  }

  function typeOf(field) {
    return (field.getAttribute('type') || '').toLowerCase();
  }

  /* The idempotency key and the CSRF token are hidden and must never be
   * restored from a draft: the server owns both. Disabled controls (an
   * unaffordable e-wallet) are skipped for the same reason. */
  function isTracked(field) {
    var type = typeOf(field);

    return type !== 'hidden'
      && type !== 'submit'
      && type !== 'button'
      && type !== 'reset'
      && type !== 'file'
      && !field.disabled;
  }

  function collect() {
    var draft = { v: 1, savedAt: Date.now(), values: {}, checks: {}, choices: {} };
    var fields = fieldsOf(formEl);

    for (var i = 0; i < fields.length; i++) {
      var field = fields[i];
      var name = field.name || '';

      if (name === '' || !isTracked(field)) {
        continue;
      }

      var type = typeOf(field);

      if (type === 'checkbox') {
        draft.checks[name] = field.checked;
      } else if (type === 'radio') {
        if (field.checked) {
          draft.choices[name] = field.value;
        }
      } else {
        draft.values[name] = field.value;
      }
    }

    return draft;
  }

  /* The payment card's selected border follows the checked radio through
   * CSS (`:has(input:checked)`), so a restored draft needs no extra
   * work here and the live region's rendered HTML is left untouched. */

  function hasAddressChoice() {
    try {
      return new URLSearchParams(window.location.search).has('address');
    } catch (error) {
      return /[?&]address=/.test(window.location.search);
    }
  }

  function notice() {
    return document.querySelector('[data-checkout-draft]');
  }

  function applyDraft(draft) {
    var changed = false;
    var fields = fieldsOf(formEl);

    for (var i = 0; i < fields.length; i++) {
      var field = fields[i];
      var name = field.name || '';

      if (name === '') {
        continue;
      }

      var type = typeOf(field);

      if (type === 'checkbox') {
        if (Object.prototype.hasOwnProperty.call(draft.checks, name) && field.checked !== draft.checks[name]) {
          field.checked = draft.checks[name] === true;
          changed = true;
        }

        continue;
      }

      if (type === 'radio') {
        var choice = draft.choices[name];

        if (choice !== undefined && choice === field.value && !field.checked && !field.disabled) {
          field.checked = true;
          changed = true;
        }

        continue;
      }

      if (!Object.prototype.hasOwnProperty.call(draft.values, name)) {
        continue;
      }

      var saved = draft.values[name] === null || draft.values[name] === undefined
        ? ''
        : String(draft.values[name]);

      if (field.value !== saved) {
        field.value = saved;
        changed = true;
      }
    }

    return changed;
  }

  function saveNow() {
    if (formEl === null || submitting || !dirty) {
      return;
    }

    writeDraft(collect());
  }

  function scheduleSave() {
    dirty = true;

    if (saveTimer !== null) {
      window.clearTimeout(saveTimer);
    }

    saveTimer = window.setTimeout(function () {
      saveTimer = null;
      saveNow();
    }, SAVE_DELAY);
  }

  function flush() {
    if (saveTimer !== null) {
      window.clearTimeout(saveTimer);
      saveTimer = null;
    }

    saveNow();
  }

  function restoreDraft() {
    /* The server just refused a submit and re-rendered the posted
     * values; they are newer than the draft, so the draft waits. Keep
     * the posted values instead, so a detour from here still finds
     * them. */
    if (formEl.getAttribute('data-checkout-posted') === '1') {
      dirty = true;
      saveNow();
      return;
    }

    /* Picking a saved address is an explicit "fill from this card". */
    if (hasAddressChoice()) {
      return;
    }

    var draft = readDraft();

    if (draft && applyDraft(draft)) {
      var alert = notice();

      if (alert) {
        alert.classList.remove('d-none');
      }
    }
  }

  function startOver() {
    clearDraft();
    dirty = false;

    if (formEl) {
      formEl.reset();
    }

    var alert = notice();

    if (alert) {
      alert.classList.add('d-none');
    }

    if (gateSync) {
      gateSync();
    }
  }

  function initDraft() {
    formEl = checkoutForm();

    if (!formEl) {
      return;
    }

    restoreDraft();

    formEl.addEventListener('input', scheduleSave);
    formEl.addEventListener('change', scheduleSave);

    /* A submit drops the draft: a successful placement lands on the
     * order page, and a refusal re-renders the posted values and
     * starts the draft again above. */
    formEl.addEventListener('submit', function () {
      submitting = true;
      clearDraft();
    });

    /* A saved-address click starts a fresh form on the next page. */
    document.addEventListener('click', function (event) {
      var card = event.target && event.target.closest
        ? event.target.closest('.checkout-address-card')
        : null;

      if (card) {
        clearDraft();
      }
    });

    var startOverButton = document.querySelector('[data-checkout-draft-clear]');

    if (startOverButton) {
      startOverButton.addEventListener('click', startOver);
    }

    /* The debounce may still be pending when the member leaves; write
     * the last keystroke before the document goes away. */
    window.addEventListener('pagehide', flush);
    window.addEventListener('beforeunload', flush);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        flush();
      }
    });
  }

  function init() {
    initDraft();
    applyGate();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.addEventListener('load', applyGate);
})();
