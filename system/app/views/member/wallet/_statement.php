<?php
declare(strict_types=1);

/**
 * Member wallet statement — the statement card.
 *
 * The whole card is one live region; its filter form is protected by
 * `data-live-hold="dirty"` and the page's stale banner, so a background
 * refresh never discards an unsubmitted filter.
 *
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $pager
 * @var array{rows:int, credits:float, debits:float, net:float} $totals
 * @var string $statement_url
 * @var string $statement_title
 * @var bool $show_member
 */
?>
<?=
    View::partial('wallet_statement', [
        'filters'         => $filters,
        'ledger'          => $ledger,
        'pager'           => $pager,
        'totals'          => $totals,
        'statement_url'   => $statement_url,
        'statement_title' => $statement_title,
        'show_member'     => $show_member,
        'live_region'     => 'member-wallet-statement',
        'live_hold'       => true,
    ])
?>
