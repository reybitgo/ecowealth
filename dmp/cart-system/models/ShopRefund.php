<?php
class ShopRefund
{
    /**
 * Refund states that block delivered → completed (§0.5 T24).
 *
 * BOTH open AND approved block. An approved-but-unsettled refund is money
 * that has been promised to the buyer and not yet returned; completing the
 * order underneath it would hide that. This model previously counted only
 * status='open', so a refund sitting in 'approved' let the buyer confirm
 * receipt and let the auto-complete timer fire (defect M3). Both callers of
 * the old check — ShopOrder::canMemberAct() and ShopOrder::autoComplete() —
 * are corrected by changing this one list.
 */
public const BLOCKING_STATUSES = ['open', 'approved'];

/** Refund task lifecycle: open → approved → processed (closed only by a
     * receipt). denied covers both "denied" and a buyer's own cancellation.
     *
     * These five values are the DDL vocabulary (install.sql, shop_refunds.status).
     * The model previously wrote 'paid', which the ENUM does not accept —
     * MySQL rejected it with ERROR 1265 and the whole settlement failed
     * (defect C1, tmp/shop/remedy.md). Do not reintroduce a sixth value without
     * a migration; tests/SchemaDriftTest.php enforces the agreement.
     */
    public const STATUSES = ['open', 'approved', 'processed', 'denied', 'closed'];

    /**
     * shop_refunds.cause values. Kept in step with the ENUM in install.sql;
     * tests/SchemaDriftTest.php asserts the agreement.
     */
    public const CAUSES = ['admin_cancel', 'delivery_failure', 'customer_dispute', 'other'];

    /**
     * shop_refunds.method values — the payout route. Kept in step with the
     * ENUM in install.sql.
     *
     * This is the field that decides whether markPaid() credits the e-wallet.
     * It used to be inferred from the free-text `destination` column — a
     * substring test for the word "wallet" — which M6 removed precisely
     * because an unconstrained VARCHAR cannot carry that decision safely.
     *
     * Adding the parameter exposed a gap: the admin refund form collects
     * `destination` but never sent `method`, so every refund created from the
     * UI fell back to the column default 'ewallet' — meaning a GCash refund
     * silently paid into the member's wallet. The model was right and the UI
     * was lying. It now sends an explicit method.
     *
     * The four values below are exactly the install.sql ENUM. Do not add a
     * fifth here without a matching migration: an option the ENUM rejects is
     * "Data truncated for column 'method'", which is the same 500 that defect
     * H4 was about. GCash and every other non-wallet route use 'manual'.
     */
    public const METHODS = ['ewallet', 'cash', 'bank', 'manual'];

