<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderNumber — the public order number (plan §3.6, Phase 6)
 *
 *      EW + yymmdd + 5-digit id     e.g. EW26110500042
 *
 *  Generated from the inserted id, so it is unique by
 *  construction (the id is the unique part). The order row is
 *  inserted with a temporary T… value and updated inside the same
 *  transaction because `order_no` is NOT NULL and UNIQUE.
 * ============================================================== */

final class OrderNumber
{
    /** Build the display number for an order id. */
    public static function make(int $orderId, ?string $createdAt = null): string
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('An order number needs a positive order id.');
        }

        $timestamp = $createdAt === null ? time() : strtotime($createdAt);

        if ($timestamp === false) {
            $timestamp = time();
        }

        return 'EW' . date('ymd', $timestamp) . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Shape check: EW + 6 date digits + at least 5 id digits.
     * The id part may grow past five digits on a very large shop;
     * it stays unique because it is still the id.
     */
    public static function isValid(string $orderNo): bool
    {
        return preg_match('/^EW\d{6}\d{5,}$/', $orderNo) === 1;
    }
}
