<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminSettingsController — every configurable key from every
 *  phase (plan §7 Phases 7–8; expanded into the full settings page
 *  in Phase 15; reference AdminController::settings/saveSettings)
 *
 *  Routes:
 *      GET  /admin/settings   every settings card
 *      POST /admin/settings   validated save + audit entry
 *
 *  Every write is audited (`settings.update`, with the before/after
 *  value per key) because these values decide where buyers send
 *  money, how long they have to send it, what the printed invoice
 *  says, how commissions are paid and what transfers may move.
 *
 *  Cards save independently: the hidden `section` field tells save()
 *  which card's keys to validate, write and audit, so pressing one
 *  card's **Save settings** never touches another card's values.
 * ============================================================== */

final class AdminSettingsController
{
    /**
     * Identity and the maintenance switch (Phase 1 keys, exposed in
     * Phase 15). `site_name` is the brand the layout renders.
     */
    private const GENERAL_FIELDS = [
        [
            'key'   => 'site_name',
            'label' => 'Site name',
            'rule'  => 'required|string|max:80',
            'max'   => 80,
            'hint'  => 'Shown in the browser title, the app header and the sign-in pages.',
        ],
        [
            'key'   => 'site_tagline',
            'label' => 'Tagline',
            'rule'  => 'nullable|string|max:160',
            'max'   => 160,
            'hint'  => 'A short line under the brand on the sign-in pages.',
        ],
    ];

    /**
     * Receiving accounts per manual method. The checkout and the order
     * page render exactly these values, so an empty field is shown as
     * "Not configured" rather than as a broken instruction.
     */
    private const ACCOUNT_GROUPS = [
        [
            'title'  => 'GCash',
            'icon'   => 'bx-mobile-alt',
            'method' => 'gcash',
            'fields' => [
                ['key' => 'payment_gcash_number', 'label' => 'GCash number', 'placeholder' => '09XX XXX XXXX'],
                ['key' => 'payment_gcash_name', 'label' => 'Account name', 'placeholder' => 'Registered account name'],
            ],
        ],
        [
            'title'  => 'Maya',
            'icon'   => 'bx-mobile',
            'method' => 'maya',
            'fields' => [
                ['key' => 'payment_maya_number', 'label' => 'Maya number', 'placeholder' => '09XX XXX XXXX'],
                ['key' => 'payment_maya_name', 'label' => 'Account name', 'placeholder' => 'Registered account name'],
            ],
        ],
        [
            'title'  => 'Bank transfer',
            'icon'   => 'bx-building-bank',
            'method' => 'bank',
            'fields' => [
                ['key' => 'payment_bank_name', 'label' => 'Bank', 'placeholder' => 'e.g. BDO'],
                ['key' => 'payment_bank_account_name', 'label' => 'Account name', 'placeholder' => 'Account holder'],
                ['key' => 'payment_bank_account_number', 'label' => 'Account number', 'placeholder' => '0000 0000 0000'],
            ],
        ],
    ];

    /**
     * The timing rules the payment lifecycle reads (plan §5.4).
     * Each entry: key => [label, rule, hint].
     */
    private const WINDOW_FIELDS = [
        'shop_payment_deadline_hours' => [
            'label' => 'Payment window (hours)',
            'rule'  => 'required|integer|gte:1|lte:720',
            'hint'  => 'How long a member has to pay a manual order before the expiry job cancels it.',
        ],
        'shop_correction_window_hours' => [
            'label' => 'Correction window (hours)',
            'rule'  => 'required|integer|gte:1|lte:720',
            'hint'  => 'How long a member has to upload a corrected proof after a rejection.',
        ],
        'shop_max_proof_attempts' => [
            'label' => 'Proof attempts per order',
            'rule'  => 'required|integer|gte:1|lte:10',
            'hint'  => 'Uploads allowed per order, including the first one.',
        ],
    ];

    /**
     * The company identity frozen onto every issued invoice (D12: store
     * the seller fields now, print a simple invoice; VAT later).
     */
    private const SELLER_FIELDS = [
        ['key' => 'seller_name', 'label' => 'Registered business name', 'placeholder' => 'EcoWealth Trading Inc.', 'type' => 'text', 'max' => 160],
        ['key' => 'seller_tin', 'label' => 'TIN', 'placeholder' => '000-000-000-000', 'type' => 'text', 'max' => 40],
        ['key' => 'seller_phone', 'label' => 'Contact number', 'placeholder' => '09XX XXX XXXX', 'type' => 'text', 'max' => 40],
        ['key' => 'seller_address', 'label' => 'Business address', 'placeholder' => 'Unit, street, city, province, postal code', 'type' => 'textarea', 'max' => 500],
    ];

    /**
     * Fulfilment timing (plan §5.5). `shop_autocomplete_days` is exposed
     * as a number; the kill switch travels beside it.
     */
    private const FULFILMENT_FIELDS = [
        'shop_autocomplete_days' => [
            'label' => 'Auto-complete delivered orders after (days)',
            'rule'  => 'required|integer|gte:1|lte:365',
            'hint'  => 'A delivered order completes itself after this many days unless the member confirms earlier.',
        ],
    ];

    /**
     * The withdrawal rules members cash out under: the floor and the
     * ₱/USDT conversion. `PayoutService` reads exactly these keys, so the
     * member's `/payouts` page and the admin queue print what is saved
     * here.
     */
    private const PAYOUT_FIELDS = [
        [
            'key'    => 'min_payout',
            'label'  => 'Minimum payout',
            'hint'   => 'The smallest withdrawal a member may request.',
            'step'   => '0.01',
            'suffix' => CURRENCY_SYMBOL,
        ],
        [
            'key'    => 'usdt_rate',
            'label'  => 'USDT rate',
            'hint'   => 'Pesos for 1 USDT. While this is 0 the two USDT channels stay closed and members are told why.',
            'step'   => '0.0001',
            'suffix' => CURRENCY_SYMBOL . ' / USDT',
        ],
    ];

    /**
     * The four payout channels, in display order. `toggle` is the
     * channel's own switch, `fee` its service-fee percentage and `gas`
     * its network fee in USDT (empty for the two peso channels).
     */
    private const PAYOUT_CHANNELS = [
        [
            'key'    => 'gcash',
            'title'  => 'GCash',
            'icon'   => 'bx-mobile-alt',
            'usdt'   => false,
            'toggle' => 'payout_gcash_enabled',
            'fee'    => 'service_fee_gcash',
            'gas'    => '',
        ],
        [
            'key'    => 'maya',
            'title'  => 'Maya',
            'icon'   => 'bx-mobile',
            'usdt'   => false,
            'toggle' => 'payout_maya_enabled',
            'fee'    => 'service_fee_maya',
            'gas'    => '',
        ],
        [
            'key'    => 'usdt_trc20',
            'title'  => 'USDT (TRC20)',
            'icon'   => 'bx-dollar-circle',
            'usdt'   => true,
            'toggle' => 'payout_usdt_trc20_enabled',
            'fee'    => 'service_fee_usdt_trc20',
            'gas'    => 'usdt_trc20_gas_fee',
        ],
        [
            'key'    => 'usdt_bep20',
            'title'  => 'USDT (BEP20)',
            'icon'   => 'bx-coin-stack',
            'usdt'   => true,
            'toggle' => 'payout_usdt_bep20_enabled',
            'fee'    => 'service_fee_usdt_bep20',
            'gas'    => 'usdt_bep20_gas_fee',
        ],
    ];

    /**
     * Commission switches (Phase 9–11 keys, exposed in Phase 15).
     *
     * A switch list rather than plain fields because each row is a
     * labelled checkbox with its own explanation of who is paid.
     */
    private const COMMISSION_TOGGLES = [
        'commission_direct_enabled' => [
            'label' => 'Direct referral bonus',
            'hint'  => 'Level 1: the sponsor receives the package direct bonus when a member activates.',
        ],
        'commission_indirect_enabled' => [
            'label' => 'Indirect referral (unilevel)',
            'hint'  => 'Levels 2–10: each eligible upline receives the source package level amount.',
        ],
        'commission_requires_active_sponsor' => [
            'label' => 'Require an active sponsor',
            'hint'  => 'An upline without an active membership and package consumes its level and is not paid (no compression).',
        ],
        'commission_compression' => [
            'label' => 'Upline compression (reserved)',
            'hint'  => 'Reserved for a later pillar; this build always pays without compression, whichever way this is set.',
        ],
        'free_registration_enabled' => [
            'label' => 'Free standard registration',
            'hint'  => 'Allow a shopping-only account with no package and no code.',
        ],
    ];

    /**
     * The unilevel depth. Level 1 is the direct bonus, so the indirect
     * ladder is 2…10.
     */
    private const COMMISSION_FIELDS = [
        [
            'key'    => 'commission_indirect_max_levels',
            'label'  => 'Unilevel depth (levels)',
            'rule'   => 'required|integer|gte:2|lte:10',
            'suffix' => 'levels',
            'hint'   => 'How far up the sponsor chain an indirect bonus is paid (2–10).',
        ],
    ];

    /**
     * The repeat-purchase bonus (Phase 17B keys, exposed in 17B;
     * Phase 18 adds the release delay).
     *
     * Seven of the eight `unilevel*` keys are operator-facing; the
     * eighth, `unilevel_ledger_since`, is a reconciliation baseline
     * written by migration 020 and is deliberately never rendered
     * (plan §13.5). Phase 18's two keys: `unilevel_hold_days` here and
     * `unilevel_release_enabled` in REPEAT_TOGGLES.
     *
     * Same `{key,label,rule,suffix,hint}` shape as COMMISSION_FIELDS, plus
     * the input attributes the view needs (`min`, `max`, `step`).
     */
    private const REPEAT_FIELDS = [
        [
            'key'    => 'unilevel_max_levels',
            'label'  => 'Upline depth',
            'rule'   => 'required|integer|gte:1|lte:10',
            'suffix' => 'levels',
            'hint'   => 'How far up the sponsor chain the repeat-purchase bonus is paid (1–10). The ladder on each product has ten levels; this caps the walk.',
            'min'    => 1,
            'max'    => 10,
            'step'   => '1',
        ],
        [
            'key'    => 'unilevel_active_threshold',
            'label'  => 'Monthly personal-purchase minimum',
            'rule'   => 'required|numeric|gte:0|lte:1000000|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'An upline must have bought at least this much in the month that just ended to be paid, i.e. to be Active for this month. 0 pays every eligible upline.',
            'min'    => 0,
            'max'    => 1000000,
            'step'   => '0.01',
        ],
        [
            'key'    => 'unilevel_hold_days',
            'label'  => 'Release delay after delivery',
            'rule'   => 'required|integer|gte:0|lte:365',
            'suffix' => 'days',
            'hint'   => 'Days after delivery before a repeat-purchase bonus becomes withdrawable. 0 releases at delivery. Applies to new awards only; each award keeps the value it was given at payment.',
            'min'    => 0,
            'max'    => 365,
            'step'   => '1',
        ],
    ];

