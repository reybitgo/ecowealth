<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageService — read-side composition for packages
 *  (plan §7 Phase 3)
 *
 *  Keeps the "Level 1 is the direct bonus" rule in one place: the
 *  admin form, the public cards and (from Phase 10) the commission
 *  engine all read the same ladder shape.
 *
 *  Activation and commission payout live in Phase 9/10 services;
 *  this one only composes what the package pages display.
 * ============================================================== */

final class PackageService
{
    /**
     * Active packages with their ladder and contents, ready for the
     * member page. Ordered by sort_order then price.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicCards(): array
    {
        $packages = Package::all(true);

        if ($packages === []) {
            return [];
        }

        $ids         = array_map(static fn (array $row): int => (int) $row['id'], $packages);
        $levelsMap   = Package::levelsMap($ids);
        $contentsMap = Package::contentsMap($ids);

        $cards = [];

        foreach ($packages as $package) {
            $id     = (int) $package['id'];
            $ladder = self::ladder($package, $levelsMap[$id] ?? []);

            $package['ladder']        = $ladder;
            $package['ladder_total']  = self::ladderTotal($ladder);
            $package['funded_levels'] = self::fundedLevels($ladder);
            $package['contents']      = $contentsMap[$id] ?? [];

            $cards[] = $package;
        }

        return $cards;
    }

    /**
     * The full 10-level ladder: level 1 is the package's direct
     * referral bonus (read-only, never stored in package_levels);
     * levels 2–10 come from that table, zero-filled.
     *
     * @param array<string, mixed> $package
     * @param array<int, float>    $levels  level 2–10 => amount
     * @return array<int, array{level:int, label:string, amount:float, read_only:bool}>
     */
    public static function ladder(array $package, array $levels): array
    {
        $ladder = [
            1 => [
                'level'     => 1,
                'label'     => 'Direct referral',
                'amount'    => (float) ($package['direct_referral_bonus'] ?? 0),
                'read_only' => true,
            ],
        ];

        for ($level = Package::LEVEL_MIN; $level <= Package::LEVEL_MAX; $level++) {
            $ladder[$level] = [
                'level'     => $level,
                'label'     => 'Level ' . $level,
                'amount'    => (float) ($levels[$level] ?? 0),
                'read_only' => false,
            ];
        }

        return $ladder;
    }

    /** @param array<int, array{amount: float}> $ladder */
    public static function ladderTotal(array $ladder): float
    {
        $total = 0.0;
        foreach ($ladder as $row) {
            $total += (float) $row['amount'];
        }

        return round($total, 2);
    }

    /** How many levels actually pay anything (for "pays N levels" copy). */
    public static function fundedLevels(array $ladder): int
    {
        $count = 0;
        foreach ($ladder as $row) {
            if ((float) $row['amount'] > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Levels 2–10 from the admin form, as strings so blank inputs stay
     * blank when the form is re-rendered after a validation error.
     *
     * @param array<int|string, mixed> $input
     * @return array<int, string>
     */
    public static function levelsFromInput(array $input): array
    {
        $levels = [];

        for ($level = Package::LEVEL_MIN; $level <= Package::LEVEL_MAX; $level++) {
            $value = $input[$level] ?? '';
            $levels[$level] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $levels;
    }

    /**
     * Zip the contents repeater's parallel arrays into rows.
     *
     * @param array<int|string, mixed> $productIds
     * @param array<int|string, mixed> $quantities
     * @return list<array{product_id: int|string, quantity: int|string}>
     */
    public static function contentsFromInput(array $productIds, array $quantities): array
    {
        $rows = [];

        foreach (array_values($productIds) as $index => $productId) {
            $rows[] = [
                'product_id' => $productId,
                'quantity'   => $quantities[$index] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Products offered in the contents form: every active product, plus
     * any inactive product already linked to this package (so editing a
     * package never silently drops an old line).
     *
     * @return list<array{id:int, name:string, sku:?string, price:string, status:string}>
     */
    public static function contentOptions(?int $packageId = null): array
    {
        $products = Product::all();
        $linked   = [];

        if ($packageId !== null) {
            foreach (Package::contents($packageId) as $content) {
                $linked[$content['product_id']] = true;
            }
        }

        $options = [];

        foreach ($products as $product) {
            $isActive = (string) $product['status'] === 'active';

            if (!$isActive && !isset($linked[(int) $product['id']])) {
                continue;
            }

            $options[] = [
                'id'     => (int) $product['id'],
                'name'   => (string) $product['name'],
                'sku'    => $product['sku'] !== null ? (string) $product['sku'] : null,
                'price'  => (string) $product['price'],
                'status' => (string) $product['status'],
            ];
        }

        return $options;
    }
}
