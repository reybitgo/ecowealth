<?php
declare(strict_types=1);

/**
 * Member General Harvest statement — the award pagination (Phase 21F).
 *
 * @var Paginator $pager
 */

$pager = $pager ?? Paginator::make(0);
?>
              <nav class="mt-3" aria-label="General Harvest awards">
                <ul class="pagination justify-content-center small">
<?php if ($pager->page > 1): ?>
                  <li class="page-item">
                    <a class="page-link" href="<?= e(url('earnings?ledger=general_harvest&page=' . ($pager->page - 1))) ?>">Previous</a>
                  </li>
<?php endif; ?>
<?php for ($page = max(1, $pager->page - 2); $page <= min($pager->pages, $pager->page + 2); $page++): ?>
                  <li class="page-item<?= $page === $pager->page ? ' active' : '' ?>">
                    <a class="page-link" href="<?= e(url('earnings?ledger=general_harvest&page=' . $page)) ?>"><?= $page ?></a>
                  </li>
<?php endfor; ?>
<?php if ($pager->page < $pager->pages): ?>
                  <li class="page-item">
                    <a class="page-link" href="<?= e(url('earnings?ledger=general_harvest&page=' . ($pager->page + 1))) ?>">Next</a>
                  </li>
<?php endif; ?>
                </ul>
              </nav>
