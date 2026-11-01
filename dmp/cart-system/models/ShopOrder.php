<?php

/**
 * @file   models/ShopOrder.php
 * @brief  Shop order state machine (14-status, plan §0.5 / §2.3).
 *
 * THE ONE WRITER OF shop_orders.status is ShopOrder::transition().
 * No controller, view, cron, or shipment model may write `status` directly —
 * every status move goes through the conditional-update transition below,
 * which also writes exactly one shop_order_events row inside the same
 * transaction (append-only audit; there is deliberately no UPDATE/DELETE path
 * for events).
 *
 * Strategy C: nothing in this model may call into core/Commission.php or any
 * other engine class. A shop order is pure retail — no PV, no bonuses, no CD.
 */
class ShopOrder
{
    public const STATUSES = [
        'pending', 'payment_review', 'payment_failed', 'paid', 'packing', 'ready_to_ship',
        'shipped', 'out_for_delivery', 'delivery_failed', 'returned_to_sender',
        'delivered', 'completed', 'cancelled', 'on_hold',
    ];

    public const TERMINAL = ['completed', 'cancelled'];

    /** Alert-only SLAs, not policy gates (plan §2.2). */
    public const SLA_REVIEW_HOURS  = 24;
    public const SLA_PICK_HOURS    = 24;
    public const RTS_RESPONSE_DAYS = 10;

    /**
     * Transition matrix (plan §0.5 — the single source of truth) —
     * to => ['from' => ..., 'actors' => [...]].
     *
     * Most rows share one flat source list across actors. 'cancelled' carries
     * PER-ACTOR source lists because §0.5 restricts customer cancels to
     * pending/payment_failed only — once paid, only staff/system can cancel —
     * while admin/system may cancel from any pre-handoff state (the timer
     * system list matches expireOverdue()'s candidate set exactly).
     *
     * The paid → payment_review revert is a dedicated method (revertPaidToReview),
     * NOT a matrix row: 'payment_review' already has its own from-list.
     */
    public const TRANSITIONS = [
        'payment_review'      => [
            'actors' => ['customer', 'admin'],
            'from'   => [
                'customer' => ['pending', 'payment_failed'],  // proof upload / re-upload
                'admin'    => ['paid'],                        // §0.5 revert row: only before packing
            ],
        ],
        'payment_failed'      => ['from' => ['payment_review'], 'actors' => ['admin']],
        'paid'                => ['from' => ['payment_review', 'pending'], 'actors' => ['admin', 'system']],
        'cancelled'           => [
            'actors' => ['customer', 'admin', 'system'],
            'from'   => [
                'customer' => ['pending', 'payment_failed'],
                'admin'    => ['pending', 'payment_review', 'payment_failed', 'paid', 'packing', 'ready_to_ship', 'on_hold', 'returned_to_sender'],
                'system'   => ['pending', 'payment_failed'],
            ],
        ],
        'on_hold'             => ['from' => ['pending', 'payment_review', 'payment_failed', 'paid', 'packing', 'ready_to_ship', 'returned_to_sender'], 'actors' => ['admin']],
        'packing'             => ['from' => ['paid', 'ready_to_ship', 'returned_to_sender', 'delivered', 'on_hold'], 'actors' => ['admin']],
        'ready_to_ship'       => ['from' => ['packing'], 'actors' => ['admin']],
        'shipped'             => ['from' => ['ready_to_ship'], 'actors' => ['admin']],
        'out_for_delivery'    => ['from' => ['shipped', 'delivery_failed'], 'actors' => ['admin', 'system']],
        'delivery_failed'     => ['from' => ['shipped', 'out_for_delivery'], 'actors' => ['admin', 'system']],
        'returned_to_sender'  => ['from' => ['shipped', 'out_for_delivery', 'delivery_failed'], 'actors' => ['admin', 'system']],
        'delivered'           => ['from' => ['shipped', 'out_for_delivery', 'delivery_failed'], 'actors' => ['admin', 'system']],
        'completed'           => ['from' => ['delivered'], 'actors' => ['customer', 'system']],
    ];

    /** Target status => [column => kind] actor/timestamp pairs (§5.4 map). */
    private const ACTOR_COLUMNS = [
        'paid'          => ['paid_by'      => 'actorId', 'paid_at'      => 'now'],
        'packing'       => ['approved_by'  => 'actorId', 'approved_at'  => 'now'],
        'ready_to_ship' => ['packed_by'    => 'actorId', 'packed_at'    => 'now'],
        'shipped'       => ['shipped_by'   => 'actorId', 'shipped_at'   => 'now'],
        'delivered'     => ['delivered_by' => 'actorId', 'delivered_at' => 'now'],
        'completed'     => ['completed_by' => 'actorId', 'completed_at' => 'now'],
        'cancelled'     => ['cancelled_by' => 'actorId', 'cancelled_at' => 'now'],
    ];

    /**
     * Reservation-state move keyed by target status (§0.3). Each move is
     * scoped to the states it is legal from — 'shipped' is deliberately NOT
     * here because the handoff deduction is per-line and conditional.
     */
    private const RESERVATION_ON = [
        'paid'           => ['to' => 'committed', 'from' => ['reserved']],
        'packing'        => ['to' => 'reserved',  'from' => ['deducted']],          // reship re-entry
        'cancelled'      => ['to' => 'released',  'from' => ['reserved', 'committed']],
        // S39: rejecting a proof frees the goods while the correction window
        // runs; a re-upload re-reserves them (see applySideEffects).
        'payment_failed' => ['to' => 'released',  'from' => ['reserved']],
    ];

    // ── Reads ─────────────────────────────────────────────────────────────────

    public static function find(int $id): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_orders WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findWithItems(int $id): ?array
    {
        $order = self::find($id);
        if (!$order) return null;
        $st = db()->prepare("SELECT oi.* FROM shop_order_items oi WHERE oi.order_id = ?");
        $st->execute([$id]);
        $order['items'] = $st->fetchAll();
        return $order;
    }

    // ── The one writer of status ──────────────────────────────────────────────

