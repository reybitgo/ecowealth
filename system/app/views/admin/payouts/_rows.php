<?php
declare(strict_types=1);

/**
 * Admin payout queue — rows.
 *
 * Each row is keyed by request id. The rejection reason is typed inside
 * this region, so the tbody is marked `data-live-hold="dirty"`: while a
 * reason is unsaved the client keeps the table (and the page shows its
 * stale banner) instead of replacing a row mid-edit.
 *
 * The amount shown is the gross the member asked for — the number that is
 * held, refunded on rejection and reported by the queue totals. "Sends"
 * is what actually leaves the company: net pesos, or USDT for the two
 * crypto channels.
 *
 * @var list<array<string, mixed>> $queue
 * @var string $tab
 * @var array{rows:int, amount:float, fees:float, net:float} $totals
 */

$badges = [
    'pending'   => ['Pending review', 'warning'],
    'approved'  => ['Approved', 'info'],
    'completed' => ['Completed', 'success'],
    'rejected'  => ['Rejected', 'danger'],
];
?>
                    <tbody data-live-region="payout-rows" data-live-hold="dirty">
<?php if ($queue === []): ?>
                      <tr>
                        <td colspan="8" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx-money-withdraw',
        'title' => $tab === '' ? 'No payout requests yet' : 'No ' . $tab . ' payout requests',
        'text'  => $tab === ''
            ? 'A member’s withdrawal request appears here as soon as it is made; the amount is already held.'
            : 'Requests move through pending → approved → completed, or are rejected with the amount refunded.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($queue as $request): ?>
<?php
    $id        = (int) $request['id'];
    $method    = (string) $request['payout_method'];
    $isUsdt    = in_array($method, PayoutService::USDT_METHODS, true);
    $status    = (string) $request['status'];
    $amount    = (float) $request['amount'];
    $fee       = (float) $request['service_fee_amount'];
    $net       = round($amount - $fee, 2);
    $usdt      = (float) $request['usdt_amount'];
    $memberUrl = url('admin/members/' . (int) $request['user_id']);
    $payoutUrl = url('admin/payouts/' . $id);
    $memberName = trim((string) ($request['member_name'] ?? ''));
?>
                      <tr data-live-key="<?= $id ?>">
                        <td>
                          <div class="text-nowrap"><?= e(format_datetime((string) $request['requested_at'])) ?></div>
                          <div class="member-meta">#<?= $id ?></div>
                        </td>
                        <td>
                          <a href="<?= e($memberUrl) ?>" class="text-body fw-medium">@<?= e((string) $request['member_username']) ?></a>
<?php if ($memberName !== ''): ?>
                          <div class="member-meta"><?= e($memberName) ?></div>
<?php endif; ?>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-2 text-nowrap">
                            <i class="icon-base <?= e(icon_class(PayoutService::icon($method))) ?>"></i>
                            <span><?= e(PayoutService::label($method)) ?></span>
                          </div>
                          <div class="member-meta font-monospace"><?= e((string) $request['payout_account']) ?></div>
                        </td>
                        <td class="text-end text-nowrap fw-medium"><?= e(money($amount)) ?></td>
                        <td class="text-end text-nowrap">
<?php if ($fee > 0): ?>
                          <div class="member-meta"><?= e(money($fee)) ?> fee</div>
<?php else: ?>
                          <div class="member-meta">no fee</div>
<?php endif; ?>
                          <div><?= e(money($net)) ?> net</div>
                        </td>
                        <td class="text-end text-nowrap">
<?php if ($isUsdt): ?>
                          <div class="fw-medium text-success"><?= e(number_format($usdt, 4, '.', ',')) ?> USDT</div>
                          <div class="member-meta">
                            @<?= e(number_format((float) $request['usdt_rate'], 2, '.', ',')) ?>
                            · <?= e(number_format((float) $request['usdt_gas_fee'], 4, '.', ',')) ?> gas
                          </div>
<?php else: ?>
                          <span class="fw-medium"><?= e(money($net)) ?></span>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status, $badges) ?>
<?php if ((string) ($request['admin_note'] ?? '') !== ''): ?>
                          <div class="member-meta"><?= e((string) $request['admin_note']) ?></div>
<?php endif; ?>
<?php if ((string) ($request['admin_username'] ?? '') !== ''): ?>
                          <div class="member-meta">
                            <?= e(format_datetime((string) ($request['processed_at'] ?? ''))) ?> ·
                            by @<?= e((string) $request['admin_username']) ?>
                          </div>
<?php endif; ?>
                        </td>
                        <td class="text-end">
                          <div class="d-flex flex-column align-items-end gap-2 payout-actions">
<?php if ($status === 'pending'): ?>
                            <form
                              method="POST"
                              action="<?= e($payoutUrl . '/approve') ?>"
                              data-confirm
                              data-confirm-title="Approve this payout?"
                              data-confirm-text="<?= e(sprintf(
                                  '%s will be released to @%s via %s (%s). Send it off-platform, then mark it complete.',
                                  money($amount),
                                  (string) $request['member_username'],
                                  PayoutService::label($method),
                                  (string) $request['payout_account']
                              )) ?>"
                              data-confirm-button="Approve"
                            >
                              <?= csrf_field() ?>
                              <button type="submit" class="btn btn-sm btn-success">
                                <i class="icon-base bx bx-check me-1"></i> Approve
                              </button>
                            </form>
<?php endif; ?>
<?php if ($status === 'pending' || $status === 'approved'): ?>
                            <form
                              method="POST"
                              action="<?= e($payoutUrl . '/reject') ?>"
                              class="payout-reject-form"
                            >
                              <?= csrf_field() ?>
                              <input type="hidden" name="page" value="<?= (int) Request::query('page', 1) ?>" />
                              <div class="input-group input-group-sm">
                                <input
                                  type="text"
                                  class="form-control"
                                  name="note"
                                  placeholder="Rejection reason"
                                  maxlength="500"
                                  required
                                  aria-label="Rejection reason"
                                  style="min-width: 0"
                                />
                                <button type="submit" class="btn btn-outline-danger">Reject</button>
                              </div>
                            </form>
<?php endif; ?>
<?php if ($status === 'approved'): ?>
                            <form
                              method="POST"
                              action="<?= e($payoutUrl . '/complete') ?>"
                              data-confirm
                              data-confirm-title="Mark this payout completed?"
                              data-confirm-text="<?= e(sprintf(
                                  'Confirm you sent %s to %s. The wallet was already debited when the request was made.',
                                  $isUsdt ? number_format($usdt, 4, '.', ',') . ' USDT' : money($net),
                                  (string) $request['payout_account']
                              )) ?>"
                              data-confirm-button="Mark complete"
                            >
                              <?= csrf_field() ?>
                              <button type="submit" class="btn btn-sm btn-primary">
                                <i class="icon-base bx bx-check-double me-1"></i> Mark complete
                              </button>
                            </form>
<?php endif; ?>
<?php if (!in_array($status, ['pending', 'approved'], true)): ?>
                            <a href="<?= e($memberUrl) ?>" class="btn btn-sm btn-label-secondary">
                              <i class="icon-base bx bx-user me-1"></i> Member
                            </a>
<?php endif; ?>
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
