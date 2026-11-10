<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminConfirmTest — the back office's confirmation contract
 *
 *  Every admin decision that can move money or state asks first, and
 *  the rejection paths ask for their reason inside that modal rather
 *  than in a row input. These cases pin the shared handler (including
 *  the reasoned prompt and its live-form hand-off), the pages that load
 *  it, and the action views that carry the copy.
 * ============================================================== */

return [
    'the confirmation handler supports a required reason prompt' => function (): void {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app-confirm.js');

        assert_contains("document.addEventListener('submit'", $js);
        assert_contains("form.matches('form[data-confirm]')", $js);
        assert_contains('data-confirm-approved', $js);
        assert_contains('form.requestSubmit()', $js, 'live forms see the approved event');
        assert_contains("getAttribute('data-confirm-prompt')", $js);
        assert_contains("options.input = 'textarea'", $js);
        assert_contains('options.inputValidator', $js);
        assert_contains('data-confirm-prompt-max', $js);
        assert_contains('setFieldValue(form, promptName', $js, 'the reason is written to a hidden field');
        assert_contains('window.Swal.fire', $js);
        assert_contains('window.prompt', $js, 'the native fallback still asks for the reason');
        assert_contains('window.confirm', $js);
        assert_contains('focusCancel', $js);
    },

    'a live admin mutation waits for the confirmation answer' => function (): void {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app-live.js');

        assert_contains("form.matches('form[data-confirm]')", $js);
        assert_contains("form.getAttribute('data-confirm-approved') !== '1'", $js);
        assert_contains('data-confirm-approved', $js, 'the approved re-submit reaches the live mutation');
    },

    'every admin decision page loads the themed confirmation handler' => function (): void {
        $root = dirname(__DIR__, 2);

        $controllers = [
            'AdminPayoutController.php',
            'AdminActivationController.php',
            'AdminOrderController.php',
            'AdminMemberController.php',
            'AdminProductController.php',
            'AdminPackageController.php',
            'AdminWalletController.php',
            'AdminCodeController.php',
        ];

        foreach ($controllers as $controller) {
            $source = (string) file_get_contents($root . '/app/Controllers/' . $controller);

            assert_contains('assets/vendor/libs/sweetalert2/sweetalert2.js', $source, $controller . ' loads SweetAlert2');
            assert_contains('assets/js/app-confirm.js', $source, $controller . ' loads the confirmation handler');
        }
    },

    'every admin decision form carries its confirmation copy' => function (): void {
        $root = dirname(__DIR__, 2);

        $expectations = [
            'admin/payouts/_rows.php' => [
                'data-confirm-title="Approve this payout?"',
                'data-confirm-title="Reject this payout?"',
                'data-confirm-title="Mark this payout completed?"',
                'data-confirm-prompt="note"',
                'data-confirm-prompt-label="Rejection reason"',
            ],
            'admin/activations/_rows.php' => [
                'data-confirm-title="Approve this activation?"',
                'data-confirm-title="Reject this activation?"',
                'data-confirm-prompt="reason"',
                'data-confirm-prompt-label="Rejection reason"',
            ],
            'admin/orders/_payment.php' => [
                'data-confirm-title="Verify this payment?"',
                'data-confirm-title="Reject this payment proof?"',
            ],
            'admin/orders/_fulfilment.php' => [
                'data-confirm-title="Start packing this order?"',
                'data-confirm-title="Mark this order ready to ship?"',
                'data-confirm-title="Ship this order?"',
                'data-confirm-title="Mark this order delivered?"',
            ],
            'admin/orders/_cancel.php' => [
                'data-confirm-title="Cancel this order?"',
                'data-confirm-tone="danger"',
            ],
            'admin/members/_rows.php' => [
                'data-confirm-title=',
                'data-confirm-button=',
            ],
            'admin/members/_heading.php' => [
                'data-confirm-title=',
                'data-confirm-button=',
            ],
            'admin/products/_rows.php' => [
                'data-confirm-title=',
                'data-live-action="status"',
                'data-live-action="delete"',
            ],
            'admin/packages/_rows.php' => [
                'data-confirm-title=',
                'data-confirm-button=',
                'data-confirm-tone=',
            ],
            'admin/wallets/_topup_form.php' => [
                'data-confirm-title="Credit this wallet?"',
                'data-confirm-button="Credit wallet"',
            ],
            'admin/codes/index.php' => [
                'data-confirm-title="Generate these registration codes?"',
                'data-confirm-button="Generate codes"',
            ],
        ];

        foreach ($expectations as $view => $needles) {
            $source = (string) file_get_contents($root . '/app/views/' . $view);

            foreach ($needles as $needle) {
                assert_contains($needle, $source, $view . ' carries ' . $needle);
            }
        }
    },
];
