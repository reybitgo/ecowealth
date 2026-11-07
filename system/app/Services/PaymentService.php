<?php
declare(strict_types=1);

/* ==============================================================
 *  PaymentService — manual payments and the order lifecycle
 *  (plan §5.4, §4.4, §7 Phase 7)
 *
 *  The member uploads a proof, an admin verifies or rejects it, and a
 *  timer cancels orders whose payment window closed. Every path is
 *  one transaction that locks the order row, so a proof cannot race
 *  the expiry job and two admins cannot both verify the same order.
 *
 *      pending ──(proof)──▶ payment_review ──(verify)──▶ paid
 *      payment_review ──(reject)──▶ payment_failed ──(re-upload)──▶ …
 *      pending / payment_failed ──(deadline)──▶ cancelled
 *
 *  Rules enforced here (P7-T1 … P7-T12):
 *    • ownership — a member only ever touches their own order
 *    • window — status, the applicable deadline and the attempt cap
 *    • evidence — JPG/PNG/PDF ≤ 5 MB, random name, never a public URL
 *    • verification — exact amount + a reference used by no other order
 *    • segregation — an admin cannot verify/reject/cancel their own order
 *    • stock — rejection and cancellation release the reservation;
 *      verification commits it and a corrected proof takes it again
 *      (refusing when the stock has sold out meanwhile)
 *    • money — a wallet-paid order cancelled by an admin is refunded to
 *      the same buckets it was spent from
 * ============================================================== */

final class PaymentService
{
    /** Why an admin may reject a proof (the UI offers these labels). */
    public const REJECT_REASONS = [
        'unclear_proof'      => 'The proof is unclear or unreadable',
        'wrong_amount'       => 'The amount received does not match the order total',
        'reference_mismatch' => 'The reference number does not match our records',
        'not_received'       => 'The payment was not received',
        'duplicate_reference' => 'The reference was already used on another order',
        'other'              => 'Other — explain in the note',
    ];

    /** Why an admin may cancel an order. */
    public const CANCEL_REASONS = [
        'admin_cancel'    => 'Cancelled by the administrator',
        'customer_request' => 'The member asked to cancel',
        'out_of_stock'    => 'The item is out of stock',
        'fraud_suspected' => 'Suspected fraudulent payment',
        'other'           => 'Other — explain in the note',
    ];

    /** Correction deadline fallback when a rejected proof has no window set. */
    private const FALLBACK_CORRECTION_HOURS = 24;

    private static int $savepointSeq = 0;

    private PDO $pdo;
    private WalletService $wallet;
    private InvoiceService $invoices;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo      = $pdo ?? db();
        $this->wallet   = new WalletService($this->pdo);
        $this->invoices = new InvoiceService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  Configuration
     * ---------------------------------------------------------- */

    /** Attempts allowed per order (`shop_max_proof_attempts`). */
    public static function maxAttempts(): int
    {
        return max(1, min(10, setting_int('shop_max_proof_attempts', 3)));
    }

    /** Hours a member has to pay a manual order. */
    public static function paymentDeadlineHours(): int
    {
        return max(1, min(720, setting_int('shop_payment_deadline_hours', 24)));
    }

    /** Hours a member has to fix a rejected payment. */
    public static function correctionWindowHours(): int
    {
        $hours = setting_int('shop_correction_window_hours', 0);

        return $hours > 0 ? max(1, min(720, $hours)) : self::FALLBACK_CORRECTION_HOURS;
    }

    /* ----------------------------------------------------------
     *  The proof window
     * ---------------------------------------------------------- */

