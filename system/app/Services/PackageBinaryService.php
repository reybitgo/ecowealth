<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageBinaryService — the package binary money engine (23C)
 *
 *  The package stream is a second INPUT into Phase 22's machine: an
 *  approved activation contributes `packages.binary_points` once, on
 *  the activating member's side, to every binary upline; the pairs
 *  those points complete are paid immediately to the withdrawable
 *  e-wallet. No own row, no self award, no escrow, no pending award.
 *
 *  One hook, one transaction: `onActivation()` runs inside
 *  `ActivationService::complete()` and never opens its own transaction.
 *  It calls `BinaryService` for the volume, the pair settlement and the
 *  wallet; it owns the event, the points, the qualification and the
 *  activation stamp.
 *
 *  §14.3 sequence:
 *    1. lock the activation; binary_processed_at set → replayed
 *    2. status <> 'approved' → nothing
 *    3. read package + global switch; off or zero points → stamp zeros
 *    4. lock the member's node and ancestors, ascending user_id
 *       no node → stamp zeros, reason 'not placed'
 *    5. one 'team' row per ancestor (side from the path), no own row
 *    6. resolve eligibility once, stream = 'package'
 *    7. parent → root: due = min(L,R)/PP, cap room, n = min(due, room)
 *       n = 0 → continue; insert credited award + two pair rows;
 *       collect wallet credits
 *    7b. credit wallets in ascending recipient order, link ledger_id
 *    8. stamp activations: binary_points, binary_total, processed_at
 *    9. audit package_binary.award
 * ============================================================== */

final class PackageBinaryService
{
    private PDO $pdo;
    private BinaryService $engine;
    private WalletService $wallet;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo   = $pdo ?? db();
        $this->engine = new BinaryService($this->pdo);
        $this->wallet = new WalletService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  The one hook — approved activation
     * ---------------------------------------------------------- */

    /**
     * Place the package's points and pay the pairs they complete.
     * Idempotent: a second call returns `replayed` and moves nothing.
     *
     * @return array{
     *   activation_id:int, processed:bool, replayed:bool, reason?:string,
     *   points:int, pairs:int, amount:float,
     *   awards:list<array<string, mixed>>, nodes:int
     * }
     */
    public function onActivation(int $activationId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->pdo;

        /* Layer 1: lock the activation row. */
        $activation = $this->lockActivation($activationId, $pdo);
        if ($activation === null) {
            throw new RuntimeException('Activation #' . $activationId . ' does not exist.');
        }

        /* Layer 2: idempotency — the package stream's own stamp. */
        if ($activation['binary_processed_at'] !== null) {
            return $this->result($activationId, [
                'processed' => false,
                'replayed'  => true,
                'points'    => (int) ($activation['binary_points'] ?? 0),
                'pairs'     => 0,
                'amount'    => round((float) ($activation['binary_total'] ?? 0), 2),
                'awards'    => [],
                'nodes'     => 0,
                'reason'    => 'replayed',
            ]);
        }

        /* Layer 3: only an approved activation pays. */
        if ((string) ($activation['status'] ?? '') !== 'approved') {
            return $this->result($activationId, [
                'processed' => false,
                'replayed'  => false,
                'points'    => 0,
                'pairs'     => 0,
                'amount'    => 0.0,
                'awards'    => [],
                'nodes'     => 0,
                'reason'    => 'not approved',
            ]);
        }

        $userId   = (int) ($activation['user_id'] ?? 0);
        $packageId= (int) ($activation['package_id'] ?? 0);
        $now      = date('Y-m-d H:i:s');

        /* Layer 4: read the package and the global switch inside the
         * transaction so a concurrent change cannot rewrite this
         * activation. */
        $globalOn      = self::enabled();
        $packageEnabled= Package::binaryEnabled($packageId);
        $packagePoints = Package::binaryPoints($packageId);

        if (!$globalOn || !$packageEnabled || $packagePoints <= 0) {
            $this->stampActivation($activationId, 0, 0.0, $pdo);
            $reason = !$globalOn ? 'stream_disabled'
                : (!$packageEnabled ? 'package_disabled' : 'no eligible points');

            return $this->result($activationId, [
                'processed' => true,
                'replayed'  => false,
                'points'    => 0,
                'pairs'     => 0,
                'amount'    => 0.0,
                'awards'    => [],
                'nodes'     => 0,
                'reason'    => $reason,
            ]);
        }

        /* Layer 5: the member must be placed. */
        $node = BinaryNode::forUser($userId, $pdo);
        if ($node === null) {
            $this->stampActivation($activationId, 0, 0.0, $pdo);

            return $this->result($activationId, [
                'processed' => true,
                'replayed'  => false,
                'points'    => 0,
                'pairs'     => 0,
                'amount'    => 0.0,
                'awards'    => [],
                'nodes'     => 0,
                'reason'    => 'not placed',
            ]);
        }

        /* Build the node list: buyer first (kind 'own', no own row is
         * written for activations), then each ancestor (kind 'team')
         * with the side the buyer occupies under that ancestor. */
        $buyerPath = (string) ($node['path'] ?? '');
        $buyerDepth= (int) ($node['depth'] ?? 0);

        $nodeList = [
            ['node' => $node, 'side' => null, 'kind' => 'own'],
        ];

        $ancestors = BinaryNode::ancestors($userId, $pdo);
        foreach ($ancestors as $ancestor) {
            $depth = (int) ($ancestor['depth'] ?? 0);
            $side  = $depth < strlen($buyerPath)
                ? ((($buyerPath[$depth] ?? '') === 'L') ? 'left' : 'right')
                : null;

            if ($side === null || !in_array($side, ['left', 'right'], true)) {
                continue;
            }

            $nodeList[] = ['node' => $ancestor, 'side' => $side, 'kind' => 'team'];
        }

        /* Lock the whole set, ascending user_id (plan §16.6). */
        $ids = array_values(array_unique(array_map(
            static fn (array $entry): int => (int) ($entry['node']['user_id'] ?? 0),
            $nodeList
        )));
        sort($ids);

        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare(
                'SELECT * FROM binary_nodes WHERE user_id IN (' . $marks . ') ORDER BY user_id ASC FOR UPDATE'
            )->execute($ids);
        }

