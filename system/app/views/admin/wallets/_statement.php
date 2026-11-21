<?php
declare(strict_types=1);

/**
 * Admin wallet page — the member's statement.
 *
 * The whole card is one live region; its filter form is protected by
 * `data-live-hold="dirty"` and the page's stale banner.
 *
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $pager
 * @var array{rows:int, credits:float, debits:float, net:float} $totals
 * @var string $statement_url
 * @var string $statement_title
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
        'show_member'     => false,
        'empty_title'     => 'This wallet has no movements yet',
        'empty_text'      => 'Credit it with a top-up, or wait for its first commission.',
        'live_region'     => 'wallet-statement',
        'live_hold'       => true,
    ])
?>
