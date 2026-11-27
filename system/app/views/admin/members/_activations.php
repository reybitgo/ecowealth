<?php
declare(strict_types=1);

/**
 * Admin member page — activation history card.
 *
 * The table and its own pagination are one region: a page clamp must move
 * the footer with the rows.
 *
 * @var list<array<string, mixed>> $activations
 * @var Paginator $activations_pager
 */
?>
                  <div class="card h-100" data-live-region="member-activations">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Activation history</h5>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col" class="text-end">#</th>
                            <th scope="col">Package</th>
                            <th scope="col">Method</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col">Requested</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($activations === []): ?>
                          <tr>
                            <td colspan="6" class="py-0">
<?php
    echo View::partial('empty_state', [
        'compact' => true,
        'icon'    => 'bx bx-package',
        'title'   => 'No activation requests',
        'text'    => 'Package activation requests appear here as members submit them.',
    ]);
?>
                            </td>
                          </tr>
<?php endif; ?>
<?php foreach ($activations as $activation): ?>
                          <tr>
                            <td class="text-body-secondary text-end">#<?= (int) $activation['id'] ?></td>
                            <td class="text-nowrap"><?= e((string) $activation['package_name']) ?></td>
                            <td><?= e(payment_method_label((string) $activation['payment_method'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) $activation['amount'])) ?></td>
                            <td>
                              <?= status_badge((string) $activation['status']) ?>
<?php if ((string) ($activation['reject_reason'] ?? '') !== ''): ?>
                              <div class="member-meta"><?= e((string) $activation['reject_reason']) ?></div>
<?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                              <span class="member-meta"><?= e(format_date((string) $activation['created_at'])) ?></span>
                              <div class="member-meta"><?= e(format_time((string) $activation['created_at'])) ?></div>
                            </td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php if (isset($activations_pager) && $activations_pager instanceof Paginator): ?>
                    <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $activations_pager, 'pager_label' => 'Requests']) ?>
                    </div>
<?php endif; ?>
                  </div>
