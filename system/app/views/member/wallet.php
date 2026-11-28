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
 * Phase 18 adds the escrow read-out: `pending_repeat` is the
 * repeat-purchase bonus that is earned but outside both buckets. The
 * bucket cards show it beside the wallet figures and combine it into a
 * presentation-only "Total funds" line — it is never spendable or
 * withdrawable until the release job credits it (plan §10.1).
 *
 * One live surface per member (`member.wallet:{id}`). The bucket cards,
 * the send-funds card and the statement card are regions; the statement
 * owns the filter form, so it is held while the member has unsaved input.
 *
 * @var array<string, mixed> $account
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $pager
 * @var array{rows:int, credits:float, debits:float, net:float} $totals
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $filters
 * @var array<string, mixed> $limits
 * @var array<string, mixed> $payout
 * @var array{total:float, count:int, next_release_at:?string, undelivered:int, overdue:int, release_enabled:bool} $pending_repeat
 * @var string $statement_url
 * @var string $statement_title
 * @var bool $show_member
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$limits         = $limits ?? (new TransferService())->usageFor($account);
$payout         = $payout ?? (new PayoutService())->optionsFor($account);
$pending_repeat = $pending_repeat ?? UnilevelAward::pendingSummaryForUser((int) ($account['id'] ?? 0));

$live = $live ?? [
    'surface'  => Live::memberWalletSurface((int) ($account['id'] ?? 0)),
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
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-6 d-none" data-live-stale role="status" aria-live="polite">
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>Your wallet changed in another tab.</strong>
                  Your unsaved filter was kept, so this page may be out of date.
                  <a href="<?= e(url('wallet')) ?>" class="alert-link" data-live-reload>
                    Reload the page
                  </a>
                  to see the latest.
                </div>
              </div>

<?= View::partial('page_header', [
    'title'       => 'E-wallet',
    'description' => 'Every peso comes from one ledger. Nothing here can be edited — corrections are new entries.'
        . ' <span class="small text-body-secondary" data-live-status aria-live="polite"></span>',
    'actions'     => '<span class="badge bg-label-primary fs-6">'
        . '<i class="icon-base bx bx-user-circle me-1"></i> ' . e((string) $account['username']) . '</span>',
]) ?>

<?= View::capture('member/wallet/_buckets', [
    'account'        => $account,
    'pending_repeat' => $pending_repeat,
]) ?>

<?= View::capture('member/wallet/_transfer_card', ['limits' => $limits]) ?>

<?= View::capture('member/wallet/_payout_card', [
    'payout'         => $payout,
    'pending_repeat' => $pending_repeat,
]) ?>

              <div class="alert alert-info d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-info-circle fs-4"></i>
                <div class="small">
                  <strong>How the two buckets work.</strong>
                  Commission earnings are withdrawable. Admin top-ups and transferred funds can be spent in the shop
                  but stay non-withdrawable. Purchases, registrations and fees spend the non-withdrawable part first,
                  so your withdrawable earnings stay available for payout.
                </div>
              </div>

<?= View::capture('member/wallet/_statement', [
    'filters'         => $filters,
    'ledger'          => $ledger,
    'pager'           => $pager,
    'totals'          => $totals,
    'statement_url'   => $statement_url,
    'statement_title' => $statement_title,
    'show_member'     => $show_member,
]) ?>
            </div>
