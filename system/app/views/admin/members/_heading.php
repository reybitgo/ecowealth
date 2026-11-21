<?php
declare(strict_types=1);

/**
 * Admin member page — identity bar and the suspend/restore control.
 *
 * @var array<string, mixed> $member
 * @var int    $member_id
 * @var string $display_name
 * @var string $status_label
 * @var bool   $is_suspended
 * @var string $statement_url
 */
?>
              <div
                class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4"
                data-live-region="member-heading"
              >
                <div>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <a href="<?= e(url('admin/members')) ?>" class="btn btn-sm btn-icon btn-text-secondary" aria-label="Back to members">
                      <i class="icon-base bx bx-chevron-left"></i>
                    </a>
                    <h4 class="mb-0">@<?= e((string) $member['username']) ?></h4>
                    <?= status_badge((string) $member['member_status'], ['active' => [$status_label, 'success']]) ?>
                  </div>
                  <p class="text-body-secondary mb-0">
                    <?= e($display_name) ?>
                    · <?= e((string) $member['email']) ?>
                    · joined <?= e(format_datetime((string) $member['created_at'])) ?>
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e($statement_url) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> Wallet statement
                  </a>
                  <form
                    method="POST"
                    action="<?= e(url('admin/members/' . $member_id . '/status')) ?>"
                    data-confirm
                    data-confirm-title="<?= $is_suspended ? 'Restore this member?' : 'Suspend this member?' ?>"
                    data-confirm-text="<?= $is_suspended
                        ? 'The account can sign in and use the member portal again.'
                        : 'The member is signed out and cannot sign in until restored.' ?>"
                    data-confirm-button="<?= $is_suspended ? 'Restore member' : 'Suspend member' ?>"
                    data-confirm-tone="<?= $is_suspended ? '' : 'danger' ?>"
                  >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $is_suspended ? 'restore' : 'suspend' ?>" />
                    <button type="submit" class="btn btn-<?= $is_suspended ? 'success' : 'danger' ?>">
                      <i class="icon-base bx <?= $is_suspended ? 'bx-play-circle' : 'bx-block' ?> me-1"></i>
                      <?= $is_suspended ? 'Restore member' : 'Suspend member' ?>
                    </button>
                  </form>
                </div>
              </div>
