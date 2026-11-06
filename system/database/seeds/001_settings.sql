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
  ('commission_requires_active_sponsor','1'),
  ('free_registration_enabled',        '1'),

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
