<?php
declare(strict_types=1);

/**
 * Admin reports — section menu.
 *
 * The desk stacks ten report families on one page. This is the shared
 * `.section-nav` pattern (app.css): a sticky, visibly labelled anchor bar
 * with an underline for the section in view, so it cannot be mistaken for
 * the dataset-changing `.view-tabs` pills. Plain anchors work without
 * JavaScript; admin-reports.js adds smooth scrolling and keeps the active
 * link in step with the section on screen (including a hash left in the
 * address bar).
 *
 * The labels are deliberately shorter than the card titles so the strip
 * fits a laptop; the `title` attribute carries the full family name for
 * hover and assistive technology.
 */

$reportSections = [
    ['id' => 'report-overview', 'label' => 'Overview', 'icon' => 'bx-grid-alt', 'title' => 'Key figures'],
    ['id' => 'report-health', 'label' => 'Health', 'icon' => 'bx-check-shield', 'title' => 'Finance health and orders'],
    ['id' => 'report-sales', 'label' => 'Sales', 'icon' => 'bx-line-chart', 'title' => 'Sales and top products'],
    ['id' => 'report-commissions', 'label' => 'Commissions', 'icon' => 'bx-network-chart', 'title' => 'Commissions and payouts'],
    ['id' => 'report-unilevel', 'label' => 'Repeat purchase', 'icon' => 'bx-repeat', 'title' => 'Repeat-purchase bonus'],
    ['id' => 'report-royalty', 'label' => 'Royalty', 'icon' => 'bx-crown', 'title' => 'Royalty bonus'],
    ['id' => 'report-team-harvest', 'label' => 'Team Harvest', 'icon' => 'bx-group', 'title' => 'Team Harvest bonus'],
    ['id' => 'report-general-harvest', 'label' => 'General Harvest', 'icon' => 'bx-sitemap', 'title' => 'General Harvest bonus'],
    ['id' => 'report-binary', 'label' => 'Binary', 'icon' => 'bx-git-branch', 'title' => 'Product and package binary bonus'],
    ['id' => 'report-codes', 'label' => 'Codes', 'icon' => 'bx-key', 'title' => 'Registration-code revenue'],
];
?>
              <nav
                class="section-nav"
                aria-label="Report sections"
                data-report-sections
              >
                <span class="section-nav-label">On this page</span>
                <ul class="nav section-nav-list reports-tab-list gap-1 flex-nowrap" role="list">
<?php foreach ($reportSections as $section): ?>
                  <li class="nav-item" role="listitem">
                    <a
                      class="nav-link text-nowrap"
                      href="#<?= e($section['id']) ?>"
                      data-report-section="<?= e($section['id']) ?>"
                      title="<?= e($section['title']) ?>"
                    >
                      <i class="icon-base <?= e(icon_class($section['icon'])) ?> me-1"></i><?= e($section['label']) ?>
                    </a>
                  </li>
<?php endforeach; ?>
                </ul>
              </nav>
