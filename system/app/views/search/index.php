<?php
declare(strict_types=1);

/**
 * Global search results (UX-26).
 *
 * One card per destination, each a short preview of the same `q` the
 * destination's own list accepts. An empty query explains the scope;
 * a query with no matches uses the shared empty state (UX-20), so
 * both states read the same as the rest of the product.
 *
 * @var string $query
 * @var list<array{key:string, label:string, icon:string, total:int, rows:list<array{title:string, subtitle:string, note:string, badge:string, url:string}>, all_url:string, all_label:string}> $groups
 * @var int    $total
 * @var string $scope
 * @var string $hint
 */

$query  = (string) ($query ?? '');
$groups = $groups ?? [];
$total  = (int) ($total ?? 0);
$scope  = (string) ($scope ?? 'products and your orders');
$hint   = (string) ($hint ?? 'Type what you are looking for in the search box above.');
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <?= flash_render() ?>

<?= View::partial('page_header', [
    'title'       => 'Search',
    'description' => 'One query across ' . e($scope) . '. Open a result or use its destination for the full, filterable list.',
]) ?>

<?php if ($query === ''): ?>
              <div class="card">
                <div class="card-body">
                  <?= View::partial('empty_state', [
                      'icon'  => 'bx-search',
                      'title' => 'What are you looking for?',
                      'text'  => $hint,
                  ]) ?>
                </div>
              </div>
<?php elseif ($groups === []): ?>
              <div class="card">
                <div class="card-body">
                  <?= View::partial('empty_state', [
                      'icon'  => 'bx-search',
                      'title' => 'No matches for “' . $query . '”',
                      'text'  => 'Searches cover ' . $scope . '. Try fewer words, check the spelling, or open a destination page for its own filters.',
                  ]) ?>
                </div>
              </div>
<?php else: ?>
              <p class="text-body-secondary mb-6">
                <?= (int) $total ?> <?= $total === 1 ? 'result' : 'results' ?> for <strong>“<?= e($query) ?>”</strong>
              </p>

              <div class="row g-6">
<?php foreach ($groups as $group): ?>
                <div class="col-xl-6">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-3">
                      <div class="d-flex align-items-center gap-2">
                        <i class="icon-base <?= e(icon_class((string) $group['icon'])) ?> icon-md text-body-secondary"></i>
                        <h5 class="card-title mb-0"><?= e((string) $group['label']) ?></h5>
                      </div>
                      <span class="badge rounded-pill bg-label-primary"><?= (int) $group['total'] ?></span>
                    </div>
                    <div class="list-group list-group-flush">
<?php foreach ($group['rows'] as $row): ?>
                      <a class="list-group-item list-group-item-action" href="<?= e((string) $row['url']) ?>">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                          <div class="flex-grow-1" style="min-width: 0">
                            <div class="fw-semibold text-truncate"><?= e((string) $row['title']) ?></div>
<?php if ((string) $row['subtitle'] !== ''): ?>
                            <small class="text-body-secondary d-block text-truncate"><?= e((string) $row['subtitle']) ?></small>
<?php endif; ?>
                          </div>
                          <div class="text-end flex-shrink-0">
<?php if ((string) $row['note'] !== ''): ?>
                            <div class="small text-body-secondary text-nowrap"><?= e((string) $row['note']) ?></div>
<?php endif; ?>
<?php if ((string) $row['badge'] !== ''): ?>
                            <?= status_badge((string) $row['badge']) ?>
<?php endif; ?>
                          </div>
                        </div>
                      </a>
<?php endforeach; ?>
                    </div>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <small class="text-body-secondary">Showing <?= count($group['rows']) ?> of <?= (int) $group['total'] ?></small>
                      <a href="<?= e((string) $group['all_url']) ?>" class="btn btn-sm btn-label-primary"><?= e((string) $group['all_label']) ?></a>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
<?php endif; ?>
            </div>
