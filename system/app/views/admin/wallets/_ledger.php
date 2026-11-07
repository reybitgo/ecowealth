<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — the cross-wallet ledger.
 *
 * The whole statement card is one live region because its filter form,
 * table, totals and pagination are one readable unit. `data-live-hold`
 * keeps the administrator's typed filters while a background refresh runs.
 *
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $ledger_filters
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $ledger_pager
 * @var array{rows:int, credits:float, debits:float, net:float} $ledger_totals
 */
?>
<?=
    View::partial('wallet_statement', [
        'filters'         => $ledger_filters,
        'ledger'          => $ledger,
        'pager'           => $ledger_pager,
        'totals'          => $ledger_totals,
        'statement_url'   => url('admin/wallets?tab=ledger'),
        'statement_title' => 'All wallet movements',
        'show_member'     => true,
        'empty_title'     => 'No movements match these filters',
        'empty_text'      => 'Every credit and debit across all wallets appears here, newest first.',
        'hidden_params'   => ['tab' => 'ledger'],
        'live_region'     => 'wallet-ledger',
        'live_hold'       => true,
    ])
?>
