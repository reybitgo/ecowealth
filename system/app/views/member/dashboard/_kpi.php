<?php
declare(strict_types=1);

/**
 * Member dashboard — one non-wallet KPI card.
 *
 * @var array{label:string, value:string, sub:string, icon:string, tone:string} $kpi
 */
?>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($kpi['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($kpi['icon'])) ?>"></i>
                          </span>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1 small"><?= e($kpi['label']) ?></p>
                      <h4 class="mb-1 text-truncate"><?= e($kpi['value']) ?></h4>
                      <small class="text-body-secondary d-block"><?= e($kpi['sub']) ?></small>
                    </div>
                  </div>
                </div>
