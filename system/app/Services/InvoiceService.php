<?php
declare(strict_types=1);

/* ==============================================================
 *  InvoiceService — the immutable invoice snapshot (plan §5.7,
 *  §7 Phase 8; reference ShopInvoice)
 *
 *  An invoice is written once, inside the transaction that marks an
 *  order `paid` (e-wallet at placement, manual payment when the
 *  administrator verifies the proof), and never edited afterwards:
 *
 *      paid ──(issue)──▶ shop_invoices + shop_invoice_items
 *      payment reversed ──(void)──▶ voided_at / void_reason
 *
 *  Numbering is `INV-yymmdd-#####` (the row id is the unique part).
 *  Seller identity comes from settings at issue time and is frozen
 *  with the document, so editing the company details later never
 *  rewrites an invoice that was already printed.
 *
 *  `ensureForOrder()` is the read/backfill entry point used by the
 *  invoice page: when an order from an earlier phase is already paid
 *  but has no snapshot, it issues one under the order row lock. The
 *  operation is idempotent — a double click or two tabs produce one
 *  invoice, never two (P8-T5/T6).
 * ============================================================== */

final class InvoiceService
{
    /** Order statuses that mean "payment received"; an invoice may exist. */
    public const PAID_STATUSES = Order::PAID_STATUSES;

    private static int $savepointSeq = 0;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    /** Is the payment of this order (still) received? */
    public static function isPaid(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::PAID_STATUSES, true);
    }

    /* ----------------------------------------------------------
     *  Writing
     * ---------------------------------------------------------- */

    /**
     * Freeze the document for a paid order. Runs inside the caller's
     * transaction (the order row is already locked there).
     *
     * Idempotent: an existing live snapshot is returned untouched.
     * Returns null when the order does not exist or is not paid.
     */
    public function issueForPaidOrder(int $orderId, ?PDO $pdo = null): ?int
    {
        $pdo = $pdo ?? $this->pdo;

        $order = Order::find($orderId, $pdo);

        if ($order === null || !self::isPaid($order)) {
            return null;
        }

        $existing = Invoice::liveFor($orderId, $pdo);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $lines    = Order::items($orderId, $pdo);
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $subtotal = round($subtotal + (float) $line['line_total'], 2);
        }

        $buyer = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $buyer->execute([(int) $order['user_id']]);
        $username = $buyer->fetchColumn();

        $invoiceId = Invoice::create($orderId, [
            'status_at_issue'  => (string) $order['status'],
            'seller_name'      => (string) setting('seller_name', APP_NAME),
            'seller_address'   => (string) setting('seller_address', ''),
            'seller_tin'       => (string) setting('seller_tin', ''),
            'seller_phone'     => (string) setting('seller_phone', ''),
            'buyer_username'   => $username === false ? null : (string) $username,
            'billing_name'     => (string) $order['billing_name'],
            'billing_phone'    => (string) ($order['billing_phone'] ?? ''),
            'shipping_name'    => (string) $order['shipping_name'],
            'shipping_address' => self::composeAddress($order),
            'payment_method'   => (string) $order['payment_method'],
            'paid_at'          => $order['paid_at'] === null ? null : (string) $order['paid_at'],
            'subtotal'         => $subtotal,
            'shipping_fee'     => (float) $order['shipping_fee'],
            'total'            => (float) $order['total'],
        ], $pdo);

        Invoice::setNumber($invoiceId, InvoiceNumber::make($invoiceId), $pdo);

        $lineNo = 0;

        foreach ($lines as $line) {
            Invoice::addItem($invoiceId, ++$lineNo, [
                'description' => (string) $line['product_name'],
                'sku'         => $line['product_sku'] === null ? null : (string) $line['product_sku'],
                'quantity'    => (int) $line['quantity'],
                'unit_price'  => (float) $line['unit_price'],
                'amount'      => (float) $line['line_total'],
            ], $pdo);
        }

        return $invoiceId;
    }

    /**
     * Void the live snapshot when a payment is reversed (an
     * administrator cancels a paid order). The content stays frozen;
     * only the validity flag changes. No-op when nothing was issued.
     */
    public function voidForOrder(int $orderId, string $reason, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? $this->pdo;
        $row = Invoice::liveFor($orderId, $pdo);

        if ($row === null) {
            return false;
        }

        Invoice::void((int) $row['id'], $reason, $pdo);

        return true;
    }

    /* ----------------------------------------------------------
     *  Reading
     * ---------------------------------------------------------- */

    /**
     * The invoice document for an order: the frozen snapshot plus its
     * lines. Null when the order was never issued an invoice.
     *
     * @return array{invoice: array<string, mixed>, items: list<array<string, mixed>>}|null
     */
    public function document(int $orderId, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? $this->pdo;
        $row = Invoice::latestFor($orderId, $pdo);

        if ($row === null) {
            return null;
        }

        return [
            'invoice' => $row,
            'items'   => Invoice::items((int) $row['id'], $pdo),
        ];
    }

    /**
     * The read entry point for the invoice pages: the snapshot when one
     * exists; otherwise issue one for an already-paid order (orders paid
     * before Phase 8 shipped, or a race between issue and first view).
     *
     * @return array{invoice: array<string, mixed>, items: list<array<string, mixed>>}|null
     */
    public function ensureForOrder(int $orderId): ?array
    {
        $document = $this->document($orderId);

        if ($document !== null) {
            return $document;
        }

        $order = Order::find($orderId, $this->pdo);

        if ($order === null || !self::isPaid($order)) {
            return null;
        }

        $this->withTransaction(function () use ($orderId): void {
            /* Lock the order, then re-check: two tabs (or the payment
             * transaction) may have issued it in the meantime. */
            $stmt = $this->pdo->prepare('SELECT id FROM shop_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);

            if ($stmt->fetchColumn() === false) {
                return;
            }

            $this->issueForPaidOrder($orderId, $this->pdo);
        });

        return $this->document($orderId);
    }

    /* ----------------------------------------------------------
     *  Helpers
     * ---------------------------------------------------------- */

    /**
     * Full delivery address as printed on the document. The order keeps
     * the parts; the invoice stores one composed line because the frozen
     * schema has a single `shipping_address` column (plan Appendix B).
     */
    public static function composeAddress(array $order): string
    {
        $parts = array_filter([
            trim((string) ($order['shipping_address'] ?? '')),
            trim((string) ($order['shipping_barangay'] ?? '')),
            trim((string) ($order['shipping_city'] ?? '')),
            trim((string) ($order['shipping_province'] ?? '')),
            trim((string) ($order['shipping_postal'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return mb_substr(implode(', ', $parts), 0, 255);
    }

    /**
     * One write, one transaction. Adopts a caller's transaction with a
     * savepoint so a refused issue never poisons outer work.
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'invoice_sp_' . (++self::$savepointSeq);
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
