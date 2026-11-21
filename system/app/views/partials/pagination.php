<?php
declare(strict_types=1);

/**
 * Pagination footer for tables and lists (plan §3.6).
 *
 *    View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Orders']);
 *
 * Renders, left to right:
 *   · a rows-per-page control (5, 10, 15 … 100; default 10) that keeps
 *     every current filter and resets to page 1,
 *   · the "Showing X–Y of Z" summary,
 *   · first / previous / page window / next / last links.
 *
 * The control is a plain GET form, so it works with JavaScript off
 * (the Apply button inside <noscript>); app.js submits it on change.
 *
 * @var Paginator $pager
 * @var string    $pager_label      Optional label before the summary
 * @var string    $page_param       Page query key (default "page")
 * @var string    $per_page_param   Rows query key (default "per_page")
 * @var bool      $show_per_page    false for fixed-size lists (catalogue grid)
 * @var string    $form_action      Already resolved URL for the rows form
 * @var string    $pagination_label Accessible name for the nav (default "Pagination")
 */

if (!isset($pager) || !($pager instanceof Paginator)) {
    return;
}

$pager_label      = $pager_label ?? '';
$page_param       = (string) ($page_param ?? 'page');
$per_page_param   = (string) ($per_page_param ?? 'per_page');
$show_per_page    = (bool) ($show_per_page ?? true);
$form_action      = (string) ($form_action ?? url(ltrim(Request::path(), '/')));
$pagination_label = (string) ($pagination_label ?? 'Pagination');

/* Keep every filter/tab already on the URL; the page resets to 1. */
$hidden = [];

foreach ($_GET as $key => $value) {
    if (!is_scalar($value)) {
        continue;
    }

    $key = (string) $key;

    if ($key === $page_param || $key === $per_page_param) {
        continue;
    }

    $hidden[$key] = (string) $value;
}

/* A stable key lets app.js keep the table card in view across the
 * reload a page-size or page-number change triggers. */
$pagination_key = substr(md5($pager_label . '|' . $page_param . '|' . $per_page_param), 0, 8);
$select_id      = 'perPage-' . $pagination_key;
?>
<div
  class="pagination-bar d-flex flex-wrap align-items-center justify-content-between gap-3 mt-4"
  data-pagination="<?= e($pagination_key) ?>"
>
  <div class="d-flex flex-wrap align-items-center gap-3">
<?php if ($show_per_page && $pager->total > 0): ?>
    <form method="GET" action="<?= e($form_action) ?>" class="d-flex align-items-center gap-2" data-per-page-form>
<?php foreach ($hidden as $key => $value): ?>
      <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>" />
<?php endforeach; ?>
      <label class="form-label text-body-secondary small mb-0 text-nowrap" for="<?= e($select_id) ?>">Rows per page</label>
      <select
        class="form-select form-select-sm w-auto"
        id="<?= e($select_id) ?>"
        name="<?= e($per_page_param) ?>"
        data-per-page
        aria-label="Rows per page"
      >
<?php foreach (Paginator::perPageOptions() as $option): ?>
        <option value="<?= $option ?>" <?= $pager->perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
<?php endforeach; ?>
      </select>
      <noscript>
        <button type="submit" class="btn btn-sm btn-label-secondary">Apply</button>
      </noscript>
    </form>
<?php endif; ?>

    <div class="text-body-secondary small">
<?php if ($pager_label !== ''): ?>
      <span class="me-2"><?= e($pager_label) ?></span>
<?php endif; ?>
      <?= e($pager->summary()) ?>
    </div>
  </div>

<?php if ($pager->pages > 1): ?>
  <nav aria-label="<?= e($pagination_label) ?>">
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $pager->hasPrev() ? '' : 'disabled' ?>">
        <a class="page-link" href="<?= e($pager->url(1, $page_param)) ?>" aria-label="First page">
          <i class="icon-base bx bx-chevrons-left"></i>
        </a>
      </li>
      <li class="page-item <?= $pager->hasPrev() ? '' : 'disabled' ?>">
        <a class="page-link" href="<?= e($pager->url(max(1, $pager->page - 1), $page_param)) ?>" aria-label="Previous page">
          <i class="icon-base bx bx-chevron-left"></i>
        </a>
      </li>
<?php foreach ($pager->window() as $number): ?>
      <li class="page-item <?= $number === $pager->page ? 'active' : '' ?>">
        <a
          class="page-link"
          href="<?= e($pager->url($number, $page_param)) ?>"
<?= $number === $pager->page ? ' aria-current="page"' : '' ?>
        ><?= $number ?></a>
      </li>
<?php endforeach; ?>
      <li class="page-item <?= $pager->hasNext() ? '' : 'disabled' ?>">
        <a class="page-link" href="<?= e($pager->url(min($pager->pages, $pager->page + 1), $page_param)) ?>" aria-label="Next page">
          <i class="icon-base bx bx-chevron-right"></i>
        </a>
      </li>
      <li class="page-item <?= $pager->hasNext() ? '' : 'disabled' ?>">
        <a class="page-link" href="<?= e($pager->url($pager->pages, $page_param)) ?>" aria-label="Last page">
          <i class="icon-base bx bx-chevrons-right"></i>
        </a>
      </li>
    </ul>
  </nav>
<?php endif; ?>
</div>
