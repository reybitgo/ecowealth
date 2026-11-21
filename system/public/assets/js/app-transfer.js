/**
 * EcoWealth — send-funds form helpers (plan §5.8, Phase 12)
 *
 * Three small behaviours, all progressive enhancement:
 *
 *   1. a live preview of "you send / fee / total debit" as the amount is
 *      typed, from the data attributes the server rendered;
 *   2. an inline warning when the amount is below the minimum or the
 *      balance cannot cover amount + fee — the server still refuses it;
 *   3. a show/hide toggle for the confirmation password field.
 *
 * The preview never sends anything and never decides anything: the
 * authoritative rules live in TransferService, and this file only
 * explains them before the member submits.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-transfer-form]');

  if (!form) {
    return;
  }

  var amountInput = form.querySelector('#transferAmount');
  var currency = form.getAttribute('data-currency') || '';
  var fee = parseFloat(form.getAttribute('data-fee')) || 0;
  var min = parseFloat(form.getAttribute('data-min')) || 0;
  var maxSend = parseFloat(form.getAttribute('data-max')) || 0;

  var sendOut = form.querySelector('[data-transfer-preview-send]');
  var totalOut = form.querySelector('[data-transfer-preview-total]');
  var warnOut = form.querySelector('[data-transfer-preview-warning]');

  function money(value) {
    var amount = Number(value) || 0;

    return currency + amount.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function warn(message) {
    if (!warnOut) {
      return;
    }

    warnOut.textContent = message;

    if (message) {
      warnOut.classList.remove('d-none');
    } else {
      warnOut.classList.add('d-none');
    }
  }

  function render() {
    var amount = amountInput ? parseFloat(amountInput.value) : 0;

    if (!isFinite(amount) || amount < 0) {
      amount = 0;
    }

    if (sendOut) {
      sendOut.textContent = money(amount);
    }

    if (totalOut) {
      totalOut.textContent = money(amount + fee);
    }

    if (amount <= 0) {
      warn('');
      return;
    }

    if (min > 0 && amount < min) {
      warn('The minimum transfer is ' + money(min) + '.');
      return;
    }

    if (maxSend >= 0 && amount > maxSend + 0.00001) {
      warn('Your balance cannot cover ' + money(amount) + ' plus the ' + money(fee) + ' fee.');
      return;
    }

    warn('');
  }

  if (amountInput) {
    amountInput.addEventListener('input', render);
    render();
  }

  var toggle = form.querySelector('[data-transfer-password-toggle]');

  if (toggle) {
    toggle.addEventListener('click', function () {
      var field = document.getElementById(toggle.getAttribute('aria-controls') || '');

      if (!field) {
        return;
      }

      var shown = field.getAttribute('type') === 'text';
      field.setAttribute('type', shown ? 'password' : 'text');
      toggle.setAttribute('aria-pressed', shown ? 'false' : 'true');
      toggle.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');

      var icon = toggle.querySelector('i');

      if (icon) {
        icon.className = 'icon-base ' + (shown ? 'bx bx-show' : 'bx bx-hide');
      }
    });
  }
})();