        /* Resolve eligibility once for the chain, stream = 'package'.
         * The buyer is included but never settles their own activation. */
        $eligibility = $this->engine->eligibilityMap(
            $nodeList,
            $now,
            BinaryService::streamFromPackage(),
            $pdo,
        );

        /* Layer 6: team-only volume — one row per ancestor, no own row. */
        $this->engine->applyVolume(
            $nodeList,
            [],   /* no order items for activations */
            0,    /* no order id */
            $userId,
            $packagePoints,
            BinaryAward::sourceFromActivation(), /* 'activation' */
            $activationId,
            true, /* teamOnly — no own row, no order refs */
            $pdo,
        );

        /* Keep left_credited/right_credited in sync with the ledger (INV-3):
         * these cached columns are only repaired by auditFix(), so the
         * service must maintain them when it writes volume. */
        $this->pdo->prepare(
            'UPDATE binary_nodes n
               JOIN (
                 SELECT node_user_id,
                        SUM(CASE WHEN side = "left"  AND kind IN ("own","team") AND points > 0 THEN points ELSE 0 END) AS lc,
                        SUM(CASE WHEN side = "right" AND kind IN ("own","team") AND points > 0 THEN points ELSE 0 END) AS rc
                 FROM binary_volume
                 WHERE activation_id = ?
                 GROUP BY node_user_id
               ) v ON v.node_user_id = n.user_id
               SET n.left_credited  = n.left_credited  + v.lc,
                   n.right_credited = n.right_credited + v.rc,
                   n.updated_at   = NOW()
             WHERE n.user_id = v.node_user_id'
        )->execute([$activationId]);

        /* Layer 7: pair settlement, parent → root. */
        $pairPoints   = (int) setting_int('binary_pair_points', 1000);
        $pairPct      = round((float) setting_float('binary_pair_pct', 10.0), 3);
        $perPairValue = BinaryService::pairValue();
        $dailyCap     = (int) setting_int('binary_daily_pair_cap', 20);
        $companyId    = (int) (Member::primaryAdmin($pdo)['id'] ?? 0);

