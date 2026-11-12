<?php
declare(strict_types=1);

/**
 * Checkout — the repeat-purchase preview line (Phase 17E, plan §9).
 *
 * Informational, never a promise (Rule 7): it says "up to", it names
 * the sponsor chain as the recipient, and it says the money is
 * credited **when the order is paid** (Rule 3). It is absent when no
 * cart product carries a ladder, and it never calls the bonus a rebate
 * (Rule 4) — the buyer is not a recipient.
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
?>
                        <div class="checkout-bonus-note mt-4" data-bonus-preview>
                          <div class="d-flex align-items-start gap-2">
                            <i class="icon-base bx bx-gift fs-5"></i>
                            <div>
                              <div class="fw-medium">
                                Up to <?= e(money($total)) ?> goes to your sponsor chain
                                when this order is paid.
                              </div>
                              <div class="small text-body-secondary mt-1">
                                This is the repeat-purchase bonus on the products in this order.
                                It is credited when the order is paid, and only to an upline who is
                                eligible and Active that month. It is not paid to you.
                              </div>
                            </div>
                          </div>
                        </div>
