<?php
declare(strict_types=1);

/* ==============================================================
 *  Team Harvest money fixtures (Phase 20D, plan §16.2)
 *
 *  Shared by `TeamHarvestEngineTest`, `TeamHarvestCatchupTest` and
 *  `TeamHarvestReconcileTest` so the three files build the **same**
 *  trees through the **same** production path and cannot disagree about
 *  what fixture A is.
 *
 *  The rule the fixtures obey: a live tree is grown through
 *  `TeamHarvestService::onQualified()` — the registration hook's exact
 *  call — so every position, counter and award is written by production
 *  code, never by a shortcut. An inert tree (the baseline fixtures) is
 *  grown with the hook disarmed and then reconstructed by
 *  `HarvestQueue::seed()`, exactly as a go-live does.
 *
 *  Fixture A (`grow(3)`) is the plan's perfect 3-ary tree: 40 qualified
 *  accounts + 81 leaf directs = **121 accounts**; the root holds
 *  `n = 39`, the three children `12`, the nine grandchildren `3`.
 *  Fixture B (`grow(4)`) adds one level: 121 qualified + 243 leaves =
 *  **364 accounts**, the root holding `n = 120` (§16.2).
 *
 *  A rolled-back INSERT still advances AUTO_INCREMENT in MySQL, so a
 *  position is asserted relatively in the cases (the queue's first
 *  position plus an offset), never as a literal `1` — the contract is
 *  the order and the money, not the arithmetic of a table counter the
 *  harness keeps nudging.
 *
 *  Returns one closure per helper; nothing here is a case file.
 * ============================================================== */

/** The reference ladder, so a case never depends on another case's edit. */
$reference = TeamHarvestLevel::referencePreset();

/* --------------------------------------------------------------
 *  One account at a known instant (the harness's test_user() takes no
 *  sponsor and no timestamp, and both are the subject of this stream).
 * -------------------------------------------------------------- */
$account = static function (
    string $username,
    ?int $sponsorId = null,
    string $role = 'member',
    string $createdAt = '',
    string $status = 'active',
    int $isActive = 1
): int {
    db()->prepare(
        'INSERT INTO users
           (username, email, password_hash, full_name, role, member_status,
            sponsor_id, referral_code, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $username,
        $username . '@p20d.test',
        password_hash('P20dMember@123', PASSWORD_BCRYPT),
        ucfirst(str_replace('_', ' ', $username)),
        $role,
        $status,
        $sponsorId,
        strtoupper(bin2hex(random_bytes(4))),
        $isActive,
        $createdAt === '' ? date('Y-m-d H:i:s') : $createdAt,
    ]);

    return (int) db()->lastInsertId();
};