        $totalPairCount = 0;
        $totalPairAmount= 0.0;
        $awardIds       = [];
        $walletCredits  = []; /* recipient_id => amount */

        foreach ($nodeList as $entry) {
            if ($entry['kind'] !== 'team') {
                continue; /* the buyer never receives from their own activation */
            }

            $settled = $this->engine->settleNode(
                $entry,
                0, /* orderId — unused when source is 'activation' */
                $userId,
                $now,
                $pairPoints,
                $pairPct,
                $perPairValue,
                $dailyCap,
                $companyId,
                $eligibility,
                true,  /* creditImmediately — credited, hold_days = 0 */
                BinaryAward::sourceFromActivation(), /* 'activation' */
                $activationId,
                $pdo,
            );

            if ($settled === null) {
                continue;
            }

            $awardIds[] = (int) ($settled['award']['id'] ?? 0);
            $totalPairCount += (int) ($settled['pair_count'] ?? 0);
            $totalPairAmount = round($totalPairAmount + (float) ($settled['amount'] ?? 0.0), 2);

            $recipientId = (int) ($entry['node']['user_id'] ?? 0);
            $walletCredits[$recipientId] = round(
                (float) ($walletCredits[$recipientId] ?? 0.0) + (float) ($settled['amount'] ?? 0.0),
                2
            );
        }

        /* Layer 7b: credit the wallets in ascending recipient order so two
         * activations sharing a recipient take wallet locks in the same
         * order and cannot deadlock (plan §14.5). */
        ksort($walletCredits);

        foreach ($walletCredits as $recipientId => $amount) {
            if ($amount <= 0) {
                continue;
            }

            /* Look up the award first so we can anchor the wallet movement
             * to it (ref_id = award id, the wallet anchor — plan §12.8 rule 3). */
            $award = BinaryAward::forActivationEvent($activationId, $recipientId, 'pair', $pdo);
            if ($award === null) {
                continue;
            }

            $movement = $this->wallet->credit(
                $recipientId,
                $amount,
                'binary',
                (int) $award['id'],
                'Package binary pair — activation #' . $activationId,
                true /* withdrawable — paid like the Phase 10–11 commissions */
            );

            /* Link the award to the wallet movement. */
            $ledgerId = (int) ($movement['ledger_id'] ?? 0);
            if ($ledgerId > 0) {
                BinaryAward::linkLedger((int) $award['id'], $ledgerId, $pdo);
                $pdo->prepare(
                    'UPDATE binary_awards SET credited_at = NOW()
                      WHERE id = ? AND activation_id = ? AND status = "credited"'
                )->execute([(int) $award['id'], $activationId]);
            }
        }

        /* Layer 8: the activation stamp — written last so anything above
         * throwing rolls the awards, volume and wallet back together. */
        $this->stampActivation($activationId, $packagePoints, $totalPairAmount, $pdo);

        /* Layer 9: audit. */
        AuditLog::record([
            'action'       => 'package_binary.award',
            'actor_id'     => null,
            'actor_role'   => 'system',
            'subject_type' => 'activation',
            'subject_id'   => $activationId,
            'meta'         => [
                'activation_id' => $activationId,
                'user_id'       => $userId,
                'package_id'    => $packageId,
                'points'        => $packagePoints,
                'pairs'         => $totalPairCount,
                'amount'        => number_format($totalPairAmount, 2, '.', ''),
                'reason'        => null,
            ],
        ], $pdo);

        Live::touch('admin.dashboard');
        Live::touch('admin.activations');
        Live::touchMember($userId);
        Live::touchMembership($userId);
        Live::touchMemberWallet($userId);
        Live::touchMemberDashboard($userId);

        /* Re-fetch the awards now that ledger_id/credited_at are set. */
        $awards = [];
        foreach ($awardIds as $awardId) {
            $row = BinaryAward::find($awardId, $pdo);
            if ($row !== null) {
                $awards[] = $row;
            }
        }

