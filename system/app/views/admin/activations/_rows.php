<?php
declare(strict_types=1);

/**
 * Admin activation queue — rows.
 *
 * Each row is keyed by activation id. Approving and rejecting both open a
 * confirmation modal; the rejection reason is typed there, never in the
 * table, so a live refresh cannot race an unsaved reason.
 *
 * @var list<array<string, mixed>> $activations
 * @var array{status:string, method:string, q:string} $filters
 */

$hasFilters = (string) $filters['status'] !== ''
    || (string) $filters['method'] !== ''
    || (string) $filters['q'] !== '';
?>
                    <tbody data-live-region="activation-rows">
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
                              data-confirm-button="Approve activation"
                              data-confirm-cancel="Keep reviewing"
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
                              data-confirm
                              data-confirm-title="Reject this activation?"
                              data-confirm-text="The request returns to pending and the member can try again. They see the reason you type below."
                              data-confirm-button="Reject activation"
                              data-confirm-cancel="Keep reviewing"
                              data-confirm-tone="danger"
                              data-confirm-prompt="reason"
                              data-confirm-prompt-label="Rejection reason"
                              data-confirm-prompt-placeholder="Explain what is wrong with the proof"
                              data-confirm-prompt-max="160"
                            >
                              <?= csrf_field() ?>
                              <input type="hidden" name="reason" value="" />
                              <button type="submit" class="btn btn-sm btn-outline-danger" data-live-focus="reject">
                                <i class="icon-base bx bx-x me-1"></i> Reject
                              </button>
                            </form>
                          </div>
<?php else: ?>
                          <span class="text-body-secondary">—</span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
