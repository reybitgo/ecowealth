<?php
declare(strict_types=1);

/**
 * Membership page — heading and status badge.
 *
 * @var string $status
 * @var string $status_label
 * @var string $status_tone
 */
?>
<?= View::partial('page_header', [
    'title'       => 'Membership',
    'description' => 'Your associate status, package and activation history.',
    'attributes'  => ['data-live-region' => 'membership-header'],
    'actions'     => status_badge($status, ['active' => [$status_label, $status_tone]]),
]) ?>
