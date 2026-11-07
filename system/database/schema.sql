-- =============================================================
--  EcoWealth — MySQL schema (fresh install, Phase 8)
--
--  Authoritative schema for a NEW database. It deliberately
--  hard-codes no database name: import it into the schema you
--  created for the app, e.g.
--
--      mysql -u root ecowealth_sneat < database/schema.sql
--
--  Existing databases are upgraded with the per-phase files in
--  database/migrations/ (apply in order: 001, 002, …, 010).
--
--  Phase 1 owns: users.role, settings, audit_log.
--  Phase 2 owns: products.
--  Phase 3 owns: packages, package_levels, package_products.
--  Phase 4 owns: carts, cart_items.
--  Phase 5 owns: users.ewallet_balance/withdrawable_balance,
--                ewallet_ledger, ewallet_admin_topups.
--  Phase 6 owns: addresses, shop_orders, shop_order_items,
--                shop_order_events, users payout-account columns.
--  Phase 7 owns: shop_payment_proofs, settings.shop_expiry_enabled.
--  Phase 8 owns: shop_shipments, shop_invoices, shop_invoice_items,
--                settings.shop_autocomplete_enabled.
--  Phase 9 owns: users membership columns (phone, member_status,
--                package_id, sponsor_id, referral_code,
--                reg_payment_method, reg_code_id, reg_paid_by,
--                activated_at), reg_codes, activations.
--  Later phases append their tables (plan §4.2, Appendix B).
--
--  Seed data lives in database/seeds/ so a schema import alone
--  creates no accounts, demo catalogue or packages.
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- -------------------------------------------------------------
-- Users
--  role: member (default) · admin · superadmin
--        `member` replaced the reference's `user` in Phase 1.
--
--  Phase 9 membership columns:
--    member_status     pending | active | inactive | suspended
--                      (a suspended member cannot sign in)
--    package_id        the activated associate package; NULL for a
--                      standard (shopping-only) or pending member
--    sponsor_id        the ONE upline link — there is no binary tree
--    referral_code     8-character public code used by ?ref= links
--    reg_payment_method  how the account joined
--    reg_code_id       registration code redeemed, if any
--    reg_paid_by       payer when a sponsor funded the account
--    activated_at      when the package was approved
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  email         VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) DEFAULT NULL,
  phone         VARCHAR(30)  NULL,
  role          ENUM('member','admin','superadmin') NOT NULL DEFAULT 'member',
  member_status ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'pending',
  package_id    INT UNSIGNED NULL,
  sponsor_id    INT UNSIGNED NULL,
  referral_code VARCHAR(16)  NULL,
  reg_payment_method ENUM('standard','code','ewallet','pending') NOT NULL DEFAULT 'standard',
  reg_code_id   INT UNSIGNED NULL,
  reg_paid_by   INT UNSIGNED NULL,
  activated_at  DATETIME     NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  -- Dual wallet bucket (Phase 5, plan §4.4). WalletService is the only
  -- writer; withdrawable_balance is always the subset <= ewallet_balance.
  ewallet_balance      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  withdrawable_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  -- Member payout accounts (Phase 6 profile; Phase 13 pays out to them).
  gcash_number        VARCHAR(20)  NULL,
  maya_number         VARCHAR(20)  NULL,
  usdt_trc20_address  VARCHAR(100) NULL,
  usdt_bep20_address  VARCHAR(100) NULL,
  last_login_at DATETIME     NULL DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_referral (referral_code),
  KEY idx_users_role (role, is_active),
  KEY idx_users_sponsor (sponsor_id),
  KEY idx_users_package (package_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Remember-me tokens (selector : validator pair)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS remember_tokens (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  selector   CHAR(24)     NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Settings — key/value configuration (plan §4.2)
--  Read through setting()/setting_bool(); written by the admin
--  settings page (Phase 7/15) and pre-seeded from seeds/.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  key_name   VARCHAR(80) NOT NULL,
  value      TEXT        NOT NULL,
  updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (key_name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Audit log — admin/money actions (plan §4.2, populated in Phase 15)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id     INT UNSIGNED NULL,
  actor_role   VARCHAR(20)  NULL,
  action       VARCHAR(80)  NOT NULL,
  subject_type VARCHAR(40)  NULL,
  subject_id   INT UNSIGNED NULL,
  meta_json    JSON         NULL,
  ip           VARCHAR(45)  NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor (actor_id, created_at),
  KEY idx_audit_subject (subject_type, subject_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Products — sellable catalogue (plan §4.2, §5.1; Phase 2)
--
--  stock    physical count on hand. `available` is derived:
--           stock − quantity reserved by non-terminal orders.
--  pv       reserved for residual PV (V3.0 later pillars) — never
--           read or written by the current build.
--  slug     generated from the name, stable once created.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sku               VARCHAR(64)   NULL,
  name              VARCHAR(160)  NOT NULL,
  slug              VARCHAR(180)  NOT NULL,
  price             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pv                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stock             INT UNSIGNED  NOT NULL DEFAULT 0,
  image_url         VARCHAR(255)  NULL,
  short_description VARCHAR(255)  NULL,
  description       TEXT          NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order        INT           NOT NULL DEFAULT 0,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_status (status, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Packages — associate packages (plan §4.2, §6; Phase 3)
--
--  price                 entry fee paid to activate the package
--  product_value         display value of the contents
--  direct_referral_bonus the level-1 amount; stored ONCE here.
--                        package_levels holds levels 2–10 only.
--  indirect_enabled      per-package switch for the unilevel ladder
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS packages (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(80)   NOT NULL,
  slug                  VARCHAR(100)  NOT NULL,
  image                 VARCHAR(255)  NULL,
  price                 DECIMAL(12,2) NOT NULL,
  product_value         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  direct_referral_bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  indirect_enabled      TINYINT(1)    NOT NULL DEFAULT 1,
  description           TEXT          NULL,
  perks                 TEXT          NULL,
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order            INT           NOT NULL DEFAULT 0,
  created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_packages_slug (slug),
  KEY idx_packages_status (status, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Indirect referral amounts per package, levels 2–10 only
-- (level 1 is the package's direct_referral_bonus).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS package_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id INT UNSIGNED NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pkg_level (package_id, level),
  CONSTRAINT fk_pkg_levels_pkg FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE CASCADE,
  CONSTRAINT chk_pkg_level_range CHECK (level BETWEEN 2 AND 10)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Package contents — products bundled with a package
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS package_products (
  package_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (package_id, product_id),
  CONSTRAINT fk_pkgprod_pkg  FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE CASCADE,
  CONSTRAINT fk_pkgprod_prod FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Carts — one active cart per member (plan §5.2; Phase 4)
--
--  `status` moves active → converted when the cart becomes an
--  order (Phase 6); a new active cart is created on the next add.
--  CartService re-checks the single-active-cart rule with a row
--  lock, so a concurrent first-add cannot create two carts.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  status     ENUM('active','converted','abandoned') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_carts_user_status (user_id, status),
  CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Cart lines — one row per product per cart (plan §5.2)
--
--  unit_price is a snapshot; prices are always re-read from
--  `products` on view and at checkout (Phase 6). Mutations are
--  always scoped by cart_id (reference defect C3 — IDOR).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cart_items (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  added_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_product (cart_id, product_id),
  KEY idx_cart_items_cart (cart_id),
  CONSTRAINT fk_cart_items_cart    FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- E-wallet ledger — immutable wallet movements (plan §4.2, §5.8;
-- Phase 5)
--
--  One row per movement, written by WalletService only. There is no
--  UPDATE/DELETE path and no updated_at column: corrections are new
--  rows (refund / adjustment).
--
--  balance_after       total bucket after the movement
--  withdrawable_after  withdrawable bucket after the movement
--                      (Appendix B stores only balance_after; the
--                      dual-bucket invariant cannot be reconciled
--                      without this snapshot — see migrations/005)
--  ref_type            plan §4.4 movement kinds; the PHP list lives
--                      in WalletService::REF_TYPES and must agree
--  ref_id              source row id (order, payout, top-up, …)
--
--  uq_ewallet_idempotency makes a replayed system movement a no-op.
--  It is scoped by user because a transfer writes three rows under
--  one (ref_type, ref_id).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ewallet_ledger (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED  NOT NULL,
  type              ENUM('credit','debit') NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  balance_after     DECIMAL(14,2) NOT NULL,
  withdrawable_after DECIMAL(14,2) NOT NULL,
  ref_type          ENUM('commission','payout','transfer','topup','registration',
                         'shop_order','adjustment','refund') NOT NULL,
  ref_id            INT UNSIGNED  NULL,
  note              VARCHAR(255)  NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ewallet_idempotency (user_id, ref_type, ref_id, type),
  KEY idx_ewallet_user (user_id, created_at),
  KEY idx_ewallet_ref (ref_type, ref_id),
  CONSTRAINT fk_ewallet_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Admin top-ups — every admin-created credit, auditable (Phase 5, §5.8)
--
--  Top-ups credit the NON-withdrawable bucket (reference behaviour)
--  so the money can be spent in the shop but not cashed out. One row
--  per top-up, never edited; the ledger records the money movement.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ewallet_admin_topups (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  admin_id     INT UNSIGNED  NOT NULL,
  recipient_id INT UNSIGNED  NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  note         VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_topups_admin (admin_id, created_at),
  KEY idx_topups_recipient (recipient_id, created_at),
  CONSTRAINT fk_topups_admin     FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_topups_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Addresses — the member's address book (plan §5.3; Phase 6)
--
--  is_default marks the address checkout pre-fills. At most one
--  default per member is enforced by AddressService (which clears
--  the others inside the same transaction), not by an index.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS addresses (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  label          VARCHAR(40)  NULL,
  recipient_name VARCHAR(120) NOT NULL,
  phone          VARCHAR(40)  NULL,
  address_line   VARCHAR(255) NOT NULL,
  barangay       VARCHAR(120) NULL,
  city           VARCHAR(80)  NULL,
  province       VARCHAR(80)  NULL,
  postal         VARCHAR(20)  NULL,
  country        CHAR(2)      NOT NULL DEFAULT 'PH',
  billing_name   VARCHAR(120) NULL,
  billing_phone  VARCHAR(40)  NULL,
  notes          VARCHAR(500) NULL,
  is_default     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_addr_user (user_id, is_default),
  CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Orders — one row per placed order (plan §4.4, §5.3; Phase 6)
--
--  Address columns are a snapshot, never a live address-book link.
--  order_no = EW + yymmdd + 5-digit id (written after insert).
--  idempotency_key is the unique anchor for double-submit replay.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_orders (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no            VARCHAR(20)  NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  status              ENUM('pending','payment_review','payment_failed','paid','packing',
                           'ready_to_ship','shipped','delivered','completed','cancelled')
                      NOT NULL DEFAULT 'pending',
  payment_method      ENUM('ewallet','gcash','maya','bank') NOT NULL DEFAULT 'ewallet',
  payment_reference   VARCHAR(40)  NULL,
  idempotency_key     VARCHAR(64)  NULL,
  subtotal            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  billing_name        VARCHAR(120) NOT NULL,
  billing_phone       VARCHAR(40)  NULL,
  shipping_name       VARCHAR(120) NOT NULL,
  shipping_phone      VARCHAR(40)  NULL,
  shipping_address    VARCHAR(255) NOT NULL,
  shipping_barangay   VARCHAR(120) NULL,
  shipping_city       VARCHAR(80)  NULL,
  shipping_province   VARCHAR(80)  NULL,
  shipping_postal     VARCHAR(20)  NULL,
  shipping_country    CHAR(2)      NOT NULL DEFAULT 'PH',
  notes_member        VARCHAR(500) NULL,
  notes_admin         VARCHAR(500) NULL,
  terms_version       VARCHAR(16)  NOT NULL DEFAULT 'v1',
  terms_accepted_at   DATETIME     NULL,
  payment_deadline    DATETIME     NULL,
  correction_deadline DATETIME     NULL,
  paid_at             DATETIME     NULL,
  packed_at           DATETIME     NULL,
  shipped_at          DATETIME     NULL,
  delivered_at        DATETIME     NULL,
  completed_at        DATETIME     NULL,
  cancelled_at        DATETIME     NULL,
  cancelled_reason    VARCHAR(160) NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shop_orders_no (order_no),
  UNIQUE KEY uq_shop_orders_idem (idempotency_key),
  KEY idx_orders_user_status (user_id, status, created_at),
  KEY idx_orders_status (status, payment_deadline),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Order lines — priced snapshots + the stock reservation state
--  reserved → committed at payment → deducted at ship → or released
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_order_items (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  product_name      VARCHAR(160) NOT NULL,
  product_sku       VARCHAR(64)  NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reservation_state ENUM('reserved','committed','deducted','released') NOT NULL DEFAULT 'reserved',
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id, reservation_state),
  CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Order events — append-only status audit trail (plan §4.4)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_order_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(20)  NULL,
  to_status   VARCHAR(20)  NOT NULL,
  actor_type  ENUM('member','admin','system') NOT NULL,
  actor_id    INT UNSIGNED NULL,
  reason_code VARCHAR(40)  NULL,
  note        VARCHAR(500) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_events_order (order_id, created_at),
  CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Payment proofs — manual payment evidence, one row per attempt
-- (plan §5.4; Phase 7). pending → verified | rejected; the proof is
-- never deleted, so a verified order keeps its evidence.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_payment_proofs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       INT UNSIGNED NOT NULL,
  attempt_no     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  file_path      VARCHAR(255) NOT NULL,
  mime           VARCHAR(40)  NULL,
  size_bytes     INT UNSIGNED NULL,
  amount_claimed DECIMAL(12,2) NULL,
  reference_no   VARCHAR(40)  NULL,
  transfer_date  DATE         NULL,
  status         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  reject_reason  VARCHAR(160) NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_proofs_order (order_id, attempt_no),
  KEY idx_proofs_reference (reference_no),
  CONSTRAINT fk_proofs_order    FOREIGN KEY (order_id)    REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_proofs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Shipments — courier + tracking (plan §5.5; Phase 8). One row is
-- written when an order leaves `ready_to_ship`; the stock is
-- deducted in the same transaction, so a shipment always has its
-- physical movement.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_shipments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        INT UNSIGNED NOT NULL,
  courier         VARCHAR(40)  NULL,
  tracking_number VARCHAR(80)  NULL,
  delivered_at    DATETIME     NULL,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shipments_order (order_id),
  CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Invoices — one frozen snapshot per paid order (plan §5.7;
-- Phase 8). The printed columns are never updated; only voided_at /
-- void_reason change when the payment is reversed. Numbering is
-- INV + yymmdd + the 5-digit id.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_invoices (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id         INT UNSIGNED NOT NULL,
  invoice_no       VARCHAR(32)  NOT NULL,
  issued_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_at_issue  VARCHAR(20)  NOT NULL,
  seller_name      VARCHAR(160) NOT NULL,
  seller_address   VARCHAR(500) NULL,
  seller_tin       VARCHAR(40)  NULL,
  seller_phone     VARCHAR(40)  NULL,
  buyer_username   VARCHAR(60)  NULL,
  billing_name     VARCHAR(120) NOT NULL,
  billing_phone    VARCHAR(40)  NULL,
  shipping_name    VARCHAR(120) NOT NULL,
  shipping_address VARCHAR(255) NOT NULL,
  payment_method   VARCHAR(20)  NOT NULL,
  paid_at          DATETIME     NULL,
  subtotal         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  voided_at        DATETIME     NULL,
  void_reason      VARCHAR(80)  NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoices_no (invoice_no),
  KEY idx_invoices_order (order_id),
  CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Invoice lines — the frozen description / price snapshots
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_invoice_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id  INT UNSIGNED NOT NULL,
  line_no     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  description VARCHAR(160) NOT NULL,
  sku         VARCHAR(64)  NULL,
  quantity    INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_invoice_items_invoice (invoice_id),
  CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES shop_invoices (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Registration codes (plan §5.10; Phase 9)
--
--  Admin-issued, package-bound, priced and optionally expiring.
--  Format: ABCD-EFGH-JKLM from an unambiguous 32-character set,
--  stored uppercase and compared case-insensitively.
--
--  Single use is enforced by CodeService's conditional UPDATE
--  (`WHERE status='unused'`, row count must be 1), never by a
--  read-then-write race. States: unused → used | expired.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reg_codes (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code       VARCHAR(24)   NOT NULL,
  package_id INT UNSIGNED  NOT NULL,
  price      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  code_type  ENUM('registration','upgrade') NOT NULL DEFAULT 'registration',
  status     ENUM('unused','used','expired') NOT NULL DEFAULT 'unused',
  created_by INT UNSIGNED  NOT NULL,
  used_by    INT UNSIGNED  NULL,
  used_at    DATETIME      NULL,
  expires_at DATE          NULL,
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_codes_code (code),
  KEY idx_reg_codes_status (status),
  KEY idx_reg_codes_package (package_id),
  KEY idx_reg_codes_user (used_by),
  CONSTRAINT fk_reg_codes_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_codes_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_codes_user    FOREIGN KEY (used_by)    REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Activations (plan §4.4, §5.10, §6.2; Phase 9)
--
--  One row per package purchase attempt. Registration-time payments
--  (code / e-wallet) write an already-`approved` row so every
--  commission in Phases 10–11 points at the activation that caused it.
--
--  commission_processed_at is the idempotency anchor: stamped in the
--  same transaction that awards commissions; a second approval bails
--  out when it is already set.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activations (
  id                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id                 INT UNSIGNED  NOT NULL,
  package_id              INT UNSIGNED  NOT NULL,
  amount                  DECIMAL(12,2) NOT NULL,
  payment_method          ENUM('ewallet','code','gcash','maya','bank') NOT NULL,
  status                  ENUM('pending_payment','pending_review','approved','rejected','cancelled')
                          NOT NULL DEFAULT 'pending_payment',
  reg_code_id             INT UNSIGNED  NULL,
  reference_no            VARCHAR(40)   NULL,
  proof_path              VARCHAR(255)  NULL,
  reject_reason           VARCHAR(160)  NULL,
  reviewed_by             INT UNSIGNED  NULL,
  reviewed_at             DATETIME      NULL,
  commission_processed_at DATETIME      NULL,
  created_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activations_user (user_id, status),
  KEY idx_activations_status (status, created_at),
  CONSTRAINT fk_activations_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_activations_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Commissions (plan §4.2, §6.2–6.5; Phase 10)
--
--  One row per award: direct referral in Phase 10, the indirect
--  ladder (levels 2–10) in Phase 11. `type` + `level` say which leg
--  paid; `source_user_id` is the activated member whose purchase
--  funds it and `package_id` is the package that prices it.
--
--  uq_commission_event (type, source_user_id, user_id, level) is the
--  money anchor: a replayed approval or a retried job cannot create a
--  second row for the same event, recipient and level (plan §6.5).
--
--  status = 'skipped' records a due-but-unpaid award (ineligible
--  upline) for the audit trail; it never moves money. A credited row
--  always carries its `ledger_id` — the immutable wallet movement
--  (ref_type = 'commission', ref_id = commissions.id).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commissions (
  id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED     NOT NULL,                 -- recipient
  type           ENUM('direct_referral','indirect_referral') NOT NULL,
  amount         DECIMAL(12,2)    NOT NULL,
  level          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  source_user_id INT UNSIGNED     NOT NULL,                 -- the activated member
  package_id     INT UNSIGNED     NOT NULL,                 -- source package (prices the award)
  activation_id  INT UNSIGNED     NOT NULL,
  status         ENUM('credited','skipped','voided') NOT NULL DEFAULT 'credited',
  reason         VARCHAR(120)     NULL,
  ledger_id      INT UNSIGNED     NULL,                     -- ewallet_ledger row credited
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_commission_event (type, source_user_id, user_id, level),
  KEY idx_commissions_user (user_id, created_at),
  KEY idx_commissions_source (source_user_id, created_at),
  KEY idx_commissions_activation (activation_id),
  KEY idx_commissions_status (status, created_at),
  KEY idx_commissions_ledger (ledger_id),
  CONSTRAINT fk_comm_user       FOREIGN KEY (user_id)        REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_comm_source     FOREIGN KEY (source_user_id) REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_comm_package    FOREIGN KEY (package_id)     REFERENCES packages (id)     ON DELETE RESTRICT,
  CONSTRAINT fk_comm_activation FOREIGN KEY (activation_id)  REFERENCES activations (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Phase 9: users ↔ packages / sponsor links
--  Declared after `packages` exists (the users table is created
--  first and cannot reference it inline). Mirrors migration 011.
-- -------------------------------------------------------------
ALTER TABLE users
  ADD CONSTRAINT fk_users_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_sponsor FOREIGN KEY (sponsor_id) REFERENCES users (id) ON DELETE SET NULL;


