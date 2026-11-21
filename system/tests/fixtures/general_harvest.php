<?php
declare(strict_types=1);

/* ==============================================================
 *  General Harvest fixtures (Phase 21C, plan §16.2, §12.6)
 *
 *  Shared by `GeneralHarvestBaselineTest` and any later phase that
 *  needs an armed board, so the tree, the queue and the arm are built
 *  the same way everywhere.
 *
 *  **The queue is written with explicit ids.** A rolled-back INSERT
 *  still advances AUTO_INCREMENT in MySQL, so a suite run leaves the
 *  next `harvest_queue.id` well above 1; the board's arithmetic,
 *  however, is defined over the **positions** `1 … N` and the arm
 *  refuses a queue where `COUNT(*) <> MAX(id)` (D-03). Writing the ids
 *  the case means (`1 … N`, in qualification order) is the harness
 *  convention Phase 20 and 21B already document; the rows live inside
 *  the case's transaction.
 *
 *  The ternary trees are the plan's fixture family (§16.2):
 *
 *      h = 2   40 accounts, 13 qualified   N = 13   (fixture B)
 *      h = 3  121 accounts, 40 qualified   N = 40   (fixture C)
 *      h = 4  364 accounts, 121 qualified  N = 121  (§16.2's h = 4)
 *
 *  Qualification is Phase 20's rule (three member directs); the fixture
 *  only builds the tree and lists the members who hold it, in the order
 *  the seed would queue them (root, then each generation).
 *
 *  Returns one closure per helper; nothing here is a case file.
 * ============================================================== */

/** The reference ladder, so a case never depends on another case's edit. */
$reference = GeneralHarvestLevel::referencePreset();

/* --------------------------------------------------------------
 *  One account at a known instant (the harness's test_user() takes no
 *  sponsor, and the tree is the subject of this stream).
 * -------------------------------------------------------------- */
$account = static function (string $username, ?int $sponsorId = null): int {
    db()->prepare(
        'INSERT INTO users
           (username, email, password_hash, full_name, role, member_status,
            sponsor_id, referral_code, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $username,
        $username . '@p21c.test',
        password_hash('P21cMember@123', PASSWORD_BCRYPT),
        ucfirst(str_replace('_', ' ', $username)),
        'member',
        'active',
        $sponsorId,
        strtoupper(bin2hex(random_bytes(4))),
    ]);

    return (int) db()->lastInsertId();
};

