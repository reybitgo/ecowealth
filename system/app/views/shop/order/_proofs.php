<?php
declare(strict_types=1);

/**
 * Member order — payment attempts card.
 *
 * The wrapper always exists so the feed can reveal the card once a proof has
 * been submitted; it carries `hidden` while there are none.
 *
 * @var list<array<string, mixed>> $proofs
 * @var array{open:bool, reason:string, deadline:?string, used:int, left:int, max:int} $window
 */

$proofBadge = static fn (string $proofStatus): string => status_badge($proofStatus, [
    'pending'  => ['Awaiting review', 'info'],
    'verified' => ['Verified', 'success'],
    'rejected' => ['Rejected', 'danger'],
]);

$max = (int) ($window['max'] ?? 0);
?>
                  <div data-live-region="member-order-proofs"<?= $proofs === [] ? ' hidden' : '' ?>>
<?php if ($proofs !== []): ?>
                    <div class="card mb-6 order-proofs-card">
                      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0">Payment attempts</h5>
                        <span class="badge bg-label-secondary"><?= count($proofs) ?> of <?= $max ?></span>
                      </div>
                      <div class="table-responsive">
                        <table class="table align-middle mb-0 order-proofs-table">
                          <thead>
                            <tr>
                              <th scope="col">#</th>
                              <th scope="col">Submitted</th>
                              <th scope="col" class="text-end">Amount</th>
                              <th scope="col">Reference</th>
                              <th scope="col">Status</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($proofs as $proof): ?>
                            <tr>
                              <td class="fw-semibold"><?= (int) $proof['attempt_no'] ?></td>
                              <td class="small text-body-secondary text-nowrap"><?= e(format_datetime((string) $proof['created_at'])) ?></td>
                              <td class="text-end text-nowrap"><?= e(money((float) ($proof['amount_claimed'] ?? 0))) ?></td>
                              <td class="small"><?= e(trim((string) ($proof['reference_no'] ?? '')) !== '' ? (string) $proof['reference_no'] : '—') ?></td>
                              <td>
                                <?= $proofBadge((string) $proof['status']) ?>
<?php if (trim((string) ($proof['reject_reason'] ?? '')) !== ''): ?>
                                <div class="small text-body-secondary mt-1"><?= e((string) $proof['reject_reason']) ?></div>
<?php endif; ?>
                              </td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
<?php endif; ?>
                  </div>
