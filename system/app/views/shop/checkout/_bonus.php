<?php
declare(strict_types=1);

/**
 * Checkout — the repeat-purchase preview line (Phase 17E, plan §9;
 * Phase 18 escrow copy, plan §10.6).
 *
 * Member copy is **Team chain directed**. The line has two jobs:
 *
 *   · it states the fact of this order — up to ₱X is earned by the
 *     members above the buyer when the order is paid, and released to
 *     their e-wallets after the return window (the buyer is not a
 *     recipient);
 *   · it turns the reader towards their own earnings — the same ladder
 *     pays them when their Team chain buys these products, Level 1 for
 *     their direct recruits up to Level 10, provided they keep the
 *     monthly minimum and stay Active.
 *
 * It is informational, never a promise: it says "up to", and it prints
 * the hold as a rule ("delivery + N days") — never an exact date, which
 * cannot be known before the parcel ships (plan §10.6). It is absent
 * when no cart product carries a ladder, and it never calls the bonus a
 * rebate or cashback.
 *
 * @var array<string, mixed> $bonus_preview UnilevelService::quoteCart(), total > 0
 */

$preview = $bonus_preview ?? null;

if (!is_array($preview)) {
    return;
}

$total = (float) ($preview['total'] ?? 0);

if ($total <= 0) {
    return;
}

$holdDays = UnilevelService::holdDays();
$holdCopy = $holdDays > 0 ? 'delivery + ' . $holdDays . ' days' : 'delivery';
?>
                        <div class="checkout-bonus-note mt-4" data-bonus-preview>
                          <div class="d-flex align-items-start gap-2">
                            <i class="icon-base bx bx-gift fs-5"></i>
                            <div>
                              <div class="fw-medium">
                                Up to <?= e(money($total)) ?> is earned by the members above you when this order is paid.
                                It is released to their e-wallets after the return window (<?= e($holdCopy) ?>).
                              </div>
                              <div class="small text-body-secondary mt-1">
                                The same repeat-purchase ladder pays you when your <strong>Team chain</strong>
                                buys these products: <strong>Level 1</strong> for the members you personally
                                sponsored, <strong>Level 2</strong> for their recruits, up to
                                <strong>Level 10</strong>. Keep your monthly minimum to stay Active, so your
                                level income is earned when their order is paid and released to your e-wallet
                                after the return window.
                              </div>
                            </div>
                          </div>
                        </div>
