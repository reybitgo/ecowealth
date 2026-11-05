-- =============================================================
--  EcoWealth — MySQL schema (fresh install, Phase 5)
--
--  Authoritative schema for a NEW database. It deliberately
--  hard-codes no database name: import it into the schema you
--  created for the app, e.g.
--
--      mysql -u root ecowealth_sneat < database/schema.sql
--
--  Existing databases are upgraded with the per-phase files in
--  database/migrations/ (apply in order: 001, 002, 003, 004, 005, …).
--
--  Phase 1 owns: users.role, settings, audit_log.
--  Phase 2 owns: products.
--  Phase 3 owns: packages, package_levels, package_products.
--  Phase 4 owns: carts, cart_items.
--  Phase 5 owns: users.ewallet_balance/withdrawable_balance,
--                ewallet_ledger, ewallet_admin_topups.
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
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  email         VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) DEFAULT NULL,
  role          ENUM('member','admin','superadmin') NOT NULL DEFAULT 'member',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  -- Dual wallet bucket (Phase 5, plan §4.4). WalletService is the only
  -- writer; withdrawable_balance is always the subset <= ewallet_balance.
  ewallet_balance      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  withdrawable_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  last_login_at DATETIME     NULL DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role, is_active)
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

