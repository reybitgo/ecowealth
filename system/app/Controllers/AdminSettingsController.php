<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminSettingsController — payment accounts and payment windows
 *  (plan §7 Phase 7; expanded into the full settings page in
 *  Phase 15; reference AdminController::settings/saveSettings)
 *
 *  Routes:
 *      GET  /admin/settings   the payment surface used so far
 *      POST /admin/settings   validated save + audit entry
 *
 *  Every write is audited (`settings.update`, with the before/after
 *  value per key) because these values decide where buyers send
 *  money and how long they have to send it.
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

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        View::page('admin', 'admin/settings/index', [
            'page_title'      => 'Settings',
            'current_nav'     => 'admin.settings',
            'page_css'        => ['assets/css/orders.css'],
            'settings'        => setting_all(),
            'account_groups'  => self::ACCOUNT_GROUPS,
            'window_fields'   => self::WINDOW_FIELDS,
            'shop_enabled'    => setting_bool('shop_enabled', true),
            'manual_open'     => $this->manualMethodsConfigured(),
        ]);
    }

    /** @param array<string, string> $params */
    public function save(array $params = []): void
    {
        $input = [];

        foreach (self::fieldKeys() as $key) {
            $input[$key] = Request::string($key);
        }

        /* An unticked checkbox posts nothing at all. */
        $input['shop_enabled'] = Request::post('shop_enabled') === '1' ? '1' : '0';

        $errors = Validator::check($input, self::fieldRules(), self::fieldLabels());

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            flash_set('danger', 'Please fix the highlighted settings.');
            redirect('admin/settings');
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

        if ($changes !== []) {
            $admin = auth_user();

            AuditLog::record([
                'action'       => 'settings.update',
                'actor_id'     => $admin === null ? null : (int) $admin['id'],
                'actor_role'   => $admin === null ? null : (string) $admin['role'],
                'subject_type' => 'settings',
                'subject_id'   => null,
                'meta'         => ['changes' => $changes],
            ]);

            flash_set('success', sprintf(
                '%d setting%s saved.',
                count($changes),
                count($changes) === 1 ? '' : 's'
            ));
        } else {
            flash_set('info', 'No changes to save.');
        }

        redirect('admin/settings');
    }

    /* ----------------------------------------------------------
     *  Field catalogue (drives save + validation)
     * ---------------------------------------------------------- */

    /** @return list<string> */
    private static function fieldKeys(): array
    {
        $keys = [];

        foreach (self::ACCOUNT_GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $keys[] = (string) $field['key'];
            }
        }

        foreach (array_keys(self::WINDOW_FIELDS) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /** @return array<string, string> */
    private static function fieldRules(): array
    {
        $rules = [
            'shop_enabled' => 'required|in:0,1',
        ];

        foreach (self::ACCOUNT_GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $rules[(string) $field['key']] = 'nullable|string|max:120';
            }
        }

        foreach (self::WINDOW_FIELDS as $key => $field) {
            $rules[(string) $key] = (string) $field['rule'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private static function fieldLabels(): array
    {
        $labels = [
            'shop_enabled' => 'Shop',
        ];

        foreach (self::ACCOUNT_GROUPS as $group) {
            foreach ($group['fields'] as $field) {
                $labels[(string) $field['key']] = (string) $group['title'] . ' ' . $field['label'];
            }
        }

        foreach (self::WINDOW_FIELDS as $key => $field) {
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