    /**
     * Can this order accept a payment proof right now?
     *
     * The deadline that applies depends on the status (plan §4.4):
     *   pending        → payment_deadline     (the window to pay in)
     *   payment_failed → correction_deadline  (the window to fix it)
     *
     * @return array{
     *   open: bool,
     *   reason: string,
     *   deadline: ?string,
     *   used: int,
     *   left: int,
     *   max: int
     * }
     */
    public function windowFor(array $order, ?PDO $pdo = null): array
    {
        $status = (string) ($order['status'] ?? '');
        $max    = self::maxAttempts();
        $used   = PaymentProof::countFor((int) ($order['id'] ?? 0), $pdo ?? $this->pdo);
        $deadline = $this->applicableDeadline($order);

        $window = [
            'open'     => false,
            'reason'   => 'status',
            'deadline' => $deadline,
            'used'     => $used,
            'left'     => max(0, $max - $used),
            'max'      => $max,
        ];

        if ($status === 'payment_review') {
            $window['reason'] = 'review';

            return $window;
        }

        if (!in_array($status, Order::PROOF_SUBMITTABLE, true)) {
            return $window;
        }

        if ($deadline === null || strtotime($deadline) === false || strtotime($deadline) < time()) {
            $window['reason'] = 'expired';

            return $window;
        }

        if ($used >= $max) {
            $window['reason'] = 'attempts';

            return $window;
        }

        $window['open']   = true;
        $window['reason'] = 'open';

        return $window;
    }

    /**
     * The deadline that gates this order's payment window.
     * `pending` reads `payment_deadline`; `payment_failed` reads the
     * correction window (falling back to the payment deadline only when
     * an older row has no correction stamp at all).
     */
    public function applicableDeadline(array $order): ?string
    {
        $status = (string) ($order['status'] ?? '');

        $deadline = $status === 'payment_failed'
            ? ($order['correction_deadline'] ?? null)
            : ($order['payment_deadline'] ?? null);

        if (($deadline === null || $deadline === '') && $status === 'payment_failed') {
            $deadline = $order['payment_deadline'] ?? null;
        }

        if ($deadline === null || trim((string) $deadline) === '') {
            return null;
        }

        return (string) $deadline;
    }

    /* ----------------------------------------------------------
     *  Claim validation
     * ---------------------------------------------------------- */

    /**
     * Validate the member's claim (P7-T2 field errors).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validateClaim(array $input): array
    {
        $errors = Validator::check($input, [
            'amount_sent'   => 'required|numeric|decimal:2|gt:0',
            'reference_no'  => 'required|string|min:4|max:40|regex:/^[A-Za-z0-9][A-Za-z0-9 _.\-]{3,39}$/',
            'transfer_date' => 'nullable|date',
        ], [
            'amount_sent'   => 'Amount sent',
            'reference_no'  => 'Reference number',
            'transfer_date' => 'Transfer date',
        ]);

        /* The transfer cannot have happened tomorrow: a future date is
         * almost always a typo and makes the proof impossible to match. */
        $date = trim((string) ($input['transfer_date'] ?? ''));

        if ($date !== '' && !isset($errors['transfer_date'])) {
            $timestamp = strtotime($date);

            if ($timestamp !== false && $timestamp > strtotime(date('Y-m-d'))) {
                $errors['transfer_date'] = 'The transfer date cannot be in the future.';
            }
        }

