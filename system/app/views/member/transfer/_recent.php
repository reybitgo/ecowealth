<?php
declare(strict_types=1);

/**
 * Send funds — recent transfers, both directions (live region).
 *
 * A row is "sent" when the signed-in member is the sender. The badge
 * and the counterparty column make the direction readable at a glance;
 * fees are shown only when one was charged.
 *
 * @var list<array<string, mixed>> $recent
 * @var array<string, mixed> $account
 * @var array{sent:int, sent_total:float, received:int, received_total:float, fees_total:float} $totals
 * @var string $statement_url
 */

$userId = (int) ($account['id'] ?? 0);
?>
              <div class="card wallet-transfer-history">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1">Transfers</h5>
                    <p class="text-body-secondary small mb-0">
                      <?= (int) $totals['sent'] ?> sent (<?= e(money((float) $totals['sent_total'])) ?>) ·
                      <?= (int) $totals['received'] ?> received (<?= e(money((float) $totals['received_total'])) ?>)
                    </p>
                  </div>
                  <a href="<?= e($statement_url) ?>" class="btn btn-sm btn-label-primary">
                    <i class="icon-base bx bx-list-ul me-1"></i> Full statement
                  </a>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Direction</th>
                        <th scope="col">Counterparty</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Fee</th>
                        <th scope="col">Note</th>
                      </tr>
                    </thead>
                    <tbody data-live-region="member-transfer-recent">
<?php if ($recent === []): ?>
                      <tr>
                        <td colspan="6" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-transfer',
        'title' => 'No transfers yet',
        'text'  => 'Money you send to another member — and money they send you — appears here.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($recent as $transfer): ?>
<?php
    $sent     = (int) $transfer['sender_id'] === $userId;
    $counter  = $sent ? (string) $transfer['recipient_username'] : (string) $transfer['sender_username'];
    $fullName = trim((string) ($sent ? ($transfer['recipient_name'] ?? '') : ($transfer['sender_name'] ?? '')));
    $fee      = (float) $transfer['fee'];
    $note     = trim((string) ($transfer['note'] ?? ''));
?>
                      <tr data-live-key="<?= (int) $transfer['id'] ?>">
                        <td class="text-nowrap small"><?= e(format_datetime((string) $transfer['created_at'])) ?></td>
                        <td>
                          <span class="badge bg-label-<?= $sent ? 'danger' : 'success' ?>">
                            <i class="icon-base bx <?= $sent ? 'bx-up-arrow-circle' : 'bx-down-arrow-circle' ?> me-1"></i>
                            <?= $sent ? 'Sent' : 'Received' ?>
                          </span>
                        </td>
                        <td>
                          <span class="fw-medium">@<?= e($counter) ?></span>
<?php if ($fullName !== ''): ?>
                          <div class="small text-body-secondary"><?= e($fullName) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap fw-semibold <?= $sent ? 'wallet-amount-negative' : 'wallet-amount-positive' ?>">
                          <?= e(money((float) $transfer['amount'], true)) ?>
                        </td>
                        <td class="text-end text-nowrap text-body-secondary small"><?= $fee > 0 ? e(money($fee)) : '—' ?></td>
                        <td class="small text-body-secondary wallet-ref-note"><?= $note !== '' ? e($note) : '—' ?></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
