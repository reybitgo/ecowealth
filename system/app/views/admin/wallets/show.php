<?php
declare(strict_types=1);

/**
 * Admin — one member's wallet: both buckets, the top-up form, the
 * statement and the top-up history (plan §5.8, §7 Phase 5; reference
 * views/admin/ewallet_topup.php).
 *
 * One live surface (`admin.wallets:{id}`). The heading, badges and bucket
 * cards refresh in place; the statement and top-up form own filter/POST
 * forms, so they carry `data-live-hold="dirty"` and the page shows a
 * stale banner instead of overwriting unsaved input.
 *
 * @var array<string, mixed> $member
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $pager
 * @var array{rows:int, credits:float, debits:float, net:float} $totals
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $filters
 * @var string $statement_url
 * @var string $statement_title
 * @var bool $show_member
 * @var list<array<string, mixed>> $topups
 * @var Paginator $topups_pager
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => Live::walletSurface((int) $member['id']),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y wallet-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <div class="d-flex flex-wrap align-items-center gap-2 small text-body-secondary mb-1">
                    <a href="<?= e(url('admin/wallets')) ?>" class="text-body-secondary">E-wallet monitor</a>
                    <i class="icon-base bx bx-chevron-right"></i>
                    <span>Wallet</span>
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
<?= View::capture('admin/wallets/_heading', ['member' => $member]) ?>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
<?= View::capture('admin/wallets/_badges', ['member' => $member]) ?>
                  <a href="<?= e(url('admin/wallets')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-arrow-back me-1"></i> Back to monitor
                  </a>
                </div>
              </div>

              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-6 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>This wallet changed in another tab.</strong>
                  Your unsaved input was kept, so the statement or top-up form may be out of date.
                  <a href="<?= e(url('admin/wallets/' . (int) $member['id'])) ?>" class="alert-link" data-live-reload>
                    Reload the wallet
                  </a>
                  to see the latest balances.
                </div>
              </div>

<?= View::capture('admin/wallets/_buckets', ['member' => $member]) ?>

              <div class="row g-6">
                <div class="col-lg-8 order-2 order-lg-1">
<?= View::capture('admin/wallets/_statement', [
    'filters'         => $filters,
    'ledger'          => $ledger,
    'pager'           => $pager,
    'totals'          => $totals,
    'statement_url'   => $statement_url,
    'statement_title' => $statement_title,
]) ?>
                </div>

                <div class="col-lg-4 order-1 order-lg-2">
<?= View::capture('admin/wallets/_topup_form', ['member' => $member]) ?>

<?= View::capture('admin/wallets/_topup_history', [
    'topups'       => $topups,
    'topups_pager' => $topups_pager,
]) ?>
                </div>
              </div>
            </div>
