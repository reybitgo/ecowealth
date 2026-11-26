<?php
declare(strict_types=1);

/**
 * Admin activation queue — heading row.
 *
 * @var array{total:int, review:int, approved:int, rejected:int, amount_review:float, amount_approved:float} $stats
 */
?>
<?= View::partial('page_header', [
    'title'       => 'Activations',
    'description' => 'Review payment proofs and approve package activations. Approval runs the commission hook once.',
    'attributes'  => ['data-live-region' => 'activation-header'],
    'align'       => 'center',
    'actions'     => '<span class="badge rounded-pill bg-label-warning">'
        . (int) $stats['review'] . ' awaiting review</span>',
]) ?>
