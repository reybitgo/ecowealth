<?php
declare(strict_types=1);

/**
 * Member e-wallet statement (plan §5.8, §7 Phase 5; reference
 * views/member/ewallet.php).
 *
 * Both buckets are shown side by side because they answer different
 * questions: how much money the member has, and how much of it may be
 * paid out. Admin top-ups raise the total only (non-withdrawable), so
 * the explanation under the cards matters.
 *
 * @var array<string, mixed> $account
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $pager
 * @var array{rows:int, credits:float, debits:float, net:float} $totals
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $filters
 * @var string $statement_url
 * @var string $statement_title
 * @var bool $show_member
 */

$total        = round((float) $account['ewallet_balance'], 2);
$withdrawable = round((float) $account['withdrawable_balance'], 2);
$locked       = round($total - $withdrawable, 2);

$cards = [
    [
        'label' => 'Total balance',
        'value' => money($total),
        'hint'  => 'Spendable in the shop and available for payouts.',
        'icon'  => 'bx-wallet',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Withdrawable',
        'value' => money($withdrawable),
        'hint'  => 'May be paid out — commissions land here.',
        'icon'  => 'bx-money-withdraw',
        'tone'  => 'success',
    ],
    [
        'label' => 'Non-withdrawable',
        'value' => money($locked),
        'hint'  => 'Top-ups and transferred funds: spendable, not cashable.',
        'icon'  => 'bx-lock-alt',
        'tone'  => 'warning',
    ],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y wallet-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">E-wallet</h4>
                  <p class="text-body-secondary mb-0">
                    Every peso comes from one ledger. Nothing here can be edited — corrections are new entries.
                  </p>
                </div>
                <span class="badge bg-label-primary fs-6">
                  <i class="icon-base bx bx-user-circle me-1"></i> <?= e((string) $account['username']) ?>
                </span>
              </div>

              <div class="row g-6 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                      </div>
                      <h3 class="mb-2 wallet-stat-value"><?= e($card['value']) ?></h3>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="alert alert-info d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-info-circle fs-4"></i>
                <div class="small">
                  <strong>How the two buckets work.</strong>
                  Commission earnings are withdrawable. Admin top-ups and transferred funds can be spent in the shop
                  but stay non-withdrawable. Purchases, registrations and fees spend the non-withdrawable part first,
                  so your withdrawable earnings stay available for payout.
                </div>
              </div>

<?=
    View::partial('wallet_statement', [
        'filters'         => $filters,
        'ledger'          => $ledger,
        'pager'           => $pager,
        'totals'          => $totals,
        'statement_url'   => $statement_url,
        'statement_title' => $statement_title,
        'show_member'     => $show_member,
    ])
?>
            </div>
