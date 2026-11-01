<?php

/**
 * @file   views/member/dfi_history.php
 * @brief  Member DFI payout history (Phase 3)
 */
?>
<?php $pageTitle = 'DFI History'; ?>
<?php
$status = $status ?? [
  'daily_rate' => 0,
  'days_used' => 0,
  'days_remaining' => 0,
  'total_dfi_earned' => 0,
  'status' => 'disabled',
];
if (!isset($calendarData) || !is_array($calendarData)) {
  $calendarData = [];
}
if (!isset($history) || !is_array($history)) {
  $history = [
    'total' => 0,
    'data' => [],
  ];
}
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
      <div>
        <h4 class="fw-800 mb-1">📅 Daily Fixed Income History</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Day-by-day record of your DFI payouts</p>
      </div>
      <a href="<?= link_to('dashboard') ?>" class="btn btn-outline-primary btn-sm">← Dashboard</a>
    </div>

    <!-- DFI Status Summary -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-6 col-md-3">
            <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;">Daily Rate</div>
            <div class="fw-700" style="font-size:1.1rem;"><?= fmt_money($status['daily_rate']) ?></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;">Days Used</div>
            <div class="fw-700" style="font-size:1.1rem;"><?= $status['days_used'] ?> / <?= $status['days_used'] + $status['days_remaining'] ?></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;">Total Earned</div>
            <div class="fw-700 text-success" style="font-size:1.1rem;"><?= fmt_money($status['total_dfi_earned']) ?></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;">Status</div>
            <div class="fw-700" style="font-size:1.1rem;">
              <?php
              $statusBadge = match ($status['status']) {
                'active'     => '<span class="badge bg-success-subtle text-success">Active</span>',
                'capped'     => '<span class="badge bg-warning-subtle text-warning">Capped</span>',
                'perminact'  => '<span class="badge bg-danger-subtle text-danger">Permanent</span>',
                'completed'  => '<span class="badge bg-info-subtle text-info">Completed</span>',
                'paused'     => '<span class="badge bg-secondary-subtle text-secondary">Paused</span>',
                default      => '<span class="badge bg-secondary-subtle text-secondary">Disabled</span>',
              };
              echo $statusBadge;
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Calendar View -->
    <?php
    $monthsToShow = [];
    $now = new DateTime();
    $monthsToShow[] = clone $now;
    $monthsToShow[] = (clone $now)->modify('-1 month');
    ?>
    <div class="card mb-4">
      <div class="card-header"><span class="card-title">🗓️ DFI Calendar</span></div>
      <div class="card-body">
        <div class="row g-4">
          <?php foreach ($monthsToShow as $monthObj):
            $ym = $monthObj->format('Y-m');
            $daysInMonth = (int)$monthObj->format('t');
            $firstDow = (int)$monthObj->format('w'); // 0=Sun
          ?>
            <div class="col-12 col-md-6">
              <div class="calendar-month">
                <div class="calendar-title"><?= $monthObj->format('F Y') ?></div>
                <div class="calendar-grid">
                  <div class="cal-head">Su</div>
                  <div class="cal-head">Mo</div>
                  <div class="cal-head">Tu</div>
                  <div class="cal-head">We</div>
                  <div class="cal-head">Th</div>
                  <div class="cal-head">Fr</div>
                  <div class="cal-head">Sa</div>
                  <?php for ($i = 0; $i < $firstDow; $i++): ?><div class="cal-cell empty"></div><?php endfor; ?>
                  <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $hasEntry = isset($calendarData[$ym][$d]);
                    $cellClass = 'cal-cell';
                    $icon = '';
                    $tooltip = '';
                    if ($hasEntry) {
                      $entry = $calendarData[$ym][$d];
                      if ($entry['amount'] > 0) {
                        $cellClass .= ' paid';
                        $icon = '✅';
                        $tooltip = 'DFI paid: ' . fmt_money($entry['amount']);
                      } else {
                        $cellClass .= ' blocked';
                        $icon = '⛔';
                        $tooltip = 'Blocked by cap';
                      }
                    } elseif ($ym . '-' . sprintf('%02d', $d) > date('Y-m-d')) {
                      $cellClass .= ' future';
                    }
                  ?>
                    <div class="<?= $cellClass ?>" title="<?= e($tooltip) ?>">
                      <span class="cal-day"><?= $d ?></span>
                      <?php if ($icon): ?><span class="cal-icon"><?= $icon ?></span><?php endif; ?>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-3 mt-3" style="font-size:.75rem;">
          <span><span style="font-size:.9rem;">✅</span> DFI Paid</span>
          <span><span style="font-size:.9rem;">⛔</span> Blocked by Cap</span>
          <span><span style="font-size:.9rem;">⏸️</span> Paused (capped)</span>
        </div>
      </div>
    </div>
    <style>
      .calendar-month {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: .75rem;
      }

      .calendar-title {
        font-weight: 700;
        font-size: .85rem;
        text-align: center;
        margin-bottom: .5rem;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #374151;
      }

      .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 4px;
      }

      .cal-head {
        text-align: center;
        font-size: .65rem;
        font-weight: 700;
        color: #9ca3af;
        padding: 2px;
      }

      .cal-cell {
        aspect-ratio: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        font-size: .75rem;
        font-weight: 600;
        background: #f9fafb;
        color: #374151;
        position: relative;
      }

      .cal-cell.empty {
        background: transparent;
      }

      .cal-cell.future {
        opacity: .4;
      }

      .cal-cell.paid {
        background: #dcfce7;
        color: #166534;
      }

      .cal-cell.blocked {
        background: #fee2e2;
        color: #991b1b;
      }

      .cal-day {
        line-height: 1;
      }

      .cal-icon {
        font-size: .65rem;
        line-height: 1;
        margin-top: 1px;
      }
    </style>

    <!-- History Table -->
    <div class="card">
      <div class="card-header">
        <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <input type="hidden" name="page" value="dfi_history">
          <?php if (is_imp_session()): ?><input type="hidden" name="imp" value="<?= e(session_id()) ?>"><?php endif; ?>
          <div class="d-flex align-items-center gap-2">
            <span class="card-title">Payout Log</span>
            <span class="text-muted" style="font-size:.75rem;"><?= $history['total'] ?> record(s)</span>
          </div>

          <!-- Rows per page -->
          <div class="d-flex align-items-center gap-2">
            <label for="perPageSelect" class="form-label mb-0 text-muted" style="font-size:.78rem;white-space:nowrap;">Rows per page</label>
            <select id="perPageSelect" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
              <?php foreach ([5, 10, 25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
      <div class="card-body p-0">
        <?php if (empty($history['data'])): ?>
          <div class="text-center py-5 text-muted">
            <div style="font-size:2rem;">📭</div>
            <p class="mt-2 mb-0" style="font-size:.85rem;">No DFI payouts yet.</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.85rem;">
              <thead class="table-light">
                <tr>
                  <th>Date</th>
                  <th>Day #</th>
                  <th>Amount</th>
                  <th>Cap Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($history['data'] as $row): ?>
                  <tr>
                    <td><?= fmt_datetime($row['created_at']) ?></td>
                    <td>Day <?= (int)$row['day_number'] ?></td>
                    <td class="fw-semibold text-success"><?= fmt_money((float)$row['amount']) ?></td>
                    <td>
                      <?php if ($row['cap_status_at_payout'] === 'active'): ?>
                        <span class="badge bg-success-subtle text-success">Active</span>
                      <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning"><?= e($row['cap_status_at_payout']) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($history['total_pages']) && $history['total_pages'] > 1): ?>
        <div class="card-footer">
          <?= pagination_links($history, link_to('dfi_history', ['per_page' => $perPage])) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>