return [
    'account' => $account,

    /**
     * Start a case from an empty member tree, the reference ladder, the
     * stream off and the queue unseeded. The demo seed's five members go
     * (cascading their queue rows and awards) inside the case's
     * transaction, so the next case sees the demo tree again.
     */
    'clean' => static function () use ($reference): void {
        db()->exec("DELETE FROM users WHERE role = 'member'");

        foreach ([
            'team_harvest_enabled'         => '0',
            'team_harvest_qualify_directs' => '3',
            'team_harvest_max_levels'      => '10',
            'team_harvest_ledger_since'    => '',
        ] as $key => $value) {
            setting_set($key, $value);
        }

        $update = db()->prepare('UPDATE team_harvest_levels SET amount = ? WHERE level = ?');

        foreach ($reference as $level => $amount) {
            $update->execute([number_format($amount, 2, '.', ''), $level]);
        }

        setting_cache_clear();
        setting_all();
    },

    /**
     * Arm the queue: write the baseline instant (the seed's job) and,
     * optionally, the gate and the switch. Every live qualification
     * after this call queues and — with the switch on — pays.
     */
    'arm' => static function (string $instant = '2026-11-01 00:00:00', ?int $gate = null, bool $enabled = true): void {
        setting_set('team_harvest_ledger_since', $instant);

        if ($gate !== null) {
            setting_set('team_harvest_qualify_directs', (string) $gate);
        }

        setting_set('team_harvest_enabled', $enabled ? '1' : '0');
        setting_cache_clear();
        setting_all();
    },

    /** Leave the queue unseeded (the hook is then inert), optionally setting the gate. */
    'disarm' => static function (?int $gate = null): void {
        setting_set('team_harvest_ledger_since', '');

        if ($gate !== null) {
            setting_set('team_harvest_qualify_directs', (string) $gate);
        }

        setting_cache_clear();
        setting_all();
    },

    /** Flip the one switch (D-09). */
    'enable' => static function (bool $on = true): void {
        setting_set('team_harvest_enabled', $on ? '1' : '0');
        setting_cache_clear();
        setting_all();
    },

    /**
     * Set one or more level amounts directly (`[3 => 5000.0]`), the way
     * an operator's save writes them. No audit row: the card owns that
     * (20B), and a money case only needs the stored value.
     *
     * @param array<int, float|int|string> $levels
     */
    'ladder' => static function (array $levels): void {
        $update = db()->prepare('UPDATE team_harvest_levels SET amount = ? WHERE level = ?');

        foreach ($levels as $level => $amount) {
            $update->execute([number_format((float) $amount, 2, '.', ''), (int) $level]);
        }

        setting_cache_clear();
        setting_all();
    },

    /** Set `team_harvest_max_levels` (the payable depth). */
    'depth' => static function (int $maxLevels): void {
        setting_set('team_harvest_max_levels', (string) $maxLevels);
        setting_cache_clear();
        setting_all();
    },

    /**
     * Grow the perfect 3-ary tree.
     *
     * `$service` non-null grows it **live** (the registration hook's
     * exact call, so money moves); null grows it **inert** (the hook
     * path returns early because the queue is unseeded), for the seed /
     * baseline fixtures.
     *
     * @return array{root:int, levels:array<int, list<int>>, accounts:int, leaves:int}
     */
    'grow' => static function (int $height, string $prefix, ?TeamHarvestService $service = null) use ($account): array {
        $queue   = new HarvestQueue();
        $root    = $account($prefix . '_root');
        $created = 1;
        $levels  = [];
        $parents = [$root];

        $hook = static function (int $sponsorId) use ($service, $queue): void {
            if ($service !== null) {
                $service->onQualified($sponsorId);
            } else {
                $queue->qualifySponsor($sponsorId);
            }
        };

        for ($depth = 1; $depth <= $height; $depth++) {
            $level = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $child = $account($prefix . '_n' . $depth . '_' . $parent . '_' . $i, $parent);
                    $created++;
                    $hook($parent);
                    $level[] = $child;
                }
            }

            $levels[$depth] = $level;
            $parents        = $level;
        }

        $leaves = 0;

        foreach ($parents as $parent) {
            for ($i = 1; $i <= 3; $i++) {
                $account($prefix . '_x' . $parent . '_' . $i, $parent);
                $created++;
                $hook($parent);   // already queued: a no-op
                $leaves++;
            }
        }

        return ['root' => $root, 'levels' => $levels, 'accounts' => $created, 'leaves' => $leaves];
    },

    /**
     * Grow a **seeded** tree from `n = 12` to `n = 39` (the baseline
     * fixture F, §16.2): under each of the nine depth-2 members, add
     * three members that each hold three directs. Each of those 27
     * qualifies, so the root moves from 12 to 39 and its level 3
     * completes after go-live.
     *
     * The tree must be the inert `grow(2, …)` tree, already seeded, with
     * the switch on.
     *
     * @param array{root:int, levels:array<int, list<int>>} $tree
     * @return int the number of accounts added
     */
    'growPastBaseline' => static function (array $tree, string $prefix, TeamHarvestService $service) use ($account): int {
        $created = 0;

        foreach ($tree['levels'][2] as $grandchild) {
            for ($i = 1; $i <= 3; $i++) {
                $member = $account($prefix . '_d' . $grandchild . '_' . $i, $grandchild);
                $created++;

                for ($j = 1; $j <= 3; $j++) {
                    $account($prefix . '_l' . $member . '_' . $j, $member);
                    $created++;
                    $service->onQualified($member);   // qualifies on the third direct
                }
            }
        }

        return $created;
    },

    /** One member's withdrawable balance. */
    'withdrawable' => static fn (int $userId): float => (new WalletService())->withdrawable($userId),

    /** One member's whole balance. */
    'balance' => static fn (int $userId): float => (new WalletService())->balance($userId),

    /**
     * One member's `team_harvest` wallet movements, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    'credits' => static function (int $userId): array {
        $stmt = db()->prepare(
            "SELECT id, type, amount, ref_type, ref_id, note, created_at
               FROM ewallet_ledger
              WHERE user_id = ? AND ref_type = 'team_harvest'
              ORDER BY id"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    },

    /** One member's award rows, level order. */
    'awards' => static fn (int $userId): array => TeamHarvestAward::forMember($userId),

    /** Count rows in one table, optionally with a WHERE clause. */
    'count' => static function (string $table, string $where = ''): int {
        $sql = 'SELECT COUNT(*) FROM ' . $table . ($where === '' ? '' : ' WHERE ' . $where);

        return (int) db()->query($sql)->fetchColumn();
    },

    /** The lowest position the queue holds — the first one the case assigned. */
    'firstPosition' => static fn (): int => (int) db()->query('SELECT COALESCE(MIN(id), 0) FROM harvest_queue')->fetchColumn(),
];