    /** The repeat-purchase switches, in display order (plan §9, §13.5). */
    private const REPEAT_TOGGLES = [
        'unilevel_enabled' => [
            'label' => 'Repeat-purchase bonus enabled',
            'hint'  => 'Off hides the offer and stops new awards. Bonuses already pending still release unless Release repeat-purchase bonuses is also off.',
        ],
        'unilevel_requires_active_upline' => [
            'label' => 'Require the monthly Active rule',
            'hint'  => 'Off: uplines are paid without the monthly personal-purchase check. The account and membership checks still apply.',
        ],
        'unilevel_reversal_enabled' => [
            'label' => 'Reverse awards when a paid order is cancelled',
            'hint'  => 'On: cancelling an order after its bonus was released claws the bonus back. Off: a released bonus stays. Pending bonuses are always cancelled when an order is cancelled; order cancellation itself is off by default (Shop → Enable Cancel of Order).',
        ],
        'unilevel_release_enabled' => [
            'label' => 'Release repeat-purchase bonuses',
            'hint'  => 'Off pauses the release job; pending bonuses stay pending. Cancelling an order still cancels its pending awards.',
        ],
    ];

    /**
     * The Royalty bonus ladder (Phase 19B, plan §12.2, §13.4).
     *
     * Thirteen operator-facing keys: four rank requirements, three
     * monthly group quotas and six payout rates. `royalty_ledger_since`
     * is a reconciliation baseline written by migration 023 and is
     * deliberately never rendered (plan §13.4).
     *
     * `group` lets the card render one sub-heading per block without
     * hard-coding key lists in the view.
     */
    private const ROYALTY_FIELDS = [
        [
            'key'    => 'royalty_leader_directs',
            'group'  => 'requirements',
            'label'  => 'Leader: direct members',
            'rule'   => 'required|integer|gte:1|lte:1000',
            'suffix' => 'members',
            'hint'   => 'Direct member accounts needed for Leader. Ranks are derived from the sponsor tree, so a change applies from the next evaluation; a closed month keeps its snapshot.',
            'min'    => 1,
            'max'    => 1000,
            'step'   => '1',
        ],
        [
            'key'    => 'royalty_supervisor_leaders',
            'group'  => 'requirements',
            'label'  => 'Supervisor: downline Leaders',
            'rule'   => 'required|integer|gte:1|lte:100000',
            'suffix' => 'members',
            'hint'   => 'Members at Leader or above below this member, at any depth, on top of the Leader requirement.',
            'min'    => 1,
            'max'    => 100000,
            'step'   => '1',
        ],
        [
            'key'    => 'royalty_manager_supervisors',
            'group'  => 'requirements',
            'label'  => 'Manager: downline Supervisors',
            'rule'   => 'required|integer|gte:1|lte:100000',
            'suffix' => 'members',
            'hint'   => 'Members at Supervisor or above below this member, at any depth, on top of the Supervisor requirement.',
            'min'    => 1,
            'max'    => 100000,
            'step'   => '1',
        ],
        [
            'key'    => 'royalty_director_managers',
            'group'  => 'requirements',
            'label'  => 'Director: downline Managers',
            'rule'   => 'required|integer|gte:1|lte:100000',
            'suffix' => 'members',
            'hint'   => 'Members at Manager or above below this member, at any depth, on top of the Manager requirement.',
            'min'    => 1,
            'max'    => 100000,
            'step'   => '1',
        ],
        [
            'key'    => 'royalty_supervisor_quota',
            'group'  => 'quotas',
            'label'  => 'Supervisor: monthly group quota',
            'rule'   => 'required|numeric|gte:0|lte:999999999.99|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'The minimum group sales (own purchases plus every downline member\'s purchases) for Supervisor to pay in a month. Below it the whole award is ₱0, including the personal percentage.',
            'min'    => 0,
            'max'    => 999999999.99,
            'step'   => '0.01',
        ],
        [
            'key'    => 'royalty_manager_quota',
            'group'  => 'quotas',
            'label'  => 'Manager: monthly group quota',
            'rule'   => 'required|numeric|gte:0|lte:999999999.99|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'The minimum group sales for Manager to pay in a month; cannot be below the Supervisor quota.',
            'min'    => 0,
            'max'    => 999999999.99,
            'step'   => '0.01',
        ],
        [
            'key'    => 'royalty_director_quota',
            'group'  => 'quotas',
            'label'  => 'Director: monthly group quota',
            'rule'   => 'required|numeric|gte:0|lte:999999999.99|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'The minimum group sales for Director to pay in a month; cannot be below the Manager quota.',
            'min'    => 0,
            'max'    => 999999999.99,
            'step'   => '0.01',
        ],
        [
            'key'    => 'royalty_supervisor_group_pct',
            'group'  => 'rates',
            'label'  => 'Supervisor: group %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'hint'   => 'Paid on the member\'s group sales at Supervisor; cannot be below a lower rank or above 100% with its personal rate.',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
        ],
        [
            'key'    => 'royalty_supervisor_personal_pct',
            'group'  => 'rates',
            'label'  => 'Supervisor: personal %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'hint'   => 'Paid again on the member\'s own purchases at Supervisor. Own purchases therefore earn group % + personal %.',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
        ],
        [
            'key'    => 'royalty_manager_group_pct',
            'group'  => 'rates',
            'label'  => 'Manager: group %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'hint'   => 'Paid on the member\'s group sales at Manager; cannot be below the Supervisor group rate.',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
        ],
        [
            'key'    => 'royalty_manager_personal_pct',
            'group'  => 'rates',
            'label'  => 'Manager: personal %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'hint'   => 'Paid again on the member\'s own purchases at Manager; cannot be below the Supervisor personal rate.',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
        ],
        [
            'key'    => 'royalty_director_group_pct',
            'group'  => 'rates',
            'label'  => 'Director: group %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'hint'   => 'Paid on the member\'s group sales at Director; cannot be below the Manager group rate.',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
        ],
        [
            'key'    => 'royalty_director_personal_pct',
            'group'  => 'rates',
            'label'  => 'Director: personal %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'hint'   => 'Paid again on the member\'s own purchases at Director; cannot be below the Manager personal rate.',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
        ],
    ];

    /** The Royalty switches, in display order (plan §13.4). */
    private const ROYALTY_TOGGLES = [
        'royalty_enabled' => [
            'label' => 'Enable the Royalty bonus',
            'hint'  => 'Off makes the next month close a skipped period: no awards are written and no money moves. Ranks stay visible on the member pages.',
        ],
        'royalty_pay_enabled' => [
            'label' => 'Pay Royalty awards',
            'hint'  => 'Off pauses payment: evaluated awards stay pending and a later run can pay them. Evaluation itself keeps running.',
        ],
    ];

    /**
     * Transfer fees and limits (Phase 12 keys, exposed in Phase 15).
     *
     * A limit of 0 means *unlimited* in TransferService: an operator who
     * wants no cap types 0, not a blank field (plan §5.8).
     */
    private const TRANSFER_FIELDS = [
        [
            'key'    => 'ewallet_transfer_fee',
            'label'  => 'Transfer fee',
            'rule'   => 'required|numeric|gte:0|lte:' . TransferService::MAX_AMOUNT . '|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'Flat fee charged to the sender; credited to the primary admin.',
        ],
        [
            'key'    => 'ewallet_min_transfer',
            'label'  => 'Minimum transfer',
            'rule'   => 'required|numeric|gte:0|lte:' . TransferService::MAX_AMOUNT . '|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'The smallest amount a member may send.',
        ],
        [
            'key'    => 'ewallet_transfer_daily_limit',
            'label'  => 'Daily limit',
            'rule'   => 'required|numeric|gte:0|lte:' . TransferService::MAX_AMOUNT . '|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'Per-member daily send cap; 0 means unlimited.',
        ],
        [
            'key'    => 'ewallet_transfer_weekly_limit',
            'label'  => 'Weekly limit',
            'rule'   => 'required|numeric|gte:0|lte:' . TransferService::MAX_AMOUNT . '|decimal:2',
            'suffix' => CURRENCY_SYMBOL,
            'hint'   => 'Per-member weekly send cap; 0 means unlimited.',
        ],
    ];

    /**
     * The Team Harvest knobs (Phase 20B, plan §6, §12.2, §12.6 Rule 3).
     *
     * Two numbers and nothing else: the ten amounts are **not** settings —
     * they are the ten rows of `team_harvest_levels` that 20A shipped — so
     * `TEAM_HARVEST_AMOUNTS` below describes them and `TeamHarvestLevel`
     * owns their arithmetic and their write.
     */
    private const TEAM_HARVEST_FIELDS = [
        [
            'key'    => 'team_harvest_qualify_directs',
            'group'  => 'knobs',
            'label'  => 'Direct members needed to qualify',
            'rule'   => 'required|integer|gte:1|lte:10',
            'suffix' => 'members',
            'hint'   => 'A member enters the queue the moment this many direct member accounts have registered. Staff accounts never count. Changing it never moves an existing position; after a lowering, run bin/harvest_queue_seed.php --rebuild to append the members who now hold it.',
            'min'    => 1,
            'max'    => 10,
            'step'   => '1',
        ],
        [
            'key'    => 'team_harvest_max_levels',
            'group'  => 'knobs',
            'label'  => 'Levels that pay',
            'rule'   => 'required|integer|gte:1|lte:10',
            'suffix' => 'levels',
            'hint'   => 'The deepest level that may pay. Levels above it still complete on the board and are marked not paid; their amounts are kept, and raising the depth pays them at the next evaluation.',
            'min'    => 1,
            'max'    => 10,
            'step'   => '1',
        ],
    ];

    /** The Team Harvest switch, in display order (plan §6 Rule 4, D-09). */
    private const TEAM_HARVEST_TOGGLES = [
        'team_harvest_enabled' => [
            'label' => 'Enable the Team Harvest bonus',
            'hint'  => 'Off means no award is written and no money moves. Qualifications are still recorded, so nothing is lost while it is off.',
        ],
    ];

    /** The one hint every amount input carries, so the ten rows read alike. */
    private const TEAM_HARVEST_AMOUNT_HINT = 'What this level pays when its block is full. Blank and 0 are the same: the level still completes but pays nothing, and a later raise can pay it once.';

    /**
     * The ten amount fields, keyed by the same dotted names the validator,
     * the error bag and `invalid_class()` use (`team_harvest_amounts.3`).
     *
     * The card posts the natural `team_harvest_amounts[3]` array; the dotted
     * key is the internal name, exactly as ProductUnilevel uses
     * `unilevel_levels.3` for `unilevel_levels[3]`. The rule is the model's
     * own constant, so the card and `TeamHarvestLevel::validate()` can never
     * disagree about the ceiling or the precision.
     *
     * `level` and `slots` are what the card needs to render one row without
     * computing the matrix itself (plan §6 Rule 8).
     */
    private const TEAM_HARVEST_AMOUNTS = [
        ['key' => 'team_harvest_amounts.1',  'level' => 1,  'group' => 'amounts', 'label' => 'Level 1 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.2',  'level' => 2,  'group' => 'amounts', 'label' => 'Level 2 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.3',  'level' => 3,  'group' => 'amounts', 'label' => 'Level 3 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.4',  'level' => 4,  'group' => 'amounts', 'label' => 'Level 4 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.5',  'level' => 5,  'group' => 'amounts', 'label' => 'Level 5 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.6',  'level' => 6,  'group' => 'amounts', 'label' => 'Level 6 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.7',  'level' => 7,  'group' => 'amounts', 'label' => 'Level 7 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.8',  'level' => 8,  'group' => 'amounts', 'label' => 'Level 8 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.9',  'level' => 9,  'group' => 'amounts', 'label' => 'Level 9 amount',  'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
        ['key' => 'team_harvest_amounts.10', 'level' => 10, 'group' => 'amounts', 'label' => 'Level 10 amount', 'rule' => TeamHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::TEAM_HARVEST_AMOUNT_HINT],
    ];

    /**
     * The card's four status states, in plan §6 Rule 7 order. The strip
     * renders exactly one of them and never claims the stream is live when
     * it is unseeded or switched off.
     *
     * @var list<string>
     */
    public const TEAM_HARVEST_STATES = ['unseeded', 'seeded_off', 'seeded_on', 'backlog'];

    /**
     * The General Harvest knobs (Phase 21B, plan §6, §12.4 Rule 4, §13.5).
     *
     * Two settings and nothing else: the switch is a toggle below and the
     * payable depth is the one field here. The ten amounts are **not**
     * settings — they are the ten rows of `general_harvest_levels` that 21A
     * shipped — so `GENERAL_HARVEST_AMOUNTS` describes them and
     * `GeneralHarvestLevel` owns their arithmetic and their write.
     */
    private const GENERAL_HARVEST_FIELDS = [
        [
            'key'    => GeneralHarvestLevel::DEPTH_KEY,
            'group'  => 'knobs',
            'label'  => 'Levels that pay',
            'rule'   => 'required|integer|gte:1|lte:10',
            'suffix' => 'levels',
            'hint'   => 'The deepest level that may pay. Levels above it still complete on the board and are marked not paying at this depth; their amounts are kept, and raising the depth pays them from the current queue. A level inside the depth must have an amount.',
            'min'    => 1,
            'max'    => 10,
            'step'   => '1',
        ],
    ];

    /** The General Harvest switch, in display order (plan §6 Rule 9, D-10). */
    private const GENERAL_HARVEST_TOGGLES = [
        GeneralHarvestLevel::ENABLED_KEY => [
            'label' => 'Enable the General Harvest bonus',
            'hint'  => 'Off means no award is written and no money moves. The queue is still recorded, so nothing is lost while it is off — and nothing can pay until the board has been armed.',
        ],
    ];

    /**
     * The Product binary block (Phase 22B, plan §14.4).
     *
     * Five numbers and four switches. `binary_ledger_since` is a
     * reconciliation baseline written by migration 026 and is deliberately
     * never rendered here. The master switch ships off; everything else
     * ships at the reference preset. A product controls only its own
     * switch and its P.V. — there is no per-product pair size, pair rate
     * or self rate (D-09).
     *
     * Same `{key,label,rule,suffix,hint}` shape as COMMISSION_FIELDS, plus
     * the input attributes the view needs (`min`, `max`, `step`).
     */
    private const BINARY_FIELDS = [
        [
            'key'    => 'binary_self_pct',
            'label'  => 'Self Bonus %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
            'hint'   => 'Paid to the buyer on their own purchase, once per order. 0 disables the Self Bonus without turning the stream off.',
        ],
        [
            'key'    => 'binary_pair_points',
            'label'  => 'Points per pair',
            'rule'   => 'required|integer|gte:1|lte:1000000',
            'suffix' => 'points',
            'min'    => 1,
            'max'    => 1000000,
            'step'   => '1',
            'hint'   => 'One pair needs this many points on each side. 0 would pair every purchase, so it is refused.',
        ],
        [
            'key'    => 'binary_pair_pct',
            'label'  => 'Pair value %',
            'rule'   => 'required|numeric|gte:0|lte:100|decimal:3',
            'suffix' => '%',
            'min'    => 0,
            'max'    => 100,
            'step'   => '0.001',
            'hint'   => 'A pair pays this percent of the matched point value, then multiplied by the pair count. 0 still accumulates and consumes points but pays nothing.',
        ],
        [
            'key'    => 'binary_daily_pair_cap',
            'label'  => 'Pairs per day per member',
            'rule'   => 'required|integer|gte:0|lte:1000',
            'suffix' => 'pairs',
            'min'    => 0,
            'max'    => 1000,
            'step'   => '1',
            'hint'   => 'Maximum pairs one recipient may be paid in one Asia/Manila day. 0 means unlimited.',
        ],
        [
            'key'    => 'binary_hold_days',
            'label'  => 'Release delay after delivery',
            'rule'   => 'required|integer|gte:0|lte:365',
            'suffix' => 'days',
            'min'    => 0,
            'max'    => 365,
            'step'   => '1',
            'hint'   => 'Days after delivery before a pending award becomes withdrawable. 0 releases at delivery. Applies to new awards only.',
        ],
    ];

    /** The Product binary switches, in display order (plan §14.4, D-16). */
    private const BINARY_TOGGLES = [
        'binary_enabled' => [
            'label' => 'Product binary enabled',
            'hint'  => 'Off hides the offer and stops new awards. Nothing is paid by this phase — the payment engine arrives with Phase 22D, and the switch stays off until its gate passes.',
        ],
        'binary_requires_active' => [
            'label' => 'Require the monthly Active rule',
            'hint'  => 'Off: a pair recipient is paid without the monthly personal-purchase check. The account and membership checks still apply.',
        ],
        'binary_release_enabled' => [
            'label' => 'Release Product binary awards',
            'hint'  => 'Off pauses the release job; pending awards stay pending. Cancelling an order still cancels its pending awards.',
        ],
        'binary_reversal_enabled' => [
            'label' => 'Reverse awards when a paid order is cancelled',
            'hint'  => 'On: cancelling an order after its award was released claws the bonus back, best effort. Pending awards are always cancelled.',
        ],
    ];

    /**
     * The Package binary block (Phase 23B-S2, plan §12.4).
     *
     * Two switches and no operator-editable fields: the master switch
     * and the recipient-qualification toggle. `package_binary_ledger_since`
     * is the reconciliation baseline written by migration 027 and is
     * deliberately never rendered (plan §12.4). Every package starts
     * `binary_enabled = 0` / `binary_points = 0`, and the master switch
     * ships off, so deploying this phase changes no money.
     */
    private const PACKAGE_BINARY_FIELDS = [];

    /** The Package binary switches, in display order (plan §12.4). */
    private const PACKAGE_BINARY_TOGGLES = [
        'package_binary_enabled' => [
            'label' => 'Package binary enabled',
            'hint'  => 'Off stops every package activation from placing points and paying pairs. Nothing is paid by this phase — the payment engine arrives with Phase 23C, and the switch stays off until its gate passes.',
        ],
        'package_binary_requires_package' => [
            'label' => 'Require a binary package to earn package pairs',
            'hint'  => 'On: a pair recipient must hold a package whose own binary switch is on. Off: every eligible member earns package pairs. The monthly Active rule never applies to package pairs.',
        ],
    ];

    /** The one hint every General Harvest amount input carries. */
    private const GENERAL_HARVEST_AMOUNT_HINT = 'What this level pays when its block of 3^L positions is full. Blank and 0 are the same: the level still completes but pays nothing, and it must be given an amount before it can sit inside the payable depth.';

    /**
     * The ten amount fields, keyed by the same dotted names the validator,
     * the error bag and `invalid_class()` use (`general_harvest_amounts.3`).
     *
     * The card posts the natural `general_harvest_amounts[3]` array; the
     * rule is the model's own constant, so the card and
     * `GeneralHarvestLevel::validate()` can never disagree about the ceiling
     * or the precision.
     */
    private const GENERAL_HARVEST_AMOUNTS = [
        ['key' => 'general_harvest_amounts.1',  'level' => 1,  'group' => 'amounts', 'label' => 'Level 1 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.2',  'level' => 2,  'group' => 'amounts', 'label' => 'Level 2 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.3',  'level' => 3,  'group' => 'amounts', 'label' => 'Level 3 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.4',  'level' => 4,  'group' => 'amounts', 'label' => 'Level 4 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.5',  'level' => 5,  'group' => 'amounts', 'label' => 'Level 5 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.6',  'level' => 6,  'group' => 'amounts', 'label' => 'Level 6 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.7',  'level' => 7,  'group' => 'amounts', 'label' => 'Level 7 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.8',  'level' => 8,  'group' => 'amounts', 'label' => 'Level 8 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.9',  'level' => 9,  'group' => 'amounts', 'label' => 'Level 9 amount',  'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
        ['key' => 'general_harvest_amounts.10', 'level' => 10, 'group' => 'amounts', 'label' => 'Level 10 amount', 'rule' => GeneralHarvestLevel::AMOUNT_RULE, 'suffix' => CURRENCY_SYMBOL, 'hint' => self::GENERAL_HARVEST_AMOUNT_HINT],
    ];

    /**
     * The General Harvest card's four status states, in plan §6 Rule 9
     * order — the board model owns the list, the view renders one of them.
     *
     * @var list<string>
     */
    public const GENERAL_HARVEST_STATES = GeneralHarvestBoard::STATES;

    /**
     * The independently saveable cards on /admin/settings. Every card
     * has its own form and its own **Save settings** button, and only
     * the submitted section is validated, written and audited — so a
     * save in one card can never overwrite another card's values.
     */
    private const SECTIONS = [
        'general'    => 'General',
        'shop'       => 'Shop',
        'accounts'   => 'Receiving accounts',
        'payouts'    => 'Payout methods',
        'windows'    => 'Payment windows',
        'fulfilment' => 'Fulfilment & invoices',
        'commissions' => 'Commissions',
        'transfers'  => 'Transfers',
    ];

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL doubles as the JSON feed. Each card body is a held
         * region; the forms, headers and footers stay static. */
        if (Live::isFeedRequest()) {
            $this->feed();
            return;
        }

        View::page('admin', 'admin/settings/index', $this->settingsContext() + [
            'page_title'  => 'Settings',
            'current_nav' => 'admin.settings',
            'page_css'    => ['assets/css/orders.css'],
            'page_js'     => ['assets/js/admin-live.js', 'assets/js/admin-settings.js', 'assets/js/app-team-harvest.js', 'assets/js/app-general-harvest.js', 'assets/js/app-ladder-toolbar.js'],
            'live'        => Live::meta('admin.settings', Live::scope($_GET, (int) (auth_user()['id'] ?? 0))),
        ]);
    }

    /**
     * Everything the settings page renders, built once for the page and
     * its feed. Public so the view tests can assert the real screen data.
     *
     * @return array<string, mixed>
     */
    public function settingsContext(): array
    {
        return [
            'settings'          => setting_all(),
            'general_fields'    => self::GENERAL_FIELDS,
            'account_groups'    => self::ACCOUNT_GROUPS,
            'payout_fields'     => self::PAYOUT_FIELDS,
            'payout_channels'   => self::PAYOUT_CHANNELS,
            'window_fields'     => self::WINDOW_FIELDS,
            'seller_fields'     => self::SELLER_FIELDS,
            'fulfilment_fields' => self::FULFILMENT_FIELDS,
            'commission_fields' => self::COMMISSION_FIELDS,
            'commission_toggles' => self::COMMISSION_TOGGLES,
            'repeat_fields'     => self::REPEAT_FIELDS,
            'repeat_toggles'    => self::REPEAT_TOGGLES,
            'royalty_fields'    => self::ROYALTY_FIELDS,
            'royalty_toggles'   => self::ROYALTY_TOGGLES,
            'royalty_ladder'    => RoyaltyRank::ladder(),
            /* The Team Harvest card (Phase 20B, plan §6): the two knobs, the
             * switch, the ten amounts as the form shows them, the generated
             * ladder table, the four-state status strip and the preset that
             * **Load reference ladder** offers. The card never restores the
             * preset by itself (Rule 9). */
            'team_harvest_fields'  => array_merge(self::TEAM_HARVEST_FIELDS, self::TEAM_HARVEST_AMOUNTS),
            'team_harvest_toggles' => self::TEAM_HARVEST_TOGGLES,
            'team_harvest_amounts' => TeamHarvestLevel::forForm(TeamHarvestLevel::amounts()),
            'team_harvest_ladder'  => $this->teamHarvestLadder(),
            'team_harvest_status'  => $this->teamHarvestStatus(),
            'team_harvest_preset'  => TeamHarvestLevel::forForm(TeamHarvestLevel::referencePreset()),
            /* The General Harvest card (Phase 21B, plan §6): the switch, the
             * payable depth, the ten amounts as the form shows them, the
             * generated ladder, the generated position table, the four-state
             * strip and the calculator's defaults. Nothing here moves money:
             * the board is unarmed and no award exists until 21C/21D. */
            'general_harvest_fields'    => array_merge(self::GENERAL_HARVEST_FIELDS, self::GENERAL_HARVEST_AMOUNTS),
            'general_harvest_toggles'   => self::GENERAL_HARVEST_TOGGLES,
            'general_harvest_amounts'   => GeneralHarvestLevel::forForm(GeneralHarvestLevel::amounts()),
            'general_harvest_ladder'    => $this->generalHarvestLadder(),
            'general_harvest_positions' => $this->generalHarvestPositions(),
            'general_harvest_board'     => $this->generalHarvestBoard(),
            'general_harvest_preset'    => GeneralHarvestLevel::forForm(GeneralHarvestLevel::referencePreset()),
            'binary_fields'  => self::BINARY_FIELDS,
            'binary_toggles' => self::BINARY_TOGGLES,
            'binary_readiness' => Product::binaryReadinessCounts(),
            'package_binary_fields'  => self::PACKAGE_BINARY_FIELDS,
            'package_binary_toggles' => self::PACKAGE_BINARY_TOGGLES,
            'package_binary_readiness' => Package::binaryReadinessCounts(),
            'transfer_fields'   => self::TRANSFER_FIELDS,
            'shop_enabled'      => setting_bool('shop_enabled', true),
            'cancel_enabled'    => Order::cancelEnabled(),
            'expiry_enabled'    => setting_bool('shop_expiry_enabled', true),
            'autocomplete_enabled' => FulfilmentService::autoCompleteEnabled(),
            'maintenance_mode'  => setting_bool('maintenance_mode', false),
            'manual_open'       => $this->manualMethodsConfigured(),
        ];
    }

    /**
     * JSON feed for the open settings page.
     *
     * The 304 check runs before settingsContext(), so an unchanged page
     * costs one revision lookup and no settings reads. The card-body
     * fragments hold no form/CSRF field (the form wraps the whole card),
     * so the session lock is released immediately.
     */
    private function feed(): void
    {
        session_write_close();

        $surface = 'admin.settings';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, (int) (auth_user()['id'] ?? 0)));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->settingsContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] settings live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the settings. Please try again.', 500);
        }
    }

    /**
     * Render the settings payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        return [
            'ok'        => true,
            'surface'   => 'admin.settings',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'settings-notice' => View::capture('admin/settings/_notice', [
                    'manual_open' => $data['manual_open'],
                ]),
                'settings-general' => View::capture('admin/settings/_general', [
                    'settings'        => $data['settings'],
                    'general_fields'  => $data['general_fields'],
                    'maintenance_mode' => $data['maintenance_mode'],
                ]),
                'settings-shop' => View::capture('admin/settings/_shop', [
                    'settings' => $data['settings'],
                ]),
                'settings-accounts' => View::capture('admin/settings/_accounts', [
                    'settings'       => $data['settings'],
                    'account_groups' => $data['account_groups'],
                ]),
                'settings-payouts' => View::capture('admin/settings/_payouts', [
                    'settings'        => $data['settings'],
                    'payout_fields'   => $data['payout_fields'],
                    'payout_channels' => $data['payout_channels'],
                ]),
                'settings-windows' => View::capture('admin/settings/_windows', [
                    'settings'       => $data['settings'],
                    'window_fields'  => $data['window_fields'],
                    'expiry_enabled' => $data['expiry_enabled'],
                ]),
                'settings-fulfilment' => View::capture('admin/settings/_fulfilment', [
                    'settings'             => $data['settings'],
                    'seller_fields'        => $data['seller_fields'],
                    'fulfilment_fields'    => $data['fulfilment_fields'],
                    'autocomplete_enabled' => $data['autocomplete_enabled'],
                ]),
                'settings-commissions' => View::capture('admin/settings/_commissions', [
                    'settings'           => $data['settings'],
                    'commission_fields'  => $data['commission_fields'],
                    'commission_toggles' => $data['commission_toggles'],
                    'repeat_fields'      => $data['repeat_fields'],
                    'repeat_toggles'     => $data['repeat_toggles'],
                    'royalty_fields'     => $data['royalty_fields'],
                    'royalty_toggles'    => $data['royalty_toggles'],
                    'royalty_ladder'     => $data['royalty_ladder'],
                    'team_harvest_fields'  => $data['team_harvest_fields'],
                    'team_harvest_toggles' => $data['team_harvest_toggles'],
                    'team_harvest_amounts' => $data['team_harvest_amounts'],
                    'team_harvest_ladder'  => $data['team_harvest_ladder'],
                    'team_harvest_status'  => $data['team_harvest_status'],
                    'team_harvest_preset'  => $data['team_harvest_preset'],
                    'general_harvest_fields'    => $data['general_harvest_fields'],
                    'general_harvest_toggles'   => $data['general_harvest_toggles'],
                    'general_harvest_amounts'   => $data['general_harvest_amounts'],
                    'general_harvest_ladder'    => $data['general_harvest_ladder'],
                    'general_harvest_positions' => $data['general_harvest_positions'],
                    'general_harvest_board'     => $data['general_harvest_board'],
                    'general_harvest_preset'    => $data['general_harvest_preset'],
                    'binary_fields'  => $data['binary_fields'],
                    'binary_toggles' => $data['binary_toggles'],
                    'binary_readiness' => $data['binary_readiness'],
                    'package_binary_fields'  => $data['package_binary_fields'],
                    'package_binary_toggles' => $data['package_binary_toggles'],
                    'package_binary_readiness' => $data['package_binary_readiness'],
                ]),
                'settings-transfers' => View::capture('admin/settings/_transfers', [
                    'settings'        => $data['settings'],
                    'transfer_fields' => $data['transfer_fields'],
                ]),
            ],
            'meta'      => [
                'canonical' => url('admin/settings'),
            ],
        ];
    }

    /**
     * POST /admin/settings — save exactly one card.
     *
     * The form posts its `section` key; the controller only reads,
     * validates and writes that section's fields. The response returns
     * to the same card (and section) with a flash and any field errors.
     *
     * @param array<string, string> $params
     */
    public function save(array $params = []): void
    {
        $section = strtolower(trim(Request::string('section')));

        if (!isset(self::SECTIONS[$section])) {
            flash_set('danger', 'Unknown settings section.');
            redirect('admin/settings');
        }

        $back   = 'admin/settings#settings-' . $section;
        $posted = [];

        /* Only fields the request actually carried: an absent key must stay
         * absent. `sectionKeys()` names the two ladders' dotted amount keys,
         * and a null placeholder for one of them would read as a submission
         * of a blank amount — which clears that ladder (D-11) instead of
         * leaving the operator's rows alone. */
        foreach (self::sectionKeys($section) as $key) {
            $value = Request::input($key);

            if ($value !== null) {
                $posted[$key] = $value;
            }
        }

        /* The ten Team Harvest amounts post as `team_harvest_amounts[1..10]`
         * and the ten General Harvest amounts as
         * `general_harvest_amounts[1..10]`. Keep the whole arrays so
         * applySection() can resolve the dotted keys it shares with the
         * validator, the error bag and invalid_class(). A post that carries
         * no such array at all (an older form, a script that only flips a
         * switch) leaves that ladder alone rather than clearing it: only an
         * explicit empty/filled array is a submission. */
        if ($section === 'commissions') {
            foreach ([TeamHarvestLevel::AMOUNT_FIELD, GeneralHarvestLevel::AMOUNT_FIELD] as $ladder) {
                $amounts = Request::post($ladder);

                if (is_array($amounts)) {
                    $posted[$ladder] = $amounts;
                }
            }

            /* The General Harvest depth is preserved when a card save does
             * not carry it at all — an older rendered form, or a script that
             * only flips a switch. A field that is *present and empty* is a
             * submission and still fails `required`. */
            if (Request::input(GeneralHarvestLevel::DEPTH_KEY) === null) {
                unset($posted[GeneralHarvestLevel::DEPTH_KEY]);
            }
        }

        /* An unticked checkbox posts nothing at all. Read the switches
         * from POST only, exactly as before the Royalty block landed. */
        foreach (self::sectionToggles($section) as $key) {
            $posted[$key] = Request::post($key);
        }

        $result = $this->applySection($section, $posted);

        if ($result['errors'] !== []) {
            errors_set($result['errors']);
            old_set($result['input']);
            flash_set('danger', 'Please fix the highlighted settings.');
            redirect($back);
        }

        $saved             = count($result['changes']);
        $ladderSaved       = (bool) ($result['ladder']['changed'] ?? false);
        $generalLadderSaved = (bool) ($result['general_ladder']['changed'] ?? false);

        if ($saved === 0 && !$ladderSaved && !$generalLadderSaved) {
            flash_set('info', 'No changes to save.');
        } else {
            $parts = [];

            if ($saved > 0) {
                $parts[] = $saved . ' setting' . ($saved === 1 ? '' : 's');
            }

            if ($ladderSaved) {
                $parts[] = 'the Team Harvest ladder';
            }

            if ($generalLadderSaved) {
                $parts[] = 'the General Harvest ladder';
            }

            flash_set('success', sprintf(
                '%s: %s saved.',
                self::SECTIONS[$section],
                implode(' and ', $parts)
            ));
        }

        /* The submitted card was valid (or unchanged), so drop any input
         * kept from an earlier failed save. Without this the next render —
         * including the live feed's card replacements — would keep showing
         * the stale attempt instead of the values just stored. */
        old_clear();

        redirect($back);
    }

    /**
     * Validate, write and audit one settings card.
     *
     * Public so the tests can exercise the real save path in-process
     * (`save()` itself ends in redirect()). The caller owns the flash
     * message, the old-input bookkeeping and the redirect; everything
     * that decides whether the card is valid and what it writes lives
     * here.
     *
     * The Commissions card adds one wrinkle: its two Harvest ladders are
     * not settings rows but the ten rows of `team_harvest_levels` and
     * `general_harvest_levels`, so the write happens in **one transaction**
     * — the knobs, the twenty amounts and every audit row commit together
     * or not at all (plan §6 Rule 3/4, §15.1, P20B-T14, P21B-T2/T4).
     *
     * @param array<string, mixed> $posted the card's raw request fields
     * @return array{
     *     errors: array<string, string>,
     *     input: array<string, string>,
     *     changes: array<string, array{from: string, to: string}>,
     *     ladder?: array{changed: bool, before: array<string, mixed>, after: array<string, mixed>, changes: array<string, array{from: mixed, to: mixed}>}|null,
     *     general_ladder?: array{changed: bool, before: array<string, mixed>, after: array<string, mixed>, changes: array<string, array{from: mixed, to: mixed}>}|null
     * }
     */
    public function applySection(string $section, array $posted): array
    {
        $input = [];

        foreach (self::sectionKeys($section) as $key) {
            $value       = self::postedValue($posted, $key);
            $input[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        /* An unticked checkbox posts nothing at all. Normalise only this
         * card's switches; other cards' switches are never in $input. */
        foreach (self::sectionToggles($section) as $key) {
            $input[$key] = (string) ($posted[$key] ?? '') === '1' ? '1' : '0';
        }

        /* A post that does not carry a ladder's amount fields at all — an
         * older form, a script that only flips a switch — keeps that stored
         * ladder, so a partial save can never clear an operator's
         * configuration. Each ladder is preserved independently. */
        if ($section === 'commissions') {
            if (!self::teamHarvestAmountsSubmitted($posted)) {
                foreach (TeamHarvestLevel::forForm(TeamHarvestLevel::amounts()) as $level => $stored) {
                    $input[TeamHarvestLevel::AMOUNT_FIELD . '.' . $level] = $stored;
                }
            }

            if (!self::generalHarvestAmountsSubmitted($posted)) {
                foreach (GeneralHarvestLevel::forForm(GeneralHarvestLevel::amounts()) as $level => $stored) {
                    $input[GeneralHarvestLevel::AMOUNT_FIELD . '.' . $level] = $stored;
                }
            }

            /* Same rule for the depth: a card save that does not carry the
             * field at all keeps the stored value, so an older form never
             * fails validation and never silently costs an operator a knob.
             * A field present but empty is still a `required` error. */
            if (!array_key_exists(GeneralHarvestLevel::DEPTH_KEY, $posted)) {
                $input[GeneralHarvestLevel::DEPTH_KEY] = (string) GeneralHarvestLevel::maxLevels();
            }
        }

        $errors = Validator::check($input, self::sectionRules($section), self::sectionLabels($section));
        $errors = array_merge($errors, self::sectionCrossFieldErrors($section, $input));

        if ($errors !== []) {
            return [
                'errors'         => $errors,
                'input'          => $input,
                'changes'        => [],
                'ladder'         => null,
                'general_ladder' => null,
            ];
        }

        $changes = [];

        foreach ($input as $key => $value) {
            /* The twenty amounts are the ladders' rows, not settings keys:
             * they are written by the two models below. */
            if (self::isTeamHarvestAmountKey($key) || self::isGeneralHarvestAmountKey($key)) {
                continue;
            }

            $before = (string) setting($key, '');

            if ($before === $value) {
                continue;
            }

            $changes[$key] = ['from' => $before, 'to' => $value];
        }

        $admin         = auth_user();
        $ladder        = null;
        $generalLadder = null;

        $pdo             = db();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            /* Each ladder's before/after includes its own knobs (§15.4), so
             * they are read before the settings writes land. */
            if ($section === 'commissions') {
                $teamBefore = self::teamHarvestStored();
                $teamAfter  = self::teamHarvestPosted($input);
                $teamDiff   = self::teamHarvestDiff($teamBefore, $teamAfter);

                $ladder = [
                    'changed' => $teamDiff !== [],
                    'before'  => $teamBefore,
                    'after'   => $teamAfter,
                    'changes' => $teamDiff,
                ];

                $ghBefore = self::generalHarvestStored();
                $ghAfter  = self::generalHarvestPosted($input);
                $ghDiff   = self::generalHarvestDiff($ghBefore, $ghAfter);

                $generalLadder = [
                    'changed' => $ghDiff !== [],
                    'before'  => $ghBefore,
                    'after'   => $ghAfter,
                    'changes' => $ghDiff,
                ];
            }

            foreach ($changes as $key => $change) {
                setting_set($key, $change['to']);
            }

            /* A ladder that moved — amounts or knobs — is one audited
             * event, written inside the same transaction as its rows
             * (plan §6 Rule 3, §15.4). A save that changes neither writes
             * neither the rows nor the row. */
            if ($ladder !== null && $ladder['changed']) {
                TeamHarvestLevel::save(self::teamHarvestFloats($input), $admin);

                AuditLog::record([
                    'action'       => 'team_harvest.ladder.update',
                    'actor_id'     => $admin === null ? null : (int) $admin['id'],
                    'actor_role'   => $admin === null ? null : (string) $admin['role'],
                    'subject_type' => 'settings',
                    'subject_id'   => null,
                    'meta'         => [
                        'before'  => $ladder['before'],
                        'after'   => $ladder['after'],
                        'changes' => $ladder['changes'],
                    ],
                ]);
            }

            /* The General Harvest ladder is audited under its own action
             * (plan §6 Phase 21B): the two streams never share a ledger, a
             * baseline or a trail (D-22). */
            if ($generalLadder !== null && $generalLadder['changed']) {
                GeneralHarvestLevel::save(self::generalHarvestFloats($input), $admin);

                AuditLog::record([
                    'action'       => 'general_harvest.ladder.update',
                    'actor_id'     => $admin === null ? null : (int) $admin['id'],
                    'actor_role'   => $admin === null ? null : (string) $admin['role'],
                    'subject_type' => 'settings',
                    'subject_id'   => null,
                    'meta'         => [
                        'before'  => $generalLadder['before'],
                        'after'   => $generalLadder['after'],
                        'changes' => $generalLadder['changes'],
                    ],
                ]);
            }

            if ($changes !== []) {
                /* The tenant-wide settings trail still records every key the
                 * card owns (plan §15.1 names both rows). */
                AuditLog::record([
                    'action'       => 'settings.update',
                    'actor_id'     => $admin === null ? null : (int) $admin['id'],
                    'actor_role'   => $admin === null ? null : (string) $admin['role'],
                    'subject_type' => 'settings',
                    'subject_id'   => null,
                    'meta'         => [
                        'section' => $section,
                        'changes' => $changes,
                    ],
                ]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            /* A rolled-back write must not linger in the per-request cache. */
            setting_cache_clear();

            throw $e;
        }

        setting_cache_clear();

        /* Turning the shop on or off changes what the catalogue renders. */
        if (array_key_exists('shop_enabled', $changes)) {
            Live::touchShop();
        }

        if ($changes !== []) {
            /* The settings page itself and every page whose regions read a
             * setting (membership channels, order payment instructions)
             * revalidate from the shared config revision. The two payout
             * pages mix `PayoutService::settingsFingerprint()` instead, so
             * the new minimum / fees / USDT rate reaches them on their next
             * poll; this broadcast (and the client's `data-live-config`)
             * makes an open one revalidate at once. */
            Live::touchSettings();
            Live::publish(['admin.settings', Live::CONFIG]);
        }

        return [
            'errors'         => [],
            'input'          => $input,
            'changes'        => $changes,
            'ladder'         => $ladder,
            'general_ladder' => $generalLadder,
        ];
    }

    /**
     * One posted field, resolving a ladder's dotted keys
     * (`team_harvest_amounts.3`, `general_harvest_amounts.3`) through the
     * posted array form (`team_harvest_amounts[3]`), exactly like
     * ProductUnilevel's `unilevel_levels.3` / `unilevel_levels[3]` pair.
     *
     * A flat dotted key still wins when it is present, so a caller (a test
     * or a future JSON endpoint) may post either shape.
     *
     * @param array<string, mixed> $posted
     */
    private static function postedValue(array $posted, string $key): mixed
    {
        if (!str_contains($key, '.')) {
            return $posted[$key] ?? '';
        }

        [$group, $leaf] = explode('.', $key, 2);
        $bag = $posted[$group] ?? null;

        if (is_array($bag) && array_key_exists($leaf, $bag)) {
            return $bag[$leaf];
        }

        return $posted[$key] ?? '';
    }

    /** Does this field key belong to the Team Harvest ladder's amounts? */
    private static function isTeamHarvestAmountKey(string $key): bool
    {
        return str_starts_with($key, TeamHarvestLevel::AMOUNT_FIELD . '.');
    }

    /** Does this field key belong to the General Harvest ladder's amounts? */
    private static function isGeneralHarvestAmountKey(string $key): bool
    {
        return str_starts_with($key, GeneralHarvestLevel::AMOUNT_FIELD . '.');
    }

    /**
     * Did the submitted card actually carry the ten amount fields?
     *
     * The card always posts `team_harvest_amounts[1..10]`; a test harness, a
     * future JSON endpoint or an older rendered form may not. "Not posted"
     * is not "cleared": the ladder is kept, so a partial save can never
     * wipe the configuration by omission.
     *
     * @param array<string, mixed> $posted
     */
    private static function teamHarvestAmountsSubmitted(array $posted): bool
    {
        return self::ladderAmountsSubmitted($posted, TeamHarvestLevel::AMOUNT_FIELD);
    }

    /**
     * The same question for the General Harvest ladder, which is preserved
     * independently of the Team Harvest one: a card that posts neither keeps
     * both, and a card that posts one keeps the other.
     *
     * @param array<string, mixed> $posted
     */
    private static function generalHarvestAmountsSubmitted(array $posted): bool
    {
        return self::ladderAmountsSubmitted($posted, GeneralHarvestLevel::AMOUNT_FIELD);
    }

    /**
     * Is one ladder's post present — as an array (`field[1]`) or as flat
     * dotted keys (`field.1`)?
     *
     * @param array<string, mixed> $posted
     */
    private static function ladderAmountsSubmitted(array $posted, string $field): bool
    {
        if (is_array($posted[$field] ?? null)) {
            return true;
        }

        /* A dotted key counts only when it carries a value: a null is the
         * absence of the field, not a blank amount (Rule 1 / D-11). */
        foreach ($posted as $key => $value) {
            if ($value !== null && str_starts_with((string) $key, $field . '.')) {
                return true;
            }
        }

        return false;
    }

    /* ----------------------------------------------------------
     *  Field catalogue (drives save + validation)
     * ---------------------------------------------------------- */

    /**
     * The non-toggle field keys a card saves.
     *
     * @return list<string>
     */
    private static function sectionKeys(string $section): array
    {
        $keys = [];

        if ($section === 'general') {
            foreach (self::GENERAL_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }
        }

        if ($section === 'accounts') {
            foreach (self::ACCOUNT_GROUPS as $group) {
                foreach ($group['fields'] as $field) {
                    $keys[] = (string) $field['key'];
                }
            }
        }

        if ($section === 'payouts') {
            foreach (self::PAYOUT_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            foreach (self::PAYOUT_CHANNELS as $channel) {
                $keys[] = (string) $channel['fee'];

                if ((string) $channel['gas'] !== '') {
                    $keys[] = (string) $channel['gas'];
                }
            }
        }

        if ($section === 'windows') {
            foreach (array_keys(self::WINDOW_FIELDS) as $key) {
                $keys[] = (string) $key;
            }
        }

        if ($section === 'fulfilment') {
            foreach (self::SELLER_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            foreach (array_keys(self::FULFILMENT_FIELDS) as $key) {
                $keys[] = (string) $key;
            }
        }

        if ($section === 'commissions') {
            foreach (self::COMMISSION_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            /* The repeat-purchase numbers live on the same card, in their
             * own sub-block (plan §13.5). `unilevel_ledger_since` is not in
             * this list: it is never operator-editable. */
            foreach (self::REPEAT_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            /* The Royalty ladder (plan §6, 19B): four requirements,
             * three quotas and six rates. `royalty_ledger_since` is not
             * here either. */
            foreach (self::ROYALTY_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            /* The Team Harvest block (plan §6, 20B): the gate, the depth and
             * the ten amounts. `team_harvest_ledger_since` is the seed's
             * proof (D-04) and is never operator-editable, so it is not in
             * this list. */
            foreach (self::TEAM_HARVEST_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            foreach (self::TEAM_HARVEST_AMOUNTS as $field) {
                $keys[] = (string) $field['key'];
            }

            /* The General Harvest block (plan §6 Phase 21B): the payable
             * depth and the ten amounts. The switch is a toggle and the two
             * board numbers are state, never fields (§13.2, §13.5). */
            foreach (self::GENERAL_HARVEST_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            foreach (self::GENERAL_HARVEST_AMOUNTS as $field) {
                $keys[] = (string) $field['key'];
            }

            /* The Product binary block (Phase 22B, plan §14.4): the master
             * switch first, then the five knobs, then the remaining toggles.
             * `binary_ledger_since` is the reconciliation baseline and is
             * never operator-editable. */
            $keys[] = 'binary_enabled';

            foreach (self::BINARY_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }

            foreach (array_keys(self::BINARY_TOGGLES) as $key) {
                if ((string) $key !== 'binary_enabled') {
                    $keys[] = (string) $key;
                }
            }

            /* The Package binary block (Phase 23B-S2, plan §12.4): the two
             * toggles, master first. `package_binary_ledger_since` is the
             * reconciliation baseline and is never operator-editable. */
            foreach (array_keys(self::PACKAGE_BINARY_TOGGLES) as $key) {
                $keys[] = (string) $key;
            }
        }

        if ($section === 'transfers') {
            foreach (self::TRANSFER_FIELDS as $field) {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private static function sectionToggles(string $section): array
    {
        return match ($section) {
            'general'    => ['maintenance_mode'],
            'shop'       => ['shop_enabled', 'shop_cancel_enabled'],
            'payouts'    => array_map(
                static fn (array $channel): string => (string) $channel['toggle'],
                self::PAYOUT_CHANNELS
            ),
            'windows'    => ['shop_expiry_enabled'],
            'fulfilment' => ['shop_autocomplete_enabled'],
            'commissions' => array_merge(
                array_keys(self::COMMISSION_TOGGLES),
                array_keys(self::REPEAT_TOGGLES),
                array_keys(self::ROYALTY_TOGGLES),
                array_keys(self::TEAM_HARVEST_TOGGLES),
                array_keys(self::GENERAL_HARVEST_TOGGLES),
                array_keys(self::BINARY_TOGGLES),
                array_keys(self::PACKAGE_BINARY_TOGGLES)
            ),
            default      => [],
        };
    }

    /** @return array<string, string> */
    private static function sectionRules(string $section): array
    {
        $keys = array_merge(self::sectionKeys($section), self::sectionToggles($section));

        return array_intersect_key(self::fieldRules(), array_flip($keys));
    }

    /** @return array<string, string> */
    private static function sectionLabels(string $section): array
    {
        $keys = array_merge(self::sectionKeys($section), self::sectionToggles($section));

        return array_intersect_key(self::fieldLabels(), array_flip($keys));
    }

    /**
     * Rules that compare two fields in the same card.
     *
     * Validator checks one value at a time; these are the cases where a
     * pair must agree or a saved combination would make the transfer
     * service refuse an otherwise valid request, would let an incoherent
     * Royalty ladder reach the month close (plan §12.9), or would save a
     * Team Harvest ladder the plan refuses (plan §6 Rules 3–4, §12.10).
     *
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private static function sectionCrossFieldErrors(string $section, array $input): array
    {
        if ($section === 'commissions') {
            return array_merge(
                self::royaltyCrossFieldErrors($input),
                self::teamHarvestCrossFieldErrors($input),
                self::generalHarvestCrossFieldErrors($input)
            );
        }

        if ($section !== 'transfers') {
            return [];
        }

        $min    = (float) ($input['ewallet_min_transfer'] ?? 0);
        $daily  = (float) ($input['ewallet_transfer_daily_limit'] ?? 0);
        $weekly = (float) ($input['ewallet_transfer_weekly_limit'] ?? 0);
        $errors = [];

        /* 0 means "unlimited", so only two real caps can be compared. */
        if ($daily > 0 && $weekly > 0 && $weekly < $daily) {
            $errors['ewallet_transfer_weekly_limit'] = 'The weekly limit cannot be below the daily limit.';
        }

        if ($daily > 0 && $min > $daily) {
            $errors['ewallet_min_transfer'] = 'The minimum transfer cannot exceed the daily limit.';
        }

        return $errors;
    }

    /* ----------------------------------------------------------
     *  Royalty ladder validation (plan §12.9)
     * ---------------------------------------------------------- */

    /**
     * The six rates, three quotas and four thresholds, parsed from the
     * submitted card, or null when any of them is not syntactically
     * valid (the per-field rules report those).
     *
     * @param array<string, string> $input
     * @return array{thresholds: array<string, int>, quotas: array<string, float>, rates: array<string, array{group: float, personal: float}>}|null
     */
    private static function royaltyMetrics(array $input): ?array
    {
        $thresholds = [];

        foreach (RoyaltyRank::THRESHOLD_KEYS as $rank => $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if (preg_match('/^\d+$/', $value) !== 1) {
                return null;
            }

            $thresholds[$rank] = (int) $value;
        }

        $quotas = [];

        foreach (RoyaltyRank::PAYING_SLUGS as $slug) {
            $value = trim((string) ($input['royalty_' . $slug . '_quota'] ?? ''));

            if (preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
                return null;
            }

            $quotas[$slug] = (float) $value;
        }

        $rates = [];

        foreach (RoyaltyRank::PAYING_SLUGS as $slug) {
            $group    = trim((string) ($input['royalty_' . $slug . '_group_pct'] ?? ''));
            $personal = trim((string) ($input['royalty_' . $slug . '_personal_pct'] ?? ''));

            if (preg_match('/^\d+(\.\d{1,3})?$/', $group) !== 1
                || preg_match('/^\d+(\.\d{1,3})?$/', $personal) !== 1) {
                return null;
            }

            $rates[$slug] = ['group' => (float) $group, 'personal' => (float) $personal];
        }

        return ['thresholds' => $thresholds, 'quotas' => $quotas, 'rates' => $rates];
    }

    /**
     * Cross-field Royalty rules: quotas and rates never fall as the
     * rank rises, group + personal never exceeds 100%, and an enabled
     * ladder must pay something (plan §12.9).
     *
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private static function royaltyCrossFieldErrors(array $input): array
    {
        $metrics = self::royaltyMetrics($input);

        if ($metrics === null) {
            return [];   // a per-field error already explains the value
        }

        $quotas = $metrics['quotas'];
        $rates  = $metrics['rates'];
        $errors = [];

        if ($quotas['manager'] < $quotas['supervisor']) {
            $errors['royalty_manager_quota'] = 'The Manager quota cannot be below the Supervisor quota.';
        }

        if ($quotas['director'] < $quotas['manager']) {
            $errors['royalty_director_quota'] = 'The Director quota cannot be below the Manager quota.';
        }

        if ($rates['manager']['group'] < $rates['supervisor']['group']) {
            $errors['royalty_manager_group_pct'] = 'The Manager group rate cannot be below the Supervisor group rate.';
        }

        if ($rates['director']['group'] < $rates['manager']['group']) {
            $errors['royalty_director_group_pct'] = 'The Director group rate cannot be below the Manager group rate.';
        }

        if ($rates['manager']['personal'] < $rates['supervisor']['personal']) {
            $errors['royalty_manager_personal_pct'] = 'The Manager personal rate cannot be below the Supervisor personal rate.';
        }

        if ($rates['director']['personal'] < $rates['manager']['personal']) {
            $errors['royalty_director_personal_pct'] = 'The Director personal rate cannot be below the Manager personal rate.';
        }

        /* Integer per-mille maths keeps the 3-decimal contract exact:
         * 33.333 + 66.667 is 100.000, not 100.00000000000001. */
        foreach ($rates as $slug => $rate) {
            $total = (int) round($rate['group'] * 1000) + (int) round($rate['personal'] * 1000);

            if ($total > 100000) {
                $errors['royalty_' . $slug . '_personal_pct'] = sprintf(
                    '%s group + personal cannot exceed 100%% (currently %s%%).',
                    ucfirst($slug),
                    rtrim(rtrim(number_format($total / 1000, 3, '.', ''), '0'), '.')
                );
            }
        }

        $anyRate = false;

        foreach ($rates as $rate) {
            if ($rate['group'] > 0 || $rate['personal'] > 0) {
                $anyRate = true;
                break;
            }
        }

        if ((string) ($input['royalty_enabled'] ?? '0') === '1' && !$anyRate) {
            $errors['royalty_enabled'] = 'This ladder would pay nothing. Give at least one rate a value above 0, or turn Enable the Royalty bonus off.';
        }

        return $errors;
    }

    /* ----------------------------------------------------------
     *  Team Harvest configuration (plan §6, §12.6, §12.10)
     * ---------------------------------------------------------- */

    /**
     * The ten amounts from the submitted card, keyed 1 … 10 as typed.
     *
     * @param array<string, string> $input
     * @return array<int, string>
     */
    private static function teamHarvestAmounts(array $input): array
    {
        $amounts = [];

        for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
            $amounts[$level] = (string) ($input[TeamHarvestLevel::AMOUNT_FIELD . '.' . $level] ?? '');
        }

        return $amounts;
    }

    /**
     * The ten amounts as pesos, for the model's write. The card has already
     * validated them, so a blank is 0 and a missing key cannot appear.
     *
     * @param array<string, string> $input
     * @return array<int, float>
     */
    private static function teamHarvestFloats(array $input): array
    {
        $amounts = [];

        foreach (self::teamHarvestAmounts($input) as $level => $value) {
            $amounts[$level] = round((float) $value, 2);
        }

        return $amounts;
    }

    /**
     * The Team Harvest rules that compare fields — the two the plan puts on
     * the model, delegated so the card and `TeamHarvestLevel::validate()`
     * can never disagree: the ₱25,000,000.00 full-ladder ceiling (Rule 3)
     * and the refusal to enable an all-zero ladder (Rule 4).
     *
     * The per-level errors are also returned; they are byte-identical to the
     * card's own `AMOUNT_RULE` messages because both read that constant.
     *
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private static function teamHarvestCrossFieldErrors(array $input): array
    {
        return TeamHarvestLevel::validate(
            self::teamHarvestAmounts($input),
            (string) ($input['team_harvest_enabled'] ?? '0') === '1'
        );
    }

    /**
     * The stored Team Harvest configuration, as the audit's `before` and the
     * card's status read it: the three knobs and the ten amounts as money
     * strings. JSON-safe scalars only, so the audit row is exact.
     *
     * @return array{enabled: bool, qualify_directs: int, max_levels: int, levels: array<int, string>}
     */
    private static function teamHarvestStored(): array
    {
        $levels = [];

        foreach (TeamHarvestLevel::amounts() as $level => $amount) {
            $levels[$level] = number_format($amount, 2, '.', '');
        }

        return [
            'enabled'         => TeamHarvestLevel::enabled(),
            'qualify_directs' => TeamHarvestLevel::qualifyDirects(),
            'max_levels'      => TeamHarvestLevel::maxLevels(),
            'levels'          => $levels,
        ];
    }

    /**
     * The configuration the submitted card would store.
     *
     * @param array<string, string> $input
     * @return array{enabled: bool, qualify_directs: int, max_levels: int, levels: array<int, string>}
     */
    private static function teamHarvestPosted(array $input): array
    {
        $levels = [];

        foreach (self::teamHarvestFloats($input) as $level => $amount) {
            $levels[$level] = number_format($amount, 2, '.', '');
        }

        return [
            'enabled'         => (string) ($input['team_harvest_enabled'] ?? '0') === '1',
            'qualify_directs' => (int) ($input['team_harvest_qualify_directs'] ?? TeamHarvestLevel::GATE_DEFAULT),
            'max_levels'      => (int) ($input['team_harvest_max_levels'] ?? TeamHarvestLevel::DEPTH_DEFAULT),
            'levels'          => $levels,
        ];
    }

    /**
     * What moved between two Team Harvest configurations, keyed `enabled`,
     * `qualify_directs`, `max_levels` and `levels.N` — the payload the
     * audit row carries (§15.4). An empty array means the whole card is
     * untouched and nothing is written or audited.
     *
     * @param array{enabled: bool, qualify_directs: int, max_levels: int, levels: array<int, string>} $before
     * @param array{enabled: bool, qualify_directs: int, max_levels: int, levels: array<int, string>} $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function teamHarvestDiff(array $before, array $after): array
    {
        $changes = [];

        foreach (['enabled', 'qualify_directs', 'max_levels'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $after[$key] ?? null];
            }
        }

        for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
            $from = (string) ($before['levels'][$level] ?? '0.00');
            $to   = (string) ($after['levels'][$level] ?? '0.00');

            if ($from !== $to) {
                $changes['levels.' . $level] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * The generated ladder table (plan §6 Rule 8): every cell comes from
     * `TeamHarvestLevel`, never from the view. Rows 1 … 10 carry their
     * slots, their threshold, the stored amount, the cumulative sum and
     * whether the configured depth pays them.
     *
     * @return array{
     *     rows: array<int, array{level:int, slots:int, threshold:int, amount:float, cumulative:float, paying:bool, has_amount:bool}>,
     *     total: float,
     *     cumulative: array<int, float>,
     *     max_levels: int,
     *     max_threshold: int,
     *     top_amount: float,
     *     enabled: bool,
     *     gate: int
     * }
     */
    private function teamHarvestLadder(): array
    {
        $amounts    = TeamHarvestLevel::amounts();
        $cumulative = TeamHarvestLevel::cumulative($amounts);
        $maxLevels  = TeamHarvestLevel::maxLevels();
        $rows       = [];

        for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
            $rows[$level] = [
                'level'      => $level,
                'slots'      => TeamHarvestLevel::slots($level),
                'threshold'  => TeamHarvestLevel::threshold($level),
                'amount'     => $amounts[$level],
                'cumulative' => $cumulative[$level],
                'paying'     => $level <= $maxLevels,
                'has_amount' => $amounts[$level] > 0,
            ];
        }

        return [
            'rows'          => $rows,
            'total'         => TeamHarvestLevel::total($amounts),
            'cumulative'    => $cumulative,
            'max_levels'    => $maxLevels,
            'max_threshold' => TeamHarvestLevel::threshold(TeamHarvestLevel::LEVEL_MAX),
            'top_amount'    => $amounts[TeamHarvestLevel::LEVEL_MAX],
            'enabled'       => TeamHarvestLevel::enabled(),
            'gate'          => TeamHarvestLevel::qualifyDirects(),
        ];
    }

    /**
     * The four-state status strip of plan §6 Rules 6–7.
     *
     * States, in order: `unseeded` → `seeded_off` → `seeded_on` → `backlog`.
     * "Seeded" is `team_harvest_ledger_since` being non-empty (D-04); the
     * backlog is the payable-but-unwritten level count and amount across the
     * queue, which is what the operator is about to owe.
     *
     * This is a **read**: 20B assigns no position and writes no award. The
     * network-wide `openLiability()` of §14.12 lands with the money engine
     * (20D), which owns the same rule for the jobs and the admin queue card;
     * until it ships this card reads the queue directly so it can tell the
     * operator the truth before any money exists.
     *
     * @return array{
     *     state: string,
     *     seeded: bool,
     *     seeded_at: string,
     *     queue: int,
     *     enabled: bool,
     *     max_levels: int,
     *     pending_levels: int,
     *     open_liability: float
     * }
     */
    private function teamHarvestStatus(): array
    {
        $seededAt = trim((string) setting('team_harvest_ledger_since', ''));
        $seeded   = $seededAt !== '';
        $enabled  = TeamHarvestLevel::enabled();
        $maxLevels = TeamHarvestLevel::maxLevels();
        $amounts  = TeamHarvestLevel::amounts();

        $pdo   = db();
        $queue = (int) $pdo->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn();

        $pending  = 0;
        $liability = 0.0;

        if ($seeded && $enabled && $queue > 0) {
            /* Every queued member's teammates and baseline, then every award
             * anchor: the backlog is a level whose threshold is met since the
             * baseline and whose (member, level) has no row yet (plan §12.7). */
            $members = $pdo->query(
                'SELECT user_id, teammates, baseline_n FROM harvest_queue'
            )->fetchAll();

            $awarded = [];

            foreach ($pdo->query('SELECT user_id, level FROM team_harvest_awards')->fetchAll() as $award) {
                $awarded[(int) $award['user_id']][(int) $award['level']] = true;
            }

            foreach ($members as $member) {
                $userId   = (int) $member['user_id'];
                $teammates = (int) $member['teammates'];
                $baseline = (int) $member['baseline_n'];

                for ($level = 1; $level <= $maxLevels; $level++) {
                    /* Thresholds ascend: nothing deeper can be complete. */
                    if ($teammates < TeamHarvestLevel::threshold($level)) {
                        break;
                    }

                    /* Complete before go-live: recorded, never paid (D-07). */
                    if (TeamHarvestLevel::threshold($level) <= $baseline) {
                        continue;
                    }

                    if (isset($awarded[$userId][$level])) {
                        continue;
                    }

                    if ($amounts[$level] <= 0) {
                        /* A zero level is a switch, not a backlog (§12.6 Rule 2). */
                        continue;
                    }

                    $pending++;
                    $liability += $amounts[$level];
                }
            }
        }

        $state = match (true) {
            !$seeded       => 'unseeded',
            !$enabled      => 'seeded_off',
            $pending > 0   => 'backlog',
            default        => 'seeded_on',
        };

        return [
            'state'          => $state,
            'seeded'         => $seeded,
            'seeded_at'      => $seededAt,
            'queue'          => $queue,
            'enabled'        => $enabled,
            'max_levels'     => $maxLevels,
            'pending_levels' => $pending,
            'open_liability' => round($liability, 2),
        ];
    }

    /* ----------------------------------------------------------
     *  General Harvest configuration (plan §6 Phase 21B, §12.4,
     *  §12.9, D-17/D-18/D-23)
     * ---------------------------------------------------------- */

    /**
     * The ten amounts from the submitted card, keyed 1 … 10 as typed.
     *
     * @param array<string, string> $input
     * @return array<int, string>
     */
    private static function generalHarvestAmounts(array $input): array
    {
        $amounts = [];

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $amounts[$level] = (string) ($input[GeneralHarvestLevel::AMOUNT_FIELD . '.' . $level] ?? '');
        }

        return $amounts;
    }

    /**
     * The ten amounts as pesos, for the model's write. The card has already
     * validated them, so a blank is 0 and a missing key cannot appear.
     *
     * @param array<string, string> $input
     * @return array<int, float>
     */
    private static function generalHarvestFloats(array $input): array
    {
        $amounts = [];

        foreach (self::generalHarvestAmounts($input) as $level => $value) {
            $amounts[$level] = round((float) $value, 2);
        }

        return $amounts;
    }

    /**
     * The General Harvest rules that compare fields — all of them on the
     * model, delegated so the card and `GeneralHarvestLevel::validate()`
     * can never disagree: the ₱25,000,000.00 full-ladder ceiling (Rule 3),
     * the "a payable level must be positive" rule on the switch and the
     * depth (Rule 4, D-17), and the frozen ladder after the arm (Rule 14,
     * D-23).
     *
     * The per-level errors are also returned; they are byte-identical to the
     * card's own `AMOUNT_RULE` messages because both read that constant.
     *
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private static function generalHarvestCrossFieldErrors(array $input): array
    {
        return GeneralHarvestLevel::validate(
            self::generalHarvestAmounts($input),
            (int) ($input[GeneralHarvestLevel::DEPTH_KEY] ?? GeneralHarvestLevel::DEPTH_DEFAULT),
            (string) ($input[GeneralHarvestLevel::ENABLED_KEY] ?? '0') === '1'
        );
    }

    /**
     * The stored General Harvest configuration, as the audit's `before`
     * reads it: the switch, the depth and the ten amounts as money strings.
     * JSON-safe scalars only, so the audit row is exact.
     *
     * @return array{enabled: bool, max_levels: int, levels: array<int, string>}
     */
    private static function generalHarvestStored(): array
    {
        $levels = [];

        foreach (GeneralHarvestLevel::amounts() as $level => $amount) {
            $levels[$level] = number_format($amount, 2, '.', '');
        }

        return [
            'enabled'    => GeneralHarvestLevel::enabled(),
            'max_levels' => GeneralHarvestLevel::maxLevels(),
            'levels'     => $levels,
        ];
    }

    /**
     * The configuration the submitted card would store.
     *
     * @param array<string, string> $input
     * @return array{enabled: bool, max_levels: int, levels: array<int, string>}
     */
    private static function generalHarvestPosted(array $input): array
    {
        $levels = [];

        foreach (self::generalHarvestFloats($input) as $level => $amount) {
            $levels[$level] = number_format($amount, 2, '.', '');
        }

        return [
            'enabled'    => (string) ($input[GeneralHarvestLevel::ENABLED_KEY] ?? '0') === '1',
            'max_levels' => (int) ($input[GeneralHarvestLevel::DEPTH_KEY] ?? GeneralHarvestLevel::DEPTH_DEFAULT),
            'levels'     => $levels,
        ];
    }

    /**
     * What moved between two General Harvest configurations, keyed
     * `enabled`, `max_levels` and `levels.N` — the payload the audit row
     * carries (§15.4). An empty array means the whole sub-block is untouched
     * and nothing is written or audited.
     *
     * @param array{enabled: bool, max_levels: int, levels: array<int, string>} $before
     * @param array{enabled: bool, max_levels: int, levels: array<int, string>} $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function generalHarvestDiff(array $before, array $after): array
    {
        $changes = [];

        foreach (['enabled', 'max_levels'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $after[$key] ?? null];
            }
        }

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $from = (string) ($before['levels'][$level] ?? '0.00');
            $to   = (string) ($after['levels'][$level] ?? '0.00');

            if ($from !== $to) {
                $changes['levels.' . $level] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * The generated ladder table (plan §6 Rules 7–8): every cell comes from
     * `GeneralHarvestLevel`, never from the view. Rows 1 … 10 carry their
     * slots, the apex's threshold, the stored amount, the cumulative sum and
     * whether the configured depth pays them.
     *
     * `apex_n`, `node2_n` and `node3_n` are the Rule 10 sentence's three
     * numbers; `depth_total` is what the ladder pays at the configured depth.
     *
     * @return array{
     *     rows: array<int, array{level:int, slots:int, apex:int, amount:float, cumulative:float, paying:bool, has_amount:bool}>,
     *     total: float,
     *     cumulative: array<int, float>,
     *     max_levels: int,
     *     apex_n: int,
     *     node2_n: int,
     *     node3_n: int,
     *     depth_total: float,
     *     top_amount: float,
     *     enabled: bool,
     *     reference_total: float
     * }
     */
    private function generalHarvestLadder(): array
    {
        $amounts    = GeneralHarvestLevel::amounts();
        $cumulative = GeneralHarvestLevel::cumulative($amounts);
        $maxLevels  = GeneralHarvestLevel::maxLevels();
        $rows       = [];
        $depthTotal = 0.0;

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $rows[$level] = [
                'level'      => $level,
                'slots'      => GeneralHarvestLevel::slots($level),
                'apex'       => GeneralHarvestLevel::apexThreshold($level),
                'amount'     => $amounts[$level],
                'cumulative' => $cumulative[$level],
                'paying'     => $level <= $maxLevels,
                'has_amount' => $amounts[$level] > 0,
            ];

            if ($level <= $maxLevels) {
                $depthTotal += $amounts[$level];
            }
        }

        return [
            'rows'            => $rows,
            'total'           => GeneralHarvestLevel::total($amounts),
            'cumulative'      => $cumulative,
            'max_levels'      => $maxLevels,
            'apex_n'          => GeneralHarvestLevel::apexThreshold(GeneralHarvestLevel::LEVEL_MAX),
            'node2_n'         => GeneralHarvestLevel::threshold(1, GeneralHarvestLevel::LEVEL_MAX),
            'node3_n'         => GeneralHarvestLevel::threshold(2, GeneralHarvestLevel::LEVEL_MAX),
            'depth_total'     => round($depthTotal, 2),
            'top_amount'      => $amounts[GeneralHarvestLevel::LEVEL_MAX],
            'enabled'         => GeneralHarvestLevel::enabled(),
            'reference_total' => GeneralHarvestLevel::total(GeneralHarvestLevel::referencePreset()),
        ];
    }

    /**
     * The generated position table (plan §6 Rule 8): positions #1–#6 ×
     * levels 1–4, each cell `3^L·(p−1) + (3^(L+1) − 1)/2` straight from
     * `GeneralHarvestLevel::threshold()`.
     *
     * The reference's §4.5 table is the first four rows and four columns of
     * this one, which is why the card prints six.
     *
     * @return array{levels: list<int>, rows: list<array{position:int, seq:int, cells: array<int, int>}>}
     */
    private function generalHarvestPositions(): array
    {
        $levels = [1, 2, 3, 4];
        $rows   = [];

        for ($position = 1; $position <= 6; $position++) {
            $seq   = $position - 1;
            $cells = [];

            foreach ($levels as $level) {
                $cells[$level] = GeneralHarvestLevel::threshold($seq, $level);
            }

            $rows[] = ['position' => $position, 'seq' => $seq, 'cells' => $cells];
        }

        return ['levels' => $levels, 'rows' => $rows];
    }

    /**
     * The board's condition for the card (plan §6 Rule 6, Rule 9, Rule 11):
     * the four-state strip, the queue length, the backlog and the
     * calculator's defaults.
     *
     * This is a **read**. 21B arms nothing and writes no award; the numbers
     * exist so the operator can see what the plan would owe before any
     * money exists, and the card says so in one line.
     *
     * @return array<string, mixed>
     */
    private function generalHarvestBoard(): array
    {
        $status  = GeneralHarvestBoard::status();
        $queue   = GeneralHarvestBoard::queueN();
        $amounts = $status['amounts'];

        return [
            'status'     => $status,
            'queue'      => $queue,
            'backlog'    => GeneralHarvestBoard::backlog(),
            'calculator' => [
                'n'      => $queue['n'],
                'total'  => GeneralHarvestLevel::boardTotal($queue['n'], $amounts),
                'awards' => GeneralHarvestLevel::awardCount($queue['n']),
            ],
        ];
    }

    /** @return array<string, string> */
    private static function fieldRules(): array
    {
        $rules = [
            'shop_enabled'              => 'required|in:0,1',
            'shop_cancel_enabled'       => 'required|in:0,1',
            'shop_expiry_enabled'       => 'required|in:0,1',
            'shop_autocomplete_enabled' => 'required|in:0,1',
            'maintenance_mode'          => 'required|in:0,1',
        ];

        foreach (self::GENERAL_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (self::COMMISSION_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (array_keys(self::COMMISSION_TOGGLES) as $key) {
            $rules[(string) $key] = 'required|in:0,1';
        }

        /* The repeat-purchase block: three numbers and four switches
         * (plan §9, §13.5). The depth is capped at the ladder's ten
         * levels, the threshold keeps the money precision of its column
         * and the hold is a whole number of days in the award's range. */
        foreach (self::REPEAT_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (array_keys(self::REPEAT_TOGGLES) as $key) {
            $rules[(string) $key] = 'required|in:0,1';
        }

        /* The Royalty ladder: the ranges and precision of §12.9 — an
         * integer threshold, a centavo quota and a three-decimal rate. */
        foreach (self::ROYALTY_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (array_keys(self::ROYALTY_TOGGLES) as $key) {
            $rules[(string) $key] = 'required|in:0,1';
        }

        /* The Team Harvest block: the gate and the depth are small integers,
         * the ten amounts are money with the column's two decimals
         * (plan §6 Rules 2 and 5). The rule strings come from the catalogue,
         * so the card's messages and the model's agree. */
        foreach (self::TEAM_HARVEST_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (self::TEAM_HARVEST_AMOUNTS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (array_keys(self::TEAM_HARVEST_TOGGLES) as $key) {
            $rules[(string) $key] = 'required|in:0,1';
        }

        /* The General Harvest block: the depth is a small integer and the ten
         * amounts are money with the column's two decimals (plan §6 Rules 2
         * and 5, §12.9). The rule strings come from the catalogue, so the
         * card's messages and the model's agree. */
        foreach (self::GENERAL_HARVEST_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (self::GENERAL_HARVEST_AMOUNTS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (array_keys(self::GENERAL_HARVEST_TOGGLES) as $key) {
            $rules[(string) $key] = 'required|in:0,1';
        }

        /* The Product binary block (Phase 22B, plan §14.4): the five numbers
         * keep their declared precision and range; the four switches are
         * simple on/off. `binary_ledger_since` is never validated. */
        foreach (self::BINARY_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (array_keys(self::BINARY_TOGGLES) as $key) {
            $rules[(string) $key] = 'required|in:0,1';
        }

        /* The Package binary toggles (Phase 23B-S2): simple on/off. */
        foreach (array_keys(self::PACKAGE_BINARY_TOGGLES) as $key) {
            $rules[(string) $key] = 'required|in:0,1';
        }

        foreach (self::TRANSFER_FIELDS as $field) {
            $rules[(string) $field['key']] = (string) $field['rule'];
        }

        foreach (self::ACCOUNT_GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $rules[(string) $field['key']] = 'nullable|string|max:120';
            }
        }

        /* The withdrawal rules. Percentages stop at 100 so a saved value
         * can never be silently clamped by PayoutService, and the rate and
         * gas fees keep the precision their DECIMAL columns store. */
        $rules['min_payout'] = 'required|numeric|gte:0|lte:' . PayoutService::MAX_AMOUNT . '|decimal:2';
        $rules['usdt_rate']  = 'required|numeric|gte:0|lte:' . PayoutService::MAX_RATE . '|decimal:4';

        foreach (self::PAYOUT_CHANNELS as $channel) {
            $rules[(string) $channel['toggle']] = 'required|in:0,1';
            $rules[(string) $channel['fee']]    = 'required|numeric|gte:0|lte:100|decimal:2';

            if ((string) $channel['gas'] !== '') {
                $rules[(string) $channel['gas']] = 'required|numeric|gte:0|lte:' . PayoutService::MAX_GAS . '|decimal:4';
            }
        }

        foreach (self::WINDOW_FIELDS as $key => $field) {
            $rules[(string) $key] = (string) $field['rule'];
        }

        foreach (self::SELLER_FIELDS as $field) {
            $max = (int) ($field['max'] ?? 160);
            $rules[(string) $field['key']] = (string) $field['key'] === 'seller_name'
                ? 'required|string|max:' . $max
                : 'nullable|string|max:' . $max;
        }

        foreach (self::FULFILMENT_FIELDS as $key => $field) {
            $rules[(string) $key] = (string) $field['rule'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private static function fieldLabels(): array
    {
        $labels = [
            'shop_enabled'              => 'Shop',
            'shop_cancel_enabled'       => 'Enable Cancel of Order',
            'shop_expiry_enabled'       => 'Automatic expiry',
            'shop_autocomplete_enabled' => 'Automatic completion',
            'maintenance_mode'          => 'Maintenance mode',
        ];

        foreach (self::GENERAL_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::COMMISSION_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::COMMISSION_TOGGLES as $key => $toggle) {
            $labels[(string) $key] = (string) $toggle['label'];
        }

        foreach (self::REPEAT_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::REPEAT_TOGGLES as $key => $toggle) {
            $labels[(string) $key] = (string) $toggle['label'];
        }

        foreach (self::ROYALTY_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::ROYALTY_TOGGLES as $key => $toggle) {
            $labels[(string) $key] = (string) $toggle['label'];
        }

        foreach (self::TEAM_HARVEST_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::TEAM_HARVEST_AMOUNTS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::TEAM_HARVEST_TOGGLES as $key => $toggle) {
            $labels[(string) $key] = (string) $toggle['label'];
        }

        foreach (self::GENERAL_HARVEST_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::GENERAL_HARVEST_AMOUNTS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::GENERAL_HARVEST_TOGGLES as $key => $toggle) {
            $labels[(string) $key] = (string) $toggle['label'];
        }

        foreach (self::BINARY_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::BINARY_TOGGLES as $key => $toggle) {
            $labels[(string) $key] = (string) $toggle['label'];
        }

        /* The Package binary toggles (Phase 23B-S2). */
        foreach (self::PACKAGE_BINARY_TOGGLES as $key => $toggle) {
            $labels[(string) $key] = (string) $toggle['label'];
        }

        foreach (self::TRANSFER_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::ACCOUNT_GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $labels[(string) $field['key']] = (string) $group['title'] . ' ' . $field['label'];
            }
        }

        foreach (self::PAYOUT_FIELDS as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }

        foreach (self::PAYOUT_CHANNELS as $channel) {
            $labels[(string) $channel['toggle']] = (string) $channel['title'] . ' payouts';
            $labels[(string) $channel['fee']]    = (string) $channel['title'] . ' service fee (%)';

            if ((string) $channel['gas'] !== '') {
                $labels[(string) $channel['gas']] = (string) $channel['title'] . ' network fee (USDT)';
            }
        }

        foreach (self::WINDOW_FIELDS as $key => $field) {
            $labels[(string) $key] = (string) $field['label'];
        }

        foreach (self::SELLER_FIELDS as $field) {
            $labels[(string) $field['key']] = 'Invoice ' . (string) $field['label'];
        }

        foreach (self::FULFILMENT_FIELDS as $key => $field) {
            $labels[(string) $key] = (string) $field['label'];
        }

        return $labels;
    }

    /**
     * Does at least one receiving account look filled in?
     *
     * The seeded placeholder numbers all contain "0000", so an
     * unconfigured install is recognisable without a separate flag: the
     * page warns that manual checkout cannot be paid yet until an admin
     * replaces them with the real accounts.
     */
    private function manualMethodsConfigured(): bool
    {
        foreach (self::ACCOUNT_GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $value = trim((string) setting((string) $field['key'], ''));

                if ($value !== '' && !str_contains($value, '0000')) {
                    return true;
                }
            }
        }

        return false;
    }
}
