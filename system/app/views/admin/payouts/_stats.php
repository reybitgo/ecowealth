<?php
declare(strict_types=1);

/**
 * Admin payout queue — the money cards (live region).
 *
 * Four questions, four cards: how much is held, how much is waiting for
 * a decision, how much has been approved and is being sent, and what has
 * actually been paid out. A facts line underneath states the settings the
 * queue is working with, because a USDT request is only possible once the
 * ₱/USDT rate is set.
 *
 * @var array<string, mixed> $totals
 * @var array<string, mixed> $facts
 */

$cards = [
    [
        'label' => 'Held in open requests',
        'value' => money((float) $totals['held_amount']),
        'hint'  => $totals['held'] . ' open · already out of the members’ wallets',
        'icon'  => 'bx-lock-alt',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Waiting for approval',
        'value' => money((float) $totals['pending_amount']),
        'hint'  => $totals['pending'] . ' pending request' . ((int) $totals['pending'] === 1 ? '' : 's'),
        'icon'  => 'bx-time-five',
        'tone'  => 'info',
    ],
    [
        'label' => 'Approved — sending',
        'value' => money((float) $totals['approved_amount']),
        'hint'  => $totals['approved'] . ' approved request' . ((int) $totals['approved'] === 1 ? '' : 's'),
        'icon'  => 'bx-paper-plane',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Paid out',
        'value' => money((float) $totals['completed_amount']),
        'hint'  => $totals['completed'] . ' completed · ' . money((float) $totals['completed_net']) . ' net'
            . ((float) $totals['fees_earned'] > 0 ? ' · ' . money((float) $totals['fees_earned']) . ' fees' : ''),
        'icon'  => 'bx-check-circle',
        'tone'  => 'success',
    ],
];

$usdtRate = (float) ($facts['usdt_rate'] ?? 0);
?>
              <div data-live-region="payout-stats">
                <div class="row g-6 mb-4">
<?php foreach ($cards as $card): ?>
                  <div class="col-6 col-xl-3">
                    <div class="card h-100 wallet-stat-card">
                      <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                          <span class="avatar avatar-sm">
                            <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                              <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                            </span>
                          </span>
                          <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                        </div>
                        <h4 class="mb-2 wallet-stat-value"><?= e($card['value']) ?></h4>
                        <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                      </div>
                    </div>
                  </div>
<?php endforeach; ?>
                </div>

                <div class="card mb-6">
                  <div class="card-body py-3">
                    <div class="d-flex flex-wrap gap-4 small">
                      <span>
                        <span class="text-body-secondary">Minimum payout</span>
                        <strong class="ms-1"><?= e(money((float) ($facts['min_payout'] ?? 0))) ?></strong>
                      </span>
                      <span>
                        <span class="text-body-secondary">USDT rate</span>
                        <strong class="ms-1">
<?php if ($usdtRate > 0): ?>
                          <?= e(money(1)) ?> = <?= e(number_format(1 / $usdtRate, 4, '.', ',')) ?> USDT
<?php else: ?>
                          not set — USDT payouts are closed
<?php endif; ?>
                        </strong>
                      </span>
                      <span>
                        <span class="text-body-secondary">TRC20</span>
                        <strong class="ms-1">
                          <?= e(rtrim(rtrim(number_format((float) ($facts['trc20_fee'] ?? 0), 2, '.', ''), '0'), '.')) ?>% ·
                          <?= e(number_format((float) ($facts['trc20_gas'] ?? 0), 4, '.', ',')) ?> USDT gas
                        </strong>
                      </span>
                      <span>
                        <span class="text-body-secondary">BEP20</span>
                        <strong class="ms-1">
                          <?= e(rtrim(rtrim(number_format((float) ($facts['bep20_fee'] ?? 0), 2, '.', ''), '0'), '.')) ?>% ·
                          <?= e(number_format((float) ($facts['bep20_gas'] ?? 0), 4, '.', ',')) ?> USDT gas
                        </strong>
                      </span>
<?php if ((int) ($totals['rejected'] ?? 0) > 0): ?>
                      <span>
                        <span class="text-body-secondary">Rejected</span>
                        <strong class="ms-1">
                          <?= (int) $totals['rejected'] ?> · <?= e(money((float) $totals['rejected_amount'])) ?> refunded
                        </strong>
                      </span>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