    public static function forOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_refunds WHERE order_id = ? ORDER BY created_at ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare("SELECT r.*, o.member_id, o.order_no, o.created_at AS order_created_at FROM shop_refunds r JOIN shop_orders o ON o.id = r.order_id WHERE r.id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function open(int $orderId): int
    {
        $ph  = implode(',', array_fill(0, count(self::BLOCKING_STATUSES), '?'));
        $st  = db()->prepare("SELECT COUNT(*) FROM shop_refunds WHERE order_id = ? AND status IN ({$ph})");
        $st->execute(array_merge([$orderId], self::BLOCKING_STATUSES));
        return (int) $st->fetchColumn();
    }

    /**
     * Segregation of duties: an admin may buy but may never refund their own
     * order. Lives in the model so every entry point gets it — the UI, a future
     * API, or a cron. Defect H6: the guard previously existed only inside
     * AdminController::orderTransition, leaving four sibling endpoints with the
     * view's hidden button as their only protection.
     *
     * Takes a memberId rather than a row so callers do not have to remember to
     * select the right join.
     */
    private static function assertNotSelfReview(int $memberId): void
    {
        // CLI and cron have no session, so there is no human to segregate from.
        if (!class_exists('Auth')) {
            return;
        }
        if (Auth::isAdmin() && $memberId > 0 && $memberId === Auth::id()) {
            throw new RuntimeException('Self-review refused: admins cannot refund their own orders.');
        }
    }

    /** The buyer behind an order, or 0 when the order does not exist. */
    private static function buyerOf(int $orderId): int
    {
        $st = db()->prepare("SELECT member_id FROM shop_orders WHERE id = ?");
        $st->execute([$orderId]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    public static function create(
        int $orderId,
        float $amount,
        string $cause,
        ?string $destination = null,
        ?string $promisedAt = null,
        ?int $requestedBy = null,
        string $method = 'ewallet'
    ): int {
        self::assertNotSelfReview(self::buyerOf($orderId));
        // Without this guard an unrecognised cause reaches MySQL and dies with
        // ERROR 1265 "Data truncated for column 'cause'", which surfaced as an
        // uncaught PDOException (a 500) because the controller only caught
        // InvalidArgumentException (defect H4).
        if (!in_array($cause, self::CAUSES, true)) {
            throw new InvalidArgumentException('Unknown refund cause: ' . $cause);
        }
        // Same reasoning for the payout route: a typo here would otherwise be
        // silently truncated by MySQL, and the refund would default to a wallet
        // credit the admin did not ask for.
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Unknown refund method: ' . $method);
        }
        $st = db()->prepare(
            "INSERT INTO shop_refunds (order_id, amount, cause, destination, method, promised_at, requested_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'open')"
        );
        $st->execute([$orderId, $amount, $cause, $destination, $method, $promisedAt, $requestedBy]);
        return (int) db()->lastInsertId();
    }

    public static function approve(int $id, int $adminId): bool
    {
        $refund = self::find($id);
        if (!$refund) return false;
        self::assertNotSelfReview((int) $refund['member_id']);

        // Issue the credit-note number at approval. It is the receipt reference
        // the settlement step consumes, so it must exist before money moves and
        // must never be recomputed afterwards.
        $receiptNo = trim((string) ($refund['receipt_ref'] ?? ''));
        if ($receiptNo === '') {
            $receiptNo = shop_credit_note_no((string) $refund['order_no'], $id);
        }

        $st = db()->prepare("UPDATE shop_refunds SET status = 'approved', approved_by = ?, approved_at = NOW(), receipt_ref = ? WHERE id = ? AND status = 'open'");
        $st->execute([$adminId, mb_substr($receiptNo, 0, 80), $id]);
        return $st->rowCount() === 1;
    }

    /**
     * Close a refund as paid. The receipt reference is the credit-note number
     * issued at approval, so the "closed only by a receipt" rule (plan §2.3
     * table 8) is satisfied without an admin typing a free-text value. An
     * optional external settlement reference (bank/GCash txn id) is appended to
     * the note. E-wallet destinations are credited through the ledger in the
     * same call.
     */
    public static function markPaid(int $id, int $adminId, string $externalRef = ''): bool
    {
        $refund = self::find($id);
        if (!$refund || $refund['status'] !== 'approved') {
            return false;
        }
        self::assertNotSelfReview((int) $refund['member_id']);

        // The receipt reference is the credit-note number issued at approval.
        // Regenerate only for a row that predates issuance (legacy data), so the
        // stored number stays immutable once issued.
        $receiptRef = trim((string) ($refund['receipt_ref'] ?? ''));
        if ($receiptRef === '') {
            $receiptRef = shop_credit_note_no((string) $refund['order_no'], $id);
        }
        $externalRef = trim($externalRef);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                // M6 (Phase 6.5): decide from the `method` ENUM, not from free
                // text. This used to be
                //   str_contains(strtolower($destination), 'wallet')
                // which is fragile in both directions: a destination of
                // "Cash payout to GCash wallet later" would credit an e-wallet
                // for a cash payout, and "bank transfer" would not. The column
                // carries no constraint, so any admin typo changed who got paid.
                //
                // shop_refunds.method is ENUM('ewallet','cash','bank','manual')
                // and was defined but never read — that is the contract. Only an
                // explicit e-wallet method credits the wallet; everything else is
                // settled out of band and closed as processed.
                $method = (string) ($refund['method'] ?? '');
                if ($method === 'ewallet') {
                    Ewallet::credit(
                        (int) $refund['member_id'],
                        (float) $refund['amount'],
                        (int) $refund['order_id'],
                        'shop_order',
                        'Shop order refund (receipt ' . $receiptRef . ')'
                    );
                }

            // An optional external settlement reference (bank/GCash txn id) is
            // kept in the note; the issued credit-note number stays in
            // receipt_ref so reprints are stable.
            $note = trim((string) ($refund['note'] ?? ''));
            if ($externalRef !== '') {
                $note = trim($note . ' External settlement ref: ' . $externalRef);
            }

            $st = $pdo->prepare(
                "UPDATE shop_refunds SET status = 'processed', processed_by = ?, processed_at = NOW(), receipt_ref = ?, note = ? WHERE id = ? AND status = 'approved'"
            );
            $st->execute([$adminId, mb_substr($receiptRef, 0, 80), mb_substr($note, 0, 500), $id]);
            if ($st->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Deny / cancel an open or approved refund task. */
    public static function deny(int $id, int $adminId, string $note = ''): bool
    {
        $refund = self::find($id);
        if (!$refund) return false;
        self::assertNotSelfReview((int) $refund['member_id']);
        $st = db()->prepare("UPDATE shop_refunds SET status = 'denied', processed_by = ?, processed_at = NOW(), note = ? WHERE id = ? AND status IN ('open','approved')");
        $st->execute([$adminId, mb_substr($note, 0, 500), $id]);
        return $st->rowCount() === 1;
    }

    /** Blocks delivered → completed while open (§0.5 T24). */
    public static function hasOpen(int $orderId): bool
    {
        return self::open($orderId) > 0;
    }
}
