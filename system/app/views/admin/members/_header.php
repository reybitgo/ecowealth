<?php
declare(strict_types=1);

/**
 * Admin member directory — heading row.
 *
 * Kept as its own region so the "joined this week" badge follows the
 * directory's counters, which are computed from CURDATE().
 *
 * @var array{total:int, active:int, pending:int, suspended:int, with_package:int, new_week:int} $stats
 */
?>
<?= View::partial('page_header', [
    'title'       => 'Members',
    'description' => 'Sponsor chain, package, status, Royalty rank and wallet for every member account.',
    'attributes'  => ['data-live-region' => 'member-header'],
    'align'       => 'center',
    'actions'     => '<span class="badge rounded-pill bg-label-primary">'
        . (int) $stats['new_week'] . ' joined this week</span>',
]) ?>
