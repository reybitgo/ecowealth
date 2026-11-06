<?php
declare(strict_types=1);

/**
 * Admin activation queue (plan §4.4, §5.10; Phase 9).
 *
 * Manual (proof-backed) activations wait here. Approving sets the
 * member active, attaches the package and runs the commission hook
 * exactly once — all inside ActivationService.
 *
 * @var array{total:int, review:int, approved:int, rejected:int, amount_review:float, amount_approved:float} $stats
 * @var array{status:string, method:string, q:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $activations
 * @var array{review:int, approved:int, rejected:int} $tab_counts
 */

$status     = (string) $filters['status'];
$method     = (string) $filters['method'];
$query      = (string) $filters['q'];
$hasFilters = $status !== '' || $method !== '' || $query !== '';

$tabs = [
    ''               => ['label' => 'All', 'count' => $stats['total']],
    'pending_review' => ['label' => 'Awaiting review', 'count' => $tab_counts['review']],
    'approved'       => ['label' => 'Approved', 'count' => $tab_counts['approved']],
    'rejected'       => ['label' => 'Rejected', 'count' => $tab_counts['rejected']],
];

$methods = [
    ''        => 'All methods',
    'ewallet' => 'E-wallet',
    'code'    => 'Registration code',
    'gcash'   => 'GCash proof',
    'maya'    => 'Maya proof',
    'bank'    => 'Bank proof',
];
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Activations</h4>
                  <p class="text-body-secondary mb-0">
                    Review payment proofs and approve package activations. Approval runs the commission hook once.
                  </p>
                </div>
                <span class="badge rounded-pill bg-label-warning">
                  <?= (int) $stats['review'] ?> awaiting review
                </span>
              </div>

              <div class="row g-4 mb-4">
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">Awaiting review</p>
                      <h4 class="mb-1"><?= (int) $stats['review'] ?></h4>
                      <small class="text-body-secondary"><?= e(money($stats['amount_review'])) ?> claimed</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">Approved</p>
                      <h4 class="mb-1"><?= (int) $stats['approved'] ?></h4>
                      <small class="text-body-secondary"><?= e(money($stats['amount_approved'])) ?> activated</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">Rejected</p>
                      <h4 class="mb-1"><?= (int) $stats['rejected'] ?></h4>
                      <small class="text-body-secondary">member returned to pending</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">All requests</p>
                      <h4 class="mb-1"><?= (int) $stats['total'] ?></h4>
                      <small class="text-body-secondary">since launch</small>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <ul class="nav nav-pills card-header-pills gap-1 flex-nowrap overflow-auto">
<?php foreach ($tabs as $value => $tab): ?>
                    <li class="nav-item">
                      <a
                        class="nav-link text-nowrap <?= $status === $value ? 'active' : '' ?>"
                        href="<?= e(url('admin/activations' . ($value === '' ? '' : '?status=' . rawurlencode($value)))) ?>"
                      ><?= e($tab['label']) ?>
                        <span class="badge bg-label-secondary ms-1"><?= (int) $tab['count'] ?></span>
                      </a>
                    </li>
<?php endforeach; ?>
                  </ul>
                </div>

                <div class="card-body py-3 border-top">
                  <form method="GET" action="<?= e(url('admin/activations')) ?>" class="membership-filters">
                    <input type="hidden" name="status" value="<?= e($status) ?>" />
                    <input
                      type="search"
                      class="form-control"
                      name="q"
                      value="<?= e($query) ?>"
                      placeholder="Member, package or reference…"
                      aria-label="Search activations"
                    />
                    <select class="form-select" name="method" aria-label="Filter by payment method">
<?php foreach ($methods as $value => $label): ?>
                      <option value="<?= e($value) ?>" <?= $method === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/activations')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Member</th>
                        <th>Package</th>
                        <th>Method</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th class="text-end">Review</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($activations === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-check-shield',
        'title'        => $hasFilters ? 'No activations match these filters' : 'No activations yet',
        'text'         => $hasFilters
            ? 'Try a different status, method or search.'
            : 'Manual activations appear here once a pending member uploads a proof.',
        'action_href'  => $hasFilters ? 'admin/activations' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($activations as $activation): ?>
<?php
    $id        = (int) $activation['id'];
    $isReview  = (string) $activation['status'] === 'pending_review';
    $hasProof  = (string) ($activation['proof_path'] ?? '') !== '';
    $channel   = (string) $activation['payment_method'];
?>
                      <tr>
                        <td>
                          <a href="<?= e(url('admin/members/' . (int) $activation['user_id'])) ?>" class="text-body fw-medium">
                            @<?= e((string) $activation['username']) ?>
                          </a>
                          <div class="member-meta">
                            #<?= $id ?> ·
                            <?= e(trim((string) ($activation['user_name'] ?? '')) !== '' ? (string) $activation['user_name'] : (string) $activation['email']) ?>
                          </div>
                        </td>
                        <td class="text-nowrap"><?= e((string) $activation['package_name']) ?></td>
                        <td>
                          <span class="text-nowrap"><?= e(payment_method_label($channel)) ?></span>
<?php if ($activation['reference_no'] !== null): ?>
                          <div class="member-meta">Ref <?= e((string) $activation['reference_no']) ?></div>
<?php endif; ?>
<?php if ($hasProof): ?>
                          <a
                            class="activation-proof-link text-nowrap"
                            href="<?= e(url('admin/activations/' . $id . '/proof')) ?>"
                            target="_blank"
                            rel="noopener"
                          >View proof</a>
<?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap"><?= e(money((float) $activation['amount'])) ?></td>
                        <td>
                          <?= status_badge((string) $activation['status']) ?>
<?php if ((string) $activation['status'] === 'rejected' && (string) ($activation['reject_reason'] ?? '') !== ''): ?>
                          <div class="member-meta activation-note"><?= e((string) $activation['reject_reason']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <span class="member-meta"><?= e(date('M j, Y', (int) strtotime((string) $activation['created_at']))) ?></span>
                          <div class="member-meta"><?= e(date('g:i A', (int) strtotime((string) $activation['created_at']))) ?></div>
<?php if ($activation['reviewer_username'] !== null): ?>
                          <div class="member-meta">by @<?= e((string) $activation['reviewer_username']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end">
<?php if ($isReview): ?>
                          <div class="d-flex flex-column align-items-end gap-2 activation-actions">
                            <form
                              method="POST"
                              action="<?= e(url('admin/activations/' . $id . '/approve')) ?>"
                              data-confirm
                              data-confirm-title="Approve this activation?"
                              data-confirm-text="The member becomes active with <?= e((string) $activation['package_name']) ?> and the commission hook runs once."
                              data-confirm-button="Approve"
                            >
                              <?= csrf_field() ?>
                              <button type="submit" class="btn btn-sm btn-success">
                                <i class="icon-base bx bx-check me-1"></i> Approve
                              </button>
                            </form>

                            <form
                              method="POST"
                              action="<?= e(url('admin/activations/' . $id . '/reject')) ?>"
                              class="activation-reject-form"
                            >
                              <?= csrf_field() ?>
                              <div class="input-group input-group-sm">
                                <input
                                  type="text"
                                  class="form-control"
                                  name="reason"
                                  placeholder="Rejection reason"
                                  maxlength="160"
                                  required
                                  aria-label="Rejection reason"
                                  style="min-width: 0"
                                />
                                <button type="submit" class="btn btn-outline-danger">Reject</button>
                              </div>
                            </form>
                          </div>
<?php else: ?>
                          <span class="text-body-secondary">—</span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Activations']) ?>
                </div>
              </div>
            </div>
