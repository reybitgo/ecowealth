<?php
/**
 * @file   views/partials/order_status_badge.php
 * @brief  The single status pill used by the member + admin order lists (§5.7).
 *
 * EXPECTS TWO ARGUMENTS, not a magic variable:
 *     $badgeStatus  string  the shop_orders.status value
 *     $badgeStamp   ?string a timestamp to print under the pill, or null
 *
 *     <?php $badgeStatus = $o['status']; $badgeStamp = $o['created_at']; ?>
 *     <?php require __DIR__ . '/order_status_badge.php'; ?>
 *
 * WHY NOT `require $order`: this partial used to read `$order` directly. Both
 * list views looped their rows as `$o`, so `$order` was never defined and every
 * single row rendered the `default` arm of shop_status_label() — i.e. the word
 * "Unknown" — in a grey badge, with two PHP warnings per row (defect C4,
 * tmp/shop/remedy.md). A partial that reads a variable it does not receive is
 * invisible to every static check, so the arguments are now explicit.
 */
?>
<?= shop_status_badge((string) $badgeStatus, $badgeStamp ?? null) ?>