return [
    'account' => $account,

    /**
     * Start a case from an empty member tree, the reference ladder, the
     * stream off and the board unarmed. The demo seed's five members go
     * (cascading their queue rows and awards) inside the case's
     * transaction, so the next case sees the demo tree again.
     */
    'clean' => static function () use ($reference): void {
        db()->exec('DELETE FROM general_harvest_awards');
        db()->exec('TRUNCATE harvest_queue');
        db()->exec('ALTER TABLE harvest_queue AUTO_INCREMENT = 1');
        db()->exec("DELETE FROM users WHERE role = 'member'");
        db()->exec(
            'UPDATE general_harvest_board
                SET baseline_n = NULL, armed_at = NULL, armed_by = NULL, settled_n = 0
              WHERE id = 1'
        );

        foreach ([
            GeneralHarvestLevel::ENABLED_KEY => '0',
            GeneralHarvestLevel::DEPTH_KEY   => '10',
            'team_harvest_enabled'            => '0',
            'team_harvest_qualify_directs'    => '3',
            'team_harvest_ledger_since'       => '',
        ] as $key => $value) {
            setting_set($key, $value);
        }

        $update = db()->prepare('UPDATE general_harvest_levels SET amount = ? WHERE level = ?');

        foreach ($reference as $level => $amount) {
            $update->execute([number_format($amount, 2, '.', ''), $level]);
        }

        setting_cache_clear();
        setting_all();
    },

    /**
     * Build the perfect 3-ary tree of `$height` generations, plus three
     * leaves under every deepest member (the §16.2 shape).
     *
     * @return array{root:int, levels:array<int, list<int>>}
     */
    'grow' => static function (int $height, string $prefix) use ($account): array {
        $root   = $account($prefix . '_root');
        $levels = [];
        $parents = [$root];

        for ($depth = 1; $depth <= $height; $depth++) {
            $level = [];

            foreach ($parents as $parent) {
                for ($i = 1; $i <= 3; $i++) {
                    $level[] = $account($prefix . '_n' . $depth . '_' . $parent . '_' . $i, $parent);
                }
            }

            $levels[$depth] = $level;
            $parents        = $level;
        }

        foreach ($parents as $parent) {
            for ($i = 1; $i <= 3; $i++) {
                $account($prefix . '_x' . $parent . '_' . $i, $parent);
            }
        }

        return ['root' => $root, 'levels' => $levels];
    },

    /**
     * The members the seed would queue, in qualification order: the root,
     * then each generation. For `h = 2` that is 13 members; for `h = 3`,
     * 40; for `h = 4`, 121.
     *
     * @param array{root:int, levels:array<int, list<int>>} $tree
     * @return list<int>
     */
    'qualified' => static function (array $tree): array {
        $users = [$tree['root']];

        foreach ($tree['levels'] as $level) {
            foreach ($level as $userId) {
                $users[] = $userId;
            }
        }

        return $users;
    },

    /**
     * Write the queue with explicit ids `1 … count($userIds)` — the
     * positions the board's arithmetic is defined over. Returns `N`.
     *
     * @param list<int> $userIds in qualification order
     */
    'queue' => static function (array $userIds): int {
        $insert = db()->prepare(
            "INSERT INTO harvest_queue
               (id, user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
             VALUES (?, ?, ?, 3, 0, 0, 'seed')"
        );

        foreach (array_values($userIds) as $index => $userId) {
            $insert->execute([
                $index + 1,
                (int) $userId,
                date('Y-m-d H:i:s', strtotime('2026-11-01 00:00:00') + $index * 60),
            ]);
        }

        return count($userIds);
    },

    /** Append one position after the current queue (a registration). */
    'append' => static function (int $userId): int {
        $next = (int) db()->query('SELECT COALESCE(MAX(id), 0) + 1 FROM harvest_queue')->fetchColumn();

        db()->prepare(
            "INSERT INTO harvest_queue
               (id, user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
             VALUES (?, ?, ?, 3, 0, 0, 'registration')"
        )->execute([$next, $userId, date('Y-m-d H:i:s')]);

        return $next;
    },

    /** Phase 20's seed marker: the queue has a history and may be armed. */
    'seedMarker' => static function (string $instant = '2026-11-01 00:00:00'): void {
        setting_set('team_harvest_ledger_since', $instant);
        setting_cache_clear();
        setting_all();
    },

    /** Flip the money switch (the arm's interlock). */
    'switch' => static function (bool $on = true): void {
        setting_set(GeneralHarvestLevel::ENABLED_KEY, $on ? '1' : '0');
        setting_cache_clear();
        setting_all();
    },

    /** Set the payable depth. */
    'depth' => static function (int $maxLevels): void {
        setting_set(GeneralHarvestLevel::DEPTH_KEY, (string) $maxLevels);
        setting_cache_clear();
        setting_all();
    },

    /**
     * Set one or more level amounts directly (`[9 => 0.0]`), the way an
     * operator's save writes them. No audit row: the card owns that.
     *
     * @param array<int, float|int|string> $levels
     */
    'ladder' => static function (array $levels): void {
        $update = db()->prepare('UPDATE general_harvest_levels SET amount = ? WHERE level = ?');

        foreach ($levels as $level => $amount) {
            $update->execute([number_format((float) $amount, 2, '.', ''), (int) $level]);
        }

        setting_cache_clear();
        setting_all();
    },

    /** The real arm, through the service the job calls. */
    'arm' => static fn (?array $actor = null): array => (new GeneralHarvestService())->arm(false, $actor),

    /** The dry run: the same numbers, nothing written. */
    'dryRun' => static fn (): array => (new GeneralHarvestService())->arm(true),

    /** Count rows in one table, optionally with a WHERE clause. */
    'count' => static function (string $table, string $where = ''): int {
        $sql = 'SELECT COUNT(*) FROM ' . $table . ($where === '' ? '' : ' WHERE ' . $where);

        return (int) db()->query($sql)->fetchColumn();
    },

    /**
     * Every award row, oldest first, as the case reads it.
     *
     * @return list<array<string, mixed>>
     */
    'awards' => static fn (): array => db()->query(
        'SELECT id, seq, user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at
           FROM general_harvest_awards
          ORDER BY id'
    )->fetchAll(),

    /** The board's stored row, as a comparable string. */
    'board' => static fn (): string => (string) db()->query(
        "SELECT CONCAT_WS('|', COALESCE(baseline_n, 'NULL'), COALESCE(armed_at, 'NULL'),
                           COALESCE(armed_by, 'NULL'), settled_n)
           FROM general_harvest_board WHERE id = 1"
    )->fetchColumn(),

    /** The whole wallet (both buckets), summed across every account. */
    'walletSums' => static fn (): string => (string) db()->query(
        "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
    )->fetchColumn(),
];
