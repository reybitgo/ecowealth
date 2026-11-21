<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryFoundationTest — Phase 22C ownership guard (plan §3.7,
 *  §7 Phase 22C, decision D-25)
 *
 *  Replaces the Phase 9 "no binary anywhere" guards with an
 *  ownership guard: the binary vocabulary may appear only in the
 *  named binary files; every other app PHP file must be clean.
 *
 *  The manifest is the single source of truth for which files own
 *  the binary words. When a phase adds a new binary file, it is
 *  added here.
 * ============================================================== */

final class BinaryFoundationTest
{
    /** The only files allowed to contain the binary vocabulary (§15.6). */
    public const VOCABULARY_FILES = [
        'Models/BinaryNode.php',
        'Models/BinaryAward.php',
        'Models/Report.php',
        'Services/BinaryPlacementService.php',
        'Services/BinaryService.php',
        'Services/BinaryTreeService.php',
        'Services/BinaryException.php',
        'Services/RegistrationService.php',
        'Services/OrderService.php',
        'Controllers/RegistrationController.php',
        'Controllers/AdminMemberController.php',
        'Controllers/AdminOrderController.php',
        'Controllers/CheckoutController.php',
        'Controllers/GenealogyController.php',
        'views/auth/register_form.php',
        'views/admin/members/_placement.php',
        'views/admin/members/_binary.php',
        'views/admin/orders/_binary.php',
        'views/admin/reports/index.php',
        'views/member/genealogy/_register_modal.php',
        'views/member/genealogy/_tree_actions.php',
        'views/shop/checkout.php',
        'views/shop/checkout/_binary.php',
        'views/shop/checkout/_summary.php',
        'views/shop/order/_binary.php',
        'helpers.php',
    ];

    /** Words that identify the binary placement tree. */
    public const VOCABULARY = [
        'binary_parent_id',
        'binary_position',
        'binary_upline',
        'binary_tree',
        'pairs_paid',
        'left_count',
        'right_count',
    ];

    /** Assert the binary vocabulary is confined to the named files. */
    public static function binaryVocabularyIsConfined(): array
    {
        $root     = str_replace('\\', '/', dirname(__DIR__, 2)) . '/app/';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $offenders = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($root, '', str_replace('\\', '/', $file->getPathname())), '/');

            if (in_array($relative, self::VOCABULARY_FILES, true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach (self::VOCABULARY as $word) {
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $source) === 1) {
                    $offenders[] = $relative . ' (' . $word . ')';
                    break;
                }
            }
        }

        sort($offenders);

        return $offenders;
    }

    /** Assert the users table carries no binary or pairs columns. */
    public static function usersTableIsClean(): array
    {
        $offenders = [];
        $columns   = array_column(db()->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');

        foreach ($columns as $column) {
            if (str_contains((string) $column, 'binary_')
                || str_contains((string) $column, 'pairs_')
                || in_array((string) $column, ['left_count', 'right_count'], true)) {
                $offenders[] = (string) $column;
            }
        }

        sort($offenders);

        return $offenders;
    }
}
