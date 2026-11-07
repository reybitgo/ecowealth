<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — every admin top-up.
 *
 * @var list<array<string, mixed>> $topups
 */
?>
                    <tbody data-live-region="wallet-topups">
<?php if ($topups === []): ?>
                      <tr>
                        <td colspan="5" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-plus-circle',
        'title' => 'No top-ups yet',
        'text'  => 'Open a member’s wallet to credit it. Every top-up lands here and in the audit log.',
        'action_href'  => 'admin/wallets',
        'action_label' => 'Open the member directory',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($topups as $topup): ?>
                      <tr data-live-key="<?= (int) $topup['id'] ?>">
                        <td class="text-nowrap small"><?= e(format_datetime((string) $topup['created_at'])) ?></td>
                        <td>
                          <a
                            href="<?= e(url('admin/wallets/' . (int) $topup['recipient_id'])) ?>"
                            class="text-body fw-medium"
                            data-live-focus="recipient"
                          >
                            <?= e((string) $topup['recipient_username']) ?>
                          </a>
                          <?php if (trim((string) ($topup['recipient_name'] ?? '')) !== ''): ?>
                            <div class="small text-body-secondary"><?= e((string) $topup['recipient_name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap fw-semibold wallet-amount-positive"><?= e(money((float) $topup['amount'], true)) ?></td>
                        <td><span class="badge bg-label-info"><?= e((string) $topup['admin_username']) ?></span></td>
                        <td class="small text-body-secondary wallet-ref-note"><?= e(trim((string) ($topup['note'] ?? '')) !== '' ? (string) $topup['note'] : '—') ?></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
