<?php
declare(strict_types=1);

/**
 * Member packages page (plan §7 Phase 3: "members can view them").
 * Activation is Phase 9 — the CTA is deliberately inert for now.
 *
 * @var list<array<string, mixed>> $packages  PackageService::publicCards()
 */

$page_styles = <<<CSS
.package-card { transition: box-shadow .2s ease, transform .2s ease; }
.package-card:hover { box-shadow: 0 .5rem 1.25rem rgba(34, 41, 47, .08); transform: translateY(-2px); }
.package-price { font-size: 2rem; line-height: 1.1; }
.package-perks li { margin-bottom: .35rem; }
CSS;
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="mb-6">
                <h4 class="mb-1">Associate packages</h4>
                <p class="text-body-secondary mb-0" style="max-width: 46rem">
                  Packages define your entry point: the products you receive, the direct referral
                  bonus you earn, and the unilevel amounts paid up to ten levels up your sponsor chain.
                </p>
              </div>

              <div class="alert alert-info d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-info-circle fs-4"></i>
                <div class="small">
                  Activation opens with the membership module (Phase 9). Until then this page is the
                  package catalogue — prices and referral amounts are shown exactly as configured by the admin.
                </div>
              </div>

<?php if ($packages === []): ?>
              <div class="card">
                <div class="card-body">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-box',
        'title' => 'No packages are available yet',
        'text'  => 'Associate packages will appear here once the administrator publishes them.',
    ]);
?>
                </div>
              </div>
<?php else: ?>
              <div class="row g-6">
<?php foreach ($packages as $package): ?>
<?php
    $perks = array_values(array_filter(array_map(
        'trim',
        preg_split('/\R/', (string) ($package['perks'] ?? '')) ?: []
    )));

    $ladder      = $package['ladder'];
    $contents    = $package['contents'];
    $funded      = (int) $package['funded_levels'];
    $directBonus = (float) $package['direct_referral_bonus'];
?>
                <div class="col-lg-6">
                  <div class="card h-100 package-card">
                    <div class="card-body d-flex flex-column">
                      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                        <div>
                          <h4 class="mb-1"><?= e((string) $package['name']) ?></h4>
                          <div class="text-body-secondary small">
                            Product value <?= e(money((float) $package['product_value'])) ?>
                          </div>
                        </div>
                        <div class="text-end">
                          <div class="package-price fw-semibold"><?= e(money((float) $package['price'])) ?></div>
                          <div class="text-body-secondary small">one-time package price</div>
                        </div>
                      </div>

<?php if (!empty($package['description'])): ?>
                      <p class="text-body-secondary mb-4"><?= e((string) $package['description']) ?></p>
<?php endif; ?>

                      <div class="row g-3 mb-4">
                        <div class="col-6">
                          <div class="border rounded p-3 h-100">
                            <div class="small text-body-secondary mb-1">Direct referral (level 1)</div>
                            <div class="h5 mb-0"><?= e(money($directBonus)) ?></div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="border rounded p-3 h-100">
                            <div class="small text-body-secondary mb-1">Levels 2–10</div>
                            <div class="h5 mb-0"><?= e(money((float) $package['ladder_total'] - $directBonus)) ?></div>
                          </div>
                        </div>
                      </div>

                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="mb-0">Referral ladder</h6>
                        <span class="badge bg-label-primary"><?= $funded ?> of 10 levels pay</span>
                      </div>

                      <details class="mb-4">
                        <summary class="small text-primary" style="cursor: pointer">Show all levels</summary>
                        <div class="table-responsive mt-2">
                          <table class="table table-sm align-middle mb-0">
                            <tbody>
<?php foreach ($ladder as $row): ?>
                              <tr class="<?= $row['read_only'] ? 'table-active' : '' ?>">
                                <td>
                                  <?= e($row['label']) ?>
<?php if ($row['read_only']): ?>
                                  <span class="badge bg-label-secondary ms-1">stored on the package</span>
<?php endif; ?>
                                </td>
                                <td class="text-end">
                                  <?= $row['amount'] > 0 ? e(money((float) $row['amount'])) : '<span class="text-body-secondary">—</span>' ?>
                                </td>
                              </tr>
<?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      </details>

<?php if ($contents !== []): ?>
                      <div class="mb-4">
                        <h6 class="mb-2">Package contents</h6>
                        <ul class="list-unstyled mb-0 small">
<?php foreach ($contents as $content): ?>
                          <li class="d-flex align-items-center gap-2 mb-1">
                            <i class="icon-base bx bx-check-circle text-success"></i>
                            <span><?= (int) $content['quantity'] ?> × <?= e((string) $content['name']) ?></span>
<?php if ($content['status'] !== 'active'): ?>
                            <span class="badge bg-label-secondary">unavailable</span>
<?php endif; ?>
                          </li>
<?php endforeach; ?>
                        </ul>
                      </div>
<?php endif; ?>

<?php if ($perks !== []): ?>
                      <div class="mb-4">
                        <h6 class="mb-2">What you get</h6>
                        <ul class="list-unstyled mb-0 small package-perks">
<?php foreach ($perks as $perk): ?>
                          <li class="d-flex align-items-start gap-2">
                            <i class="icon-base bx bx-check text-success mt-1"></i>
                            <span><?= e($perk) ?></span>
                          </li>
<?php endforeach; ?>
                        </ul>
                      </div>
<?php endif; ?>

                      <div class="mt-auto">
                        <button type="button" class="btn btn-primary w-100" disabled
                          title="Activation opens with the membership module (Phase 9)">
                          <i class="icon-base bx bx-lock-alt me-1"></i> Activation opens in Phase 9
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
<?php endif; ?>
            </div>
