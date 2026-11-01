-- ============================================================
--  MLM BINARY SYSTEM — FULL SCHEMA + SEED DATA (v2)
--
--  Usage:
--    mysql -u root -p DATABASE < install.sql
--
--  The target schema is supplied by the caller and is NOT named in this file.
--  A previous revision hardcoded a schema name here, which meant the same file
--  silently installed into the wrong database on any machine that did not
--  happen to share it (defect: install.sql hardcoded CREATE DATABASE/USE,
--  tmp/shop/remedy.md Phase 2). tests/run.php relies on this: it creates the
--  test schema itself and then loads this file into it.
--
--  If you need a one-liner that also creates the database, do it at the shell:
--    mysql -u root -p -e "CREATE DATABASE mydb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--    mysql -u root -p mydb < install.sql
-- ============================================================

-- ─── PACKAGES ────────────────────────────────────────────────
CREATE TABLE packages (
  id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                      VARCHAR(80)      NOT NULL,
  image                     VARCHAR(200)     NULL,
  entry_fee                 DECIMAL(12,2)    NOT NULL,
  pairing_bonus             DECIMAL(12,2)    NOT NULL,
  daily_pair_cap            TINYINT UNSIGNED NOT NULL DEFAULT 3,
  direct_ref_bonus          DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  -- v2: Lifetime capping & DFI
  lifetime_cap_multiplier   DECIMAL(5,2)     NOT NULL DEFAULT 3.00,
  reactivation_fee          DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  reactivation_window_days  INT              NOT NULL DEFAULT 15,
  daily_fixed_income        DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  daily_fixed_income_days   INT              NOT NULL DEFAULT 90,
  status                    ENUM('active','inactive') NOT NULL DEFAULT 'active',
  indirect_referral_enabled TINYINT(1)       NOT NULL DEFAULT 1,
  dfi_enabled               TINYINT(1)       NOT NULL DEFAULT 1,
  pairing_enabled           TINYINT(1)       NOT NULL DEFAULT 1,
  created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── INDIRECT REFERRAL LEVELS ────────────────────────────────
CREATE TABLE package_indirect_levels (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id INT UNSIGNED     NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,
  bonus      DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
  UNIQUE KEY uq_pkg_level (package_id, level)
) ENGINE=InnoDB;

-- ─── SHOP CATALOG (Phase 1.2 — plan §2.3) ─────────────────────
CREATE TABLE products (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku               VARCHAR(64)  NULL,  -- optional; unique when set
  name              VARCHAR(160) NOT NULL,
  product_type      ENUM('physical') NOT NULL DEFAULT 'physical',
  price             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  product_pv        DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- inert (§0.2): never read/written by PHP
  pv_value          DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- inert (§0.2): never read/written by PHP
  stock             INT UNSIGNED  NOT NULL DEFAULT 0,
  image_url         VARCHAR(255)  NULL,
  short_description VARCHAR(255)  NULL,
  description       TEXT          NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY sku (sku),
  INDEX idx_products_status (status),
  INDEX idx_products_name (name)
) ENGINE=InnoDB;

-- ─── USERS ────────────────────────────────────────────────────
CREATE TABLE users (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username          VARCHAR(40)  NOT NULL UNIQUE,
  password_hash     VARCHAR(255) NOT NULL,
  role              ENUM('member','admin','superadmin') NOT NULL DEFAULT 'member',
  package_id        INT UNSIGNED NULL,
  reg_code_id           INT UNSIGNED NULL,
  reg_payment_method    ENUM('code','ewallet','pending','standard') NOT NULL DEFAULT 'code',
  reg_paid_by           INT UNSIGNED NULL,

  -- Binary tree placement
  sponsor_id        INT UNSIGNED NULL,
  binary_parent_id  INT UNSIGNED NULL,
  binary_position   ENUM('left','right') NULL,

  -- Pair counters (pairs_paid + pairs_flushed = total ever processed)
  left_count        INT UNSIGNED NOT NULL DEFAULT 0,
  left_count_paid   INT UNSIGNED NOT NULL DEFAULT 0,
  right_count       INT UNSIGNED NOT NULL DEFAULT 0,
  right_count_paid  INT UNSIGNED NOT NULL DEFAULT 0,
  pairs_paid        INT UNSIGNED NOT NULL DEFAULT 0,
  pairs_flushed     INT UNSIGNED NOT NULL DEFAULT 0,
  pairs_paid_today  INT UNSIGNED NOT NULL DEFAULT 0,  -- reset by midnight cron

  -- v2: Lifetime capping & DFI
  lifetime_earned       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cap_status            ENUM('active','capped','perminact') NOT NULL DEFAULT 'active',
  capping_bypass        TINYINT(1)   NOT NULL DEFAULT 0,
  daily_cap_bypass      TINYINT(1)   NOT NULL DEFAULT 0,
  capped_at             TIMESTAMP NULL,
  last_reactivation_at  TIMESTAMP NULL,
  dfi_days_used         INT UNSIGNED NOT NULL DEFAULT 0,
  dfi_active            TINYINT(1)   NOT NULL DEFAULT 1,
  cd_active             TINYINT(1)   NOT NULL DEFAULT 0,
  ewallet_sent_today      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ewallet_sent_this_week  DECIMAL(12,2) NOT NULL DEFAULT 0.00,

  -- Profile
  full_name         VARCHAR(120) NULL,
  email             VARCHAR(120) NULL,
  mobile            VARCHAR(20)  NULL,
  gcash_number      VARCHAR(20)  NULL,
  maya_number       VARCHAR(20)  NULL,
  usdt_trc20_address VARCHAR(100) NULL,
  usdt_bep20_address VARCHAR(100) NULL,
  address           TEXT         NULL,
  photo             VARCHAR(200) NULL,

  ewallet_balance       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  withdrawable_balance  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status                ENUM('active','suspended','pending','deactivated') NOT NULL DEFAULT 'active',
  joined_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login        TIMESTAMP NULL,

  FOREIGN KEY (sponsor_id)       REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (binary_parent_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (package_id)       REFERENCES packages(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── REGISTRATION CODES ───────────────────────────────────────
CREATE TABLE reg_codes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(24)   NOT NULL UNIQUE,
  package_id  INT UNSIGNED  NOT NULL,
  price       DECIMAL(12,2) NOT NULL,
  status      ENUM('unused','used','expired') NOT NULL DEFAULT 'unused',
  code_type   ENUM('registration','cd','upgrade') NOT NULL DEFAULT 'registration',
  used_by     INT UNSIGNED  NULL,
  created_by  INT UNSIGNED  NOT NULL,
  used_at     TIMESTAMP     NULL,
  expires_at  DATE          NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (used_by)    REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Tie reg_codes FKs back to users (added after users table)
ALTER TABLE users ADD FOREIGN KEY (reg_code_id) REFERENCES reg_codes(id) ON DELETE SET NULL;

-- ─── MEMBER DELIVERY ADDRESS BOOK ────────────────────────────
CREATE TABLE member_addresses (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id      INT UNSIGNED NOT NULL,
  label          VARCHAR(40)  NULL,
  recipient_name VARCHAR(120) NOT NULL,
  phone          VARCHAR(40)  NULL,
  address_line   VARCHAR(255) NOT NULL,
  barangay       VARCHAR(120) NULL,
  city           VARCHAR(80)  NULL,
  province       VARCHAR(80)  NULL,
  region         VARCHAR(80)  NULL,
  postal         VARCHAR(20)  NULL,
  country        VARCHAR(2)   NOT NULL DEFAULT 'PH',
  region_code    VARCHAR(20)  NULL,
  province_code  VARCHAR(20)  NULL,
  city_code      VARCHAR(20)  NULL,
  barangay_code  VARCHAR(20)  NULL,
  billing_name   VARCHAR(120) NULL,
  billing_phone  VARCHAR(40)  NULL,
  notes_member   VARCHAR(500) NULL,
  is_default     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_member_addr (member_id, is_default),
  FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── SHOP: CARTS + ORDERS (Phase 1.2 — §2.3 order) ───────────
CREATE TABLE carts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  status     ENUM('active','abandoned','converted') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_carts_member_status (member_id, status),
  FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE shop_orders (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no            VARCHAR(20) NOT NULL,
  member_id           INT UNSIGNED NOT NULL,
  total_price         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_pv            DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- inert (§0.2)
  payment_method      ENUM('ewallet','gcash','maya','usdt_trc20','usdt_bep20') NOT NULL DEFAULT 'ewallet',
  payment_reference   VARCHAR(40) NULL,
  idempotency_key     VARCHAR(64) NULL,
  status              ENUM('pending','payment_review','payment_failed','paid','packing','ready_to_ship','shipped','out_for_delivery','delivery_failed','returned_to_sender','delivered','completed','cancelled','on_hold') NOT NULL DEFAULT 'pending',
  previous_status     ENUM('pending','payment_review','payment_failed','paid','packing','ready_to_ship','shipped','out_for_delivery','delivery_failed','returned_to_sender','delivered','completed','cancelled') NULL,
  payment_deadline    DATETIME NULL,
  correction_deadline DATETIME NULL,
  completion_due_at   DATETIME NULL,
  hold_deadline       DATETIME NULL,
  hold_owner_id       INT UNSIGNED NULL,
  hold_reason         VARCHAR(160) NULL,
  billing_name        VARCHAR(120) NOT NULL,
  billing_phone       VARCHAR(40) NULL,
  billing_email       VARCHAR(120) NULL,
  shipping_name       VARCHAR(120) NOT NULL,
  shipping_phone      VARCHAR(40) NULL,
  shipping_address    VARCHAR(255) NOT NULL,
  shipping_city       VARCHAR(80) NULL,
  shipping_province   VARCHAR(80) NULL,
  shipping_postcode   VARCHAR(20) NULL,  -- rev-4 legacy name (unused)
  shipping_postal     VARCHAR(20) NULL,  -- plan §2.3 name
  shipping_country    VARCHAR(2) NULL DEFAULT 'PH',
  notes_member        VARCHAR(500) NULL,
  notes_admin         VARCHAR(500) NULL,
  terms_version       VARCHAR(16) NULL,
  terms_accepted_at   DATETIME NULL,
  paid_by             INT UNSIGNED NULL,
  paid_at             DATETIME NULL,
  approved_by         INT UNSIGNED NULL,
  approved_at         DATETIME NULL,
  packed_by           INT UNSIGNED NULL,
  packed_at           DATETIME NULL,
  shipped_by          INT UNSIGNED NULL,
  shipped_at          DATETIME NULL,
  delivered_by        INT UNSIGNED NULL,
  delivered_at        DATETIME NULL,
  completed_by        INT UNSIGNED NULL,
  completed_at        DATETIME NULL,
  cancelled_by        INT UNSIGNED NULL,
  cancelled_at        DATETIME NULL,
  cancelled_reason    VARCHAR(160) NULL,
  version             INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY order_no (order_no),
  UNIQUE KEY idempotency_key (idempotency_key),
  INDEX paid_by (paid_by),
  INDEX approved_by (approved_by),
  INDEX packed_by (packed_by),
  INDEX shipped_by (shipped_by),
  INDEX delivered_by (delivered_by),
  INDEX completed_by (completed_by),
  INDEX cancelled_by (cancelled_by),
  INDEX hold_owner_id (hold_owner_id),
  INDEX idx_shop_orders_member_status (member_id, status, created_at),
  INDEX idx_shop_orders_status_deadlines (status, payment_deadline, correction_deadline, completion_due_at),
  INDEX idx_shop_orders_idem (idempotency_key),
  FOREIGN KEY (member_id)     REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (paid_by)       REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by)   REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (packed_by)     REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (shipped_by)    REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (delivered_by)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (completed_by)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (cancelled_by)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (hold_owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shop_order_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  product_name      VARCHAR(160) NOT NULL,
  product_sku       VARCHAR(64) NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_pv           DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- inert (§0.2)
  total_price       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_pv          DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- inert (§0.2)
  reservation_state ENUM('reserved','committed','deducted','released','written_off') NOT NULL DEFAULT 'reserved',
  deducted_at       DATETIME NULL,
  released_at       DATETIME NULL,
  INDEX idx_shop_order_items_order (order_id),
  INDEX idx_shop_order_items_product (product_id),
  FOREIGN KEY (order_id)   REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE shop_order_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status   VARCHAR(20) NOT NULL,
  actor_type  ENUM('customer','admin','system') NOT NULL DEFAULT 'system',
  actor_id    INT UNSIGNED NULL,
  source      ENUM('ui','api','timer','carrier','system') NOT NULL DEFAULT 'ui',
  reason_code VARCHAR(40) NULL,
  note        VARCHAR(500) NULL,
  meta_json   JSON NULL,
  ip          VARCHAR(45) NULL,
  ua          VARCHAR(255) NULL,
  created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX actor_id (actor_id),
  INDEX idx_shop_order_events_order_created (order_id, created_at),
  FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shop_shipments (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id            INT UNSIGNED NOT NULL,
  reship_of           INT UNSIGNED NULL,
  seq                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
  state               ENUM('draft','active','closed','void') NOT NULL DEFAULT 'active',
  courier             VARCHAR(40) NULL,
  tracking_number     VARCHAR(80) NULL,
  courier_link        VARCHAR(255) NULL,
  handoff_at          DATETIME NULL,
  expected_delivery_at DATETIME NULL,
  out_for_delivery_at DATETIME NULL,
  delivered_at        DATETIME NULL,
  pod_receiver        VARCHAR(120) NULL,
  pod_image           VARCHAR(255) NULL,
  report_window_end   DATETIME NULL,
  rts_at              DATETIME NULL,
  attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fault               ENUM('customer','carrier','shop') NULL,
  fail_reason         VARCHAR(160) NULL,
  response_deadline   DATETIME NULL,
  closed_at           DATETIME NULL,
  closed_reason       VARCHAR(80) NULL,
  created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX reship_of (reship_of),
  INDEX idx_shop_shipments_order_seq (order_id, seq),
  INDEX idx_tracking (tracking_number),
  FOREIGN KEY (order_id)  REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (reship_of) REFERENCES shop_shipments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shop_payment_proofs (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id         INT UNSIGNED NOT NULL,
  attempt_no       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  proof_image      VARCHAR(255) NOT NULL,
  mime             VARCHAR(40) NULL,
  size_bytes       INT UNSIGNED NULL,
  submitted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by      INT UNSIGNED NULL,
  reviewed_at      DATETIME NULL,
  status           ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  reject_reason    VARCHAR(160) NULL,
  amount_received  DECIMAL(12,2) NULL,
  reference_no     VARCHAR(40) NULL,
  amount_sent      DECIMAL(12,2) NULL,
  transfer_date    DATE NULL,
  uploaded_by      INT UNSIGNED NULL,
  ip               VARCHAR(45) NULL,
  INDEX reviewed_by (reviewed_by),
  INDEX idx_shop_payment_proofs_order_attempt (order_id, attempt_no),
  FOREIGN KEY (order_id)    REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shop_refunds (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cause         ENUM('admin_cancel','delivery_failure','customer_dispute','other') NOT NULL DEFAULT 'admin_cancel',
  destination   VARCHAR(120) NULL,
  method        ENUM('ewallet','cash','bank','manual') NOT NULL DEFAULT 'ewallet',
  status        ENUM('open','approved','processed','denied','closed') NOT NULL DEFAULT 'open',
  receipt_ref   VARCHAR(80) NULL,
  promised_at   DATETIME NULL,
  requested_by  INT UNSIGNED NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME NULL,
  processed_by  INT UNSIGNED NULL,
  processed_at  DATETIME NULL,
  note          VARCHAR(500) NULL,
  ledger_ref_id INT UNSIGNED NULL,
  created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX requested_by (requested_by),
  INDEX approved_by (approved_by),
  INDEX processed_by (processed_by),
  INDEX idx_shop_refunds_order_status (order_id, status),
  FOREIGN KEY (order_id)     REFERENCES shop_orders(id) ON DELETE CASCADE,  -- M16: was RESTRICT; matches the other four shop children (migrate_shop_refunds_cascade.sql)
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Immutable sales-invoice snapshots. A row is written once when the order
-- reaches `paid` (ShopOrder::applySideEffects → ShopInvoice::issue) and its
-- printed columns are never UPDATEd afterwards; only voided_at/void_reason
-- may change (payment reverted / order cancelled). Re-payment after a void
-- appends a new row (invoice_no -R2, …).
CREATE TABLE shop_invoices (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  invoice_no        VARCHAR(32) NOT NULL,
  issued_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by_type    ENUM('system','admin') NOT NULL DEFAULT 'system',
  issued_by_id      INT UNSIGNED NULL,
  voided_at         DATETIME NULL,
  void_reason       VARCHAR(80) NULL,
  snapshot_version  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  order_no          VARCHAR(20) NOT NULL,
  order_created_at  DATETIME NULL,
  status_at_issue   VARCHAR(20) NOT NULL,
  seller_name       VARCHAR(160) NOT NULL DEFAULT '',
  seller_address    VARCHAR(500) NULL,
  seller_tin        VARCHAR(40) NULL,
  seller_phone      VARCHAR(40) NULL,
  seller_email      VARCHAR(120) NULL,
  seller_footer     VARCHAR(500) NULL,
  seller_vat_note   VARCHAR(500) NULL,
  currency          VARCHAR(8) NOT NULL DEFAULT 'PHP',
  billing_name      VARCHAR(120) NOT NULL DEFAULT '',
  billing_phone     VARCHAR(40) NULL,
  billing_email     VARCHAR(120) NULL,
  shipping_name     VARCHAR(120) NOT NULL DEFAULT '',
  shipping_phone    VARCHAR(40) NULL,
  shipping_address  VARCHAR(255) NULL,
  shipping_city     VARCHAR(80) NULL,
  shipping_province VARCHAR(80) NULL,
  shipping_postal   VARCHAR(20) NULL,
  shipping_country  VARCHAR(2) NULL,
  payment_method    VARCHAR(20) NOT NULL DEFAULT '',
  payment_reference VARCHAR(40) NULL,
  paid_at           DATETIME NULL,
  notes_member      VARCHAR(500) NULL,
  buyer_username    VARCHAR(60) NULL,
  subtotal          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shop_invoices_no (invoice_no),
  INDEX idx_shop_invoices_order (order_id, voided_at),
  FOREIGN KEY (order_id)     REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (issued_by_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shop_invoice_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id  INT UNSIGNED NOT NULL,
  line_no     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  description VARCHAR(160) NOT NULL,
  sku         VARCHAR(64) NULL,
  quantity    INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  INDEX idx_shop_invoice_items_invoice (invoice_id),
  FOREIGN KEY (invoice_id) REFERENCES shop_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cart_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_pv    DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- inert (§0.2)
  added_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_product (cart_id, product_id),
  INDEX product_id (product_id),
  INDEX idx_cart_items_cart (cart_id),
  FOREIGN KEY (cart_id)    REFERENCES carts(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ─── COMMISSIONS ──────────────────────────────────────────────
CREATE TABLE commissions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  type           ENUM('pairing','direct_referral','indirect_referral','daily_fixed_income') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cap_deduction  DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- v2: amount blocked by lifetime cap
  source_user_id INT UNSIGNED  NULL,
  level          TINYINT UNSIGNED NULL,
  pairs_count    TINYINT UNSIGNED NULL,
  description    VARCHAR(255)  NULL,
  status         ENUM('credited','flushed') NOT NULL DEFAULT 'credited',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)        REFERENCES users(id),
  FOREIGN KEY (source_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── E-WALLET LEDGER ──────────────────────────────────────────
CREATE TABLE ewallet_ledger (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  NOT NULL,
  type          ENUM('credit','debit') NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  reference_id  INT UNSIGNED  NULL,
  ref_type      ENUM('commission','payout','reactivation','transfer','topup','registration','upgrade','shop_order') NULL,  -- C7: added 'upgrade' (migrate_ewallet_ref_types.sql). Keep in sync with Ewallet::VALID_REF_TYPES
  balance_after DECIMAL(14,2) NOT NULL,
  note          VARCHAR(255)  NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ─── E-WALLET TRANSFERS ─────────────────────────────────────
CREATE TABLE ewallet_transfers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id     INT UNSIGNED NOT NULL,
  recipient_id  INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  fee           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_amount    DECIMAL(12,2) NOT NULL,
  status        ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id)    REFERENCES users(id),
  FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ─── E-WALLET ADMIN TOP-UPS ─────────────────────────────────
CREATE TABLE ewallet_admin_topups (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id      INT UNSIGNED NOT NULL,
  recipient_id  INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id)     REFERENCES users(id),
  FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ─── PAYOUT REQUESTS ──────────────────────────────────────────
CREATE TABLE payout_requests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  payout_method  ENUM('gcash','maya','usdt_trc20','usdt_bep20') NOT NULL DEFAULT 'gcash',
  payout_account VARCHAR(100) NOT NULL DEFAULT '',
  service_fee_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  service_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  usdt_trc20_rate          DECIMAL(12,4) NOT NULL DEFAULT 0.00,
  usdt_trc20_gas_fee       DECIMAL(10,4) NOT NULL DEFAULT 0.00,
  usdt_trc20_amount        DECIMAL(12,4) NOT NULL DEFAULT 0.00,
  usdt_bep20_rate          DECIMAL(12,4) NOT NULL DEFAULT 0.00,
  usdt_bep20_gas_fee       DECIMAL(10,4) NOT NULL DEFAULT 0.00,
  usdt_bep20_amount        DECIMAL(12,4) NOT NULL DEFAULT 0.00,
  status        ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  admin_note    TEXT          NULL,
  processed_by  INT UNSIGNED  NULL,
  requested_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at  TIMESTAMP NULL,
  FOREIGN KEY (user_id)      REFERENCES users(id),
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── REACTIVATIONS ────────────────────────────────────────────
CREATE TABLE reactivations (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED  NOT NULL,
  amount_paid         DECIMAL(12,2) NOT NULL,
  previous_earned     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  package_id          INT UNSIGNED  NOT NULL,
  payment_method      ENUM('ewallet','gcash','maya','usdt_trc20','usdt_bep20','admin') NOT NULL DEFAULT 'ewallet',
  status              ENUM('pending','completed','rejected') NOT NULL DEFAULT 'completed',
  admin_note          TEXT          NULL,
  proof_image         VARCHAR(255)  NULL,
  processed_by        INT UNSIGNED  NULL,
  processed_at        TIMESTAMP NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id),
  FOREIGN KEY (package_id)  REFERENCES packages(id),
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_react_user (user_id, created_at),            -- v2
  INDEX idx_react_status (status, created_at),            -- v2
  INDEX fk_reactivations_processed_by (processed_by)
) ENGINE=InnoDB;

-- ─── DAILY FIXED INCOME LOG ───────────────────────────────────
CREATE TABLE daily_fixed_income_log (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED  NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  day_number          INT UNSIGNED  NOT NULL,
  cap_status_at_payout ENUM('active','capped','perminact') NOT NULL DEFAULT 'active',
  cap_remaining       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_dfi_user_date (user_id, created_at),         -- v2
  UNIQUE KEY uq_user_day (user_id, day_number)
) ENGINE=InnoDB;

-- ─── COMMISSION-DEDUCT (CD) STATUS ────────────────────────────
CREATE TABLE user_cd_status (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED  NOT NULL,
  target_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  filled_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status          ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  assigned_by     INT UNSIGNED  NOT NULL,
  assigned_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at    TIMESTAMP NULL,
  cancelled_at    TIMESTAMP NULL,
  notes           TEXT NULL,
  FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_active (user_id, status),
  INDEX idx_assigned_at (assigned_at)
) ENGINE=InnoDB;

-- ─── COMMISSION-DEDUCT LEDGER ─────────────────────────────────
CREATE TABLE cd_ledger (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  user_id              INT UNSIGNED  NOT NULL,
  cd_status_id         INT           NOT NULL,  -- signed: must match user_cd_status.id (MySQL 8 FK type check)
  commission_id        INT UNSIGNED  NULL,
  type                 ENUM('pairing','direct_referral','indirect_referral') NOT NULL,
  gross_amount         DECIMAL(12,2) NOT NULL,
  cd_amount            DECIMAL(12,2) NOT NULL,
  withdrawable_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  source_user_id       INT UNSIGNED  NULL,
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)        REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (cd_status_id)   REFERENCES user_cd_status(id) ON DELETE CASCADE,
  FOREIGN KEY (commission_id)  REFERENCES commissions(id) ON DELETE SET NULL,
  FOREIGN KEY (source_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_cd (user_id, cd_status_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ─── SYSTEM SETTINGS ──────────────────────────────────────────
CREATE TABLE settings (
  key_name   VARCHAR(80) NOT NULL PRIMARY KEY,
  value      TEXT        NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── SUPER-LOGIN (S-LOGIN) AUDIT LOG ──────────────────────────
CREATE TABLE impersonation_log (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  superadmin_id   INT UNSIGNED NOT NULL,
  superadmin_name VARCHAR(40)  NOT NULL,
  target_user_id  INT UNSIGNED NOT NULL,
  target_username VARCHAR(40)  NOT NULL,
  nonce_hash      VARCHAR(255) NOT NULL,
  ip              VARCHAR(45)  NOT NULL,
  user_agent      VARCHAR(255) NOT NULL,
  status          ENUM('active','expired','logged_out') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME NOT NULL,
  FOREIGN KEY (superadmin_id) REFERENCES users(id),
  FOREIGN KEY (target_user_id) REFERENCES users(id),
  INDEX idx_imp_super (superadmin_id, created_at),
  INDEX idx_imp_target (target_user_id, created_at),
  INDEX idx_imp_status (status)
) ENGINE=InnoDB;

-- ─── INDEXES ──────────────────────────────────────────────────
-- (Indexes already defined inline in CREATE TABLE for reactivations & daily_fixed_income_log)
ALTER TABLE users          ADD INDEX idx_sponsor       (sponsor_id);
ALTER TABLE users          ADD INDEX idx_binary_parent (binary_parent_id, binary_position);
ALTER TABLE users          ADD INDEX idx_role_status   (role, status);
ALTER TABLE users          ADD INDEX idx_cap_status    (cap_status, capped_at);          -- v2
ALTER TABLE users          ADD INDEX idx_dfi_active    (dfi_active, dfi_days_used);      -- v2
ALTER TABLE users          ADD INDEX idx_reg_code      (reg_code_id);
ALTER TABLE commissions    ADD INDEX idx_user_type     (user_id, type, created_at);
ALTER TABLE commissions    ADD INDEX idx_source        (source_user_id);
ALTER TABLE commissions    ADD INDEX idx_status        (status, created_at);
ALTER TABLE reg_codes      ADD INDEX idx_status        (status);
ALTER TABLE ewallet_ledger ADD INDEX idx_user          (user_id, created_at);
ALTER TABLE payout_requests ADD INDEX idx_user_status  (user_id, status);
ALTER TABLE payout_requests ADD INDEX idx_status       (status, requested_at);

-- ─── SEED DATA ────────────────────────────────────────────────

-- Default admin account (password: Admin@1234 — CHANGE ON FIRST LOGIN)
INSERT INTO users (username, password_hash, role, status, full_name, email)
VALUES (
  'admin',
  '$2y$12$h3j0mO9NbtMyLg6EsC4M6eGy6buk0zanOgPmFBIgaI8V5/CUbaYqq', -- Admin@1234
  'admin',
  'active',
  'System Administrator',
  'admin@mlm.local'
);

-- Default superadmin account (password: Sadmin@1234 — CHANGE ON FIRST LOGIN)
INSERT INTO users (username, password_hash, role, status, full_name, email)
VALUES (
  'sadmin',
  '$2y$12$Z.Ylb68X5KH9D.J8l9fYNOlXbkaVXd/S7DcOkwbhoMLn6bDFydHyC', -- Sadmin@1234
  'superadmin',
  'active',
  'Super Administrator',
  'sadmin@mlm.local'
);

-- Default starter package (v2 defaults)
INSERT INTO packages (
  name, entry_fee, pairing_bonus, daily_pair_cap, direct_ref_bonus,
  lifetime_cap_multiplier, reactivation_fee, reactivation_window_days,
  daily_fixed_income, daily_fixed_income_days, status
) VALUES (
  'Starter', 10000.00, 2000.00, 3, 500.00,
  3.00, 10000.00, 15,
  100.00, 90, 'active'
);

-- Global compensation toggles
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('commissions_enabled', '1'),
  ('commission_direct_ref_enabled', '1'),
  ('commission_indirect_ref_enabled', '1'),
  ('commission_binary_enabled', '1'),
  ('commission_dfi_enabled', '1');

-- Indirect referral levels for starter package
INSERT INTO package_indirect_levels (package_id, level, bonus) VALUES
  (1, 1,  300.00),
  (1, 2,  200.00),
  (1, 3,  150.00),
  (1, 4,  100.00),
  (1, 5,  100.00),
  (1, 6,   50.00),
  (1, 7,   50.00),
  (1, 8,   50.00),
  (1, 9,   50.00),
  (1, 10,  50.00);

-- System settings
INSERT INTO settings (key_name, value) VALUES
  ('site_name',         'AltasFarmLife'),
  ('site_tagline',      'Livelihood'),
  ('min_payout',        '500'),
  ('last_reset',        ''),
  ('maintenance_mode',  '0'),
  ('contact_email',     'support@altasfarm.com'),
  ('service_fee_gcash', '0'),
  ('service_fee_maya',  '0'),
  ('service_fee_usdt_trc20',  '5'),
  ('service_fee_usdt_bep20',  '5'),
  ('usdt_trc20_gas_fee',      '2.50'),
  ('usdt_bep20_gas_fee',      '0.05'),
  ('gcash_enabled',     '1'),
  ('maya_enabled',      '1'),
  ('gcash_number',      ''),
  ('maya_number',       ''),
  ('usdt_trc20_address',''),
  ('usdt_bep20_address',''),
  ('default_cap_multiplier', '3.00'),
  ('reactivation_ewallet_enabled', '1'),
  ('reactivation_external_enabled', '1'),
  ('ewallet_transfer_fee',        '0.00'),
  ('ewallet_min_transfer',        '50.00'),
  ('ewallet_transfer_daily_limit',  '5000.00'),
  ('ewallet_transfer_weekly_limit', '20000.00'),
  ('free_registration_enabled',   '1'),
  ('seat_limit',                  '0'),
  ('shop_enabled',                '1'),
  ('shop_payment_deadline_hours', '24'),
  ('shop_correction_window_hours','24'),
  ('shop_max_proof_attempts',     '3'),
  ('shop_report_window_days',     '5'),
  ('shop_max_delivery_attempts',  '3');

-- Demo registration code (package 1, price 10500)
INSERT INTO reg_codes (code, package_id, price, created_by)
VALUES ('DEMO-STAR-TKIT', 1, 10500.00, 1);