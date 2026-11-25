<?php
declare(strict_types=1);

/**
 * Genealogy — the view switcher (UX-07).
 *
 * Two views share the /genealogy route: the sponsor tree (the default,
 * built from users.sponsor_id) and the binary placement tree
 * (?view=binary). Until this partial existed the binary view was linked
 * only from inside itself, the registration return URL and the admin
 * member record, so a member could not discover that a second view
 * existed.
 *
 * The pills follow the same pattern as the earnings and commissions view
 * tabs: `nav nav-pills` above the content, one link active with
 * `aria-current="page"`. The binary pill is offered only when placement
 * is on (the binary_nodes table exists); without it there is no tree to
 * open, and the binary page would only say so.
 *
 * @var bool $is_binary  whether the binary view is the one on screen
 */

$is_binary   = (bool) ($is_binary ?? false);
$show_binary = BinaryPlacementService::isEnabled();

/* A single "Sponsor tree" pill would advertise a switch that is not there:
 * without the placement table there is no second view to open. */
if (!$show_binary) {
    return;
}
?>
              <ul class="nav nav-pills mb-4 commission-tabs" role="list">
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e(url('genealogy')) ?>"
                    class="nav-link<?= $is_binary ? '' : ' active' ?>"
<?= $is_binary ? '' : ' aria-current="page"' ?>
                  >
                    <i class="icon-base bx bx-git-branch me-1"></i> Sponsor tree
                  </a>
                </li>
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e(url('genealogy?view=binary')) ?>"
                    class="nav-link<?= $is_binary ? ' active' : '' ?>"
<?= $is_binary ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-sitemap me-1"></i> Binary tree
                  </a>
                </li>
              </ul>