    /**
     * Conditional, audited status transition. All-or-nothing inside one
     * transaction; on any contention or failed guard it returns false having
     * written nothing (rollback), so the caller can flash "try again".
     *
     * opts:
     *   reason_code  string   audit reason (cancelled/on_hold/payment_failed…)
     *   note         string   free-text note for the event row
     *   source       string   ui|api|timer|carrier|system (default 'ui'; the
     *                         expiry cron passes 'timer')
     *   guards       array    named callables; each receives ($order, $opts);
     *                         any false refuses the transition
     *   extra        array    additional shop_orders SET pairs (hold fields,
     *                         correction_deadline, payment_reference…)
     */
    public static function transition(
        int $orderId,
        string $to,
        string $actorType,
        ?int $actorId = null,
        array $opts = []
    ): bool {
        // 3. Target must exist in the matrix — a typo in a route must throw,
        //    not silently no-op (plan §2.3 transition() step 3).
        if (!isset(self::TRANSITIONS[$to])) {
            throw new InvalidArgumentException("No transition rule into '{$to}'");
        }
        if (!in_array($actorType, ['customer', 'admin', 'system'], true)) {
            throw new InvalidArgumentException("Invalid actor type: {$actorType}");
        }

        $rule = self::TRANSITIONS[$to];
        $pdo  = db();

        // Transaction-aware (Phase 6.1): adopt the caller's transaction when one
        // is already open — placeOrder() wraps create + debit + paid in a single
        // unit, so that path must reach into this method rather than around it.
        // Otherwise own one, as before. PDO throws on a nested beginTransaction,
        // which is why this cannot simply be unconditional.
        $ownsTxn = !$pdo->inTransaction();
        if ($ownsTxn) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Lock the row.
            $st = $pdo->prepare("SELECT * FROM shop_orders WHERE id = ? FOR UPDATE");
            $st->execute([$orderId]);
            $order = $st->fetch();
            if (!$order) {
                if ($ownsTxn) $pdo->rollBack();
                return false;
            }

            // 2. Effective source status: a hold resumes from previous_status.
            $effective = $order['status'] === 'on_hold'
                ? (string) ($order['previous_status'] ?? '')
                : (string) $order['status'];

            // 4-5. Matrix legality: source allowed, actor allowed. Refused is
            //      a false return, not an exception. A per-actor from-map
            //      (cancelled) narrows the list for that actor; flat lists
            //      apply to everyone.
            $fromList = $rule['from'][$actorType] ?? $rule['from'];
            if (!in_array($effective, $fromList, true)) {
                if ($ownsTxn) $pdo->rollBack();
                return false;
            }
            if (!in_array($actorType, $rule['actors'], true)) {
                if ($ownsTxn) $pdo->rollBack();
                return false;
            }

            // 6. Named guards — any false refuses the transition.
            foreach (($opts['guards'] ?? []) as $guard) {
                if (!$guard($order, $opts)) {
                    if ($ownsTxn) $pdo->rollBack();
                    return false;
                }
            }

            // 7. Actor/timestamp pair for the target (§5.4 map). Bound in SQL
            //    placeholder order: status value first, then the SET values.
            $setPairs   = [];
            $setParams  = [];
            foreach (self::ACTOR_COLUMNS[$to] ?? [] as $col => $kind) {
                if ($kind === 'now') {
                    $setPairs[] = "{$col} = NOW()";
                } else {
                    $setPairs[] = "{$col} = ?";
                    $setParams[] = $actorId;
                }
            }

            // Caller-supplied extra SET pairs (hold fields, deadlines…).
            foreach (($opts['extra'] ?? []) as $col => $value) {
                $setPairs[]  = "{$col} = ?";
                $setParams[] = $value;
            }

            $setSql = count($setPairs) ? ', ' . implode(', ', $setPairs) : '';

            // 8. Conditional write — the WHERE status clause is the race guard
            //    (guide §11.1): 0 rows means another actor moved the order first.
            $sql    = "UPDATE shop_orders
                        SET status = ?, version = version + 1{$setSql}
                      WHERE id = ? AND status = ?";
            $params = array_merge([$to], $setParams, [$orderId, $order['status']]);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            if ($st->rowCount() !== 1) {
                if ($ownsTxn) $pdo->rollBack();
                return false;
            }

            // 9. Side effects for the target status.
            self::applySideEffects($pdo, $orderId, $to, $order, $opts, $actorType, $actorId);

            // 10. Append-only audit row (same transaction).
            ShopOrderEvent::log(
                $orderId,
                $order['status'],
                $to,
                $actorType,
                $actorId,
                [
                    'reason_code' => $opts['reason_code'] ?? null,
                    'note'        => $opts['note'] ?? null,
                    'meta_json'   => $opts['meta'] ?? null,
                    'source'      => $opts['source'] ?? 'ui',
                ]
            );

            // Commit only a transaction we opened. When we adopted the caller's,
            // committing here would end the caller's unit early — placeOrder()
            // still has the cart clear and the ledger assertion to do.
            if ($ownsTxn) {
                $pdo->commit();
            }
            return true;
        } catch (StockConflictException $e) {
            // Handoff stock conflict (§0.3): the transition is REFUSED, not an
            // error — roll back everything, then record a no-op audit row so
            // the attempt is visible in the timeline.
            //
            // Narrow on purpose. This used to be `catch (RuntimeException)`
            // with a string compare on the message, but PDOException extends
            // RuntimeException, so every database error was diverted here and
            // misreported as a stock problem (Phase 7).
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // The audit row is written AFTER the rollback on purpose, so it
            // survives. That is only safe when we owned the transaction; if the
            // caller's is still open the refusal is the caller's to report.
            if ($ownsTxn) {
                $from = $order['status'] ?? null;
                ShopOrderEvent::log(
                    $orderId,
                    $from,
                    $from ?? $to,
                    $actorType,
                    $actorId,
                    [
                        'reason_code' => 'stock_conflict',
                        'note'        => 'Handoff refused — insufficient stock for one or more lines',
                        'source'      => $opts['source'] ?? 'ui',
                    ]
                );
            }
            return false;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Side effects per target status (§0.5 step 9): reservation_state moves,
     * the stock deduction at courier handoff (shipped), deadline stamps, and
     * hold-field cleanup. All statements assume an open transaction on $pdo.
     */
    private static function applySideEffects(PDO $pdo, int $orderId, string $to, array $order, array $opts = [], string $actorType = 'system', ?int $actorId = null): void
    {
        // Reservation-state move, scoped to the states it is legal from (§0.3).
        if (isset(self::RESERVATION_ON[$to])) {
            $move = self::RESERVATION_ON[$to];
            $fromList = implode(',', array_fill(0, count($move['from']), '?'));
            $stampCol = $move['to'] === 'deducted' ? 'deducted_at'
                      : ($move['to'] === 'released' ? 'released_at' : null);
            $stampSql = $stampCol ? ", {$stampCol} = NOW()" : '';
            $st = $pdo->prepare(
                "UPDATE shop_order_items
                    SET reservation_state = ?{$stampSql}
                  WHERE order_id = ? AND reservation_state IN ({$fromList})"
            );
            // Placeholder order: SET ? then WHERE order_id ? then IN-list values.
            $st->execute(array_merge([$move['to'], $orderId], $move['from']));
        }

        // M4 (Phase 6.2): proof of delivery is written HERE, inside the
        // transition's transaction.
        //
        // It used to be written by markDelivered() *after* transition() had
        // already committed. A POD write that failed - a constraint violation,
        // a dropped connection - therefore left an order sitting in `delivered`
        // with no proof of delivery and nothing to retry from: the status was
        // durable, the evidence was not. Proof of delivery is the compliance
        // artifact for the whole fulfilment leg, so it must move atomically
        // with the status that claims it exists.
        if ($to === 'delivered' && isset($opts['pod']) && is_array($opts['pod'])) {
            $pod = $opts['pod'];
            $st = $pdo->prepare(
                "SELECT id FROM shop_shipments WHERE order_id = ? AND state = 'active' ORDER BY seq DESC LIMIT 1"
            );
            $st->execute([$orderId]);
            $shipmentId = $st->fetchColumn();
            if ($shipmentId) {
                ShopShipment::savePod((int) $shipmentId, $pod);
            }
        }

        // Re-upload after rejection (payment_failed → payment_review) brings
        // the released lines back into the pipeline (S39 inverse).
        //
        // M2: rejecting a proof RELEASES the reservation, so while the buyer
        // had their correction window open those units were sellable to
        // everyone else. Re-reserving them unconditionally is an oversell the
        // availability read cannot catch afterwards, because at this point the
        // order exists. Re-check per line and refuse the whole re-entry if any
        // line can no longer be honoured.
        if ($to === 'payment_review' && ($order['status'] ?? '') === 'payment_failed') {
            $lines = $pdo->prepare(
                "SELECT product_id, quantity FROM shop_order_items
                  WHERE order_id = ? AND reservation_state = 'released'"
            );
            $lines->execute([$orderId]);
            $reserved = [];
            foreach ($lines->fetchAll() as $line) {
                $pid = (int) $line['product_id'];
                $qty = (int) $line['quantity'];
                $reserved[$pid] = ($reserved[$pid] ?? 0) + $qty;

                $s = $pdo->prepare("SELECT stock FROM products WHERE id = ?");
                $s->execute([$pid]);
                $stock = (int) ($s->fetchColumn() ?? 0);

                $r = $pdo->prepare(
                    "SELECT COALESCE(SUM(oi.quantity), 0)
                       FROM shop_order_items oi
                       JOIN shop_orders o ON o.id = oi.order_id
                      WHERE oi.product_id = ?
                        AND oi.reservation_state IN ('reserved','committed')
                        AND o.id <> ?
                        AND o.status IN ('pending','payment_review','payment_failed','paid','packing','ready_to_ship','on_hold')"
                );
                $r->execute([$pid, $orderId]);
                $heldElsewhere = (int) $r->fetchColumn();

                if ($stock - $heldElsewhere < $qty) {
                    throw new StockConflictException();
                }
            }

            $st = $pdo->prepare(
                "UPDATE shop_order_items
                    SET reservation_state = 'reserved', released_at = NULL
                  WHERE order_id = ? AND reservation_state = 'released'"
            );
            $st->execute([$orderId]);
        }

        // Stock deduction at courier handoff — reachable only from ready_to_ship
        // (§0.3). Per-line conditional deduction; any zero-row result aborts
        // the whole transaction (the exception rolls everything back).
        if ($to === 'shipped') {
            $lines = $pdo->prepare(
                "SELECT product_id, quantity, deducted_at FROM shop_order_items
                  WHERE order_id = ? AND reservation_state IN ('reserved','committed')"
            );
            $lines->execute([$orderId]);
            $dec = $pdo->prepare(
                "UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?"
            );
            $mark = $pdo->prepare(
                "UPDATE shop_order_items
                    SET reservation_state = 'deducted', deducted_at = NOW()
                  WHERE order_id = ? AND product_id = ?"
            );
            foreach ($lines->fetchAll() as $line) {
                $qty = (int) $line['quantity'];
                if ($line['deducted_at'] !== null) {
                    // Reshipment of returned goods: the unit already left the
                    // stock figure at the first handoff and was never re-added
                    // (§0.3 "deducted exactly once") — re-deducting would
                    // double-count the same goods. Only re-stamp the line.
                    $mark->execute([$orderId, (int) $line['product_id']]);
                    continue;
                }
                $dec->execute([$qty, (int) $line['product_id'], $qty]);
                if ($dec->rowCount() !== 1) {
                    throw new StockConflictException();
                }
                $mark->execute([$orderId, (int) $line['product_id']]);
            }
        }

        // Deadlines (§0.5): correction window opens on payment_failed;
        // report window opens on delivered.
        if ($to === 'payment_failed') {
            $hours = max(1, (int) setting('shop_correction_window_hours', '24'));
            $pdo->prepare(
                "UPDATE shop_orders SET correction_deadline = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?"
            )->execute([$hours, $orderId]);
        }
        if ($to === 'delivered') {
            $days = max(1, (int) setting('shop_report_window_days', '5'));
            $pdo->prepare(
                "UPDATE shop_orders SET completion_due_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?"
            )->execute([$days, $orderId]);
        }

        // Immutable invoice freeze: issue on payment, void when the payment is
        // reverted or the order cancelled. Same transaction as the status
        // write, so an order can never sit in `paid` without its snapshot (or
        // carry a snapshot whose payment was rolled back). Re-payment after a
        // void re-enters issue() and appends a new row (-R2); issue() is
        // idempotent while an unvoided row exists (hold resume → paid).
        if ($to === 'paid') {
            ShopInvoice::issue($orderId, $pdo, $actorType, $actorId);
        } elseif ($to === 'payment_review' && ($order['status'] ?? '') === 'paid') {
            ShopInvoice::voidLatest($orderId, $pdo, 'paid_reverted');
        } elseif ($to === 'cancelled') {
            ShopInvoice::voidLatest($orderId, $pdo, 'order_cancelled');
        }

        // Leaving on_hold clears the hold fields.
        if ($order['status'] === 'on_hold' && $to !== 'on_hold') {
            $pdo->prepare(
                "UPDATE shop_orders
                    SET previous_status = NULL, hold_reason = NULL,
                        hold_owner_id = NULL, hold_deadline = NULL
                  WHERE id = ?"
            )->execute([$orderId]);
        }

        // Entering shipped timestamps the courier handoff on the active shipment.
        if ($to === 'shipped') {
            $pdo->prepare(
                "UPDATE shop_shipments SET handoff_at = NOW()
                  WHERE order_id = ? AND state = 'active' AND handoff_at IS NULL"
            )->execute([$orderId]);
        }
    }

    // ── Holds (§0.5 on_hold / §2.3 onHold, resume) ────────────────────────────

    /**
     * Pause an order. Reason, owner, and deadline are all mandatory — an
     * owner-less, deadline-less hold is permanent limbo, which the workflow
     * guide explicitly forbids. previous_status is frozen as the resume target.
     */
    public static function onHold(int $orderId, int $adminId, string $reason, int $ownerId, string $deadline): bool
    {
        $order = self::find($orderId);
        if (!$order) return false;
        if ($reason === '' || $ownerId <= 0 || $deadline === '') return false;
        if ($order['status'] === 'on_hold') return false; // already paused
        self::assertNotSelfReview($order);

        return self::transition($orderId, 'on_hold', 'admin', $adminId, [
            'reason_code' => mb_substr($reason, 0, 64),
            'extra'       => [
                'previous_status' => $order['status'],   // resume target, frozen
                'hold_reason'     => mb_substr($reason, 0, 160),
                'hold_owner_id'   => $ownerId,
                'hold_deadline'   => $deadline,
            ],
        ]);
    }

    /**
     * Resume from on_hold: write status back to previous_status, clear the
     * hold fields, then re-validate the resume target against the matrix —
     * if the world moved while paused (e.g. the shipment was flagged lost),
     * the resume is refused. This is a write, not a matrix row (§0.5).
     */
    public static function resume(int $orderId, int $adminId): bool
    {
        $order = self::find($orderId);
        if (!$order || $order['status'] !== 'on_hold') return false;
        self::assertNotSelfReview($order);
        $target = (string) ($order['previous_status'] ?? '');
        if ($target === '' || !isset(self::TRANSITIONS[$target])) return false;

        // Re-validate the resume target: it must be a matrix target an admin
        // can still move the order INTO (resume is an admin write). A target
        // like `completed` is customer-only, so it stays unreachable. "The
        // world moved meanwhile" is handled by the conditional write below —
        // an order cancelled or further transitioned while paused no longer
        // matches `status = 'on_hold'`, so the resume writes 0 rows.
        $rule = self::TRANSITIONS[$target];
        if (!in_array('admin', $rule['actors'], true)) {
            return false; // the resume target is not admin-reachable
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Conditional on BOTH the current status and the frozen resume
            // target: if another actor moved the order while we read it, 0 rows.
            $st = $pdo->prepare(
                "UPDATE shop_orders
                    SET status = ?, previous_status = NULL, hold_reason = NULL,
                        hold_owner_id = NULL, hold_deadline = NULL,
                        version = version + 1
                  WHERE id = ? AND status = 'on_hold' AND previous_status = ?"
            );
            $st->execute([$target, $orderId, $target]);
            if ($st->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }

            ShopOrderEvent::log($orderId, 'on_hold', $target, 'admin', $adminId, [
                'reason_code' => 'hold_resumed',
                'note'        => 'Hold released; order returned to ' . $target,
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    /** Member's own orders, newest first (plan §2.3 forMember). */
    public static function forMember(int $memberId, int $page = 1, int $perPage = 10): array
    {
        return paginate(
            "SELECT * FROM shop_orders WHERE member_id = ? ORDER BY created_at DESC, id DESC",
            [$memberId],
            $page,
            $perPage
        );
    }

    /**
     * Every one of a member's orders, newest first — a plain list, not a
     * paginated envelope. The My Orders page pages these client-side (like the
     * admin history timeline), so it needs the full set in one render.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allForMember(int $memberId): array
    {
        $st = db()->prepare(
            "SELECT * FROM shop_orders WHERE member_id = ? ORDER BY created_at DESC, id DESC"
        );
        $st->execute([$memberId]);
        return $st->fetchAll();
    }

    /** Admin list filtered by one status ('all' = everything). */
    public static function byStatus(string $status, int $page = 1, int $perPage = 10): array
    {
        if ($status !== 'all' && !in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }
        $sql    = $status === 'all'
            ? "SELECT * FROM shop_orders ORDER BY created_at DESC, id DESC"
            : "SELECT * FROM shop_orders WHERE status = ? ORDER BY created_at DESC, id DESC";
        return paginate($sql, $status === 'all' ? [] : [$status], $page, $perPage);
    }

    /**
     * Every order for one status ('all' = everything), newest first — a plain
     * list, not a paginated envelope. The admin queue pages these client-side
     * (like the member My Orders list), so it needs the full set in one render.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allByStatus(string $status): array
    {
        if ($status !== 'all' && !in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }
        $sql = $status === 'all'
            ? "SELECT * FROM shop_orders ORDER BY created_at DESC, id DESC"
            : "SELECT * FROM shop_orders WHERE status = ? ORDER BY created_at DESC, id DESC";
        $st = db()->prepare($sql);
        $st->execute($status === 'all' ? [] : [$status]);
        return $st->fetchAll();
    }

    /** One GROUP BY row per status — drives admin tabs and sidebar badges. */
    public static function statusCounts(): array
    {
        $rows = db()->query("SELECT status, COUNT(*) AS c FROM shop_orders GROUP BY status")->fetchAll();
        $out  = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * The single member-side predicate deciding whether an action button
     * renders (plan §2.3). The server re-checks in the handler, so hiding a
     * button is never the only guard.
     */
    public static function canMemberAct(array $order, string $act): bool
    {
        $status = (string) $order['status'];
        return match ($act) {
            'cancel'  => in_array($status, ['pending', 'payment_failed'], true),
            'proof'   => self::proofWindowOpen($order),
            'confirm' => $status === 'delivered' && !ShopRefund::hasOpen((int) $order['id']),
            default   => false,
        };
    }

    /** Is the proof-upload window open for this order (status + deadline + attempts)? */
    private static function proofWindowOpen(array $order): bool
    {
        $status = (string) $order['status'];
        if (!in_array($status, ['pending', 'payment_failed'], true)) return false;

        $deadline = $status === 'pending' ? $order['payment_deadline'] : $order['correction_deadline'];
        if ($deadline && strtotime((string) $deadline) < time()) return false;

        $max = max(1, (int) setting('shop_max_proof_attempts', '3'));
        return ShopPaymentProof::attemptCount((int) $order['id']) < $max;
    }

    /**
     * Segregation of duties (§5.9): an admin may buy but may never verify,
     * pack, ship, or complete their own order. In the model so every entry
     * point (UI, cron, future API) gets it.
     */
    public static function assertNotSelfReview(array $order): void
    {
        // class_exists guard: CLI/cron contexts may not have core/Auth loaded,
        // and a timer action has no human session to check anyway.
        if (class_exists('Auth') && Auth::isAdmin() && (int) $order['member_id'] === Auth::id()) {
            throw new RuntimeException('Self-review refused: admins cannot act on their own orders.');
        }
    }

    // ── Commerce: order creation (T1) ─────────────────────────────────────────

    private const PAYMENT_METHODS = ['ewallet', 'gcash', 'maya', 'usdt_trc20', 'usdt_bep20'];

    /**
     * T1: place an order from an active cart (plan §2.3 createFromCart).
     * Prices are re-read from the catalog inside the transaction, so an admin
     * price change can never be baked in at a stale cart price. Idempotent:
     * a repeated key returns the existing order instead of double-placing.
     * Throws InvalidArgumentException with a buyer-safe message on bad input.
     */
    public static function createFromCart(
        int $memberId,
        int $cartId,
        array $addr,
        string $paymentMethod,
        ?string $idempotencyKey = null
    ): int {
        if (!in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('Unknown payment method.');
        }

        $pdo = db();

        // Idempotency fast path (plan guard 4).
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $st = $pdo->prepare("SELECT id FROM shop_orders WHERE idempotency_key = ?");
            $st->execute([$idempotencyKey]);
            $existing = $st->fetchColumn();
            if ($existing) return (int) $existing;
        }

        // Transaction-aware (§4.1): adopt the caller's transaction when one is
        // already open (placeOrder wraps create + debit atomically); otherwise
        // own one. Nested beginTransaction on PDO would throw immediately.
        $ownsTxn = !$pdo->inTransaction();
        if ($ownsTxn) {
            $pdo->beginTransaction();
        }
        try {
            $cart = Cart::getActive($memberId);
            if (!$cart || (int) $cart['id'] !== $cartId) {
                throw new InvalidArgumentException('Your cart is no longer active.');
            }

            // Authoritative pricing straight from the catalog (§4.1 edit 2).
            Cart::refreshPrices($cartId);
            $items  = Cart::getItems($cartId);
            if (!$items) {
                throw new InvalidArgumentException('Your cart is empty.');
            }
            $errors = Cart::validateStock($cartId);
            if ($errors) {
                throw new InvalidArgumentException('Stock changed: ' . $errors[0]);
            }
            $total  = 0.0;
            foreach ($items as $i) {
                $total += ((float) $i['unit_price']) * ((int) $i['quantity']);
            }

            $hours = max(1, (int) setting('shop_payment_deadline_hours', '24'));

            $st = $pdo->prepare(
                "INSERT INTO shop_orders
                    (order_no, member_id, idempotency_key, total_price, payment_method, status,
                     payment_deadline, billing_name, billing_phone, shipping_name, shipping_phone,
                     shipping_address, shipping_city, shipping_province, shipping_postal,
                     notes_member, terms_version, terms_accepted_at)
                 VALUES
                    (?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL {$hours} HOUR),
                     ?, ?, ?, ?, ?, ?, ?, ?, ?, 'v1', NOW())"
            );
            $st->execute([
                'T' . bin2hex(random_bytes(8)),      // ≤20 chars; replaced below inside this txn
                $memberId,
                $idempotencyKey,
                $total,
                $paymentMethod,
                $addr['billing_name']   ?? ($addr['shipping_name'] ?? 'Customer'),
                $addr['billing_phone']  ?? null,
                $addr['shipping_name']  ?? 'Customer',
                $addr['shipping_phone'] ?? null,
                $addr['shipping_address'] ?? '',
                $addr['shipping_city']      ?? null,
                $addr['shipping_province']  ?? null,
                $addr['shipping_postal']    ?? null,
                $addr['notes_member']       ?? null,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            // Public order number, unique because id is (§2.3 note).
            $pdo->prepare("UPDATE shop_orders SET order_no = ? WHERE id = ?")
                ->execute([shop_public_order_no($orderId, date('Y-m-d H:i:s')), $orderId]);

            $ins = $pdo->prepare(
                "INSERT INTO shop_order_items
                    (order_id, product_id, product_name, product_sku, quantity, unit_price, total_price, reservation_state)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'reserved')"
            );
            foreach ($items as $i) {
                $ins->execute([
                    $orderId,
                    (int) $i['product_id'],
                    $i['product_name'],
                    $i['product_sku'] ?? null,
                    (int) $i['quantity'],
                    (float) $i['unit_price'],
                    (float) $i['unit_price'] * (int) $i['quantity'],
                ]);
            }

            Cart::markConverted($cartId);

            ShopOrderEvent::log($orderId, null, 'pending', 'customer', $memberId, [
                'reason_code' => 'order_placed',
                'note'        => 'Order placed via ' . $paymentMethod,
            ]);

            if ($ownsTxn) {
                $pdo->commit();
            }
            return $orderId;
        } catch (InvalidArgumentException $e) {
            if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } catch (PDOException $e) {
            if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
            // Concurrent duplicate idempotency submit lost the UNIQUE race —
            // the winner's order id is the correct answer for the loser too.
            if ($idempotencyKey !== null && $idempotencyKey !== '' && (int) $e->getCode() === 23000) {
                $st = $pdo->prepare("SELECT id FROM shop_orders WHERE idempotency_key = ?");
                $st->execute([$idempotencyKey]);
                $existing = $st->fetchColumn();
                if ($existing) return (int) $existing;
            }
            throw $e;
        } catch (Throwable $e) {
            if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ── Commerce: payment proof legs (T2/T3/T5) ───────────────────────────────

    /**
     * T2: buyer uploads a payment proof. Guards: owned order, from pending
     * (before payment_deadline) or payment_failed (before correction_deadline),
     * attempts under the cap. Never touches payment_deadline — payment_review
     * is exempt from expiry, so the stamp preserves the audit trail (§2.3).
     */
    public static function submitProof(int $orderId, int $memberId, string $imagePath, array $claim = []): bool
    {
        $order = self::find($orderId);
        if (!$order || (int) $order['member_id'] !== $memberId) return false;
        if (!self::proofWindowOpen($order)) return false;
        if ($imagePath === '') return false;

        $proofId = null;
        $image   = $imagePath;

        // The proof row is written from a GUARD, so it commits or rolls back
        // inside transition()'s transaction.
        //
        // It used to be INSERTed first, outside, and the status change second.
        // Against the expiry cron that left a real window: if the timer won the
        // race between the two, transition() returned false and the order was
        // cancelled WITH an orphan proof attached claiming payment that no
        // longer mattered (defect M1). The plan asked for "exactly one winner";
        // this is what makes that true.
        $insertProof = static function () use (&$proofId, $orderId, &$image, $claim, $memberId): bool {
            $proofId = ShopPaymentProof::add($orderId, $image, [
                'reference_no'  => clean_reference($claim['reference_no'] ?? '', 40) ?: null,
                'amount'        => $claim['amount'] ?? null,
                'transfer_date' => $claim['transfer_date'] ?? null,
                'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
                'uploaded_by'   => $memberId,
            ]);
            return $proofId > 0;
        };

        $ok = self::transition($orderId, 'payment_review', 'customer', $memberId, [
            'reason_code' => 'proof_submitted',
            'guards'      => [$insertProof],
        ]);

        // Only meaningful on success; kept for callers that want the id.
        self::$lastProofId = $ok ? $proofId : null;
        return $ok;
    }

    /** Id of the proof written by the most recent successful submitProof(). */
    private static ?int $lastProofId = null;

    public static function lastProofId(): ?int
    {
        return self::$lastProofId;
    }

    /**
     * T3: staff rejects a proof — correction window opens. On the attempt
     * cap the order moves on_hold (reason proof_attempts_exhausted) instead.
     */
    public static function rejectProof(int $orderId, int $adminId, string $reasonCode, ?float $amountReceived = null): bool
    {
        self::assertNotSelfReview(self::find($orderId) ?: []);
        $order = self::find($orderId);
        if (!$order || $order['status'] !== 'payment_review') return false;

        $proof = ShopPaymentProof::latestFor($orderId);
        if ($proof) {
            ShopPaymentProof::markRejected((int) $proof['id'], $adminId, $reasonCode);
        }

        $max   = max(1, (int) setting('shop_max_proof_attempts', '3'));
        $after = ShopPaymentProof::attemptCount($orderId);
        if ($after >= $max) {
            return self::onHold($orderId, $adminId, 'proof_attempts_exhausted', $adminId,
                date('Y-m-d H:i:s', time() + self::SLA_REVIEW_HOURS * 3600));
        }

        return self::transition($orderId, 'payment_failed', 'admin', $adminId, [
            'reason_code' => $reasonCode,
            'extra'       => ['payment_reference' => $proof['reference_no'] ?? $order['payment_reference']],
        ]);
    }

    /**
     * T5: payment verified. Admin path guards exact-amount (§0.4: no
     * tolerance) and reference uniqueness across orders — both the
     * `shop_orders.payment_reference` column (set only when an order is PAID)
     * and every non-rejected `shop_payment_proofs.reference_no` (a pending or
     * verified proof on another order still claims that transfer); system path
     * is the e-wallet auto-verify (money already moved through our own ledger).
     */
    public static function markPaid(
        int $orderId,
        string $actorType,
        ?int $actorId,
        ?float $amountSent = null,
        ?string $paymentReference = null
    ): bool {
        $order = self::find($orderId);
        if (!$order) return false;
        if ($actorType === 'admin') {
            self::assertNotSelfReview($order);
        }

        $guards = [];
        $extra  = [];
        if ($actorType === 'admin') {
            $amountSent = $amountSent ?? 0.0;
            if (abs($amountSent - (float) $order['total_price']) >= 0.005) {
                throw new InvalidArgumentException(
                    'Amount mismatch: claimed ' . fmt_money($amountSent)
                    . ' vs order total ' . fmt_money((float) $order['total_price']) . '.'
                );
            }
            // Normalise at the boundary: the column is VARCHAR(40) and the only
            // caller-facing constraint was a client-side maxlength (Phase 7).
            $paymentReference = clean_reference($paymentReference, 40);
            if ($paymentReference === '') {
                throw new InvalidArgumentException('Payment reference required.');
            }
            if ($paymentReference !== null && $paymentReference !== '') {
                // Uniqueness spans the whole transfer lifecycle: a reference on
                // shop_orders (set only when PAID) AND any proof still on file
                // on another order (pending/verified — rejected releases it).
                $st = db()->prepare(
                    "SELECT id FROM shop_orders WHERE payment_reference = ? AND id <> ? AND payment_reference IS NOT NULL"
                );
                $st->execute([$paymentReference, $orderId]);
                if ($st->fetchColumn()) {
                    throw new InvalidArgumentException('Reference already used by another order.');
                }
                $st = db()->prepare(
                    "SELECT order_id FROM shop_payment_proofs
                     WHERE reference_no = ? AND order_id <> ? AND status <> 'rejected'
                     LIMIT 1"
                );
                $st->execute([$paymentReference, $orderId]);
                if ($st->fetchColumn()) {
                    throw new InvalidArgumentException('Reference already used by another order.');
                }
                $extra['payment_reference'] = $paymentReference;
            }
            // A verified proof must exist for a manually verified payment.
            $guards[] = function () use ($orderId) {
                return ShopPaymentProof::latestFor($orderId) !== null;
            };
        }

        $ok = self::transition($orderId, 'paid', $actorType, $actorId, [
            'reason_code' => $actorType === 'system' ? 'ewallet_auto_verified' : 'payment_verified',
            'guards'      => $guards,
            'extra'       => $extra,
        ]);

        if ($ok && $actorType === 'admin') {
            $proof = ShopPaymentProof::latestFor($orderId);
            if ($proof && $proof['status'] === 'pending') {
                ShopPaymentProof::markVerified((int) $proof['id'], (int) $actorId);
            }
        }
        return $ok;
    }

    /** §0.5 revert row: paid → payment_review, admin only, only before packing. */
    public static function revertPaidToReview(int $orderId, int $adminId, string $reason): bool
    {
        $order = self::find($orderId);
        if (!$order || $order['status'] !== 'paid') return false;
        self::assertNotSelfReview($order);
        return self::transition($orderId, 'payment_review', 'admin', $adminId, [
            'reason_code' => 'paid_reverted',
            'note'        => $reason,
        ]);
    }

    // ── Commerce: fulfilment legs (T9–T18, T24) ───────────────────────────────

    /** T9: paid → packing (the address is already snapshotted). */
    public static function startPacking(int $orderId, int $adminId): bool
    {
        $order = self::find($orderId);
        if (!$order) return false;
        self::assertNotSelfReview($order);
        return self::transition($orderId, 'packing', 'admin', $adminId, [
            'reason_code' => 'packing_started',
        ]);
    }

    /** T10: packing → ready_to_ship. Guard: every line still present. */
    public static function finishPacking(int $orderId, int $adminId): bool
    {
        $order = self::find($orderId);
        if (!$order) return false;
        self::assertNotSelfReview($order);
        return self::transition($orderId, 'ready_to_ship', 'admin', $adminId, [
            'reason_code' => 'packed',
            'guards'      => [
                function () use ($orderId) {
                    $st = db()->prepare("SELECT COUNT(*) FROM shop_order_items WHERE order_id = ?");
                    $st->execute([$orderId]);
                    return (int) $st->fetchColumn() > 0;
                },
            ],
        ]);
    }

    /**
     * T13: courier handoff — the ONE stock-deduction moment. Guard: courier
     * + tracking set. The shipment row is upserted first (idempotent by
     * order+seq); handoff_at is stamped inside the transition's transaction,
     * and the per-line conditional deduction lives in applySideEffects.
     */
    public static function ship(int $orderId, int $adminId, string $courier, string $tracking, ?string $expectedAt = null): bool
    {
        $order = self::find($orderId);
        if (!$order) return false;
        self::assertNotSelfReview($order);
        if (trim($courier) === '' || trim($tracking) === '') return false;

        $shipmentId = ShopShipment::upsertTracking($orderId, $courier, $tracking, $expectedAt);

        $ok = self::transition($orderId, 'shipped', 'admin', $adminId, [
            'reason_code' => 'handed_to_courier',
            'note'        => $courier . ' · ' . $tracking,
        ]);
        if (!$ok && $shipmentId) {
            // Handoff refused — the freshly opened draft shipment must not
            // linger as the active one for a still-packed order.
            ShopShipment::close($shipmentId, 'void');
        }
        return $ok;
    }

    /** T14: shipped/delivery_failed → out_for_delivery. */
    public static function markOutForDelivery(int $orderId, string $actorType, ?int $actorId): bool
    {
        $order = self::find($orderId);
        if (!$order) return false;
        // Retry leg only (dispatching after failures): the attempt cap holds
        // — at shop_max_delivery_attempts the parcel must go returned-to-
        // sender or be delivered, never re-dispatched (matrix §0.4).
        if ($order['status'] === 'delivery_failed') {
            $s = ShopShipment::activeFor($orderId);
            $max = max(1, (int) setting('shop_max_delivery_attempts', '3'));
            if ($s && (int) $s['attempts'] >= $max) {
                return false;
            }
        }
        $ok = self::transition($orderId, 'out_for_delivery', $actorType, $actorId, [
            'source' => $actorType === 'system' ? 'timer' : 'ui',
        ]);
        if ($ok) {
            $s = ShopShipment::activeFor($orderId);
            if ($s) ShopShipment::touch((int) $s['id'], ['out_for_delivery_at' => date('Y-m-d H:i:s')]);
        }
        return $ok;
    }

    /**
     * T16: an attempt failed. Guard: fault ∈ {customer, carrier, shop} +
     * reason. Increments attempts; at the cap the response deadline opens.
     */
    public static function markDeliveryFailed(int $orderId, int $adminId, string $fault, string $failReason): bool
    {
        $order = self::find($orderId);
        if (!$order) return false;
        self::assertNotSelfReview($order);
        if (!in_array($fault, ['customer', 'carrier', 'shop'], true) || trim($failReason) === '') {
            return false;
        }

        $s = ShopShipment::activeFor($orderId);
        if (!$s) return false;
        $max = max(1, (int) setting('shop_max_delivery_attempts', '3'));
        if ((int) $s['attempts'] >= $max) {
            return false; // cap reached: only RTS or delivered remain
        }
        $attempts = (int) $s['attempts'] + 1;

        // Transition first — the shipment attempt/deadline writes below are
        // separate autocommit statements, so they must only run once the
        // status change is actually accepted (a refused transition must not
        // leave a phantom attempt behind).
        $ok = self::transition($orderId, 'delivery_failed', 'admin', $adminId, [
            'reason_code' => 'delivery_attempt_failed',
            'note'        => "{$fault}: {$failReason} (attempt {$attempts}/{$max})",
        ]);
        if ($ok) {
            ShopShipment::recordAttempt((int) $s['id'], $fault, $failReason);
            $extra = [];
            if ($attempts >= $max) {
                $extra['response_deadline'] = date('Y-m-d H:i:s', time() + self::RTS_RESPONSE_DAYS * 86400);
            }
            ShopShipment::touch((int) $s['id'], $extra);
        }
        return $ok;
    }

    /** T18: parcel returning / back at shop; closes the active shipment. */
    public static function returnToSender(int $orderId, string $actorType, ?int $actorId, string $reason = ''): bool
    {
        $ok = self::transition($orderId, 'returned_to_sender', $actorType, $actorId, [
            'reason_code' => 'returned_to_sender',
            'note'        => $reason,
            'source'      => $actorType === 'system' ? 'timer' : 'ui',
        ]);
        if ($ok) {
            $s = ShopShipment::activeFor($orderId);
            if ($s) {
                ShopShipment::close((int) $s['id']);
                ShopShipment::touch((int) $s['id'], [
                    'response_deadline' => date('Y-m-d H:i:s', time() + self::RTS_RESPONSE_DAYS * 86400),
                ]);
            }
        }
        return $ok;
    }

    /**
     * T15: proof of delivery stored; report window opens (completion_due_at
     * is stamped inside the transition's side effects).
     */
    public static function markDelivered(int $orderId, int $adminId, ?string $podReceiver, ?string $podImage): bool
    {
        $order = self::find($orderId);
        if (!$order) return false;
        self::assertNotSelfReview($order);
        if (($podReceiver === null || trim($podReceiver) === '') && ($podImage === null || $podImage === '')) {
            // POD required (S27). A missing input is a validation error, not a
            // lost race — throw so the caller surfaces the real reason instead
            // of the generic "order just changed" message reserved for false.
            throw new InvalidArgumentException('Proof of delivery required — enter who received the order or attach a POD photo.');
        }

        // POD facts live on shop_shipments (§2.3), written by applySideEffects
        // inside the transition's transaction - the order row itself carries no
        // POD columns (M4, Phase 6.2).
        return self::transition($orderId, 'delivered', 'admin', $adminId, [
            'reason_code' => 'delivered',
            'note'        => $podReceiver ? "Received by {$podReceiver}" : 'POD photo stored',
            'pod'         => [
                'pod_receiver'      => $podReceiver,
                'pod_image'         => $podImage,
                'delivered_at'      => date('Y-m-d H:i:s'),
                'report_window_end' => date('Y-m-d H:i:s', time() + max(1, (int) setting('shop_report_window_days', '5')) * 86400),
            ],
        ]);
    }

    /**
     * Reship (§0.4 / §2.3): from returned_to_sender only, one reship max in
     * v1. Goods are reused — stock is NEVER re-added, lines go back to
     * reserved against unchanged stock, no fee, no payment record.
     */
    public static function reship(int $orderId, int $adminId): bool
    {
        $order = self::find($orderId);
        if (!$order || $order['status'] !== 'returned_to_sender') return false;
        self::assertNotSelfReview($order);

        $seq = ShopShipment::nextSeq($orderId);
        if ($seq > 2) return false; // v1: one reship

        $old = ShopShipment::activeFor($orderId);
        if ($old) ShopShipment::close((int) $old['id']);

        // Link to the previous shipment — normally the one just closed above,
        // but RTS has usually closed it already, so fall back to the latest
        // row regardless of state.
        $link = $old ?? ShopShipment::latestFor($orderId);
        ShopShipment::create($orderId, ['seq' => $seq, 'reship_of' => $link['id'] ?? null]);

        return self::transition($orderId, 'packing', 'admin', $adminId, [
            'reason_code' => 'reshipped',
            'note'        => 'Reshipment #' . $seq . ' — shop absorbs the cost, no fee charged',
        ]);
    }

    /**
     * T6: customer cancels own order (owner only, matrix-checked). Admin/
     * system cancels go through cancel().
     */
    public static function cancelByCustomer(int $orderId, int $memberId, string $note = ''): bool
    {
        return self::cancel($orderId, 'customer', $memberId, 'customer_request', $note);
    }

    /** T24: buyer confirms receipt — terminal; an open refund blocks it. */
    public static function confirmReceipt(int $orderId, int $memberId): bool
    {
        $order = self::find($orderId);
        if (!$order || (int) $order['member_id'] !== $memberId) return false;
        if ($order['status'] !== 'delivered') return false;
        if (ShopRefund::hasOpen($orderId)) return false;

        return self::transition($orderId, 'completed', 'customer', $memberId, [
            'reason_code' => 'receipt_confirmed',
        ]);
    }

    // ── Customer / system operations ─────────────────────────────────────────

    /**
     * Cancel: customer (owner only — verified here so a forged id cannot cancel
     * someone else's order even though the route also checks it), or the system
     * timer, or an admin. Releases every non-deducted line so availability
     * recovers immediately (§0.3).
     */
    public static function cancel(
        int $orderId,
        string $actorType,
        ?int $actorId,
        string $reasonCode,
        string $note = ''
    ): bool {
        if ($actorType === 'customer') {
            $order = self::find($orderId);
            if (!$order || (int) $order['member_id'] !== (int) $actorId) {
                return false;
            }
        } elseif ($actorType === 'admin') {
            // An admin may cancel anyone's order EXCEPT their own: cancelling
            // your own paid order is the first step of refunding yourself.
            $order = self::find($orderId);
            if (!$order) return false;
            self::assertNotSelfReview($order);
        }

        return self::transition($orderId, 'cancelled', $actorType, $actorId, [
            'reason_code' => $reasonCode,
            'note'        => $note,
            'source'      => $actorType === 'system' ? 'timer' : 'ui',
            'extra'       => ['cancelled_reason' => mb_substr($reasonCode . ($note !== '' ? ": {$note}" : ''), 0, 160)],
        ]);
    }

    /**
     * The deadline that applies to an order in its CURRENT status.
     *
     *   pending          -> payment_deadline    (the window to pay in)
     *   payment_failed   -> correction_deadline (the window to fix and re-upload)
     *
     * payment_deadline is deliberately never cleared when a proof is rejected,
     * because payment_review is exempt from expiry and the original stamp is
     * part of the audit trail. That is exactly why a plain
     * COALESCE(payment_deadline, correction_deadline) is wrong: payment_deadline
     * is ALWAYS set, so COALESCE always returned it and the correction window
     * was never read. A buyer whose proof was rejected near the payment
     * deadline lost most of the 24h correction window the code had just opened
     * for them (defect H3, tmp/shop/remedy.md).
     */
    private const EXPIRY_DEADLINE_SQL =
        "COALESCE(CASE WHEN status = 'payment_failed' THEN correction_deadline END, payment_deadline)";

    /**
     * Timer leg 1 (cron + admin_shop_expire): cancel pending/payment_failed
     * orders whose applicable deadline has passed. NEVER touches
     * payment_review (§0.5 T7) — a submitted proof is exempt from expiry. Every
     * candidate goes through the conditional transition, so the proof-vs-expiry
     * race has one winner.
     */
    public static function expireOverdue(int $limit = 200): int
    {
        $deadline = self::EXPIRY_DEADLINE_SQL;
        $st = db()->prepare(
            "SELECT id FROM shop_orders
              WHERE status IN ('pending','payment_failed')
                AND {$deadline} < NOW()
                AND {$deadline} IS NOT NULL
              ORDER BY {$deadline} ASC
              LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();

        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                if (self::cancel((int) $id, 'system', null, 'payment_expired')) {
                    $n++;
                }
            } catch (Throwable $e) {
                // A candidate that races a proof upload simply loses; the next
                // cron pass re-evaluates. Never abort the whole sweep.
                continue;
            }
        }
        return $n;
    }

    /**
     * Timer leg 2: auto-complete delivered orders whose report window closed,
     * unless a refund is still open (§0.5 T24; open refunds block completion).
     */
    public static function autoComplete(int $limit = 200): int
    {
        $st = db()->prepare(
            "SELECT o.id
               FROM shop_orders o
              WHERE o.status = 'delivered'
                AND o.completion_due_at < NOW()
                AND NOT EXISTS (
                    SELECT 1 FROM shop_refunds r
                     WHERE r.order_id = o.id AND r.status IN ('open','approved')
                )
              LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();

        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                if (self::transition((int) $id, 'completed', 'system', null, ['source' => 'timer'])) {
                    $n++;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return $n;
    }
}
