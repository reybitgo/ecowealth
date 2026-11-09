<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — every member-to-member transfer.
 *
 * @var list<array<string, mixed>> $transfers
 */

$amount = static fn (float $value): string => money($value, true);
?>
                    <tbody data-live-region="wallet-transfers">
<?php if ($transfers === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-transfer',
        'title' => 'No transfers yet',
        'text'  => 'Members send money to each other from their own wallet page. Every transfer lands here.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($transfers as $transfer): ?>
<?php
    $id          = (int) $transfer['id'];
    $senderId    = (int) $transfer['sender_id'];
    $recipientId = (int) $transfer['recipient_id'];
    $fee         = (float) $transfer['fee'];
    $note        = trim((string) ($transfer['note'] ?? ''));
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap small"><?= e(format_datetime((string) $transfer['created_at'])) ?></td>
                        <td>
                          <a
                            href="<?= e(url('admin/wallets/' . $senderId)) ?>"
                            class="text-body fw-medium"
                            data-live-focus="sender"
                          >
                            @<?= e((string) $transfer['sender_username']) ?>
                          </a>
                          <div class="small text-body-secondary"><?= e(trim((string) ($transfer['sender_name'] ?? '')) !== '' ? (string) $transfer['sender_name'] : '—') ?></div>
                        </td>
                        <td class="text-center text-body-secondary">
                          <i class="icon-base bx bx-right-arrow-alt"></i>
                        </td>
                        <td>
                          <a
                            href="<?= e(url('admin/wallets/' . $recipientId)) ?>"
                            class="text-body fw-medium"
                            data-live-focus="recipient"
                          >
                            @<?= e((string) $transfer['recipient_username']) ?>
                          </a>
                          <div class="small text-body-secondary"><?= e(trim((string) ($transfer['recipient_name'] ?? '')) !== '' ? (string) $transfer['recipient_name'] : '—') ?></div>
                        </td>
                        <td class="text-end text-nowrap fw-semibold"><?= e($amount((float) $transfer['amount'])) ?></td>
                        <td class="text-end text-nowrap small <?= $fee > 0 ? 'text-body' : 'text-body-secondary' ?>">
                          <?= $fee > 0 ? e(money($fee)) : '—' ?>
                        </td>
                        <td class="small text-body-secondary wallet-ref-note"><?= $note !== '' ? e($note) : '—' ?></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
