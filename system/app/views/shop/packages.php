<?php
declare(strict_types=1);

/**
 * Member packages page (plan §7 Phase 3: "members can view them").
 * Activation itself lives on /membership (Phase 9); this page is the
 * package catalogue.
 *
 * One live surface (`shop.packages`): the cards (or the empty state) are
 * a region; the heading and the explanatory alert stay static.
 *
 * @var list<array<string, mixed>> $packages  PackageService::publicCards()
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$packages = $packages ?? [];

$live = $live ?? [
    'surface'  => 'shop.packages',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$page_styles = <<<CSS
.package-card { transition: box-shadow .2s ease, transform .2s ease; }
.package-card:hover { box-shadow: 0 .5rem 1.25rem rgba(34, 41, 47, .08); transform: translateY(-2px); }
.package-price { font-size: 2rem; line-height: 1.1; }
.package-perks li { margin-bottom: .35rem; }
CSS;
?>
            <div
              class="container-xxl flex-grow-1 container-p-y"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="mb-6">
                <h4 class="mb-1">Associate packages</h4>
                <p class="text-body-secondary mb-0" style="max-width: 46rem">
                  Packages define your entry point: the products you receive, the direct referral
                  bonus you earn, and the level income your Team chain earns you up to ten levels deep.
                  <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                </p>
              </div>

              <div class="alert alert-info d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-info-circle fs-4"></i>
                <div class="small">
                  Prices and referral amounts are shown exactly as configured by the admin.
                  Activate a package from <a href="<?= e(url('membership')) ?>">Membership</a> with a
                  registration code, your e-wallet or a payment proof.
                </div>
              </div>

<?= View::capture('shop/packages/_cards', ['packages' => $packages]) ?>
            </div>
