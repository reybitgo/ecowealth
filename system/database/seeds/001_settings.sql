-- =============================================================
--  EcoWealth — Phase 1 settings defaults (plan §4.5)
--
--  Idempotent: re-running adds missing keys and never overwrites
--  a value an admin has already changed (ON DUPLICATE ... no-op).
--
--      mysql -u root <db> < database/seeds/001_settings.sql
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO settings (key_name, value) VALUES
  -- Branding / general -----------------------------------------
  ('site_name',                        'EcoWealth'),
  ('site_tagline',                     'Membership, shop and e-wallet'),
  ('currency',                         'PHP'),
  ('maintenance_mode',                 '0'),
  ('shop_enabled',                     '1'),

  -- Order cancellation kill switch (post-Phase 18). Off by default: a
  -- member or an administrator cannot cancel an order, so no wallet refund
  -- and no repeat-purchase escrow reversal happens in normal operation.
  -- Automatic expiry of unpaid orders stays under `shop_expiry_enabled`.
  ('shop_cancel_enabled',              '0'),

  -- Manual payment accounts (placeholders — replace before go-live)
  ('payment_gcash_name',               'EcoWealth Trading'),
  ('payment_gcash_number',             '0917 000 0000'),
  ('payment_maya_name',                'EcoWealth Trading'),
  ('payment_maya_number',              '0917 000 0000'),
  ('payment_bank_name',                'BDO'),
  ('payment_bank_account_name',        'EcoWealth Trading Inc.'),
  ('payment_bank_account_number',      '0000 0000 0000'),

  -- Shop payment windows (Phase 7)
  ('shop_payment_deadline_hours',      '24'),
  ('shop_correction_window_hours',     '24'),
  ('shop_max_proof_attempts',          '3'),
  ('shop_expiry_enabled',              '1'),

  -- Fulfilment (Phase 8)
  ('shop_autocomplete_days',           '7'),
  ('shop_autocomplete_enabled',        '1'),

  -- Invoice seller block (D12 — filled in again before go-live)
  ('seller_name',                      'EcoWealth Trading Inc.'),
  ('seller_address',                   ''),
  ('seller_tin',                       ''),
  ('seller_phone',                     ''),

  -- Compensation toggles (Phases 9–11)
  ('commission_direct_enabled',        '1'),
  ('commission_indirect_enabled',      '1'),
  ('commission_indirect_max_levels',   '10'),
  ('commission_compression',           '0'),
  ('commission_requires_active_sponsor','1'),
  ('free_registration_enabled',        '1'),

  -- Commission award-history start (F-01). Migration 012 adds this key to an
  -- upgraded database; a fresh install must carry it too, or the two diverge.
  -- A new install has no earlier award history, so "now" is the correct mark;
  -- an existing value is never overwritten (ON DUPLICATE … no-op below).
  ('commission_ledger_since',          DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')),

  -- Repeat-purchase bonus (Phase 17). Every key is inert until an operator
  -- turns a product's ladder on: the global master is '0' and the product
  -- switch defaults to off. `unilevel_ledger_since` marks the instant the
  -- stream may pay, so reconciliation can separate legacy ledger rows.
  ('unilevel_enabled',                 '0'),
  ('unilevel_max_levels',              '10'),
  ('unilevel_requires_active_upline',  '1'),
  ('unilevel_active_threshold',        '500'),
  ('unilevel_reversal_enabled',        '1'),
  ('unilevel_ledger_since',            DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')),

  -- Repeat-purchase bonus escrow (Phase 18). A paid order writes `pending`
  -- awards and moves no money; the release job credits the wallet after
  -- delivery + `unilevel_hold_days`. `unilevel_release_enabled` pauses the
  -- job without stranding earned awards (cancelling an order still cancels
  -- its pending awards, whatever these two say).
  ('unilevel_hold_days',               '7'),
  ('unilevel_release_enabled',         '1'),

  -- Royalty bonus (Phase 19). The reference preset: Leader at ten directs,
  -- then ten downline Leaders / Supervisors / Managers for each rank above;
  -- ₱500k / ₱1M / ₱5M monthly group-sales quotas; 3%/5%, 5%/10%, 10%/15%
  -- group/personal rates. The stream ships OFF (`royalty_enabled = 0`) so a
  -- deployment never starts paying by accident (D-14); `royalty_pay_enabled`
  -- is the separate payment pause and ships on because nothing is evaluated
  -- yet. `royalty_ledger_since` is the first-payable-month baseline for the
  -- close job and reconciliation (D-15).
  ('royalty_enabled',                  '0'),
  ('royalty_pay_enabled',              '1'),
  ('royalty_leader_directs',           '10'),
  ('royalty_supervisor_leaders',       '10'),
  ('royalty_manager_supervisors',      '10'),
  ('royalty_director_managers',        '10'),
  ('royalty_supervisor_quota',         '500000'),
  ('royalty_manager_quota',            '1000000'),
  ('royalty_director_quota',           '5000000'),
  ('royalty_supervisor_group_pct',     '3.000'),
  ('royalty_supervisor_personal_pct',  '5.000'),
  ('royalty_manager_group_pct',        '5.000'),
  ('royalty_manager_personal_pct',     '10.000'),
  ('royalty_director_group_pct',       '10.000'),
  ('royalty_director_personal_pct',    '15.000'),
  ('royalty_ledger_since',             DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')),

  -- Team Harvest bonus (Phase 20). The reference preset: three direct
  -- member sign-ups qualify a member (1–10 configurable) and the private
  -- 3×10 matrix pays a flat amount per completed level, up to ten levels.
  -- The stream ships OFF (`team_harvest_enabled = 0`): qualification
  -- accounting still runs once the queue is seeded, but no award is
  -- written while the switch is off (D-09). `team_harvest_ledger_since`
  -- is deliberately EMPTY: it is not a first-payable date, it is the proof
  -- that the queue was seeded by bin/harvest_queue_seed.php (D-04). A
  -- business reset clears it again, because the queue no longer exists.
  ('team_harvest_enabled',             '0'),
  ('team_harvest_qualify_directs',     '3'),
  ('team_harvest_max_levels',          '10'),
  ('team_harvest_ledger_since',        ''),

  -- General Harvest bonus (Phase 21). One shared 3-ary board over the
  -- Harvest queue: every qualified member holds a node, and a completed
  -- level pays a flat amount to the node's holder. The stream ships OFF
  -- (`general_harvest_enabled = 0`) and the board ships UNARMED (its
  -- singleton row has `baseline_n NULL`): enabling and arming are two
  -- separate, deliberate acts (D-07, D-10). There is deliberately no
  -- baseline key — `baseline_n` lives on the board row, where it is read
  -- and written under the same lock as `settled_n` (§13.5).
  ('general_harvest_enabled',          '0'),
  ('general_harvest_max_levels',       '10'),

  -- Product binary bonus (Phase 22). The stream ships OFF at both the
  -- global master and the product master; a deployment never starts
  -- paying by accident (D-16). `binary_ledger_since` marks the instant
  -- the stream may pay, so reconciliation can separate legacy rows.
  ('binary_enabled',                   '0'),
  ('binary_self_pct',                  '5.000'),
  ('binary_pair_points',               '1000'),
  ('binary_pair_pct',                  '10.000'),
  ('binary_daily_pair_cap',            '20'),
  ('binary_requires_active',           '1'),
  ('binary_hold_days',                 '7'),
  ('binary_release_enabled',           '1'),
  ('binary_reversal_enabled',          '1'),
  ('binary_ledger_since',              DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')),

  -- Package binary bonus (Phase 23A). The stream ships OFF at both the
  -- global master and the per-package level; a deployment never starts
  -- paying by accident (D-13). `package_binary_ledger_since` marks the
  -- instant the stream may pay, so reconciliation can separate legacy
  -- activations from the first payable one.
  ('package_binary_enabled',            '0'),
  ('package_binary_requires_package',   '1'),
  ('package_binary_ledger_since',       DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')),

  -- Payouts (Phase 13)
  ('min_payout',                       '500'),
  ('service_fee_gcash',                '0'),
  ('service_fee_maya',                 '0'),
  ('service_fee_usdt_trc20',           '5'),
  ('service_fee_usdt_bep20',           '5'),
  ('usdt_rate',                        '0'),
  ('usdt_trc20_gas_fee',               '2.50'),
  ('usdt_bep20_gas_fee',               '0.05'),
  ('payout_gcash_enabled',             '1'),
  ('payout_maya_enabled',              '1'),
  ('payout_usdt_trc20_enabled',        '1'),
  ('payout_usdt_bep20_enabled',        '1'),

  -- E-wallet transfers (Phase 12)
  ('ewallet_transfer_fee',             '0'),
  ('ewallet_min_transfer',             '50'),
  ('ewallet_transfer_daily_limit',     '5000'),
  ('ewallet_transfer_weekly_limit',    '20000')
ON DUPLICATE KEY UPDATE key_name = key_name;

-- Team Harvest reference ladder (Phase 20, plan §13.3, D-10). Ten rows,
-- the same amounts migration 024 inserts; ON DUPLICATE keeps an
-- operator's edited amount when this file is re-run.
INSERT INTO team_harvest_levels (level, amount) VALUES
  (1,   1000.00),
  (2,   2000.00),
  (3,   4000.00),
  (4,   6000.00),
  (5,  12000.00),
  (6,  50000.00),
  (7, 120000.00),
  (8, 200000.00),
  (9, 1200000.00),
  (10, 3500000.00)
ON DUPLICATE KEY UPDATE level = level;

-- General Harvest reference ladder (Phase 21, plan §12.4, Appendix A).
-- Ten rows, the same amounts migration 025 inserts; ON DUPLICATE keeps an
-- operator's edited amount when this file is re-run. The full ladder pays
-- ₱8,381,000.00 per node.
INSERT INTO general_harvest_levels (level, amount) VALUES
  (1,   2000.00),
  (2,   4000.00),
  (3,   8000.00),
  (4,  12000.00),
  (5,  25000.00),
  (6, 100000.00),
  (7, 250000.00),
  (8, 480000.00),
  (9, 2500000.00),
  (10, 5000000.00)
ON DUPLICATE KEY UPDATE level = level;

-- General Harvest board singleton (Phase 21, plan §13.2). Unarmed:
-- no baseline, no arm instant, nothing settled. Every read must find the
-- row without a write, and the installer verifies it, so the seed creates
-- it; ON DUPLICATE never disturbs an armed board.
INSERT INTO general_harvest_board (id, baseline_n, armed_at, armed_by, settled_n)
VALUES (1, NULL, NULL, NULL, 0)
ON DUPLICATE KEY UPDATE id = id;
