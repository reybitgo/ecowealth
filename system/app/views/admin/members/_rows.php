<?php
declare(strict_types=1);

/**
 * Admin member directory — rows.
 *
 * Each row is keyed by user id so the client patches only the members that
 * changed; links and the status button carry data-live-focus so focus
 * survives a replacement.
 *
 * @var list<array<string, mixed>> $members
 * @var array{q:string, status:string, package:string} $filters
 */

$hasFilters = (string) $filters['q'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['package'] !== '';
?>
                    <tbody data-live-region="member-rows">
<?php if ($members === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-group',
        'title'        => $hasFilters ? 'No members match these filters' : 'No members yet',
        'text'         => $hasFilters
            ? 'Try a different search, status or package.'
            : 'Members appear here as soon as they register.',
        'action_href'  => $hasFilters ? 'admin/members' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($members as $row): ?>
<?php
    $id          = (int) $row['id'];
    $rowStatus   = (string) $row['member_status'];
    $isSuspended = $rowStatus === 'suspended';
?>
                      <tr data-live-key="<?= $id ?>">
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <span class="member-row-avatar"><?= e(initials((string) $row['username'])) ?></span>
                            <div class="min-w-0">
                              <a
                                href="<?= e(url('admin/members/' . $id)) ?>"
                                class="text-body fw-medium"
                                data-live-focus="member"
                              >
                                @<?= e((string) $row['username']) ?>
                              </a>
                              <div class="member-meta text-truncate">
                                <?= e(trim((string) ($row['full_name'] ?? '')) !== '' ? (string) $row['full_name'] : (string) $row['email']) ?>
                              </div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <?= $row['package_name'] === null
                              ? '<span class="text-body-secondary">—</span>'
                              : e((string) $row['package_name']) ?>
                        </td>
                        <td>
                          <?= $row['sponsor_username'] === null
                              ? '<span class="text-body-secondary">company</span>'
                              : e('@' . (string) $row['sponsor_username']) ?>
                        </td>
                        <td><?= status_badge($rowStatus) ?></td>
                        <td class="text-end member-wallet-cell">
                          <?= e(money((float) $row['ewallet_balance'])) ?>
                          <small><?= e(money((float) $row['withdrawable_balance'])) ?> withdrawable</small>
                        </td>
                        <td class="text-nowrap">
                          <span class="member-meta"><?= e(format_datetime((string) $row['created_at'])) ?></span>
                        </td>
                        <td class="text-end">
                          <div class="d-inline-flex align-items-center gap-1">
                            <a
                              href="<?= e(url('admin/members/' . $id)) ?>"
                              class="btn btn-sm btn-icon btn-label-primary"
                              title="View member"
                              aria-label="View @<?= e((string) $row['username']) ?>"
                              data-live-focus="view"
                            ><i class="icon-base bx bx-show"></i></a>

                            <form
                              method="POST"
                              action="<?= e(url('admin/members/' . $id . '/status')) ?>"
                              class="d-inline"
                              data-confirm
                              data-confirm-title="<?= $isSuspended ? 'Restore this member?' : 'Suspend this member?' ?>"
                              data-confirm-text="<?= $isSuspended
                                  ? 'The account can sign in and use the member portal again.'
                                  : 'The member is signed out and cannot sign in until restored.' ?>"
                              data-confirm-button="<?= $isSuspended ? 'Restore' : 'Suspend' ?>"
                              data-confirm-tone="<?= $isSuspended ? '' : 'danger' ?>"
                            >
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="<?= $isSuspended ? 'restore' : 'suspend' ?>" />
                              <button
                                type="submit"
                                class="btn btn-sm btn-icon btn-label-<?= $isSuspended ? 'success' : 'danger' ?>"
                                title="<?= $isSuspended ? 'Restore' : 'Suspend' ?>"
                                aria-label="<?= $isSuspended ? 'Restore' : 'Suspend' ?> @<?= e((string) $row['username']) ?>"
                                data-live-focus="status"
                              ><i class="icon-base bx <?= $isSuspended ? 'bx-play-circle' : 'bx-block' ?>"></i></button>
                            </form>
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
