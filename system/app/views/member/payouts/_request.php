<?php
declare(strict_types=1);

/**
 * Payouts — the request card (live region).
 *
 * Three states, one region:
 *
 *   · an open request holds the member's money — show it and the next
 *     step, and close the form (one request at a time);
 *   · the member has nothing withdrawable / no channel / is below the
 *     floor — explain why instead of showing a form that cannot work;
 *   · otherwise the request form.
 *
 * The region is held while it has unsaved input, so a background refresh
 * can never wipe a half-typed amount or account.
 *
 * @var array<string, mixed> $options
 * @var string $payout_url
 * @var string $profile_url
 */

$open      = $options['open'] ?? null;
$canRequest = (bool) ($options['can_request'] ?? false);
$withdrawable = (float) ($options['withdrawable'] ?? 0);
$min       = (float) ($options['min'] ?? 0);
$channels  = (int) ($options['channels_open'] ?? 0);
$methods   = $options['methods'] ?? [];
$badges    = ['approved' => ['Approved', 'info'], 'pending' => ['Pending review', 'warning']];
?>
              <div data-live-region="payout-request" data-live-hold="dirty">
<?php if (is_array($open)): ?>
<?php
    $status      = (string) ($open['status'] ?? 'pending');
    $fee         = (float) ($open['service_fee_amount'] ?? 0);
    $net         = round((float) ($open['amount'] ?? 0) - $fee, 2);
    $usdtAmount  = (float) ($open['usdt_amount'] ?? 0);
    $isUsdt      = in_array((string) ($open['payout_method'] ?? ''), PayoutService::USDT_METHODS, true);
?>
                <div class="card h-100 payout-open-card">
                  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                      <h5 class="mb-1">Withdrawal in progress</h5>
                      <p class="text-body-secondary small mb-0">
                        The amount is already held — you cannot spend it while it is in the queue.
                      </p>
                    </div>
                    <?= status_badge($status, $badges) ?>
                  </div>
                  <div class="card-body">
                    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-3 mb-4">
                      <div>
                        <span class="text-body-secondary small d-block">Held amount</span>
                        <h3 class="mb-0"><?= e(money((float) $open['amount'])) ?></h3>
                      </div>
                      <div class="text-end">
                        <span class="text-body-secondary small d-block">
                          <?= $status === 'approved' ? 'Will be sent' : 'Will be sent once approved' ?>
                        </span>
                        <span class="fw-semibold">
<?php if ($isUsdt && $usdtAmount > 0): ?>
                          <?= e(number_format($usdtAmount, 4, '.', ',')) ?> USDT
<?php else: ?>
                          <?= e(money($net)) ?>
<?php endif; ?>
                        </span>
                      </div>
                    </div>

                    <ul class="list-unstyled small text-body-secondary mb-4 payout-facts">
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Method</span>
                        <span class="fw-medium text-body"><?= e(PayoutService::label((string) $open['payout_method'])) ?></span>
                      </li>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Account</span>
                        <span class="fw-medium text-body font-monospace"><?= e((string) $open['payout_account']) ?></span>
                      </li>
<?php if ($fee > 0): ?>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Service fee (<?= e(rtrim(rtrim(number_format((float) $open['service_fee_pct'], 2, '.', ''), '0'), '.')) ?>%)</span>
                        <span class="fw-medium text-body"><?= e(money($fee)) ?></span>
                      </li>
<?php endif; ?>
<?php if ($isUsdt): ?>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Rate used</span>
                        <span class="fw-medium text-body">
                          <?= e(number_format(1 / max(0.0001, (float) $open['usdt_rate']), 4, '.', ',')) ?> USDT per <?= e(money(1)) ?>
                        </span>
                      </li>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Network fee</span>
                        <span class="fw-medium text-body"><?= e(number_format((float) $open['usdt_gas_fee'], 4, '.', ',')) ?> USDT</span>
                      </li>
<?php endif; ?>
                      <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>Requested</span>
                        <span class="fw-medium text-body"><?= e(format_datetime((string) $open['requested_at'])) ?></span>
                      </li>
                    </ul>

                    <div class="alert alert-info mb-0" role="status">
                      <div class="small">
<?php if ($status === 'approved'): ?>
                        <strong>Approved.</strong> The company is sending the funds to the account above. This page
                        updates when the payout is completed.
<?php else: ?>
                        <strong>Waiting for review.</strong> An administrator approves the request, then sends the funds
                        off-platform and marks it complete.
<?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
<?php elseif (!$canRequest): ?>
                <div class="card h-100">
                  <div class="card-header">
                    <h5 class="mb-1">Withdraw earnings</h5>
                    <p class="text-body-secondary small mb-0">This is where you cash out withdrawable earnings.</p>
                  </div>
                  <div class="card-body">
<?php if ($channels === 0): ?>
                    <div class="alert alert-warning mb-4" role="alert">
                      <strong>No payout channel is open.</strong>
                      Payouts are temporarily unavailable. Please check back later or contact support.
                    </div>
<?php elseif ($withdrawable <= 0): ?>
                    <div class="alert alert-info mb-4" role="alert">
                      <strong>Nothing to withdraw yet.</strong>
                      Commission earnings are withdrawable; admin top-ups, transferred funds and refunds are not.
                      Keep earning and come back when your withdrawable balance grows.
                    </div>
<?php else: ?>
                    <div class="alert alert-info mb-4" role="alert">
                      <strong>The minimum payout is <?= e(money($min)) ?>.</strong>
                      You have <?= e(money($withdrawable)) ?> withdrawable, so the request cannot be made yet.
                    </div>
<?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                      <a href="<?= e($profile_url) ?>" class="btn btn-label-primary">
                        <i class="icon-base bx bx-credit-card me-1"></i> Payout details
                      </a>
                      <a href="<?= e(url('wallet')) ?>" class="btn btn-label-secondary">
                        <i class="icon-base bx bx-wallet me-1"></i> Open e-wallet
                      </a>
                    </div>
                  </div>
                </div>
<?php else: ?>
<?= View::capture('member/payouts/_form', [
    'options'    => $options,
    'payout_url' => $payout_url,
    'methods'    => $methods,
]) ?>
<?php endif; ?>
              </div>
