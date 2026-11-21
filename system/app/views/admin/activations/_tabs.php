<?php
declare(strict_types=1);

/**
 * Admin activation queue — status tabs with their live counts.
 *
 * The tab is part of the URL, so the feed request already selects the right
 * tab and just re-renders the matching "active" pill.
 *
 * @var array{status:string, method:string, q:string} $filters
 * @var array{total:int, review:int, approved:int, rejected:int, amount_review:float, amount_approved:float} $stats
 * @var array{review:int, approved:int, rejected:int} $tab_counts
 */

$status = (string) $filters['status'];

$tabs = [
    ''               => ['label' => 'All', 'count' => $stats['total']],
    'pending_review' => ['label' => 'Awaiting review', 'count' => $tab_counts['review']],
    'approved'       => ['label' => 'Approved', 'count' => $tab_counts['approved']],
    'rejected'       => ['label' => 'Rejected', 'count' => $tab_counts['rejected']],
];
?>
                  <ul class="nav nav-pills card-header-pills gap-1 flex-nowrap overflow-auto" data-live-region="activation-tabs">
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
