<?php
declare(strict_types=1);

/**
 * Member — withdraw earnings (plan §5.9, §7 Phase 13; reference
 * views/member/payout.php).
 *
 * The page answers three questions in one screen:
 *   1. how much of the wallet may be paid out (the withdrawable bucket,
 *      not the total balance);
 *   2. how to ask for it (channel, account, amount, fee and — for USDT —
 *      the rate and gas fee the company will use);
 *   3. what happened before (every request with its status, fee, net and
 *      USDT amount).
 *
 * One live surface per member (`member.payouts:{id}`). The balance card,
 * the request card, the history and its pagination are regions. The
 * request card is held while it has unsaved input, so a background
 * refresh can never wipe a half-typed form.
 *
 * @var array<string, mixed> $account
 * @var array<string, mixed> $options
 * @var list<array<string, mixed>> $requests
 * @var Paginator $pager
 * @var array<string, mixed> $totals
 * @var string $payout_url
 * @var string $statement_url
 * @var string $profile_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => Live::payoutSurface((int) ($account['id'] ?? 0)),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y wallet-page payout-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Payouts</h4>
                  <p class="text-body-secondary mb-0">
                    Cash out the withdrawable part of your e-wallet. The amount is held as soon as you ask for it,
                    so you cannot spend it twice.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <a href="<?= e($profile_url) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-credit-card me-1"></i> Payout details
                  </a>
                  <a href="<?= e($statement_url) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-list-ul me-1"></i> Open statement
                  </a>
                </div>
              </div>

              <div class="row g-6 mb-6">
                <div class="col-12 col-xl-5">
<?= View::capture('member/payouts/_balance', ['options' => $options]) ?>
                </div>
                <div class="col-12 col-xl-7">
<?= View::capture('member/payouts/_request', [
    'options'     => $options,
    'payout_url'  => $payout_url,
    'profile_url' => $profile_url,
]) ?>
                </div>
              </div>

<?= View::capture('member/payouts/_history', [
    'requests'      => $requests,
    'totals'        => $totals,
    'statement_url' => $statement_url,
]) ?>

<?= View::capture('member/payouts/_pagination', ['pager' => $pager]) ?>
            </div>
