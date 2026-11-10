<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminSettingsController — payment accounts, payout methods,
 *  payment windows, fulfilment and invoices (plan §7 Phases 7–8;
 *  expanded into the full settings page in Phase 15; reference
 *  AdminController::settings/saveSettings)
 *
 *  Routes:
 *      GET  /admin/settings   the surfaces used so far
 *      POST /admin/settings   validated save + audit entry
 *
 *  Every write is audited (`settings.update`, with the before/after
 *  value per key) because these values decide where buyers send
 *  money, how long they have to send it, and what the printed
 *  invoice says.
 * ============================================================== */

final class AdminSettingsController
{
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
     * The independently saveable cards on /admin/settings. Every card
     * has its own form and its own **Save settings** button, and only
     * the submitted section is validated, written and audited — so a
     * save in one card can never overwrite another card's values.
     */
    private const SECTIONS = [
        'shop'       => 'Shop',
        'accounts'   => 'Receiving accounts',
        'payouts'    => 'Payout methods',
        'windows'    => 'Payment windows',
        'fulfilment' => 'Fulfilment & invoices',
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
            'page_js'     => ['assets/js/admin-live.js'],
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
            'account_groups'    => self::ACCOUNT_GROUPS,
            'payout_fields'     => self::PAYOUT_FIELDS,
            'payout_channels'   => self::PAYOUT_CHANNELS,
            'window_fields'     => self::WINDOW_FIELDS,
            'seller_fields'     => self::SELLER_FIELDS,
            'fulfilment_fields' => self::FULFILMENT_FIELDS,
            'shop_enabled'      => setting_bool('shop_enabled', true),
            'expiry_enabled'    => setting_bool('shop_expiry_enabled', true),
            'autocomplete_enabled' => FulfilmentService::autoCompleteEnabled(),
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

        $back  = 'admin/settings#settings-' . $section;
        $input = [];

        foreach (self::sectionKeys($section) as $key) {
            $input[$key] = Request::string($key);
        }

        /* An unticked checkbox posts nothing at all. Normalise only this
         * card's switches; other cards' switches are never in $input. */
        foreach (self::sectionToggles($section) as $key) {
            $input[$key] = Request::post($key) === '1' ? '1' : '0';
        }

        $errors = Validator::check($input, self::sectionRules($section), self::sectionLabels($section));

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            flash_set('danger', 'Please fix the highlighted settings.');
            redirect($back);
        }

        $changes = [];

        foreach ($input as $key => $value) {
            $before = (string) setting($key, '');
            $value  = trim((string) $value);

            if ($before === $value) {
                continue;
            }

            setting_set($key, $value);
            $changes[$key] = ['from' => $before, 'to' => $value];
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

            $admin = auth_user();

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

            flash_set('success', sprintf(
                '%s: %d setting%s saved.',
                self::SECTIONS[$section],
                count($changes),
                count($changes) === 1 ? '' : 's'
            ));
        } else {
            flash_set('info', 'No changes to save.');
        }

        /* The submitted card was valid (or unchanged), so drop any input
         * kept from an earlier failed save. Without this the next render —
         * including the live feed's card replacements — would keep showing
         * the stale attempt instead of the values just stored. */
        old_clear();

        redirect($back);
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

        return $keys;
    }

    /** @return list<string> */
    private static function sectionToggles(string $section): array
    {
        return match ($section) {
            'shop'       => ['shop_enabled'],
            'payouts'    => array_map(
                static fn (array $channel): string => (string) $channel['toggle'],
                self::PAYOUT_CHANNELS
            ),
            'windows'    => ['shop_expiry_enabled'],
            'fulfilment' => ['shop_autocomplete_enabled'],
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

    /** @return array<string, string> */
    private static function fieldRules(): array
    {
        $rules = [
            'shop_enabled'              => 'required|in:0,1',
            'shop_expiry_enabled'       => 'required|in:0,1',
            'shop_autocomplete_enabled' => 'required|in:0,1',
        ];

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
            'shop_expiry_enabled'       => 'Automatic expiry',
            'shop_autocomplete_enabled' => 'Automatic completion',
        ];

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
