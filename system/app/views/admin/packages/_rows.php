<?php
declare(strict_types=1);

/**
 * Admin package list — the package rows.
 *
 * Each row is keyed by package id so the client patches only the packages
 * that changed; the edit link and the status button carry data-live-focus
 * so focus survives a row replacement.
 *
 * @var list<array<string, mixed>> $packages
 * @var array{q?: string, status?: string} $filters
 * @var array<int, array<int, float>> $levels    package id => level 2–10 => amount
 * @var array<int, list<array<string, mixed>>> $contents
 */

$query      = trim((string) ($filters['q'] ?? ''));
$status     = (string) ($filters['status'] ?? '');
$hasFilters = $query !== '' || $status !== '';
?>
                    <tbody data-live-region="package-rows">
<?php if ($packages === []): ?>
                      <tr>
                        <td colspan="8" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-box',
        'title'        => $hasFilters ? 'No packages match these filters' : 'No packages yet',
        'text'         => $hasFilters
            ? 'Try a different search or clear the filters to see every package.'
            : 'Create the first associate package to open membership offers.',
        'action_href'  => $hasFilters ? 'admin/packages' : 'admin/packages/new',
        'action_label' => $hasFilters ? 'Clear filters' : 'New package',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($packages as $package): ?>
<?php
    $id          = (int) $package['id'];
    $levelRows   = $levels[$id] ?? [];
    $ladderTotal = array_sum($levelRows);
    $contentRows = $contents[$id] ?? [];
    $isActive    = $package['status'] === 'active';
?>
                      <tr data-live-key="<?= $id ?>">
                        <td>
                          <a href="<?= e(url('admin/packages/' . $id . '/edit')) ?>" class="text-body fw-medium" data-live-focus="open">
                            <?= e((string) $package['name']) ?>
                          </a>
                          <div class="small text-body-secondary"><?= e((string) $package['slug']) ?></div>
                        </td>
                        <td class="text-end text-nowrap"><?= e(money((float) $package['price'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $package['product_value'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $package['direct_referral_bonus'])) ?></td>
                        <td class="text-end text-nowrap">
                          <div><?= e(money($ladderTotal)) ?></div>
                          <div class="small text-body-secondary"><?= (int) count(array_filter($levelRows, static fn (float $v): bool => $v > 0)) ?> paying levels</div>
                        </td>
                        <td class="text-end text-nowrap"><?= (int) count($contentRows) ?></td>
                        <td><?= status_badge((string) $package['status']) ?></td>
                        <td class="text-end">
                          <div class="d-inline-flex align-items-center gap-1">
                            <a
                              href="<?= e(url('admin/packages/' . $id . '/edit')) ?>"
                              class="btn btn-sm btn-icon btn-label-primary"
                              title="Edit"
                              aria-label="Edit <?= e((string) $package['name']) ?>"
                              data-live-focus="edit"
                            ><i class="icon-base bx bx-edit"></i></a>

                            <form method="POST" action="<?= e(url('admin/packages/' . $id . '/status')) ?>" class="d-inline">
                              <?= csrf_field() ?>
                              <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>" />
                              <button
                                type="submit"
                                class="btn btn-sm btn-icon btn-label-<?= $isActive ? 'warning' : 'success' ?>"
                                title="<?= $isActive ? 'Disable (hide from members)' : 'Enable (show to members)' ?>"
                                aria-label="<?= $isActive ? 'Disable' : 'Enable' ?> <?= e((string) $package['name']) ?>"
                                data-live-focus="toggle"
                              ><i class="icon-base bx <?= $isActive ? 'bx-pause-circle' : 'bx-play-circle' ?>"></i></button>
                            </form>
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
