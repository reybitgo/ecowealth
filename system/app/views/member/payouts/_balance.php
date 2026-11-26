<?php
declare(strict_types=1);

/**
 * Payouts — the withdrawable-balance card (live region).
 *
 * Both buckets are shown because a payout may only take the withdrawable
 * one: a member looking at their total balance would over-ask, and the
 * refusal would feel arbitrary.
 *
 * Phase 18 adds the escrow line (plan §11.1, Appendix C.2): repeat-
 * purchase money pending release sits beside the withdrawable figure it
 * is missing from, labelled "not withdrawable yet", so the over-limit
 * refusal reads as a rule rather than a surprise. When nothing is
 * withdrawable but something is pending, the card says exactly that
 * instead of a bare ₱0.00.
 *
 * @var array<string, mixed> $options
 */

$withdrawable = (float) ($options['withdrawable'] ?? 0);
$balance      = (float) ($options['balance'] ?? 0);
$locked       = (float) ($options['non_withdrawable'] ?? 0);
$min          = (float) ($options['min'] ?? 0);
$usdtRate     = (float) ($options['usdt_rate'] ?? 0);
$open         = $options['open'] ?? null;
$held         = is_array($open) ? (float) ($open['amount'] ?? 0) : 0.0;

$pending      = is_array($options['pending_repeat'] ?? null) ? $options['pending_repeat'] : [];
$pendingTotal = round((float) ($pending['total'] ?? 0), 2);
$pendingShown = $pendingTotal > 0;

/* Everything the member earned is still in escrow: this is the one state
 * where "₱0.00 can withdraw" needs an explanation, not a figure. */
$allPending = $pendingShown && $withdrawable <= 0;
?>
                <div class="card h-100 payout-balance" data-live-region="payout-balance">
                  <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                      <div>
                        <span class="text-body-secondary small d-block">Withdrawable</span>
                        <h4 class="mb-0 stat-value"><?= e(money($withdrawable)) ?></h4>
                      </div>
                      <span class="avatar avatar-md">
                        <span class="avatar-initial rounded-circle bg-label-success">
                          <i class="icon-base bx bx-money-withdraw icon-md"></i>
                        </span>
                      </span>
                    </div>

                    <div class="d-flex flex-wrap gap-4 mb-3">
                      <div>
                        <span class="text-body-secondary small d-block">Wallet balance</span>
                        <span class="fw-semibold"><?= e(money($balance)) ?></span>
                      </div>
                      <div>
                        <span class="text-body-secondary small d-block">Non-withdrawable</span>
                        <span class="fw-semibold text-body-secondary"><?= e(money($locked)) ?></span>
                      </div>
                    </div>

                    <ul class="list-unstyled small text-body-secondary mb-0 payout-facts">
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Minimum payout</span>
                        <span class="fw-medium text-body"><?= e(money($min)) ?></span>
                      </li>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Can withdraw now</span>
                        <span class="fw-medium text-body"><?= e(money($withdrawable)) ?></span>
                      </li>
<?php if ($pendingShown): ?>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Pending release</span>
                        <span class="fw-medium text-info">
                          <?= e(money($pendingTotal)) ?>
                          <span class="text-body-secondary fw-normal">· not withdrawable yet</span>
                        </span>
                      </li>
<?php endif; ?>
<?php if ($held > 0): ?>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Held by an open request</span>
                        <span class="fw-medium text-warning"><?= e(money($held)) ?></span>
                      </li>
<?php endif; ?>
<?php if ($usdtRate > 0): ?>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>USDT rate</span>
                        <span class="fw-medium text-body">
                          <?= e(money(1)) ?> = <?= e(number_format(1 / $usdtRate, 4, '.', ',')) ?> USDT
                        </span>
                      </li>
<?php endif; ?>
                    </ul>

<?php if ($allPending): ?>
                    <div class="alert alert-info py-2 small mb-0 mt-3" role="status">
                      You have <?= e(money($pendingTotal)) ?> pending release, but none is withdrawable yet.
                      It becomes available after the return window.
                    </div>
<?php else: ?>
                    <p class="small text-body-secondary mb-0 mt-3">
                      Only commission earnings are withdrawable. Top-ups, transferred funds and refunds can be spent in
                      the shop but stay non-withdrawable.
<?php if ($pendingShown): ?>
                      Pending release is repeat-purchase bonus that is not withdrawable yet.
<?php endif; ?>
                    </p>
<?php endif; ?>
                  </div>
                </div>