        return $this->result($activationId, [
            'processed' => true,
            'replayed'  => false,
            'points'    => $packagePoints,
            'pairs'     => $totalPairCount,
            'amount'    => $totalPairAmount,
            'awards'    => $awards,
            'nodes'     => count($nodeList),
            'reason'    => null,
        ]);
    }

    /* ----------------------------------------------------------
     *  Quote (read-only, never writes)
     * ---------------------------------------------------------- */

    /**
     * The points, pairs and money one activation of this package would
     * place against current balances. Read-only: no rows are written.
     *
     * @return array{
     *   eligible:bool, points:int, pairs:int, amount:float,
     *   balances:array{left:int, right:int}, reason?:string
     * }
     */
    public function quoteActivation(int $packageId, int $memberId): array
    {
        $globalOn   = self::enabled();
        $pkgEnabled = Package::binaryEnabled($packageId);
        $pkgPoints  = Package::binaryPoints($packageId);

        if (!$globalOn) {
            return [
                'eligible' => false, 'points' => 0, 'pairs' => 0, 'amount' => 0.0,
                'balances' => ['left' => 0, 'right' => 0],
                'reason'   => 'stream_disabled',
            ];
        }

        if (!$pkgEnabled || $pkgPoints <= 0) {
            return [
                'eligible' => false, 'points' => $pkgPoints, 'pairs' => 0, 'amount' => 0.0,
                'balances' => ['left' => 0, 'right' => 0],
                'reason'   => 'package_disabled',
            ];
        }

        $node = BinaryNode::forUser($memberId, $this->pdo);
        if ($node === null) {
            return [
                'eligible' => false, 'points' => $pkgPoints, 'pairs' => 0, 'amount' => 0.0,
                'balances' => ['left' => 0, 'right' => 0],
                'reason'   => 'not placed',
            ];
        }

        /* Qualification gate for the member themselves (they earn from
         * their team's activations, not from their own). */
        if (!$this->qualifies($memberId, $this->pdo)) {
            return [
                'eligible' => false, 'points' => $pkgPoints, 'pairs' => 0, 'amount' => 0.0,
                'balances' => [
                    'left'  => (int) ($node['left_points'] ?? 0),
                    'right' => (int) ($node['right_points'] ?? 0),
                ],
                'reason'   => 'not qualified',
            ];
        }

        $pairPoints = (int) setting_int('binary_pair_points', 1000);
        $perPair    = BinaryService::pairValue();

        $buyerPath = (string) ($node['path'] ?? '');
        $buyerDepth= (int) ($node['depth'] ?? 0);
        $ancestors = BinaryNode::ancestors($memberId, $this->pdo);

        $totalPairs = 0;
        $totalAmount= 0.0;

        foreach ($ancestors as $ancestor) {
            $depth = (int) ($ancestor['depth'] ?? 0);
            $side  = $depth < strlen($buyerPath)
                ? ((($buyerPath[$depth] ?? '') === 'L') ? 'left' : 'right')
                : null;

            if ($side === null) {
                continue;
            }

            $ancestorNode = BinaryNode::forUser((int) ($ancestor['user_id'] ?? 0), $this->pdo);
            $left  = (int) ($ancestorNode['left_points'] ?? 0);
            $right = (int) ($ancestorNode['right_points'] ?? 0);

            if ($side === 'left') {
                $newLeft  = $left + $pkgPoints;
                $newRight = $right;
            } else {
                $newLeft  = $left;
                $newRight = $right + $pkgPoints;
            }

            $pairs = (int) min(floor($newLeft / $pairPoints), floor($newRight / $pairPoints));
            $totalPairs += $pairs;
            $totalAmount  = round($totalAmount + $pairs * $perPair, 2);
        }

        return [
            'eligible'  => true,
            'points'    => $pkgPoints,
            'pairs'     => $totalPairs,
            'amount'    => $totalAmount,
            'balances'  => [
                'left'  => (int) ($node['left_points'] ?? 0),
                'right' => (int) ($node['right_points'] ?? 0),
            ],
            'reason'    => null,
        ];
    }

    /* ----------------------------------------------------------
     *  Qualification
     * ---------------------------------------------------------- */

    /**
     * Whether one member may receive package pairs right now:
     * not the company root, account active, membership active,
     * and (when required) a binary-enabled package.
     * The monthly Active rule does NOT apply (plan §12.5 rule 4).
     */
    public function qualifies(int $userId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? $this->pdo;

        if ($userId <= 0) {
            return false;
        }

        $companyId = (int) (Member::primaryAdmin($pdo)['id'] ?? 0);
        if ($companyId > 0 && $userId === $companyId) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT is_active, member_status, package_id FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $member = $stmt->fetch();

        if ($member === false) {
            return false;
        }

        if ((int) ($member['is_active'] ?? 0) !== 1) {
            return false;
        }

        if ((string) ($member['member_status'] ?? '') !== 'active') {
            return false;
        }

        if (self::requiresPackage()) {
            $pid = (int) ($member['package_id'] ?? 0);
            if ($pid <= 0 || !Package::binaryEnabled($pid, $pdo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The ancestors of one member that are blocked from earning package
     * pairs, keyed by user_id with a reason. Volume still flows past a
     * blocked node; it only skips the award.
     *
     * @return array<int, string> user_id => reason
     */
    public function blockedMap(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $node = BinaryNode::forUser($userId, $this->pdo);
        if ($node === null) {
            return [];
        }

        $ancestors = BinaryNode::ancestors($userId, $this->pdo);
        $blocked   = [];

        $companyId  = (int) (Member::primaryAdmin($this->pdo)['id'] ?? 0);
        $requires   = self::requiresPackage();

        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $ancestors
        )));

        if ($ids === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->pdo->prepare(
            'SELECT id, is_active, member_status, package_id FROM users WHERE id IN (' . $marks . ')'
        );
        $stmt->execute($ids);
        $members = [];
        foreach ($stmt->fetchAll() as $row) {
            $members[(int) $row['id']] = $row;
        }

        foreach ($ancestors as $ancestor) {
            $id = (int) ($ancestor['user_id'] ?? 0);

            if ($companyId > 0 && $id === $companyId) {
                $blocked[$id] = 'company account is not paid';
                continue;
            }

            $member = $members[$id] ?? null;
            if ($member === null || (int) ($member['is_active'] ?? 0) !== 1) {
                $blocked[$id] = 'recipient account is disabled';
                continue;
            }

            if ((string) ($member['member_status'] ?? '') !== 'active') {
                $status = (string) ($member['member_status'] ?? '');
                $blocked[$id] = 'recipient membership is ' . ($status === '' ? 'unknown' : $status);
                continue;
            }

            if ($requires) {
                $pid = (int) ($member['package_id'] ?? 0);
                if ($pid <= 0 || !Package::binaryEnabled($pid, $this->pdo)) {
                    $blocked[$id] = 'recipient package is not binary-enabled';
                }
            }
        }

        return $blocked;
    }

    /* ----------------------------------------------------------
     *  Totals for one activation (read-only)
     * ---------------------------------------------------------- */

    /**
     * @return array{
     *   activation_id:int, points:int, pairs:int, amount:float,
     *   awards:list<array<string, mixed>>, volume:list<array<string, mixed>>
     * }
     */
    public function totalsFor(int $activationId): array
    {
        if ($activationId <= 0) {
            return [
                'activation_id' => 0,
                'points'        => 0,
                'pairs'         => 0,
                'amount'        => 0.0,
                'awards'        => [],
                'volume'        => [],
            ];
        }

        $awards = BinaryAward::forActivation($activationId, $this->pdo);
        $volume = BinaryVolume::forActivation($activationId, $this->pdo);

        $points    = 0;
        $pairCount = 0;
        $pairTotal = 0.0;

        foreach ($volume as $row) {
            if ((string) ($row['kind'] ?? '') === 'team') {
                $points += (int) ($row['points'] ?? 0);
            }
        }

        foreach ($awards as $award) {
            if ((string) ($award['kind'] ?? '') === 'pair') {
                $pairCount += (int) ($award['pair_count'] ?? 0);
                $pairTotal = round($pairTotal + (float) ($award['amount'] ?? 0), 2);
            }
        }

        return [
            'activation_id' => $activationId,
            'points'        => $points,
            'pairs'         => $pairCount,
            'amount'        => $pairTotal,
            'awards'        => $awards,
            'volume'        => $volume,
        ];
    }

    /* ----------------------------------------------------------
     *  Reversal — support only, dry run by default
     * ---------------------------------------------------------- */

    /**
     * Reverse one activation's package binary awards, volume and wallet
     * credits. One transaction per activation, idempotent, never re-pairs.
     *
     * Options:
     *   apply  bool  default false — dry run writes nothing
     *   reason string — audit/reversal reason
     *
     * @return array{
     *   activation_id:int, applied:bool, dry_run:bool, replayed:bool, reason?:string,
     *   voided:int, recovered:float, shortfall:float, points_reversed:int,
     *   pair_returned:int, awards:list<array<string, mixed>>, nodes:int
     * }
     * @throws PackageBinaryException when the activation was never approved
     */
    public function reverseActivation(int $activationId, array $options = [], ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->pdo;

        $apply  = (bool) ($options['apply'] ?? false);
        $reason = mb_substr(trim((string) ($options['reason'] ?? 'Activation reversed')), 0, 160);
        $dryRun = !$apply;

        $activation = $this->lockActivation($activationId, $pdo);
        if ($activation === null) {
            throw new RuntimeException('Activation #' . $activationId . ' does not exist.');
        }

        /* Idempotency: reversal rows already exist for this activation. */
        $alreadyStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM binary_volume
              WHERE activation_id = ? AND kind = ? AND source = ?'
        );
        $alreadyStmt->execute([
            $activationId,
            BinaryVolume::KINDS[3],
            BinaryVolume::sourceFromActivation(),
        ]);
        $alreadyReversed = (int) $alreadyStmt->fetchColumn();

        if ($alreadyReversed > 0) {
            return $this->reverseResult($activationId, true, true, 0, 0.0, 0.0, 0, 0, [], 'already_reversed', $dryRun);
        }

        $awards = BinaryAward::forActivation($activationId, $pdo);
        $credited = array_values(array_filter($awards, fn (array $a): bool => (string) ($a['status'] ?? '') === 'credited'));

        /* T50: a never-approved activation paid nothing — refuse. */
        if ($credited === [] && (string) ($activation['status'] ?? '') !== 'approved') {
            throw PackageBinaryException::notApproved();
        }

        if ($credited === []) {
            return $this->reverseResult($activationId, true, false, 0, 0.0, 0.0, 0, 0, [], null, $dryRun);
        }

        if ($dryRun) {
            $wouldRecover = 0.0;
            $wouldShortfall = 0.0;
            $wouldPairPoints = 0;
            $wouldTeamPoints = 0;
            $plan = [];

            foreach ($credited as $award) {
                $userId = (int) $award['user_id'];
                $amount = round((float) $award['amount'], 2);
                $take = round(min($amount, $this->wallet->withdrawable($userId)), 2);
                $wouldRecover = round($wouldRecover + $take, 2);
                $wouldShortfall = round($wouldShortfall + max(0.0, round($amount - $take, 2)), 2);

                if ((string) $award['kind'] === 'pair') {
                    $wouldPairPoints += (int) $award['pair_count'] * (int) $award['pair_points'];
                }

                $plan[] = [
                    'award_id' => (int) $award['id'],
                    'user_id' => $userId,
                    'kind' => (string) $award['kind'],
                    'amount' => $amount,
                    'would_recover' => $take,
                    'would_shortfall' => max(0.0, round($amount - $take, 2)),
                ];
            }

            $teamRows = array_values(array_filter(
                BinaryVolume::forActivation($activationId, $pdo),
                fn (array $r): bool => (string) ($r['kind'] ?? '') === 'team'
            ));

            foreach ($teamRows as $row) {
                $wouldTeamPoints += (int) $row['points'];
            }

            return $this->reverseResult($activationId, false, false, count($credited), $wouldRecover, $wouldShortfall, $wouldTeamPoints, $wouldPairPoints * 2, $plan, null, $dryRun);
        }

        /* Apply — lock nodes and recipients ascending to avoid deadlocks. */
        $teamRows = array_values(array_filter(
            BinaryVolume::forActivation($activationId, $pdo),
            fn (array $r): bool => (string) ($r['kind'] ?? '') === 'team'
        ));

        $nodeIds = array_values(array_unique(array_map(
            static fn (array $r): int => (int) ($r['node_user_id'] ?? 0),
            $teamRows
        )));
        sort($nodeIds);

        if ($nodeIds !== []) {
            $marks = implode(',', array_fill(0, count($nodeIds), '?'));
            $pdo->prepare(
                'SELECT * FROM binary_nodes WHERE user_id IN (' . $marks . ') ORDER BY user_id ASC FOR UPDATE'
            )->execute($nodeIds);
        }

        $recipientIds = array_values(array_unique(array_map(
            static fn (array $a): int => (int) ($a['user_id'] ?? 0),
            $credited
        )));
        sort($recipientIds);

        if ($recipientIds !== []) {
            $marks = implode(',', array_fill(0, count($recipientIds), '?'));
            $pdo->prepare(
                'SELECT * FROM users WHERE id IN (' . $marks . ') ORDER BY id ASC FOR UPDATE'
            )->execute($recipientIds);
        }

        $voided = 0;
        $recovered = 0.0;
        $shortfall = 0.0;
        $pointsReversed = 0;
        $pairReturned = 0;

        foreach ($credited as $award) {
            $awardId = (int) $award['id'];
            $userId = (int) $award['user_id'];
            $amount = round((float) $award['amount'], 2);

            $take = round(min($amount, $this->wallet->withdrawable($userId)), 2);
            $take = max(0.0, $take);

            $reversalLedgerId = null;
            $recoveredNow = 0.0;

            if ($take > 0) {
                try {
                    $movement = $this->wallet->debit(
                        $userId,
                        $take,
                        'binary_reversal',
                        $awardId,
                        'Package binary reversal — activation #' . $activationId,
                        true
                    );
                    $reversalLedgerId = (int) ($movement['ledger_id'] ?? 0);
                    $recoveredNow = $take;
                } catch (Throwable $e) {
                    error_log(sprintf(
                        '[ecowealth] package binary reversal for award #%d recovered nothing: %s',
                        $awardId,
                        $e->getMessage()
                    ));
                    $recoveredNow = 0.0;
                }
            }

            if ((string) $award['kind'] === 'pair') {
                $pairCount = (int) ($award['pair_count'] ?? 0);
                $pp = (int) ($award['pair_points'] ?? 0);
                $pts = $pairCount * $pp;

                if ($pts > 0) {
                    foreach (['left', 'right'] as $side) {
                        BinaryVolume::create([
                            'node_user_id' => $userId,
                            'side' => $side,
                            'kind' => 'pair_return',
                            'points' => $pts,
                            'order_id' => null,
                            'order_item_id' => null,
                            'buyer_id' => (int) ($award['buyer_id'] ?? 0),
                            'award_id' => $awardId,
                            'note' => 'Pair return — voided award #' . $awardId,
                            'source' => BinaryVolume::sourceFromActivation(),
                            'activation_id' => $activationId,
                        ], $pdo);
                    }

                    $this->pdo->prepare(
                        'UPDATE binary_nodes
                            SET left_points = left_points + ?, right_points = right_points + ?,
                                pairs_paid = pairs_paid - ?, points_matched = points_matched - ?,
                                updated_at = NOW()
                          WHERE user_id = ?'
                    )->execute([
                        $pts,
                        $pts,
                        $pairCount,
                        2 * $pairCount * $pp,
                        $userId,
                    ]);

                    $pairReturned += $pts * 2;
                }
            }

            BinaryAward::void($awardId, [
                'reversal_ledger_id' => $reversalLedgerId,
                'reversed_amount' => $recoveredNow,
                'void_reason' => $reason,
            ], $pdo);

            $recovered = round($recovered + $recoveredNow, 2);
            $shortfall = round($shortfall + max(0.0, round($amount - $recoveredNow, 2)), 2);
            $voided++;
        }

        /* Reverse every team row of this activation. */
        foreach ($teamRows as $row) {
            $nodeId = (int) $row['node_user_id'];
            $side = (string) $row['side'];
            $points = (int) $row['points'];

            BinaryVolume::create([
                'node_user_id' => $nodeId,
                'side' => $side,
                'kind' => 'reversal',
                'points' => -$points,
                'order_id' => null,
                'order_item_id' => null,
                'buyer_id' => (int) ($row['buyer_id'] ?? 0),
                'award_id' => null,
                'note' => 'Reversal — package activation #' . $activationId,
                'source' => BinaryVolume::sourceFromActivation(),
                'activation_id' => $activationId,
            ], $pdo);

            $column = $side === 'left' ? 'left_points' : 'right_points';
            $this->pdo->prepare(
                'UPDATE binary_nodes
                    SET ' . $column . ' = ' . $column . ' - ?, updated_at = NOW()
                  WHERE user_id = ?'
            )->execute([$points, $nodeId]);

            $pointsReversed += $points;
        }

        /* Recompute the activation total from live (credited) pair awards. */
        $liveTotal = 0.0;
        $liveAwards = BinaryAward::forActivation($activationId, $pdo);
        foreach ($liveAwards as $award) {
            if ((string) ($award['kind'] ?? '') === 'pair' && (string) ($award['status'] ?? '') === 'credited') {
                $liveTotal = round($liveTotal + (float) ($award['amount'] ?? 0), 2);
            }
        }

        $this->stampActivation(
            $activationId,
            (int) ($activation['binary_points'] ?? 0),
            $liveTotal,
            $pdo
        );

        /* Audit. */
        AuditLog::record([
            'action' => 'package_binary.reverse',
            'actor_id' => null,
            'actor_role' => 'system',
            'subject_type' => 'activation',
            'subject_id' => $activationId,
            'meta' => [
                'activation_id' => $activationId,
                'awards' => $voided,
                'amount' => number_format($recovered, 2, '.', ''),
                'shortfall' => number_format($shortfall, 2, '.', ''),
                'reason' => $reason,
            ],
        ], $pdo);

        $userId = (int) ($activation['user_id'] ?? 0);
        Live::touch('admin.dashboard');
        Live::touch('admin.activations');
        Live::touchMember($userId);
        Live::touchMembership($userId);
        Live::touchMemberWallet($userId);
        Live::touchMemberDashboard($userId);

        return $this->reverseResult($activationId, true, false, $voided, $recovered, $shortfall, $pointsReversed, $pairReturned, [], null, false);
    }

    /* ----------------------------------------------------------
     *  Feature switches (static)
     * ---------------------------------------------------------- */

    public static function enabled(): bool
    {
        return setting_bool('package_binary_enabled', false);
    }

    public static function requiresPackage(): bool
    {
        return setting_bool('package_binary_requires_package', true);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** Lock the activation row for the caller's transaction. */
    private function lockActivation(int $activationId, PDO $pdo): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM activations WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$activationId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Write the idempotency anchor: the activation's binary stamp. */
    private function stampActivation(int $activationId, int $points, float $total, PDO $pdo): void
    {
        $pdo->prepare(
            'UPDATE activations
               SET binary_points = ?,
                   binary_total = ?,
                   binary_processed_at = NOW()
             WHERE id = ?'
        )->execute([
            number_format((float) $points, 0, '.', ''),
            number_format(round($total, 2), 2, '.', ''),
            $activationId,
        ]);
    }

    /** The common return shape. */
    private function result(int $activationId, array $values): array
    {
        return $values + [
            'activation_id' => $activationId,
            'processed'     => false,
            'replayed'      => false,
            'points'        => 0,
            'pairs'         => 0,
            'amount'        => 0.0,
            'awards'        => [],
            'nodes'         => 0,
            'reason'        => null,
        ];
    }

    /** The common reversal return shape. */
    private function reverseResult(
        int $activationId,
        bool $applied,
        bool $replayed,
        int $voided,
        float $recovered,
        float $shortfall,
        int $pointsReversed,
        int $pairReturned,
        array $plan,
        ?string $reason,
        bool $dryRun
    ): array {
        return [
            'activation_id' => $activationId,
            'applied' => $applied,
            'dry_run' => $dryRun,
            'replayed' => $replayed,
            'reason' => $reason,
            'voided' => $voided,
            'recovered' => $recovered,
            'shortfall' => $shortfall,
            'points_reversed' => $pointsReversed,
            'pair_returned' => $pairReturned,
            'awards' => $plan,
            'nodes' => 0,
        ];
    }
}
