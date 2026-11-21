<?php
declare(strict_types=1);

/**
 * Admin wallet page — top-up history.
 *
 * @var list<array<string, mixed>> $topups
 * @var Paginator $topups_pager
 */
?>
                  <div class="card" data-live-region="wallet-topup-history">
                    <div class="card-header">
                      <h5 class="mb-0">Top-up history</h5>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col">Date</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">By</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($topups === []): ?>
                          <tr>
                            <td colspan="3" class="text-center text-body-secondary small py-4">No top-ups yet.</td>
                          </tr>
<?php endif; ?>
<?php foreach ($topups as $topup): ?>
                          <tr>
                            <td class="small text-nowrap">
                              <div><?= e(format_datetime((string) $topup['created_at'])) ?></div>
<?php if (trim((string) ($topup['note'] ?? '')) !== ''): ?>
                              <div class="text-body-secondary wallet-ref-note"><?= e((string) $topup['note']) ?></div>
<?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap fw-semibold wallet-amount-positive"><?= e(money((float) $topup['amount'], true)) ?></td>
                            <td><span class="badge bg-label-info"><?= e((string) $topup['admin_username']) ?></span></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <div class="card-body py-3">
<?= View::partial('pagination', ['pager' => $topups_pager, 'page_param' => 'topup_page']) ?>
                    </div>
                  </div>
