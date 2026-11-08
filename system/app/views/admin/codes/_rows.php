<?php
declare(strict_types=1);

/**
 * Admin registration codes — rows.
 *
 * Each row is keyed by code id so the client patches only the codes that
 * changed; the copy button and the "used by" link carry data-live-focus so
 * focus survives a replacement.
 *
 * @var list<array<string, mixed>> $codes
 * @var array{q:string, status:string, package:string} $filters
 */

$hasFilters = (string) $filters['q'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['package'] !== '';
?>
                    <tbody data-live-region="code-rows">
<?php if ($codes === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-key',
        'title'        => $hasFilters ? 'No codes match these filters' : 'No codes issued yet',
        'text'         => $hasFilters
            ? 'Try a different search, status or package.'
            : 'Issue the first batch with the form above.',
        'action_href'  => $hasFilters ? 'admin/codes' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($codes as $code): ?>
<?php $isUnused = (string) $code['status'] === 'unused'; ?>
                      <tr data-live-key="<?= (int) $code['id'] ?>">
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <span class="reg-code"><?= e((string) $code['code']) ?></span>
<?php if ($isUnused): ?>
                            <button
                              type="button"
                              class="btn btn-sm btn-icon btn-text-secondary"
                              data-copy="<?= e((string) $code['code']) ?>"
                              data-copy-tooltip="Copy code"
                              data-bs-toggle="tooltip"
                              aria-label="Copy code <?= e((string) $code['code']) ?>"
                              title="Copy code"
                              data-live-focus="copy"
                            ><i class="icon-base bx bx-copy"></i></button>
<?php endif; ?>
                          </div>
                        </td>
                        <td><?= e((string) $code['package_name']) ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $code['price'])) ?></td>
                        <td><?= status_badge((string) $code['status']) ?></td>
                        <td>
<?php if ($code['used_by_username'] !== null): ?>
                          <a
                            href="<?= e(url('admin/members/' . (int) $code['used_by'])) ?>"
                            class="text-body"
                            data-live-focus="member"
                          >
                            @<?= e((string) $code['used_by_username']) ?>
                          </a>
                          <div class="member-meta"><?= e(format_datetime($code['used_at'] === null ? null : (string) $code['used_at'])) ?></div>
<?php else: ?>
                          <span class="text-body-secondary">—</span>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap"><span class="member-meta"><?= e(format_datetime((string) $code['created_at'])) ?></span></td>
                        <td class="text-nowrap">
                          <?= $code['expires_at'] === null
                              ? '<span class="text-body-secondary">No expiry</span>'
                              : '<span class="member-meta">' . e(format_datetime((string) $code['expires_at'])) . '</span>' ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
