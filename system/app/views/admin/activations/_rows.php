<?php
declare(strict_types=1);

/**
 * Admin activation queue — rows.
 *
 * Each row is keyed by activation id. The rejection reason is typed inside
 * this region, so the tbody is marked `data-live-hold="dirty"`: while any
 * reason is unsaved the client keeps the whole table (and the page shows
 * its stale banner) instead of replacing a row mid-edit.
 *
 * @var list<array<string, mixed>> $activations
 * @var array{status:string, method:string, q:string} $filters
 */

$hasFilters = (string) $filters['status'] !== ''
    || (string) $filters['method'] !== ''
    || (string) $filters['q'] !== '';
?>
                    <tbody data-live-region="activation-rows" data-live-hold="dirty">
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
                      <tr data-live-key="<?= $id ?>">
                        <td>
                          <a
                            href="<?= e(url('admin/members/' . (int) $activation['user_id'])) ?>"
                            class="text-body fw-medium"
                            data-live-focus="member"
                          >
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
                            data-live-focus="proof"
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
                              <button type="submit" class="btn btn-sm btn-success" data-live-focus="approve">
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
                                  data-live-focus="reject"
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
