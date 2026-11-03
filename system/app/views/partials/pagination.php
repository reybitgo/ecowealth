<?php
declare(strict_types=1);

/**
 * Pagination footer for tables and lists.
 *
 * @var Paginator $pager
 * @var string    $pager_label Optional label rendered before the summary
 */

if (!isset($pager) || !($pager instanceof Paginator)) {
    return;
}

$pager_label = $pager_label ?? '';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-4">
  <div class="text-body-secondary small">
<?php if ($pager_label !== ''): ?>
    <span class="me-2"><?= e($pager_label) ?></span>
<?php endif; ?>
    <?= e($pager->summary()) ?>
  </div>

<?php if ($pager->pages > 1): ?>
  <nav aria-label="Pagination">
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $pager->hasPrev() ? '' : 'disabled' ?>">
        <a class="page-link" href="<?= e($pager->url(max(1, $pager->page - 1))) ?>" aria-label="Previous">
          <i class="icon-base bx bx-chevron-left"></i>
        </a>
      </li>
<?php foreach ($pager->window() as $number): ?>
      <li class="page-item <?= $number === $pager->page ? 'active' : '' ?>">
        <a class="page-link" href="<?= e($pager->url($number)) ?>"><?= $number ?></a>
      </li>
<?php endforeach; ?>
      <li class="page-item <?= $pager->hasNext() ? '' : 'disabled' ?>">
        <a class="page-link" href="<?= e($pager->url(min($pager->pages, $pager->page + 1))) ?>" aria-label="Next">
          <i class="icon-base bx bx-chevron-right"></i>
        </a>
      </li>
    </ul>
  </nav>
<?php endif; ?>
</div>
