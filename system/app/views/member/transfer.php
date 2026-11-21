<?php
declare(strict_types=1);

/**
 * Member — send funds to another member (plan §5.8, §7 Phase 12;
 * reference views/member/ewallet_transfer.php).
 *
 * The page answers three questions in one screen:
 *   1. what the member has (both buckets, so the fee and the spend
 *      order are understandable without a second page);
 *   2. what the transfer costs them (fee, minimum, limit meters);
 *   3. what happened before (sent and received transfers).
 *
 * One live surface per member (`member.transfer:{id}`). The balance
 * card, the limit meters and the recent list are regions; the form is
 * outside every region so a background refresh can never wipe what the
 * member is typing.
 *
 * @var array<string, mixed> $account
 * @var array<string, mixed> $limits
 * @var list<array<string, mixed>> $recent
 * @var Paginator $pager
 * @var array{sent:int, sent_total:float, received:int, received_total:float, fees_total:float} $totals
 * @var string $transfer_url
 * @var string $statement_url
 * @var string $prefill_to
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => Live::transferSurface((int) ($account['id'] ?? 0)),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$staff = (bool) ($limits['staff'] ?? false);
?>
            <div
              class="container-xxl flex-grow-1 container-p-y wallet-page transfer-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Send funds</h4>
                  <p class="text-body-secondary mb-0">
                    Move money to another member’s e-wallet instantly. The recipient cannot withdraw a transfer;
                    they can spend it in the shop.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
<?php if ($staff): ?>
                  <span class="badge bg-label-warning">
                    <i class="icon-base bx bx-shield-quarter me-1"></i> Staff transfer — no fee, no limit
                  </span>
<?php endif; ?>
                  <a href="<?= e($statement_url) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-list-ul me-1"></i> Open statement
                  </a>
                </div>
              </div>

              <div class="row g-6 mb-6">
                <div class="col-12 col-xl-5">
<?= View::capture('member/transfer/_balance', ['limits' => $limits]) ?>
                </div>
                <div class="col-12 col-xl-7">
<?= View::capture('member/transfer/_form', [
    'limits'      => $limits,
    'transfer_url' => $transfer_url,
    'prefill_to'  => $prefill_to,
]) ?>
                </div>
              </div>

<?= View::capture('member/transfer/_limits', ['limits' => $limits]) ?>

<?= View::capture('member/transfer/_recent', [
    'recent'        => $recent,
    'account'       => $account,
    'totals'        => $totals,
    'statement_url' => $statement_url,
]) ?>

<?= View::capture('member/transfer/_recent_pagination', ['pager' => $pager]) ?>
            </div>
