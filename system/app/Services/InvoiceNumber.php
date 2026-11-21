<?php
declare(strict_types=1);

/* ==============================================================
 *  InvoiceNumber — the printed invoice number (plan §5.7)
 *
 *      INV + - + yymmdd + - + 5-digit id     e.g. INV-261106-00042
 *
 *  Generated from the inserted id, so it is unique by
 *  construction (the id is the unique part). The row is inserted
 *  with a temporary T… number and updated inside the same
 *  transaction because `invoice_no` is NOT NULL and UNIQUE.
 * ============================================================== */

final class InvoiceNumber
{
    /** Build the display number for an invoice id. */
    public static function make(int $invoiceId, ?string $issuedAt = null): string
    {
        if ($invoiceId <= 0) {
            throw new InvalidArgumentException('An invoice number needs a positive invoice id.');
        }

        $timestamp = $issuedAt === null ? time() : strtotime($issuedAt);

        if ($timestamp === false) {
            $timestamp = time();
        }

        return 'INV-' . date('ymd', $timestamp) . '-' . str_pad((string) $invoiceId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Shape check: INV- + 6 date digits + - + at least 5 id digits.
     * The id part may grow past five digits on a very large shop; it
     * stays unique because it is still the id.
     */
    public static function isValid(string $invoiceNo): bool
    {
        return preg_match('/^INV-\d{6}-\d{5,}$/', $invoiceNo) === 1;
    }
}
