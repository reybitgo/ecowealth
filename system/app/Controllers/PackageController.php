<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageController — the member-facing package cards
 *  (plan §7 Phase 3: "members can view them")
 *
 *  Activation itself lands in Phase 9 (Membership); this page only
 *  presents the active packages, their price, product value, direct
 *  bonus, ladder and contents.
 * ============================================================== */

final class PackageController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        View::page('member', 'shop/packages', [
            'page_title'  => 'Packages',
            'current_nav' => 'packages',
            'packages'    => PackageService::publicCards(),
        ]);
    }
}
