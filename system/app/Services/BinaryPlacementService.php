<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryPlacementService — the only writer of placement columns (22C)
 *
 *  Writes binary_nodes.upline_id, side, depth, path, state,
 *  placed_* and moved_*. Every method runs inside the caller's
 *  transaction; the service never opens one. Validation throws
 *  BinaryException with a machine-readable reason.
 *
 *  Placement is structural: it is on whenever the binary_nodes table
 *  exists (isEnabled()), independent of the money switch
 *  (binary_enabled). No money is written here — that is 22D.
 * ============================================================== */

final class BinaryPlacementService
{
    private PDO $pdo;

    /** Placement is on once the table exists (Phase 22A). */
    public static function isEnabled(): bool
    {
        static $known;

        if ($known === null) {
            try {
                $row = db()->prepare(
                    'SELECT 1 FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
                );
                $row->execute(['binary_nodes']);
                $known = (bool) $row->fetch();
            } catch (Throwable) {
                $known = false;
            }
        }

        return $known;
    }

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /** Bootstrap the company root idempotently. */
    public function ensureRoot(): array
    {
        return BinaryNode::ensureRoot($this->pdo);
    }

    /**
     * Validate an upline/side choice without writing anything.
     *
     * @return array<string, string> field errors keyed `binary_upline` /
     *         `binary_position`, empty when the choice is legal
     */
    public function validate(int $uplineId, string $side, array $context = []): array
    {
        $errors = [];

        if (!in_array($side, BinaryNode::SIDES, true)) {
            $errors['binary_position'] = 'Choose left or right.';
            return $errors;
        }

        $registrar = $context['registrar'] ?? null;
        $sponsor   = $context['sponsor'] ?? null;

        try {
            $this->assertUplineLegal($uplineId, 0, $side, $registrar, $sponsor);
        } catch (BinaryException $e) {
            $errors['binary_upline'] = $e->getMessage();
        } catch (InvalidArgumentException $e) {
            $errors['binary_position'] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * Place a new member under an upline on one side.
     *
     * Throws BinaryException when the placement is illegal
     * (not_placed, slot_taken, cycle, immutable, state).
     *
     * @param array<string, mixed> $context  registrar, sponsor, placedBy, actorId
     * @return array<string, mixed> the placed node row
     */
    public function placeNew(int $userId, int $uplineId, string $side, array $context = []): array
    {
        if (!in_array($side, BinaryNode::SIDES, true)) {
            throw new InvalidArgumentException('Side must be "left" or "right".');
        }

        $this->ensureRoot();

        $registrar = $context['registrar'] ?? null;
        $sponsor   = $context['sponsor'] ?? null;
        $placedBy  = (int) ($context['placedBy'] ?? ($registrar['id'] ?? 0));
        $actorId   = (int) ($context['actorId'] ?? $placedBy);

        /* Cycle / eligibility / slot / legality are checked before the
         * "already has a node" guard, so an already-placed ancestor that
         * would cycle under a descendant reports cycle (plan P22C-T10). */
        $this->assertUplineLegal($uplineId, $userId, $side, $registrar, $sponsor);

        /* A member may have exactly one node. */
        if (BinaryNode::forUser($userId, $this->pdo) !== null) {
            throw BinaryException::state('Member #' . $userId . ' already has a binary node.');
        }

        BinaryNode::place($userId, $uplineId, $side, $placedBy, $this->pdo);

        $node = BinaryNode::find($userId, $this->pdo);
        if ($node === null) {
            throw BinaryException::state('The node was not written.');
        }

        $uplineUser = Member::find($uplineId, $this->pdo);

        AuditLog::record([
            'action'       => 'binary.placement.create',
            'actor_id'     => $actorId,
            'actor_role'   => null,
            'subject_type' => 'user',
            'subject_id'   => $userId,
            'meta'         => [
                'upline_id'   => $uplineId,
                'upline'      => (string) ($uplineUser['username'] ?? ''),
                'side'        => $side,
                'depth'       => (int) ($node['depth'] ?? 0),
                'path'        => (string) ($node['path'] ?? ''),
            ],
        ], $this->pdo);

        return $node;
    }

    /**
     * Move an empty subtree to a new (upline, side).
     *
     * Throws BinaryException when the move is illegal
     * (not_placed, slot_taken, immutable, cycle, state).
     *
     * @return array{before:array<string,mixed>, after:array<string,mixed>, node:array<string,mixed>}
     */
    public function move(int $userId, int $newUplineId, string $newSide, int $actorId): array
    {
        if (!in_array($newSide, BinaryNode::SIDES, true)) {
            throw new InvalidArgumentException('Side must be "left" or "right".');
        }

        $this->ensureRoot();

        $node = BinaryNode::forUser($userId, $this->pdo);
        if ($node === null) {
            throw BinaryException::notPlaced($userId);
        }

        if ((string) ($node['state'] ?? '') === 'root') {
            throw BinaryException::state('The company root cannot be moved.');
        }

        /* Already occupying the requested slot: nothing to do. */
        if ((int) ($node['upline_id'] ?? 0) === $newUplineId
            && (string) ($node['side'] ?? '') === $newSide) {
            $fresh = BinaryNode::find($userId, $this->pdo);

            return ['before' => $node, 'after' => $fresh, 'node' => $fresh];
        }

        $newUpline = BinaryNode::find($newUplineId, $this->pdo);
        if ($newUpline === null) {
            throw BinaryException::notPlaced($newUplineId);
        }

        if ($newUplineId === $userId
            || BinaryNode::isAncestor($userId, $newUplineId, $this->pdo)) {
            throw BinaryException::cycle($userId, $newUplineId);
        }

        if ((int) $newUpline['depth'] + 1 > BinaryNode::MAX_DEPTH) {
            throw BinaryException::state('That move would exceed the maximum tree depth.');
        }

        $existing = BinaryNode::slotChild($newUplineId, $newSide, $this->pdo);
        if ($existing !== null && (int) ($existing['user_id'] ?? 0) !== $userId) {
            throw BinaryException::slotTaken($newUplineId, $newSide);
        }

        /* Immutability: no volume or awards anywhere in the moved subtree,
         * and the node itself carries no personal points. */
        if ((int) ($node['personal_points'] ?? 0) > 0 || $this->subtreeHasMoney($userId)) {
            throw BinaryException::immutable(
                $userId,
                'the subtree has volume or awards'
            );
        }

        $before = $node;

        BinaryNode::move($userId, $newUplineId, $newSide, $actorId, $this->pdo);

        $after = BinaryNode::find($userId, $this->pdo);
        if ($after === null) {
            throw BinaryException::state('The move did not persist.');
        }

        AuditLog::record([
            'action'       => 'binary.placement.move',
            'actor_id'     => $actorId,
            'actor_role'   => null,
            'subject_type' => 'user',
            'subject_id'   => $userId,
            'meta'         => [
                'before' => $before,
                'after'  => $after,
                'reason' => 'empty-subtree move',
            ],
        ], $this->pdo);

        return ['before' => $before, 'after' => $after, 'node' => $after];
    }

    /** Resolve a typed upline: username first, then referral code. */
    public function resolveUpline(string $identifier): ?array
    {
        $member = Member::findByUsername($identifier, $this->pdo);

        return $member !== null ? $member : Member::findByReferralCode($identifier, $this->pdo);
    }

    /**
     * Whether placing `$newMemberId` under `$uplineId` on `$side` is legal,
     * without throwing. Mirrors the checks in placeNew().
     */
    public function legalUpline(
        int $uplineId,
        int $newMemberId,
        string $side,
        array $context = []
    ): bool {
        try {
            $registrar = $context['registrar'] ?? null;
            $sponsor   = $context['sponsor'] ?? null;

            $this->assertUplineLegal($uplineId, $newMemberId, $side, $registrar, $sponsor);

            return true;
        } catch (BinaryException|InvalidArgumentException) {
            return false;
        }
    }

    /** Every free slot under a node: `[['side'=>'left','free'=>true], …]`. */
    public function slotsFor(int $uplineId): array
    {
        $slots = [];

        foreach (BinaryNode::SIDES as $side) {
            $slots[] = [
                'side' => $side,
                'free' => BinaryNode::slotChild($uplineId, $side, $this->pdo) === null,
            ];
        }

        return $slots;
    }

    /**
     * Up to `$limit` free slots inside `$rootId`'s subtree, breadth-first
     * (shallow before deep, left before right). Each entry:
     * `{upline_id, upline, side, depth}`. Used by checkSponsor() to let
     * the register page prefill position choices.
     *
     * @return array{0:array<string,mixed>}
     */
    public function freeSlotsInSubtree(int $rootId, int $limit = 20): array
    {
        $rootNode = BinaryNode::find($rootId, $this->pdo);
        if ($rootNode === null) {
            return [];
        }

        $slots = [];
        $queue   = [(int) $rootNode['user_id']];
        $visited = [(int) $rootNode['user_id'] => true];

        while ($queue !== [] && count($slots) < $limit) {
            $current = array_shift($queue);
            $currentNode = BinaryNode::find($current, $this->pdo);
            $currentDepth = (int) ($currentNode['depth'] ?? 0) + 1;

            $currentMember = Member::find($current, $this->pdo);
            $currentUsername = (string) ($currentMember['username'] ?? '');

            foreach (BinaryNode::SIDES as $side) {
                $child = BinaryNode::slotChild($current, $side, $this->pdo);
                if ($child === null) {
                    $slots[] = [
                        'upline_id' => $current,
                        'upline'    => $currentUsername,
                        'side'      => $side,
                        'depth'     => $currentDepth,
                    ];
                    if (count($slots) >= $limit) {
                        break;
                    }
                }
            }

            if (count($slots) >= $limit) {
                break;
            }

            foreach (BinaryNode::children($current, $this->pdo) as $child) {
                $childId = (int) ($child['user_id'] ?? 0);
                if (!isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        return $slots;
    }

    /**
     * Backfill every member that has no node, in ascending id, under the
     * nearest already-placed sponsor ancestor's first free slot
     * (breadth-first, left before right); members with no placed sponsor
     * ancestor go under the company root's first free slot.
     *
     * With `$apply = false` (the default) it only reports what it would
     * do; with `$apply = true` it places the members and returns the
     * placed count.
     *
     * @return array{
     *   placed:int, root:int, max_depth:int, floating:int,
     *   levels:list<int>, dry_run:bool
     * }
     */
    public function backfill(bool $apply = false, ?int $limit = null): array
    {
        $root = $this->ensureRoot();
        $rootId = (int) $root['user_id'];

        $unplaced = $this->fetchUnplacedMembers($limit);

        $placed  = 0;
        $levels  = [];

        foreach ($unplaced as $member) {
            $userId = (int) $member['id'];
            $target = $this->findBackfillTarget($userId, $rootId);

            if ($target === null) {
                continue; // no free slot anywhere reachable — stays floating
            }

            if (!$apply) {
                $placed++;
                $levels[$target['depth']] = ($levels[$target['depth']] ?? 0) + 1;
                continue;
            }

            try {
                $this->placeNew($userId, $target['uplineId'], $target['side'], [
                    'placedBy' => $rootId,
                    'actorId'  => $rootId,
                ]);
                $placed++;
                $levels[$target['depth']] = ($levels[$target['depth']] ?? 0) + 1;
            } catch (BinaryException) {
                // Slot raced away; leave the member floating.
            }
        }

        $floating = $this->countFloating();

        return [
            'placed'   => $placed,
            'root'     => $rootId,
            'max_depth' => (int) ($this->deepestDepth() ?? 0),
            'floating' => $floating,
            'levels'   => array_values($levels),
            'dry_run'  => !$apply,
        ];
    }

    /**
     * Place the deterministic demo tree (root + five demo members on fixed
     * slots) wherever the members exist and the slots are free. Idempotent:
     * a member that already has a node or a taken slot is reported and
     * skipped; the rest of the tree still builds.
     */
    public function demoPlacement(): void
    {
        $root = $this->ensureRoot();
        $rootId = (int) $root['user_id'];

        /* (member username, upline username, side) — plan D-24 / seed 005. */
        $shape = [
            ['juan',  'admin', 'left'],
            ['maria', 'juan',  'left'],
            ['peter', 'maria', 'left'],
            ['sarah', 'maria', 'right'],
            ['david', 'juan',  'right'],
        ];

        foreach ($shape as [$member, $upline, $side]) {
            $m = Member::findByUsername($member, $this->pdo);
            $u = Member::findByUsername($upline, $this->pdo);

            if ($m === null || $u === null) {
                continue;
            }

            $userId   = (int) $m['id'];
            $uplineId = (int) $u['id'];

            if (BinaryNode::forUser($userId, $this->pdo) !== null) {
                continue; // already placed
            }

            if (BinaryNode::slotChild($uplineId, $side, $this->pdo) !== null) {
                continue; // slot taken
            }

            try {
                $this->placeNew($userId, $uplineId, $side, [
                    'placedBy' => $rootId,
                    'actorId'  => $rootId,
                ]);
            } catch (BinaryException) {
                // Slot raced away; leave the member floating.
            }
        }
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * Whether the moved subtree (node + descendants) already carries
     * volume or awards.
     */
    private function subtreeHasMoney(int $userId): bool
    {
        $ids = array_merge([$userId], BinaryNode::subtreeIds($userId, $this->pdo));
        $ids = array_values(array_unique($ids));

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM binary_volume WHERE node_user_id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM binary_awards WHERE user_id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * All checks that make an upline/side legal, throwing on the first
     * violation. Used by placeNew() and legalUpline().
     */
    private function assertUplineLegal(
        int $uplineId,
        int $newMemberId,
        string $side,
        ?array $registrar,
        ?array $sponsor
    ): void {
        $upline = BinaryNode::find($uplineId, $this->pdo);
        if ($upline === null) {
            throw BinaryException::notPlaced($uplineId);
        }

        $uplineUser = Member::find($uplineId, $this->pdo);
        if ($uplineUser === null) {
            throw BinaryException::notPlaced($uplineId);
        }

        if ((int) ($uplineUser['is_active'] ?? 0) !== 1) {
            throw BinaryException::state('That account is disabled.');
        }

        if ((string) ($uplineUser['member_status'] ?? '') === 'suspended') {
            throw BinaryException::state('That membership is suspended.');
        }

        $root = BinaryNode::root($this->pdo);
        $rootUserId = $root !== null ? (int) $root['user_id'] : 0;

        /* Staff accounts are not in the tree; the company root excepted. */
        if ((string) ($uplineUser['role'] ?? 'member') !== 'member' && $uplineId !== $rootUserId) {
            throw BinaryException::state('Staff accounts cannot be a binary upline.');
        }

        if ($uplineId === $newMemberId) {
            throw BinaryException::cycle($newMemberId, $uplineId);
        }

        if ((int) $upline['depth'] + 1 > BinaryNode::MAX_DEPTH) {
            throw BinaryException::state('That placement would exceed the maximum tree depth.');
        }

        if (BinaryNode::isAncestor($newMemberId, $uplineId, $this->pdo)) {
            throw BinaryException::cycle($newMemberId, $uplineId);
        }

        if (BinaryNode::slotChild($uplineId, $side, $this->pdo) !== null) {
            throw BinaryException::slotTaken($uplineId, $side);
        }

        /* Legality: a registrar may place inside their own subtree or the
         * sponsor's subtree; a guest self-registers inside the sponsor's
         * subtree. A registrar-less call is an admin placement and may
         * place anywhere legal. */
        if ($registrar !== null) {
            $legal = $this->uplineInSubtree($upline, $registrar, $this->pdo);

            if (!$legal && $sponsor !== null) {
                $sponsorNode = BinaryNode::forUser((int) $sponsor['id'], $this->pdo);
                $legal = $sponsorNode !== null && $this->uplineInSubtree($upline, $sponsorNode, $this->pdo);
            }

            if (!$legal) {
                throw BinaryException::state('That upline is outside your subtree.');
            }
        }
    }

    /** Whether `$upline` sits inside `$who`'s subtree (`who` itself counts). */
    private function uplineInSubtree(array $upline, array $who, PDO $pdo): bool
    {
        $uplineNode = BinaryNode::forUser((int) $upline['user_id'] ?? (int) $upline['id'], $pdo);
        $whoNode    = BinaryNode::forUser((int) $who['id'], $pdo);

        if ($uplineNode === null || $whoNode === null) {
            return false;
        }

        $whoPath = (string) $whoNode['path'];

        return str_starts_with((string) $uplineNode['path'], $whoPath);
    }

    /** The nearest already-placed sponsor ancestor's first free slot, or the root's. */
    private function findBackfillTarget(int $userId, int $rootId): ?array
    {
        $member = Member::find($userId, $this->pdo);
        if ($member === null) {
            return null;
        }

        $current = (int) ($member['sponsor_id'] ?? 0);
        $visited = [$userId => true];

        while ($current > 0 && !isset($visited[$current])) {
            $visited[$current] = true;

            $currentNode = BinaryNode::forUser($current, $this->pdo);
            if ($currentNode !== null) {
                foreach (BinaryNode::SIDES as $side) {
                    if (BinaryNode::slotChild($current, $side, $this->pdo) === null) {
                        return [
                            'uplineId' => $current,
                            'side'     => $side,
                            'depth'    => (int) $currentNode['depth'] + 1,
                        ];
                    }
                }
            }

            $currentMember = Member::find($current, $this->pdo);
            $current       = $currentMember === null ? 0 : (int) ($currentMember['sponsor_id'] ?? 0);
        }

        /* Fall back to the company root's first free slot. */
        $rootNode = BinaryNode::forUser($rootId, $this->pdo);
        if ($rootNode === null) {
            return null;
        }

        foreach (BinaryNode::SIDES as $side) {
            if (BinaryNode::slotChild($rootId, $side, $this->pdo) === null) {
                return ['uplineId' => $rootId, 'side' => $side, 'depth' => 1];
            }
        }

        return null;
    }

    /** Sponsor of a member (0 when none). */
    private function sponsorOf(int $userId): int
    {
        $member = Member::find($userId, $this->pdo);

        return $member === null ? 0 : (int) ($member['sponsor_id'] ?? 0);
    }

    /** Members with no binary node, oldest first. */
    private function fetchUnplacedMembers(?int $limit): array
    {
        $sql = 'SELECT u.id FROM users u
                LEFT JOIN binary_nodes b ON b.user_id = u.id
               WHERE b.user_id IS NULL AND u.role = ? AND u.is_active = 1
               ORDER BY u.id ASC';
        $params = ['member'];

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function countFloating(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM binary_nodes WHERE state = \'floating\''
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function deepestDepth(): ?int
    {
        $row = $this->pdo->query('SELECT MAX(depth) AS d FROM ' . BinaryNode::TABLE)->fetch();

        return $row === false ? null : (int) ($row['d'] ?? 0);
    }
}
