/**
 * EcoWealth — payout request form helpers (plan §5.9, Phase 13)
 *
 * Two small behaviours, both progressive enhancement:
 *
 *   1. the account box of the selected channel is shown and the others are
 *      hidden (without JavaScript every box is visible, and the server
 *      reads only the selected channel's box — so the form still works);
 *   2. a live preview of "you request / service fee / you receive" and, for
 *      the two USDT channels, the USDT amount — computed from the data
 *      attributes the server rendered, with the same formula
 *      PayoutService::quote() uses;
 *   3. an inline warning when the amount is below the minimum or above the
 *      withdrawable balance. The server still refuses it.
 *
 * The preview never sends anything and never decides anything: the
 * authoritative rules live in PayoutService.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-payout-form]');

  if (!form) {
    return;
  }

  var amountInput = form.querySelector('[data-payout-amount]');
  var currency = form.getAttribute('data-currency') || '';
  var min = parseFloat(form.getAttribute('data-min')) || 0;
  var maxAmount = parseFloat(form.getAttribute('data-max')) || 0;
  var formRate = parseFloat(form.getAttribute('data-usdt-rate')) || 0;

  var amountOut = form.querySelector('[data-payout-preview-amount]');
  var feeOut = form.querySelector('[data-payout-preview-fee]');
  var netOut = form.querySelector('[data-payout-preview-net]');
  var usdtOut = form.querySelector('[data-payout-preview-usdt]');
  var usdtRow = form.querySelector('[data-payout-preview-usdt-row]');
  var warnOut = form.querySelector('[data-payout-preview-warning]');

  function money(value) {
    var amount = Number(value) || 0;

    return currency + amount.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function usdt(value) {
    var amount = Number(value) || 0;

    return amount.toLocaleString('en-PH', {
      minimumFractionDigits: 4,
      maximumFractionDigits: 4
    }) + ' USDT';
  }

  function selected() {
    return form.querySelector('[data-payout-method]:checked');
  }

  function feePct() {
    var radio = selected();

    return radio ? parseFloat(radio.getAttribute('data-fee-pct')) || 0 : 0;
  }

  function isUsdt() {
    var radio = selected();

    return radio ? radio.getAttribute('data-usdt') === '1' : false;
  }

  function gasFee() {
    var radio = selected();

    return radio ? parseFloat(radio.getAttribute('data-gas')) || 0 : 0;
  }

  function rate() {
    var radio = selected();

    if (!radio) {
      return formRate;
    }

    var own = parseFloat(radio.getAttribute('data-usdt-rate'));

    return isFinite(own) && own > 0 ? own : formRate;
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

  function showChannel(key) {
    var groups = form.querySelectorAll('[data-payout-channel-group]');
    var i;

    for (i = 0; i < groups.length; i++) {
      if (groups[i].getAttribute('data-payout-channel-group') === key) {
        groups[i].removeAttribute('hidden');
      } else {
        groups[i].setAttribute('hidden', 'hidden');
      }
    }
  }

  function render() {
    var amount = amountInput ? parseFloat(amountInput.value) : 0;

    if (!isFinite(amount) || amount < 0) {
      amount = 0;
    }

    var pct = feePct();
    var fee = Math.round(amount * pct) / 100;
    fee = Math.round(fee * 100) / 100;

    var net = Math.round((amount - fee) * 100) / 100;
    var crypto = isUsdt();

    if (amountOut) {
      amountOut.textContent = money(amount);
    }

    if (feeOut) {
      feeOut.textContent = fee > 0 ? money(fee) + ' (' + pct + '%)' : money(0);
    }

    if (netOut) {
      netOut.textContent = money(Math.max(0, net));
    }

    if (usdtRow && usdtOut) {
      if (crypto) {
        var usdtValue = 0;
        var r = rate();

        if (r > 0) {
          var netPhp = Math.round((net - gasFee() * r) * 100) / 100;
          usdtValue = netPhp > 0 ? Math.round((netPhp / r) * 10000) / 10000 : 0;
        }

        usdtOut.textContent = usdt(usdtValue);
        usdtRow.classList.remove('d-none');
      } else {
        usdtRow.classList.add('d-none');
      }
    }

    if (amount <= 0) {
      warn('');
      return;
    }

    if (min > 0 && amount < min) {
      warn('The minimum payout is ' + money(min) + '.');
      return;
    }

    if (maxAmount >= 0 && amount > maxAmount + 0.00001) {
      warn('You can withdraw up to ' + money(maxAmount) + ' right now.');
      return;
    }

    warn('');
  }

  var radios = form.querySelectorAll('[data-payout-method]');
  var i;

  for (i = 0; i < radios.length; i++) {
    radios[i].addEventListener('change', function () {
      showChannel(this.getAttribute('data-channel') || '');
      render();
    });
  }

  var checked = selected();

  if (checked) {
    showChannel(checked.getAttribute('data-channel') || '');
  }

  if (amountInput) {
    amountInput.addEventListener('input', render);
    render();
  }
})();