        return $errors;
    }

    /**
     * Validate the admin's verification input (P7-T4).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validateVerification(array $input): array
    {
        return Validator::check($input, [
            'amount_received'   => 'required|numeric|decimal:2|gt:0',
            'payment_reference' => 'required|string|min:4|max:40|regex:/^[A-Za-z0-9][A-Za-z0-9 _.\-]{3,39}$/',
            'note'              => 'nullable|string|max:200',
        ], [
            'amount_received'   => 'Amount received',
            'payment_reference' => 'Payment reference',
            'note'              => 'Note',
        ]);
    }

    /* ----------------------------------------------------------
     *  Member — submit a proof
     * ---------------------------------------------------------- */

    /**
     * Store a proof and move the order to `payment_review` (P7-T1).
     *
     * The file is written before the transaction starts (an upload must
     * not hold a row lock) and removed again when the transaction is
     * refused, so a rejected attempt leaves no orphan file.
     *
     * @param array<string, mixed> $file One entry from $_FILES
     * @param array<string, mixed> $claim amount_sent / reference_no / transfer_date
     * @return array{proof_id:int, attempt:int, status:string}
     */
    public function submitProof(int $userId, int $orderId, array $file, array $claim): array
    {
        $order = Order::findForUser($orderId, $userId, $this->pdo);

        if ($order === null) {
            throw PaymentException::notFound();
        }

        $window = $this->windowFor($order);

        if (!$window['open']) {
            throw PaymentException::window($window);
        }

        $errors = self::validateClaim($claim);

        /* A malformed claim never touches the disk: the file is stored
         * only once the claim itself is acceptable. */
        if ($errors !== []) {
            throw PaymentException::validation($errors);
        }

        $upload = Upload::store($file, 'proofs');

        if (!$upload['ok']) {
            throw PaymentException::validation([
                'proof_image' => (string) ($upload['error'] ?? 'Please attach the payment proof.'),
            ]);
        }

        $path = (string) $upload['path'];
        [$mime, $size] = $this->fileFacts($path);

        try {
            return $this->withTransaction(function () use ($userId, $orderId, $path, $mime, $size, $claim): array {
                /* Re-read under the lock: the expiry job (or a second
                 * tab) may have changed the order since the pre-flight. */
                $order = $this->lockOrder($orderId);

                if ($order === null || (int) $order['user_id'] !== $userId) {
                    throw PaymentException::notFound();
                }

                $window = $this->windowFor($order);

                if (!$window['open']) {
                    throw PaymentException::window($window);
                }

                /* A rejected payment released the hold; a corrected proof
                 * takes it again — but only while the stock is still there
                 * (P7-T6, plan §5.1). */
                $this->reserveAgain($orderId);

                $attempt = $window['used'] + 1;

                $proofId = PaymentProof::create($orderId, [
                    'file_path'      => $path,
                    'mime'           => $mime,
                    'size_bytes'     => $size,
                    'amount_claimed' => round((float) ($claim['amount_sent'] ?? 0), 2),
                    'reference_no'   => trim((string) ($claim['reference_no'] ?? '')),
                    'transfer_date'  => trim((string) ($claim['transfer_date'] ?? '')) ?: null,
                ], $this->pdo);

                /* The order moves to review. The payment deadline stamp is
                 * kept for the audit trail (payment_review never expires);
                 * a leftover correction window is cleared. */
                $this->pdo->prepare(
                    "UPDATE shop_orders
                        SET status = 'payment_review', correction_deadline = NULL
                      WHERE id = ?"
                )->execute([$orderId]);

                OrderEvent::log(
                    $orderId,
                    (string) $order['status'],
                    'payment_review',
                    'member',
                    $userId,
                    'proof_submitted',
                    sprintf(
                        'Proof #%d · attempt %d of %d · %s · ref %s',
                        $proofId,
                        $attempt,
                        $window['max'],
                        money((float) ($claim['amount_sent'] ?? 0)),
                        (string) ($claim['reference_no'] ?? '')
                    ),
                    $this->pdo
                );

                /* The order desk now has a proof to review. */
                Live::touchOrder($orderId);

                return ['proof_id' => $proofId, 'attempt' => $attempt, 'status' => 'payment_review'];
            });
        } catch (Throwable $e) {
            /* The transaction rolled back: the stored file has no row. */
            UploadService::remove($path);

            throw $e;
        }
    }

    /* ----------------------------------------------------------
     *  Admin — verify / reject / cancel
     * ---------------------------------------------------------- */

    /**
     * Verify a payment and commit the reservation (P7-T4).
     *
     * Guards: the order is in `payment_review`, a pending proof exists,
     * the amount matches the order total exactly, and the reference has
     * not been used on another order.
     *
     * @param array<string, mixed> $input amount_received / payment_reference / note
     * @return array{order_id:int, status:string, total:float}
     */
    public function verify(int $adminId, int $orderId, array $input): array
    {
        $order = Order::find($orderId, $this->pdo);

        if ($order === null) {
            throw PaymentException::notFound();
        }

        $this->assertNotSelfReview($adminId, $order);

        $errors = self::validateVerification($input);

        if ($errors !== []) {
            throw PaymentException::validation($errors);
        }

        $amount    = round((float) $input['amount_received'], 2);
        $reference = trim((string) $input['payment_reference']);
        $note      = trim((string) ($input['note'] ?? ''));

        return $this->withTransaction(function () use ($adminId, $orderId, $amount, $reference, $note): array {
            $order = $this->lockOrder($orderId);

            if ($order === null) {
                throw PaymentException::notFound();
            }

            if ((string) $order['status'] !== 'payment_review') {
                throw PaymentException::status('verified', (string) $order['status']);
            }

            $proof   = PaymentProof::latestFor($orderId, $this->pdo);
            $total   = round((float) $order['total'], 2);

            if ($proof === null || (string) $proof['status'] !== 'pending') {
                throw PaymentException::proof();
            }

            if (abs($amount - $total) >= 0.005) {
                throw PaymentException::amount($amount, $total);
            }

            if (PaymentProof::referenceTakenByAnotherOrder($reference, $orderId, $this->pdo)) {
                throw PaymentException::reference($reference);
            }

            PaymentProof::markVerified((int) $proof['id'], $adminId, $this->pdo);

            $this->pdo->prepare(
                "UPDATE shop_orders
                    SET status = 'paid', paid_at = NOW(), payment_reference = ?, correction_deadline = NULL
                  WHERE id = ?"
            )->execute([$reference, $orderId]);

            /* Payment confirmed: the hold becomes a committed reservation
             * (the stock itself is deducted when the order ships, Phase 8). */
            $this->pdo->prepare(
                "UPDATE shop_order_items
                    SET reservation_state = 'committed'
                  WHERE order_id = ? AND reservation_state = 'reserved'"
            )->execute([$orderId]);

            OrderEvent::log(
                $orderId,
                'payment_review',
                'paid',
                'admin',
                $adminId,
                'payment_verified',
                sprintf('Received %s · ref %s%s', money($amount), $reference, $note === '' ? '' : ' · ' . $note),
                $this->pdo
            );

            /* The invoice is frozen with the verification (plan §5.7). */
            $this->invoices->issueForPaidOrder($orderId, $this->pdo);

            Live::touch('admin.products');
            Live::touchOrder($orderId);

            return ['order_id' => $orderId, 'status' => 'paid', 'total' => $total];
        });
    }

    /**
     * Reject the pending proof, release the stock and open the
     * correction window (P7-T5).
     *
     * @return array{order_id:int, status:string, reason:string}
     */
    public function reject(int $adminId, int $orderId, string $reasonCode, string $note = ''): array
    {
        $order = Order::find($orderId, $this->pdo);

        if ($order === null) {
            throw PaymentException::notFound();
        }

        $this->assertNotSelfReview($adminId, $order);

        $reasonCode = trim($reasonCode) === '' ? 'unclear_proof' : trim($reasonCode);

        if (!array_key_exists($reasonCode, self::REJECT_REASONS)) {
            $reasonCode = 'other';
        }

        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? $note), 0, 500);

        if ($reasonCode === 'other' && $note === '') {
            throw PaymentException::reason('reject');
        }

        $reasonText = self::REJECT_REASONS[$reasonCode] . ($note === '' ? '' : ' — ' . $note);

        return $this->withTransaction(function () use ($adminId, $orderId, $reasonCode, $reasonText): array {
            $order = $this->lockOrder($orderId);

            if ($order === null) {
                throw PaymentException::notFound();
            }

            if ((string) $order['status'] !== 'payment_review') {
                throw PaymentException::status('rejected', (string) $order['status']);
            }

            $proof = PaymentProof::latestFor($orderId, $this->pdo);

            if ($proof === null || (string) $proof['status'] !== 'pending') {
                throw PaymentException::proof();
            }

            PaymentProof::markRejected((int) $proof['id'], $adminId, $reasonText, $this->pdo);

            $this->pdo->prepare(
                "UPDATE shop_orders
                    SET status = 'payment_failed', correction_deadline = ?
                  WHERE id = ?"
            )->execute([
                date('Y-m-d H:i:s', time() + self::correctionWindowHours() * 3600),
                $orderId,
            ]);

            /* The hold goes back to the shelf while the member corrects
             * the payment; a re-upload reserves it again (P7-T6). */
            $this->releaseReservation($orderId);

            OrderEvent::log(
                $orderId,
                'payment_review',
                'payment_failed',
                'admin',
                $adminId,
                'payment_rejected',
                sprintf('Attempt %d rejected · %s', (int) $proof['attempt_no'], $reasonText),
                $this->pdo
            );

            Live::touchOrder($orderId);

            return ['order_id' => $orderId, 'status' => 'payment_failed', 'reason' => $reasonCode];
        });
    }

    /**
     * Cancel an order before it ships (P7-T12).
     *
     * Releases the reservation, rejects a pending proof so nothing is
     * left "awaiting review", and refunds a wallet-paid order to the
     * same buckets the purchase spent from.
     *
     * @return array{order_id:int, status:string, refunded:float, reason:string}
     */
    public function cancelByAdmin(int $adminId, int $orderId, string $reasonCode, string $note = ''): array
    {
        $order = Order::find($orderId, $this->pdo);

        if ($order === null) {
            throw PaymentException::notFound();
        }

        $this->assertNotSelfReview($adminId, $order);

        $reasonCode = trim($reasonCode) === '' ? 'admin_cancel' : trim($reasonCode);

        if (!array_key_exists($reasonCode, self::CANCEL_REASONS)) {
            $reasonCode = 'other';
        }

        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? $note), 0, 300);

        if ($reasonCode === 'other' && $note === '') {
            throw PaymentException::reason('cancel');
        }

        return $this->withTransaction(function () use ($adminId, $orderId, $reasonCode, $note): array {
            $order = $this->lockOrder($orderId);

            if ($order === null) {
                throw PaymentException::notFound();
            }

            $status = (string) $order['status'];

            if (!in_array($status, Order::ADMIN_CANCELLABLE, true)) {
                throw PaymentException::status('cancelled', $status);
            }

            $reasonText = self::CANCEL_REASONS[$reasonCode] . ($note === '' ? '' : ' — ' . $note);

            /* A proof still waiting for review must not stay pending on a
             * cancelled order. */
            $proof = PaymentProof::latestFor($orderId, $this->pdo);

            if ($proof !== null && (string) $proof['status'] === 'pending') {
                PaymentProof::markRejected((int) $proof['id'], $adminId, 'Order cancelled — ' . $reasonText, $this->pdo);
            }

            $refunded = in_array($status, Order::REFUNDABLE, true)
                ? $this->refundWalletDebit($order)
                : 0.0;

            /* A paid order had an invoice; cancelling reverses the sale,
             * so the document is voided (its content stays frozen). */
            $this->invoices->voidForOrder($orderId, 'Order cancelled — ' . $reasonText, $this->pdo);

            $this->pdo->prepare(
                "UPDATE shop_orders
                    SET status = 'cancelled', cancelled_at = NOW(), cancelled_reason = ?
                  WHERE id = ?"
            )->execute([mb_substr($reasonText, 0, 160), $orderId]);

            $this->releaseReservation($orderId);

            OrderEvent::log(
                $orderId,
                $status,
                'cancelled',
                'admin',
                $adminId,
                $reasonCode,
                $reasonText . ($refunded > 0 ? ' · ' . money($refunded) . ' refunded to the e-wallet' : ''),
                $this->pdo
            );

            Live::touchOrder($orderId);

            return ['order_id' => $orderId, 'status' => 'cancelled', 'refunded' => $refunded, 'reason' => $reasonCode];
        });
    }

    /* ----------------------------------------------------------
     *  System — the expiry pass (bin/shop_expire.php)
     * ---------------------------------------------------------- */

    /**
     * Is automatic expiry switched on? (`shop_expiry_enabled`, default on)
     *
     * The admin settings page exposes the switch; the sweep itself
     * checks it, so every caller (CLI, the web cron entry, a future
     * admin button) obeys it without repeating the rule.
     */
    public static function expiryEnabled(): bool
    {
        return setting_bool('shop_expiry_enabled', true);
    }

    /**
     * The order ids whose payment / correction window has closed.
     * Read-only: the CLI's `--dry-run` and the tests use it too.
     *
     * `$fromSettings` recomputes the window from the *current* admin
     * settings instead of the stamp stored on the order:
     *   pending        → created_at        + shop_payment_deadline_hours
     *   payment_failed → last rejection at + shop_correction_window_hours
     * and falls back to the stored correction/payment deadline when a
     * rejected order has no event row. The default (stamps) is what the
     * cron uses; the settings mode exists for QA and "what-if" checks.
     *
     * @return list<int>
     */
    public function overdueOrderIds(int $limit = 200, bool $fromSettings = false): array
    {
        $limit = max(1, min(1000, $limit));

        if ($fromSettings) {
            $deadlineHours   = self::paymentDeadlineHours();
            $correctionHours = self::correctionWindowHours();

            $sql = "SELECT o.id
                      FROM shop_orders o
                     WHERE (
                             (o.status = 'pending'
                               AND o.created_at < DATE_SUB(NOW(), INTERVAL {$deadlineHours} HOUR))
                          OR (o.status = 'payment_failed'
                               AND COALESCE(
                                     (SELECT MAX(e.created_at)
                                        FROM shop_order_events e
                                       WHERE e.order_id = o.id
                                         AND e.reason_code = 'payment_rejected'),
                                     o.correction_deadline,
                                     o.payment_deadline
                                   ) < DATE_SUB(NOW(), INTERVAL {$correctionHours} HOUR))
                           )
                     ORDER BY o.id ASC
                     LIMIT {$limit}";

            return array_map('intval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        }

        $deadline = "COALESCE(CASE WHEN status = 'payment_failed' THEN correction_deadline END, payment_deadline)";

        $sql = "SELECT id FROM shop_orders
                 WHERE status IN ('pending', 'payment_failed')
                   AND {$deadline} IS NOT NULL
                   AND {$deadline} < NOW()
                 ORDER BY {$deadline} ASC
                 LIMIT " . $limit;

        return array_map('intval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Cancel `pending` / `payment_failed` orders past their applicable
     * deadline (P7-T7). A submitted proof (`payment_review`) is exempt.
     *
     * Idempotent: it only moves orders that are still open, so running
     * it twice changes nothing the second time. A candidate that races a
     * proof upload simply loses that round; the next pass re-reads it.
     *
     * `$fromSettings` recomputes each candidate's deadline under the row
     * lock (see overdueOrderIds()); the default enforces the stored stamp.
     *
     * @return array{expired:int, ids:list<int>, candidates:int, skipped:?string, mode:string}
     */
    public function expireOverdue(int $limit = 200, bool $fromSettings = false): array
    {
        $mode = $fromSettings ? 'settings' : 'stamps';

        if (!self::expiryEnabled()) {
            return [
                'expired'    => 0,
                'ids'        => [],
                'candidates' => 0,
                'skipped'    => 'disabled',
                'mode'       => $mode,
            ];
        }

        $ids = $this->overdueOrderIds($limit, $fromSettings);

        $expired = [];

        foreach ($ids as $orderId) {
            try {
                $done = $this->withTransaction(function () use ($orderId, $fromSettings): ?int {
                    /* The lock + status re-check is the race guard: if the
                     * member just uploaded a proof, this order is skipped. */
                    $order = $this->lockOrder($orderId);

                    if ($order === null) {
                        return null;
                    }

                    $status = (string) $order['status'];

                    if (!in_array($status, ['pending', 'payment_failed'], true)) {
                        return null;
                    }

                    $deadline = $fromSettings
                        ? $this->recomputedDeadline($order)
                        : $this->applicableDeadline($order);

                    if ($deadline === null || strtotime($deadline) === false || strtotime($deadline) >= time()) {
                        return null;
                    }

                    $this->pdo->prepare(
                        "UPDATE shop_orders
                            SET status = 'cancelled', cancelled_at = NOW(), cancelled_reason = ?
                          WHERE id = ?"
                    )->execute(['Payment window expired', $orderId]);

                    $this->releaseReservation($orderId);

                    OrderEvent::log(
                        $orderId,
                        $status,
                        'cancelled',
                        'system',
                        null,
                        'payment_expired',
                        'Cancelled automatically: the payment window closed on '
                            . format_datetime($deadline),
                        $this->pdo
                    );

                    Live::touchOrder($orderId);

                    return $orderId;
                });

                if ($done !== null) {
                    $expired[] = $done;
                }
            } catch (Throwable $e) {
                /* One bad candidate must never abort the sweep. */
                error_log(sprintf('[ecowealth] order #%d expiry failed: %s', $orderId, $e->getMessage()));
            }
        }

        return [
            'expired'    => count($expired),
            'ids'        => $expired,
            'candidates' => count($ids),
            'skipped'    => null,
            'mode'       => $mode,
        ];
    }

    /**
     * The deadline the *current* settings imply for this order
     * (`--from-settings` only). Null when the order has nothing to
     * measure from.
     */
    private function recomputedDeadline(array $order): ?string
    {
        $status = (string) ($order['status'] ?? '');

        if ($status === 'pending') {
            $createdAt = strtotime((string) ($order['created_at'] ?? ''));

            if ($createdAt === false) {
                return null;
            }

            return date('Y-m-d H:i:s', $createdAt + self::paymentDeadlineHours() * 3600);
        }

        if ($status === 'payment_failed') {
            $stmt = $this->pdo->prepare(
                "SELECT MAX(created_at) FROM shop_order_events
                  WHERE order_id = ? AND reason_code = 'payment_rejected'"
            );
            $stmt->execute([(int) ($order['id'] ?? 0)]);
            $rejectedAt = $stmt->fetchColumn();

            if ($rejectedAt !== false && $rejectedAt !== null && $rejectedAt !== '') {
                $base = strtotime((string) $rejectedAt);

                if ($base !== false) {
                    return date('Y-m-d H:i:s', $base + self::correctionWindowHours() * 3600);
                }
            }

            /* No event row (older data): the stored correction stamp is
             * already a deadline, so do not add the window twice. */
            return $this->applicableDeadline($order);
        }

        return null;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @return array<string, mixed>|null The order row, locked for update. */
    private function lockOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Release every held unit on the order (cancellation / rejection). */
    private function releaseReservation(int $orderId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE shop_order_items
                SET reservation_state = 'released'
              WHERE order_id = ? AND reservation_state IN ('reserved', 'committed')"
        );
        $stmt->execute([$orderId]);

        if ($stmt->rowCount() > 0) {
            Live::touch('admin.products');
        }
    }

    /**
     * Take the reservation again for a rejected order whose lines were
     * released (P7-T6, plan §5.1).
     *
     * The products are locked in ascending id order (the same order the
     * checkout uses, so the two cannot deadlock) and availability is
     * re-read as `stock − reserved`. If the stock went to another buyer
     * while the payment was being corrected, the upload is refused
     * rather than silently over-reserving the product.
     */
    private function reserveAgain(int $orderId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT product_id, SUM(quantity) AS qty
               FROM shop_order_items
              WHERE order_id = ? AND reservation_state = 'released'
              GROUP BY product_id
              ORDER BY product_id ASC"
        );
        $stmt->execute([$orderId]);
        $lines = $stmt->fetchAll();

        if ($lines === []) {
            return;
        }

        $productIds = array_map(static fn (array $line): int => (int) $line['product_id'], $lines);
        sort($productIds);
        $marks = implode(',', array_fill(0, count($productIds), '?'));

        $locked = $this->pdo->prepare(
            'SELECT id, name, stock FROM products WHERE id IN (' . $marks . ') ORDER BY id ASC FOR UPDATE'
        );
        $locked->execute($productIds);

        $products = [];
        foreach ($locked->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $quantity  = (int) $line['qty'];
            $product   = $products[$productId] ?? null;

            if ($product === null) {
                throw PaymentException::stock('A product on this order', $quantity, 0);
            }

            $available = max(0, (int) $product['stock'] - StockService::reserved($productId, $this->pdo));

            if ($quantity > $available) {
                throw PaymentException::stock((string) $product['name'], $quantity, $available);
            }
        }

        $stmt = $this->pdo->prepare(
            "UPDATE shop_order_items
                SET reservation_state = 'reserved'
              WHERE order_id = ? AND reservation_state = 'released'"
        );
        $stmt->execute([$orderId]);

        if ($stmt->rowCount() > 0) {
            Live::touch('admin.products');
        }
    }

    /** Segregation of duties (plan §3.7): never review your own order. */
    private function assertNotSelfReview(int $adminId, array $order): void
    {
        if ((int) ($order['user_id'] ?? 0) === $adminId) {
            error_log(sprintf('[ecowealth] self-review refused for order #%d by admin #%d', (int) $order['id'], $adminId));

            throw PaymentException::selfReview();
        }
    }

    /**
     * Refund a wallet-paid order and return the amount.
     *
     * The money goes back to the buckets the purchase took it from: the
     * withdrawable portion the original debit consumed is restored as
     * withdrawable, the rest stays spendable-only. A proof-paid order has
     * no wallet movement, so nothing is credited.
     */
    private function refundWalletDebit(array $order): float
    {
        $userId  = (int) $order['user_id'];
        $orderId = (int) $order['id'];
        $total   = round((float) $order['total'], 2);

        if ($userId <= 0 || $orderId <= 0 || $total <= 0) {
            return 0.0;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id FROM ewallet_ledger
              WHERE user_id = ? AND ref_type = 'shop_order' AND ref_id = ? AND type = 'debit'
              ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$userId, $orderId]);
        $ledgerId = $stmt->fetchColumn();

        if ($ledgerId === false) {
            return 0.0;
        }

        $withdrawablePortion = $this->withdrawableSpent($userId, (int) $ledgerId, $total);

        try {
            $this->wallet->refund(
                $userId,
                $total,
                $orderId,
                'Refund · order ' . (string) $order['order_no'] . ' cancelled',
                $withdrawablePortion
            );
        } catch (WalletException $e) {
            throw PaymentException::refund('The e-wallet refund failed: ' . $e->getMessage());
        }

        return $total;
    }

    /**
     * How much of a recorded debit came out of the withdrawable bucket.
     * The ledger stores both snapshots, so the previous row's
     * `withdrawable_after` is the balance before the debit.
     */
    private function withdrawableSpent(int $userId, int $ledgerId, float $amount): float
    {
        $previous = $this->pdo->prepare(
            'SELECT withdrawable_after FROM ewallet_ledger
              WHERE user_id = ? AND id < ? ORDER BY id DESC LIMIT 1'
        );
        $previous->execute([$userId, $ledgerId]);
        $before = $previous->fetchColumn();
        $before = $before === false ? 0.0 : round((float) $before, 2);

        $row = $this->pdo->prepare('SELECT withdrawable_after FROM ewallet_ledger WHERE id = ? LIMIT 1');
        $row->execute([$ledgerId]);
        $after = $row->fetchColumn();
        $after = $after === false ? $before : round((float) $after, 2);

        $spent = max(0.0, round($before - $after, 2));

        return min($amount, $spent);
    }

    /**
     * MIME type and size of a stored upload (read from the file, never
     * from the client's claim).
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function fileFacts(string $relativePath): array
    {
        $relative = str_replace('\\', '/', ltrim($relativePath, '/'));

        if (!str_starts_with($relative, 'uploads/')) {
            return [null, null];
        }

        $absolute = Upload::root() . substr($relative, strlen('uploads/'));

        if (!is_file($absolute)) {
            return [null, null];
        }

        $mime = null;

        if (class_exists('finfo')) {
            $value = (new finfo(FILEINFO_MIME_TYPE))->file($absolute);
            $mime  = is_string($value) ? strtolower($value) : null;
        }

        $size = filesize($absolute);

        return [$mime === null ? null : mb_substr($mime, 0, 40), $size === false ? null : (int) $size];
    }

    /**
     * One payment action, one transaction. Adopts a caller's transaction
     * with a savepoint so a refused action never poisons outer work.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'payment_sp_' . (++self::$savepointSeq);
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();

            if ($owns) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $e) {
            if ($owns) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } elseif ($savepoint !== null) {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    /* The outer transaction is already unusable. */
                }
            }

            throw $e;
        }
    }
}
