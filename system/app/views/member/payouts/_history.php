<?php
declare(strict_types=1);

/**
 * Payouts — the member's withdrawal history (live region).
 *
 * One row per request, newest first: what was asked for, what will be
 * (or was) sent — including the USDT amount for a crypto payout — and
 * where the request stands. A rejected row carries the reason the member
 * was given.
 *
 * @var list<array<string, mixed>> $requests
 * @var array<string, mixed> $totals
 * @var string $statement_url
 */

$badges = [
    'pending'   => ['Pending review', 'warning'],
    'approved'  => ['Approved', 'info'],
    'completed' => ['Completed', 'success'],
    'rejected'  => ['Rejected', 'danger'],
];
?>
              <div class="card payout-history" data-live-region="payout-history">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-0">Withdrawal history</h5>
                    <p class="text-body-secondary small mb-0">
<?php if ((int) ($totals['requests'] ?? 0) > 0): ?>
                      <?= (int) $totals['requests'] ?> request<?= (int) $totals['requests'] === 1 ? '' : 's' ?> ·
                      <?= e(money((float) $totals['requested'])) ?> requested ·
                      <?= e(money((float) $totals['paid_total'])) ?> paid out
<?php if ((float) ($totals['fees_total'] ?? 0) > 0): ?>
                      · <?= e(money((float) $totals['fees_total'])) ?> in fees
<?php endif; ?>
<?php else: ?>
                      Every request you make appears here with its fee, net amount and status.
<?php endif; ?>
                    </p>
                  </div>
                  <a href="<?= e($statement_url) ?>" class="btn btn-sm btn-label-primary">
                    <i class="icon-base bx bx-list-ul me-1"></i> Statement
                  </a>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 wallet-transfer-history payout-history-table">
                    <thead>
                      <tr>
                        <th scope="col">Requested</th>
                        <th scope="col">Method</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Fee</th>
                        <th scope="col" class="text-end">Net / USDT</th>
                        <th scope="col">Status</th>
                        <th scope="col">Processed</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($requests === []): ?>
                      <tr>
                        <td colspan="7">
<?= View::partial('empty_state', [
    'icon'  => 'bx-money-withdraw',
    'title' => 'No payout requests yet',
    'text'  => 'When you cash out, every request appears here with its fee, net amount and status.',
]) ?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($requests as $request): ?>
<?php
    $method     = (string) $request['payout_method'];
    $isUsdt     = in_array($method, PayoutService::USDT_METHODS, true);
    $fee        = (float) $request['service_fee_amount'];
    $net        = round((float) $request['amount'] - $fee, 2);
    $usdtAmount = (float) $request['usdt_amount'];
    $status     = (string) $request['status'];
?>
                      <tr data-live-key="payout-<?= (int) $request['id'] ?>">
                        <td>
                          <div class="fw-medium"><?= e(format_datetime((string) $request['requested_at'])) ?></div>
                          <div class="small text-body-secondary">#<?= (int) $request['id'] ?></div>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <i class="icon-base <?= e(icon_class(PayoutService::icon($method))) ?>"></i>
                            <span><?= e(PayoutService::label($method)) ?></span>
                          </div>
                          <div class="small text-body-secondary font-monospace"><?= e((string) $request['payout_account']) ?></div>
                        </td>
                        <td class="text-end fw-medium"><?= e(money((float) $request['amount'])) ?></td>
                        <td class="text-end text-body-secondary">
<?php if ($fee > 0): ?>
                          <?= e(money($fee)) ?>
<?php else: ?>
                          —
<?php endif; ?>
                        </td>
                        <td class="text-end">
<?php if ($isUsdt && $usdtAmount > 0): ?>
                          <div class="fw-medium text-success"><?= e(number_format($usdtAmount, 4, '.', ',')) ?> USDT</div>
                          <div class="small text-body-secondary"><?= e(money($net)) ?> net</div>
<?php else: ?>
                          <span class="fw-medium"><?= e(money($net)) ?></span>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status, $badges) ?>
<?php if ($status === 'rejected' && (string) ($request['admin_note'] ?? '') !== ''): ?>
                          <div class="small text-body-secondary mt-1"><?= e((string) $request['admin_note']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="small text-body-secondary">
<?php if ((string) ($request['processed_at'] ?? '') !== '' && $request['processed_at'] !== null): ?>
                          <div><?= e(format_datetime((string) $request['processed_at'])) ?></div>
<?php if ((string) ($request['admin_username'] ?? '') !== ''): ?>
                          <div>by @<?= e((string) $request['admin_username']) ?></div>
<?php endif; ?>
<?php else: ?>
                          —
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
