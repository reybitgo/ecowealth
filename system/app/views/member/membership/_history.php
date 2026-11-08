<?php
declare(strict_types=1);

/**
 * Membership page — activation history card.
 *
 * The table and its own pagination are one region: a page clamp must move
 * the footer with the rows.
 *
 * @var list<array<string, mixed>> $history
 * @var Paginator $history_pager
 */
?>
                  <div class="card h-100" data-live-region="membership-history">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Activation history</h5>
                      <small class="text-body-secondary">Every request, in order.</small>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Package</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th>When</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($history === []): ?>
                          <tr>
                            <td colspan="5" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-medal',
        'title' => 'No activation requests yet',
        'text'  => 'Requests appear here as soon as you activate a package.',
    ]);
?>
                            </td>
                          </tr>
<?php endif; ?>
<?php foreach ($history as $activation): ?>
                          <tr>
                            <td>
                              <span class="text-nowrap"><?= e((string) $activation['package_name']) ?></span>
                              <div class="member-meta">#<?= (int) $activation['id'] ?></div>
                            </td>
                            <td><?= e(payment_method_label((string) $activation['payment_method'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) $activation['amount'])) ?></td>
                            <td>
                              <?= status_badge((string) $activation['status']) ?>
<?php if ((string) $activation['status'] === 'rejected' && (string) ($activation['reject_reason'] ?? '') !== ''): ?>
                              <div class="member-meta"><?= e((string) $activation['reject_reason']) ?></div>
<?php endif; ?>
                            </td>
                            <td class="text-nowrap"><span class="member-meta"><?= e(format_datetime((string) $activation['created_at'])) ?></span></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php if (isset($history_pager) && $history_pager instanceof Paginator): ?>
                    <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $history_pager, 'pager_label' => 'Requests']) ?>
                    </div>
<?php endif; ?>
                  </div>
