-- ===========================================================================
--  EcoWealth — production install bundle
--
--  GENERATED FILE — DO NOT EDIT.
--
--  database/install.sql is assembled from:
--      database/install/header.sql   (this file)
--      database/schema.sql
--      database/seeds/001_settings.sql
--      database/seeds/002_accounts.sql
--      database/seeds/003_products.sql
--      database/seeds/004_packages.sql
--      database/install/footer.sql
--
--  Rebuild:  php bin/build_install_sql.php
--  Verify:   php bin/build_install_sql.php --check
--  Edit the sources above, never the bundle: the test suite fails when the
--  committed bundle is older than its sources (plan IR-2, D-IR-01).
--
-- ---------------------------------------------------------------------------
--  ONE FILE, ONE EMPTY DATABASE
--
--  This bundle creates the schema and the starter data for a NEW EcoWealth
--  site:
--
--      42 tables · 89 settings (+3 install-time metadata = 92 on fresh install) · 2 staff accounts · 7 products
--      2 packages · 18 ladder levels · 10 package contents
--      10 Team Harvest level amounts · 10 General Harvest level amounts
--      1 unarmed General Harvest board row
--
--  It seeds NO demo members, NO demo registration codes and NO demo wallet
--  balances. Demo fixtures are a separate, explicit import:
--  database/seeds/005_demo_tree.sql (never on a production site).
--
--  It deliberately contains no CREATE DATABASE, USE, DELIMITER or SOURCE.
--  The caller selects the schema, so the same bytes install into any
--  database name on any machine.
--
-- ---------------------------------------------------------------------------
--  LOCAL INSTALL (MySQL CLI)
--
--      mysql -u root -e "CREATE DATABASE ecowealth_sneat
--                         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      mysql -u root ecowealth_sneat < database/install.sql
--
--  ONLINE INSTALL (phpMyAdmin, no SSH)
--
--      1. hPanel → Databases → create the database and its MySQL user.
--      2. phpMyAdmin → select that database → Import → choose install.sql.
--      3. Select "Stop on error" and click Go. The footer prints the
--         verification counts; they must match the list above.
--
--  Server support: MySQL 8.0.16+ or MariaDB 10.4+
--  (the schema uses CHECK constraints and JSON).
--
-- ---------------------------------------------------------------------------
--  DEFAULT STAFF ACCOUNTS — CHANGE BOTH PASSWORDS IMMEDIATELY
--
--      admin      / Admin@123     role: admin
--      superadmin / Super@123     role: superadmin
--
--  These are published defaults, not secrets. Until both are changed from
--  Profile → Change password, treat the site as compromised. The guided
--  installers (bin/db_install.php, public/install.php) replace them with
--  operator-chosen passwords and never leave a default in place.
--
-- ---------------------------------------------------------------------------
--  FRESH INSTALL ONLY
--
--  There is no upgrade path in this file. An existing database is upgraded
--  with database/migrations/NNN_*.sql in numeric order. Importing this
--  bundle into a non-empty schema is refused by the guard below; if an
--  import is interrupted or a tool ignores the error, recover by dropping
--  and recreating the schema and importing once more — never by re-running
--  this bundle on top of the partial result.
--
--  The guard fails immediately when the schema already holds any table.
--  MySQL cannot SIGNAL outside a stored program (and DELIMITER is forbidden
--  here), so the failure is a deliberately missing table whose name is the
--  instruction: ecowealth_install_requires_an_empty_database.
--
--  On an empty schema the guard is a no-op ("DO 0"). With one unrelated
--  table it stops with ERROR 1146 (42S02) and a `mysql` run exits non-zero
--  before a single CREATE TABLE executes.
-- ===========================================================================

SET NAMES utf8mb4;

-- The bundle is fresh-install only. If the schema contains any table at all,
-- this block fails with "table doesn't exist" and stops a `mysql < install.sql`
-- run before it can half-apply anything.
SET @ew_present := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE()
);

SET @ew_guard := IF(
  @ew_present = 0,
  'DO 0',
  'SELECT * FROM `ecowealth_install_requires_an_empty_database`'
);

PREPARE ew_guard FROM @ew_guard;
EXECUTE ew_guard;
DEALLOCATE PREPARE ew_guard;

SET time_zone = '+08:00';

-- ===========================================================================
--  SECTION 1/7 — SCHEMA
--  source: database/schema.sql sha256=6c292c89fd45f4b7563b5be70438a0f0d23bb64406dae8537ff5c5841e042aa2
-- ===========================================================================

-- =============================================================
--  EcoWealth — MySQL schema (fresh install, Phase 14)
--
--  Authoritative schema for a NEW database. It deliberately
--  hard-codes no database name: import it into the schema you
--  created for the app, e.g.
--
--      mysql -u root ecowealth_sneat < database/schema.sql
--
--  Existing databases are upgraded with the per-phase files in
--  database/migrations/ (apply in order: 001, 002, …, 026).
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
--  Phase 10/11 owns: commissions.
--  Phase 12 owns: users.ewallet_sent_today/ewallet_sent_this_week,
--                ewallet_transfers.
--  Phase 13 owns: payout_requests.
--  Phase 14 owns: impersonation_log.
--  Phase 17 owns: product_unilevel_levels, unilevel_awards,
--                unilevel_activity, unilevel_periods,
--                products.unilevel_enabled/unilevel_base,
--                shop_orders.unilevel_processed_at/unilevel_total,
--                shop_order_items.unit_base/base_total and the two
--                unilevel ledger ref types.
--  Phase 18 owns: unilevel_awards.hold_days/credited_at, the
--                'pending' + 'cancelled' award states, two lookup
--                indexes and the release settings.
--  Phase 19 owns: royalty_periods, royalty_awards and the
--                'royalty' ledger ref type (the stream is off).
--  Phase 20 owns: harvest_queue, team_harvest_levels,
--                team_harvest_awards and the 'team_harvest' ledger
--                ref type (the stream is off and unseeded).
--  Phase 21 owns: general_harvest_levels, general_harvest_board,
--                general_harvest_awards and the 'general_harvest'
--                ledger ref type (the stream is off and unarmed).
--  Phase 22 owns: binary_nodes, binary_volume, binary_awards and the
--                'binary' / 'binary_reversal' ledger ref types
--                (the stream is off by default).
--  Phase 23A amends binary_volume and binary_awards so a package
--  activation can be a second source (`source`, `activation_id`), adds
--  packages.binary_enabled/binary_points, activations.binary_* and the
--  three package_binary_* settings (no new table).
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
--    sponsor_id        the ONE upline link — there is no binary tree.
--                      Phase 20 derives the Team Harvest queue and its
--                      teammate counters from this chain (harvest_queue).
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
  -- Transfer limit usage (Phase 12, plan §5.8). Incremented by
  -- TransferService inside the locked sender row; zeroed by
  -- bin/reset_transfer_limits.php. Staff never increment them.
  ewallet_sent_today     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ewallet_sent_this_week DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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
  KEY idx_audit_subject (subject_type, subject_id),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_created (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Products — sellable catalogue (plan §4.2, §5.1; Phase 2)
--
--  stock    physical count on hand. `available` is derived:
--           stock − quantity reserved by non-terminal orders.
--  pv       the point value; from Phase 17 it is the repeat-purchase
--           bonus base when unilevel_base = 'pv'. 0 means "not set".
--  unilevel_enabled  per-product master switch (Phase 17); off by
--                    default so a migration never starts paying.
--  unilevel_base     which base the repeat-purchase ladder prices:
--                    auto (P.V. when set, else price) | price | pv
--  slug     generated from the name, stable once created.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sku               VARCHAR(64)   NULL,
  name              VARCHAR(160)  NOT NULL,
  slug              VARCHAR(180)  NOT NULL,
  price             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pv                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unilevel_enabled  TINYINT(1)    NOT NULL DEFAULT 0,
  unilevel_base     ENUM('auto','price','pv') NOT NULL DEFAULT 'auto',
  binary_enabled    TINYINT(1)    NOT NULL DEFAULT 0,
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
-- Binary nodes — the placement tree and the cached point balances
-- (Phase 22A, plan §15.3). Separate from the sponsor tree; the
-- company root is the only row with upline_id/side both NULL.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS binary_nodes (
  user_id        INT UNSIGNED     NOT NULL,
  upline_id      INT UNSIGNED     NULL,
  side           ENUM('left','right') NULL,
  root_id        INT UNSIGNED     NULL,
  depth          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  path           VARCHAR(255)     NOT NULL DEFAULT '',
  state          ENUM('root','placed','floating') NOT NULL DEFAULT 'floating',
  left_points    BIGINT           NOT NULL DEFAULT 0,
  right_points   BIGINT           NOT NULL DEFAULT 0,
  left_credited  BIGINT           NOT NULL DEFAULT 0,
  right_credited BIGINT           NOT NULL DEFAULT 0,
  points_matched BIGINT           NOT NULL DEFAULT 0,
  pairs_paid     INT UNSIGNED     NOT NULL DEFAULT 0,
  personal_points BIGINT          NOT NULL DEFAULT 0,
  placed_at      DATETIME         NULL,
  placed_by      INT UNSIGNED     NULL,
  moved_at       DATETIME         NULL,
  moved_by       INT UNSIGNED     NULL,
  move_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_binary_slot (upline_id, side),
  KEY idx_binary_upline (upline_id),
  KEY idx_binary_path (path(24)),
  KEY idx_binary_root (root_id),
  KEY idx_binary_state (state),
  CONSTRAINT fk_bn_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_bn_upline FOREIGN KEY (upline_id) REFERENCES binary_nodes (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_bn_root   FOREIGN KEY (root_id)   REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_bn_slot  CHECK ((upline_id IS NULL) = (side IS NULL)),
  CONSTRAINT chk_bn_self  CHECK (upline_id IS NULL OR upline_id <> user_id),
  CONSTRAINT chk_bn_depth CHECK (depth BETWEEN 0 AND 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Repeat-purchase ladder — one percentage per level, 1–10
-- (Phase 17, plan §13.2). The buyer's direct sponsor is level 1,
-- their sponsor level 2, and so on. Missing rows are zero (the
-- model zero-fills), exactly like package_levels; the ladder is
-- inert until the product's unilevel_enabled switch is on.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_unilevel_levels (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED     NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,
  percent    DECIMAL(6,3)     NOT NULL DEFAULT 0.000,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_unilevel (product_id, level),
  CONSTRAINT fk_pul_product  FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT chk_pul_level   CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_pul_percent CHECK (percent >= 0 AND percent <= 100)
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
  binary_enabled        TINYINT(1)    NOT NULL DEFAULT 0,
  binary_points         INT UNSIGNED  NOT NULL DEFAULT 0,
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
--                      in WalletService::REF_TYPES and must agree.
--                      Phase 17 adds 'unilevel' (a repeat-purchase
--                      award credit) and 'unilevel_reversal' (its
--                      cancellation debit); ref_id = unilevel_awards.id.
--                      Phase 19 appends 'royalty' last (a monthly
--                      award credit); ref_id = royalty_awards.id.
--                      Phase 20 appends 'team_harvest' last (a Team
--                      Harvest level credit); ref_id = team_harvest_awards.id.
--                      Phase 21 appends 'general_harvest' last (a
--                      General Harvest block credit);
--                      ref_id = general_harvest_awards.id.
--                      Phase 22 appends 'binary' and 'binary_reversal'
--                      last (a Product binary award credit and its
--                      cancellation debit); ref_id = binary_awards.id.
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
                         'shop_order','adjustment','refund','unilevel',
                         'unilevel_reversal','royalty','team_harvest',
                         'general_harvest','binary','binary_reversal') NOT NULL,
  ref_id            INT UNSIGNED  NULL,
  note              VARCHAR(255)  NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ewallet_idempotency (user_id, ref_type, ref_id, type),
  KEY idx_ewallet_user (user_id, created_at),
  KEY idx_ewallet_ref (ref_type, ref_id),
  KEY idx_ewallet_created (created_at, ref_type),
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
-- E-wallet transfers — member-to-member movement log (Phase 12,
-- plan §5.8)
--
--  One row per completed transfer. The money itself moves through
--  `ewallet_ledger` (sender debit, recipient credit, admin fee credit
--  under ref_type = 'transfer', ref_id = this row's id); this table
--  records the intent, the fee and the note.
--
--  amount      what the recipient receives
--  fee         what the primary admin receives (0 for staff senders)
--  net_amount  amount received by the recipient (same as amount in
--              this build; the payout table uses the same shape)
--
--  A refused transfer writes nothing here — there is no failed row.
--  There is no updated_at column: a completed transfer is never edited.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ewallet_transfers (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sender_id    INT UNSIGNED  NOT NULL,
  recipient_id INT UNSIGNED  NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  fee          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_amount   DECIMAL(12,2) NOT NULL,
  note         VARCHAR(255)  NULL,
  status       ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transfers_sender (sender_id, created_at),
  KEY idx_transfers_recipient (recipient_id, created_at),
  KEY idx_transfers_status (status, created_at),
  CONSTRAINT fk_transfers_sender    FOREIGN KEY (sender_id)    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_transfers_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Payout requests — the withdrawal queue (Phase 13, plan §5.9)
--
--  One row per withdrawal request. The funds are HELD at request time
--  (D17): `amount` leaves the member's wallet in the same transaction
--  that inserts this row, as a `payout` debit in `ewallet_ledger`
--  (ref_id = this row's id). Rejection writes the matching `refund`
--  row; completion moves no money at all — it records that staff sent
--  the funds off-platform.
--
--  amount              gross the member asked for (what is held)
--  service_fee_pct     settings.service_fee_<method> at request time
--  service_fee_amount  amount × pct / 100
--  usdt_rate           ₱ per 1 USDT at request time (settings, never
--                      the browser — plan §2.3)
--  usdt_gas_fee        network fee in USDT at request time
--  usdt_amount         USDT actually sent:
--                      (net − gas × rate) / rate
--  payout_account      the number/address to send to; copied from the
--                      profile when the request is made so a later
--                      profile edit cannot rewrite history
--
--  status: pending → approved → completed, or pending/approved →
--  rejected (which refunds the hold). There is no updated_at column:
--  a request is never edited, only moved through its status machine.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payout_requests (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED  NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  payout_method       ENUM('gcash','maya','usdt_trc20','usdt_bep20') NOT NULL DEFAULT 'gcash',
  payout_account      VARCHAR(120)  NOT NULL DEFAULT '',
  service_fee_pct     DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  service_fee_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  usdt_rate           DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  usdt_gas_fee        DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  usdt_amount         DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  status              ENUM('pending','approved','completed','rejected') NOT NULL DEFAULT 'pending',
  admin_note          VARCHAR(500)  NULL,
  processed_by        INT UNSIGNED  NULL,
  requested_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at        DATETIME      NULL,
  PRIMARY KEY (id),
  KEY idx_payouts_user_status (user_id, status),
  KEY idx_payouts_status (status, requested_at),
  CONSTRAINT fk_payouts_user  FOREIGN KEY (user_id)      REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_payouts_admin FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL
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
--
--  Phase 17 repeat-purchase anchor:
--    unilevel_processed_at  stamped inside the payment transaction,
--                           so a second payment event cannot pay twice
--    unilevel_total         the order's credited repeat-purchase money
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
  unilevel_processed_at DATETIME   NULL,
  unilevel_total      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  binary_position     ENUM('left','right') NULL,
  binary_processed_at DATETIME     NULL,
  binary_points       BIGINT       NOT NULL DEFAULT 0,
  binary_self_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  binary_pair_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  binary_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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
  KEY idx_orders_created (created_at),
  KEY idx_orders_paid (paid_at),
  KEY idx_orders_unilevel (unilevel_processed_at),
  KEY idx_orders_user_paid (user_id, paid_at),
  KEY idx_orders_binary (binary_processed_at),
  KEY idx_orders_binary_side (binary_position, paid_at),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Order lines — priced snapshots + the stock reservation state
--  reserved → committed at payment → deducted at ship → or released
--
--  Phase 17: unit_base / base_total freeze the repeat-purchase base
--  at placement, so a later product edit cannot rewrite a payout.
--  They stay NULL for lines created before the migration; the engine
--  then derives the base from the product (base_source = 'product').
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
  unit_base         DECIMAL(12,2) NULL,
  base_total        DECIMAL(12,2) NULL,
  unit_points       INT UNSIGNED  NOT NULL DEFAULT 0,
  points_total      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reservation_state ENUM('reserved','committed','deducted','released') NOT NULL DEFAULT 'reserved',
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id, reservation_state),
  CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Repeat-purchase awards — the Phase 17 money ledger (plan §13.2)
--
--  One row per (order line, recipient, level) decision. There is no
--  `type` column: the level (1–10) is the whole story (D-17).
--
--  status   pending   = earned at payment, not in the wallet yet
--                      (Phase 18 escrow; no ledger_id)
--           credited  = released: the wallet was credited (ledger_id,
--                      credited_at set)
--           skipped   = due but not paid (reason set); never moves money
--           cancelled = the order was cancelled before release; never
--                      paid, no ledger, no shortfall (Phase 18)
--           voided    = paid then reversed (reversal_ledger_id set)
--  hold_days   days after delivery before a pending award releases; the
--              value is snapshotted at payment so a later setting change
--              cannot move an award's promised date (Phase 18).
--  uq_unilevel_award (order_item_id, user_id, level) is the money
--  anchor: a replayed payment cannot create a second award.
--  ledger_id / reversal_ledger_id are deliberately NOT foreign keys
--  (the ledger is append-only and must outlive a correction).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS unilevel_awards (
  id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  order_id           INT UNSIGNED     NOT NULL,
  order_item_id      INT UNSIGNED     NOT NULL,
  product_id         INT UNSIGNED     NOT NULL,
  buyer_id           INT UNSIGNED     NOT NULL,
  user_id            INT UNSIGNED     NOT NULL,
  level              TINYINT UNSIGNED NOT NULL,
  base_amount        DECIMAL(12,2)    NOT NULL,
  base_source        ENUM('line','product') NOT NULL DEFAULT 'line',
  rate_pct           DECIMAL(6,3)     NOT NULL,
  amount             DECIMAL(12,2)    NOT NULL,
  hold_days          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status             ENUM('pending','credited','skipped','voided','cancelled') NOT NULL DEFAULT 'pending',
  reason             VARCHAR(120)     NULL,
  ledger_id          INT UNSIGNED     NULL,
  credited_at        DATETIME         NULL,
  reversal_ledger_id INT UNSIGNED     NULL,
  reversed_amount    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  paid_at            DATETIME         NOT NULL,
  voided_at          DATETIME         NULL,
  void_reason        VARCHAR(160)     NULL,
  created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_unilevel_award (order_item_id, user_id, level),
  KEY idx_unilevel_user (user_id, created_at),
  KEY idx_unilevel_order (order_id),
  KEY idx_unilevel_user_status (user_id, status),
  KEY idx_unilevel_order_status (order_id, status),
  KEY idx_unilevel_buyer (buyer_id, created_at),
  KEY idx_unilevel_product (product_id, created_at),
  KEY idx_unilevel_status (status, created_at),
  KEY idx_unilevel_paid (paid_at),
  KEY idx_unilevel_ledger (ledger_id),
  CONSTRAINT fk_ua_order     FOREIGN KEY (order_id)      REFERENCES shop_orders (id)      ON DELETE CASCADE,
  CONSTRAINT fk_ua_item      FOREIGN KEY (order_item_id) REFERENCES shop_order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_ua_product   FOREIGN KEY (product_id)    REFERENCES products (id)         ON DELETE RESTRICT,
  CONSTRAINT fk_ua_buyer     FOREIGN KEY (buyer_id)      REFERENCES users (id)            ON DELETE CASCADE,
  CONSTRAINT fk_ua_recipient FOREIGN KEY (user_id)       REFERENCES users (id)            ON DELETE CASCADE,
  CONSTRAINT chk_ua_level    CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_ua_amount   CHECK (amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= amount)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Binary awards — the self/pair money ledger (escrowed)
-- (Phase 22A, plan §15.4). One row per award kind per order.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS binary_awards (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind               ENUM('self','pair') NOT NULL,
  source             ENUM('order','activation') NOT NULL DEFAULT 'order',
  order_id           INT UNSIGNED    NULL,
  activation_id      INT UNSIGNED    NULL,
  buyer_id           INT UNSIGNED    NOT NULL,
  user_id            INT UNSIGNED    NOT NULL,
  side               ENUM('left','right') NULL,
  points             BIGINT          NOT NULL DEFAULT 0,
  pair_count         INT UNSIGNED    NOT NULL DEFAULT 0,
  pair_points        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rate_pct           DECIMAL(6,3)    NOT NULL DEFAULT 0.000,
  amount             DECIMAL(12,2)   NOT NULL,
  status             ENUM('pending','credited','voided','cancelled') NOT NULL DEFAULT 'pending',
  hold_days          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ledger_id          INT UNSIGNED    NULL,
  credited_at        DATETIME        NULL,
  reversal_ledger_id INT UNSIGNED    NULL,
  reversed_amount    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  paid_at            DATETIME        NOT NULL,
  voided_at          DATETIME        NULL,
  void_reason        VARCHAR(160)    NULL,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_binary_award (order_id, user_id, kind),
  UNIQUE KEY uq_binary_award_activation (activation_id, user_id, kind),
  KEY idx_ba_user (user_id, created_at),
  KEY idx_ba_buyer (buyer_id, created_at),
  KEY idx_ba_order (order_id),
  KEY idx_ba_activation (activation_id),
  KEY idx_ba_status (status, created_at),
  KEY idx_ba_user_status (user_id, status),
  KEY idx_ba_paid (paid_at),
  KEY idx_ba_ledger (ledger_id),
  CONSTRAINT fk_ba_order  FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_buyer  FOREIGN KEY (buyer_id) REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_ba_user   FOREIGN KEY (user_id)  REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT chk_ba_amount CHECK (amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= amount),
  CONSTRAINT chk_ba_self   CHECK (kind <> 'self' OR (pair_count = 0 AND points > 0)),
  CONSTRAINT chk_ba_pair   CHECK (kind <> 'pair' OR (pair_count > 0 AND points = 2 * pair_count * pair_points)),
  CONSTRAINT chk_ba_source CHECK (
    (source = 'order'      AND order_id IS NOT NULL AND activation_id IS NULL) OR
    (source = 'activation' AND activation_id IS NOT NULL AND order_id IS NULL)
  )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Binary volume — the append-only, signed points ledger
-- (Phase 22A, plan §15.4). One row per +/− point movement.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS binary_volume (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  node_user_id  INT UNSIGNED    NOT NULL,
  side          ENUM('left','right') NOT NULL,
  kind          ENUM('own','team','pair','pair_return','reversal','adjustment') NOT NULL,
  source        ENUM('order','activation') NOT NULL DEFAULT 'order',
  points        BIGINT          NOT NULL,
  order_id      INT UNSIGNED    NULL,
  order_item_id INT UNSIGNED    NULL,
  activation_id INT UNSIGNED    NULL,
  buyer_id      INT UNSIGNED    NULL,
  award_id      BIGINT UNSIGNED NULL,
  note          VARCHAR(160)    NULL,
  created_by    INT UNSIGNED    NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bv_line (node_user_id, order_item_id, side, kind),
  UNIQUE KEY uq_bv_award (award_id, side, kind),
  UNIQUE KEY uq_bv_activation (node_user_id, activation_id, side, kind),
  KEY idx_bv_node (node_user_id, side, created_at),
  KEY idx_bv_order (order_id),
  KEY idx_bv_activation (activation_id),
  KEY idx_bv_buyer (buyer_id, created_at),
  KEY idx_bv_kind (kind, created_at),
  KEY idx_bv_award (award_id),
  CONSTRAINT fk_bv_node  FOREIGN KEY (node_user_id)  REFERENCES users (id)         ON DELETE CASCADE,
  CONSTRAINT fk_bv_buyer FOREIGN KEY (buyer_id)      REFERENCES users (id)         ON DELETE SET NULL,
  CONSTRAINT fk_bv_order FOREIGN KEY (order_id)      REFERENCES shop_orders (id)   ON DELETE CASCADE,
  CONSTRAINT fk_bv_item  FOREIGN KEY (order_item_id) REFERENCES shop_order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_bv_actor FOREIGN KEY (created_by)    REFERENCES users (id)         ON DELETE SET NULL,
  CONSTRAINT fk_bv_award FOREIGN KEY (award_id)      REFERENCES binary_awards (id) ON DELETE RESTRICT,
  CONSTRAINT chk_bv_points CHECK (points <> 0),
  CONSTRAINT chk_bv_own    CHECK (
      kind <> 'own' OR (points > 0 AND award_id IS NULL AND
        ((source = 'order' AND order_item_id IS NOT NULL) OR (source = 'activation' AND activation_id IS NOT NULL)))
  ),
  CONSTRAINT chk_bv_team   CHECK (
      kind <> 'team' OR (points > 0 AND award_id IS NULL AND
        ((source = 'order' AND order_item_id IS NOT NULL) OR (source = 'activation' AND activation_id IS NOT NULL)))
  ),
  CONSTRAINT chk_bv_rev    CHECK (kind <> 'reversal' OR (points < 0 AND
      ((source = 'order' AND order_item_id IS NOT NULL) OR (source = 'activation' AND activation_id IS NOT NULL))
  )),
  CONSTRAINT chk_bv_pair   CHECK (kind <> 'pair'   OR (points < 0 AND award_id IS NOT NULL)),
  CONSTRAINT chk_bv_return CHECK (kind <> 'pair_return' OR (points > 0 AND award_id IS NOT NULL)),
  CONSTRAINT chk_bv_adj    CHECK (kind <> 'adjustment' OR points <> 0),
  CONSTRAINT chk_bv_source CHECK (
    (source = 'order'      AND (order_id IS NOT NULL OR order_item_id IS NOT NULL) AND activation_id IS NULL) OR
    (source = 'activation' AND activation_id IS NOT NULL AND order_id IS NULL AND order_item_id IS NULL)
  )
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
  KEY idx_reg_codes_created (created_at, status),
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
  binary_processed_at     DATETIME      NULL,
  binary_points           BIGINT        NOT NULL DEFAULT 0,
  binary_total            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activations_user (user_id, status),
  KEY idx_activations_status (status, created_at),
  KEY idx_activations_binary (binary_processed_at),
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
  KEY idx_commissions_level (level, status),
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

-- -------------------------------------------------------------
-- Repeat-purchase Active snapshot — per member, per month
-- (Phase 17, plan §13.2)
--
--  Written ONLY by the month close, never by a purchase: one row per
--  member per closed period with their personal purchases for that
--  period and whether they cleared the threshold. A member with no
--  row is treated as Active (the two-clock fallback, plan §12.7).
--
--  No auto-increment column: the primary key is natural.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS unilevel_activity (
  period             CHAR(7)       NOT NULL,
  user_id            INT UNSIGNED  NOT NULL,
  personal_purchases DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  qualified          TINYINT(1)    NOT NULL DEFAULT 0,
  evaluated_at       DATETIME      NOT NULL,
  PRIMARY KEY (period, user_id),
  KEY idx_unilevel_activity_user (user_id, period),
  CONSTRAINT fk_ua_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_ua_period CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Repeat-purchase periods — one row per CLOSED month
-- (Phase 17, plan §13.2)
--
--  The header that makes a period authoritative and immutable: the
--  threshold in force, the row counts and the credited money for that
--  month. The close writes this row and the unilevel_activity rows in
--  one transaction, so activity never exists for an open period.
--
--  No auto-increment column: the primary key is the period.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS unilevel_periods (
  period          CHAR(7)       NOT NULL,
  closed_at       DATETIME      NOT NULL,
  closed_by       INT UNSIGNED  NULL,
  threshold       DECIMAL(12,2) NOT NULL,
  members_total   INT UNSIGNED  NOT NULL DEFAULT 0,
  qualified_count INT UNSIGNED  NOT NULL DEFAULT 0,
  award_total     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (period),
  CONSTRAINT fk_up_closer FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_up_period CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Royalty periods — one row per EVALUATED month (Phase 19, plan
-- §13.2)
--
--  The header that makes a month authoritative and immutable: the
--  ladder, quotas and rates in force (a snapshot, so a historic payout
--  is always reproducible), the counts, the totals and the finality
--  state. The close (19C) writes it with its award rows in one
--  transaction; a closed month is never reopened (plan §12.6).
--
--  status: evaluated → paid (final). A `skipped` month (the stream was
--          off) has no award rows and all totals zero.
--  enabled  was `royalty_enabled` on when the month was evaluated?
--  as_of    the period end used as the rank cutoff (D-06).
--  paid_total is the money actually credited; `award_total − paid_total`
--          is the pending liability at any instant.
--
--  No auto-increment column: the primary key is the period.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS royalty_periods (
  period                 CHAR(7)       NOT NULL,
  status                 ENUM('evaluated','paid','skipped') NOT NULL DEFAULT 'evaluated',
  enabled                TINYINT(1)    NOT NULL DEFAULT 0,
  evaluated_at           DATETIME      NOT NULL,
  evaluated_by           INT UNSIGNED  NULL,
  paid_at                DATETIME      NULL,
  paid_by                INT UNSIGNED  NULL,
  as_of                  DATETIME      NOT NULL,
  leader_directs         SMALLINT UNSIGNED NOT NULL,
  supervisor_leaders     INT UNSIGNED  NOT NULL,
  manager_supervisors    INT UNSIGNED  NOT NULL,
  director_managers      INT UNSIGNED  NOT NULL,
  quota_supervisor       DECIMAL(12,2) NOT NULL,
  quota_manager          DECIMAL(12,2) NOT NULL,
  quota_director         DECIMAL(12,2) NOT NULL,
  group_pct_supervisor   DECIMAL(6,3)  NOT NULL,
  personal_pct_supervisor DECIMAL(6,3) NOT NULL,
  group_pct_manager      DECIMAL(6,3)  NOT NULL,
  personal_pct_manager   DECIMAL(6,3)  NOT NULL,
  group_pct_director     DECIMAL(6,3)  NOT NULL,
  personal_pct_director  DECIMAL(6,3)  NOT NULL,
  members_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  leaders_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  supervisors_count      INT UNSIGNED  NOT NULL DEFAULT 0,
  managers_count         INT UNSIGNED  NOT NULL DEFAULT 0,
  directors_count        INT UNSIGNED  NOT NULL DEFAULT 0,
  award_count            INT UNSIGNED  NOT NULL DEFAULT 0,
  paid_count             INT UNSIGNED  NOT NULL DEFAULT 0,
  skipped_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  award_total            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  paid_total             DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  note                   VARCHAR(160)  NULL,
  created_at             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (period),
  KEY idx_royalty_periods_status (status, paid_at),
  CONSTRAINT fk_rp_evaluator FOREIGN KEY (evaluated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_rp_payer     FOREIGN KEY (paid_by)      REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_rp_period   CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
  CONSTRAINT chk_rp_totals   CHECK (award_total >= 0 AND paid_total >= 0 AND paid_total <= award_total),
  CONSTRAINT chk_rp_ranks    CHECK (members_count >= leaders_count + supervisors_count
                                     + managers_count + directors_count)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Royalty awards — the monthly money ledger (Phase 19, plan §13.3)
--
--  One row per member per evaluated month; `UNIQUE (period, user_id)`
--  is the money anchor (D-08), so a retried close is a no-op. Only
--  Supervisor/Manager/Director rows exist (rank_level 2–4): Members
--  and Leaders earn no Royalty and have no row.
--
--  The award is `group_pct × group_volume + personal_pct ×
--  personal_volume`, rounded once, half-up, in centavos. A row below
--  quota keeps amount 0.00 and `status = 'skipped'` with a `reason`.
--
--  The only update ever applied is pending → paid plus ledger_id and
--  credited_at; every money column is immutable after the insert.
--  `ledger_id` is deliberately NOT a foreign key: the wallet ledger is
--  append-only and outlives a correction (same as unilevel_awards).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS royalty_awards (
  id                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  period               CHAR(7)          NOT NULL,
  user_id              INT UNSIGNED     NOT NULL,
  rank_level           TINYINT UNSIGNED NOT NULL,
  directs              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  downline_rank_count  INT UNSIGNED     NOT NULL DEFAULT 0,
  team_size            INT UNSIGNED     NOT NULL DEFAULT 0,
  group_volume         DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  personal_volume      DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  quota                DECIMAL(12,2)    NOT NULL,
  group_pct            DECIMAL(6,3)     NOT NULL,
  personal_pct         DECIMAL(6,3)     NOT NULL,
  amount               DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  status               ENUM('pending','paid','skipped') NOT NULL DEFAULT 'pending',
  reason               VARCHAR(120)     NULL,
  ledger_id            INT UNSIGNED     NULL,
  credited_at          DATETIME         NULL,
  created_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_royalty_award (period, user_id),
  KEY idx_royalty_user (user_id, period),
  KEY idx_royalty_period_status (period, status),
  KEY idx_royalty_status (status, created_at),
  KEY idx_royalty_ledger (ledger_id),
  CONSTRAINT fk_ra_period FOREIGN KEY (period)  REFERENCES royalty_periods (period) ON DELETE CASCADE,
  CONSTRAINT fk_ra_user   FOREIGN KEY (user_id) REFERENCES users (id)              ON DELETE CASCADE,
  CONSTRAINT chk_ra_rank   CHECK (rank_level BETWEEN 2 AND 4),
  CONSTRAINT chk_ra_amount CHECK (amount >= 0),
  CONSTRAINT chk_ra_volume CHECK (group_volume >= 0 AND personal_volume >= 0
                                  AND personal_volume <= group_volume),
  CONSTRAINT chk_ra_rates  CHECK (group_pct BETWEEN 0 AND 100
                                  AND personal_pct BETWEEN 0 AND 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Harvest queue — one row per qualified member (Phase 20, plan §13.2)
--
--  `id` IS the position and it is a fact: auto-increment, 1-based,
--  never renumbered, never reused, gaps allowed (D-03). Every Team
--  Harvest award is computed from the difference between positions,
--  and the later General Harvest stream reads the same order (D-16),
--  so the queue is shared infrastructure. Phase 21's board numbers
--  these rows: a node's `seq` is `id − 1`, and the money is attached
--  to that node (general_harvest_awards).
--
--  `qualified_at`      the Nth direct's created_at (seed) or the new
--                      member's created_at (live hook, 20C)
--  `directs_at_qualification`  the direct count that opened the gate;
--                      an audit fact, never re-read
--  `teammates`         n — the maintained counter (D-05, 20C)
--  `baseline_n`        n at the go-live baseline instant; written once
--                      by the seed job, 0 for anyone queued later
--  `source`            registration | seed | repair
--
--  The queue is populated by bin/harvest_queue_seed.php and the
--  registration hook, never by this file: 20A ships it empty (D-04).
--  chk_hq_baseline is a data-integrity assertion, not a business rule:
--  teammates only grows after the baseline, so a recount below it means
--  a descendant left the tree.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS harvest_queue (
  id                      INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id                 INT UNSIGNED     NOT NULL,
  qualified_at            DATETIME         NOT NULL,
  directs_at_qualification SMALLINT UNSIGNED NOT NULL,
  teammates               INT UNSIGNED     NOT NULL DEFAULT 0,
  baseline_n              INT UNSIGNED     NOT NULL DEFAULT 0,
  source                  ENUM('registration','seed','repair') NOT NULL DEFAULT 'registration',
  created_at              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_user (user_id),
  KEY idx_queue_qualified (qualified_at),
  KEY idx_queue_teammates (teammates),
  CONSTRAINT fk_hq_user     FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_hq_directs CHECK (directs_at_qualification >= 1),
  CONSTRAINT chk_hq_baseline CHECK (teammates >= baseline_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Team Harvest levels — the ten amounts (Phase 20, plan §13.3)
--
--  Catalogue, not money: kept by a business reset, like package_levels
--  and product_unilevel_levels. The slot counts and thresholds are
--  structural and fixed (D-18); only these amounts are configurable.
--  Ten rows always exist after a fresh install or a migration, seeded
--  at the reference ladder (₱1,000 … ₱3,500,000).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_harvest_levels (
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED     NULL,
  PRIMARY KEY (level),
  CONSTRAINT chk_thl_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_thl_amount CHECK (amount >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Team Harvest awards — the money ledger (Phase 20, plan §13.4)
--
--  One row per (member, level); `UNIQUE (user_id, level)` is the money
--  anchor (D-06), so a replayed evaluation can never pay twice. There
--  is no type column, no period, no order, no product and no rate:
--  the level is the whole story.
--
--  `status = 'baseline'` records a level complete before go-live: it
--  is kept for the audit, carries `ledger_id IS NULL`, and is never
--  credited (D-07). A `credited` row always names its wallet movement.
--  There is no `voided` status in v1 (D-12): the row is never edited.
--  `ledger_id` is deliberately NOT a foreign key: the wallet ledger is
--  append-only and outlives a correction (same as royalty_awards).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_harvest_awards (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED     NOT NULL,
  level        TINYINT UNSIGNED NOT NULL,
  threshold_n  INT UNSIGNED     NOT NULL,
  n_at_award   INT UNSIGNED     NOT NULL,
  amount       DECIMAL(12,2)    NOT NULL,
  status       ENUM('credited','baseline') NOT NULL DEFAULT 'credited',
  ledger_id    INT UNSIGNED     NULL,
  credited_at  DATETIME         NOT NULL,
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tha_member_level (user_id, level),
  KEY idx_tha_level (level, credited_at),
  KEY idx_tha_credited (credited_at),
  KEY idx_tha_status (status),
  KEY idx_tha_ledger (ledger_id),
  CONSTRAINT fk_tha_user   FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_tha_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_tha_amount CHECK (amount >= 0),
  CONSTRAINT chk_tha_n      CHECK (n_at_award >= threshold_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- General Harvest levels — the ten amounts (Phase 21, plan §13.3)
--
--  Catalogue, not money: kept by a business reset, like
--  package_levels, product_unilevel_levels and team_harvest_levels.
--  The slot counts and thresholds are structural and fixed (D-18);
--  only these amounts are configurable, and only while the board is
--  unarmed (D-23). Ten rows always exist after a fresh install or a
--  migration, seeded at the reference ladder
--  (₱2,000 … ₱5,000,000; ₱8,381,000 per node).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS general_harvest_levels (
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED     NULL,
  PRIMARY KEY (level),
  CONSTRAINT chk_ghl_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_ghl_amount CHECK (amount >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- General Harvest board — the singleton state row (Phase 21, §13.2)
--
--  Exactly one row, id = 1. It holds the two numbers the board
--  cannot derive and must remember:
--
--    baseline_n  N₀, the queue length at arm; NULL = unarmed (D-06)
--    settled_n   the settled queue length; >= baseline_n, <= N
--
--  `armed_at` and `armed_by` record when and by whom the baseline was
--  taken; `armed_by` is NULL when the CLI armed it without a session.
--  The row is created by the migration and the install seed, RESET
--  (never deleted) by a business reset, and locked FOR UPDATE by every
--  settlement: it is the stream's serialisation point (D-05).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS general_harvest_board (
  id          TINYINT UNSIGNED NOT NULL,
  baseline_n  INT UNSIGNED     NULL,
  armed_at    DATETIME         NULL,
  armed_by    INT UNSIGNED     NULL,
  settled_n   INT UNSIGNED     NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ghb_armed_by FOREIGN KEY (armed_by) REFERENCES users (id)
    ON DELETE SET NULL,
  CONSTRAINT chk_ghb_singleton CHECK (id = 1),
  CONSTRAINT chk_ghb_armed CHECK (
    (baseline_n IS NULL AND armed_at IS NULL)
    OR (baseline_n IS NOT NULL AND armed_at IS NOT NULL)),
  CONSTRAINT chk_ghb_settled CHECK (baseline_n IS NULL OR settled_n >= baseline_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- General Harvest awards — the money ledger (Phase 21, plan §13.4)
--
--  One row per (node, level); `UNIQUE (seq, level)` is the money
--  anchor (D-04), so a replayed settlement can never pay twice. There
--  is no type column, no period, no order, no product and no rate:
--  the node and the level are the whole story. `seq` IS the node index
--  (the queue's `id − 1`), because the money is attached to a
--  structural place on the board, not to an account.
--
--  `status = 'baseline'` records a block that was already full when
--  the board was armed: it is kept for the audit, carries
--  `ledger_id IS NULL`, and is never credited (D-06). A `credited`
--  row always names its wallet movement. There is no `voided` status
--  in v1 (D-13): a row is written once and never edited.
--  `ledger_id` is deliberately NOT a foreign key: the wallet ledger is
--  append-only and outlives a correction (same as royalty_awards).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS general_harvest_awards (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  seq          INT UNSIGNED     NOT NULL,
  user_id      INT UNSIGNED     NOT NULL,
  level        TINYINT UNSIGNED NOT NULL,
  threshold_n  INT UNSIGNED     NOT NULL,
  n_at_award   INT UNSIGNED     NOT NULL,
  amount       DECIMAL(12,2)    NOT NULL,
  status       ENUM('credited','baseline') NOT NULL DEFAULT 'credited',
  ledger_id    INT UNSIGNED     NULL,
  credited_at  DATETIME         NOT NULL,
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gha_seq_level (seq, level),
  KEY idx_gha_user (user_id, level),
  KEY idx_gha_level (level, seq),
  KEY idx_gha_credited (credited_at),
  KEY idx_gha_status (status),
  KEY idx_gha_ledger (ledger_id),
  CONSTRAINT fk_gha_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_gha_level    CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_gha_amount   CHECK (amount >= 0),
  CONSTRAINT chk_gha_n        CHECK (n_at_award >= threshold_n),
  CONSTRAINT chk_gha_baseline CHECK (status <> 'baseline' OR ledger_id IS NULL)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Impersonation log — super-login sessions (plan §4.2, §4.4,
-- §7 Phase 14)
--
--  One row per S-Login session. The tab carries the raw nonce in
--  its URL (`?imp=<64 hex>`); only `hash('sha256', $nonce)` is
--  stored here, so a database read can never recreate a live URL.
--
--  superadmin_name / target_username are snapshots taken when the
--  session opened: the audit trail must still name who acted even
--  after an account is renamed (the FKs keep the ids linked).
--
--  status machine (plan §4.4):
--      active ──(exit)──────────────────▶ logged_out
--      active ──(TTL / IP-UA / target)──▶ expired
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS impersonation_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  superadmin_id   INT UNSIGNED  NOT NULL,
  superadmin_name VARCHAR(50)   NOT NULL,
  target_user_id  INT UNSIGNED  NOT NULL,
  target_username VARCHAR(50)   NOT NULL,
  nonce_hash      CHAR(64)      NOT NULL,
  ip              VARCHAR(45)   NOT NULL,
  user_agent      VARCHAR(255)  NOT NULL,
  status          ENUM('active','expired','logged_out') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_imp_nonce (nonce_hash),
  KEY idx_imp_super (superadmin_id, created_at),
  KEY idx_imp_target (target_user_id, created_at),
  KEY idx_imp_status (status, expires_at),
  CONSTRAINT fk_imp_super  FOREIGN KEY (superadmin_id)  REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_imp_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Live updates: one revision row per live page surface
--  The no-refresh client asks for a surface revision before it
--  reads page data; an unchanged revision becomes a 304. Rows are
--  created lazily by Live::touch() on the first write.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS live_revisions (
  surface    VARCHAR(64)     NOT NULL,
  revision   BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (surface)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Phase 23A: binary awards and volume accept a package activation
-- as a second source. `activations` is declared earlier in this file
-- (before binary_awards / binary_volume), so these foreign keys are
-- added here to keep the create-table order linear; they mirror
-- migration `027_package_binary.sql` exactly.
-- -------------------------------------------------------------
ALTER TABLE binary_awards
  ADD CONSTRAINT fk_ba_activation FOREIGN KEY (activation_id) REFERENCES activations (id) ON DELETE CASCADE;

ALTER TABLE binary_volume
  ADD CONSTRAINT fk_bv_activation FOREIGN KEY (activation_id) REFERENCES activations (id) ON DELETE CASCADE;

-- ===========================================================================
--  SECTION 2/7 — SETTINGS
--  source: database/seeds/001_settings.sql sha256=4307ccc340355ab8739ffd5f2578363fdd2cf9623ef058a9293baa27cbe5d583
-- ===========================================================================

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

-- ===========================================================================
--  SECTION 3/7 — STAFF ACCOUNTS
--  source: database/seeds/002_accounts.sql sha256=aa28cb0ca54fd90d54e57cb337c3de1217f3f07fcda604d3c8928d354bd9f149
-- ===========================================================================

-- =============================================================
--  EcoWealth — Phase 1 accounts (fresh install only)
--
--  admin      / Admin@123   role admin       (back office)
--  superadmin / Super@123   role superadmin  (S-Login console, Phase 14)
--
--  These are development/demo credentials. The go-live checklist
--  (plan §9.6) requires both passwords to be changed and the demo
--  accounts removed before production.
--
--  Idempotent: existing rows are left untouched, so re-seeding
--  never resets a changed password.
--
--  member_status is written explicitly (F-09): the schema default is
--  'pending', while a reset returns staff to 'active'. A fresh install
--  must match the reset state, or the two paths disagree on a staff field
--  that controls sign-in.
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active) VALUES
  (
    'admin',
    'admin@sneat.dev',
    '$2y$10$Zb7S7VhUUy1BqfkZz0pjJ.2x1YQr67cqdgExziYaSMKbFkhLx6ZvW',
    'EcoWealth Administrator',
    'admin',
    'active',
    1
  ),
  (
    'superadmin',
    'superadmin@sneat.dev',
    '$2y$10$b49wTwncaKZOyP8XvxSjKu7t5iIQcG5d0bKuPuxOMikhbwIBG9.ya',
    'EcoWealth Super Administrator',
    'superadmin',
    'active',
    1
  )
ON DUPLICATE KEY UPDATE username = username;

-- ===========================================================================
--  SECTION 4/7 — STARTER PRODUCTS
--  source: database/seeds/003_products.sql sha256=61eaecb1e56c921dc206b9057ec677b2f65f6160c4365cc0a7afa21f37d340be
-- ===========================================================================

-- =============================================================
--  EcoWealth — Phase 2 demo catalogue (plan §4.5)
--
--  Six placeholder products. Prices are the illustrative ones from
--  dmp/workflow.md — decision D10 (official price list) is still
--  open, so the copy below says so instead of inventing claims.
--
--  Images live under public/assets/img/products/ (copied from the
--  marketing site). Two rows are seeded without an image on purpose,
--  so the storefront placeholder path is exercised by the demo.
--
--  Idempotent: re-running adds missing rows and never overwrites a
--  product an admin has edited.
--
--      mysql -u root <db> < database/seeds/003_products.sql
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO products
  (sku, name, slug, price, stock, image_url, short_description, description, status, sort_order)
VALUES
  (
    'EW-COFFEE', 'Coffee', 'coffee', 300.00, 50,
    'assets/img/products/collagen-coffee.webp',
    'Roasted coffee blend, sold per pack.',
    'Demo catalogue entry. The official product description, pack size and price replace this text before launch.',
    'active', 10
  ),
  (
    'EW-IMMUNE', 'Immune Booster', 'immune-booster', 500.00, 40,
    'assets/img/products/immune-booster.webp',
    'EcoWealth wellness pack.',
    'Demo catalogue entry. The official product description, pack size and price replace this text before launch.',
    'active', 20
  ),
  (
    'EW-SPIRULINA', 'Spirulina', 'spirulina', 400.00, 60,
    'assets/img/products/spirulina.webp',
    'Spirulina powder pack.',
    'Demo catalogue entry. The official product description, pack size and price replace this text before launch.',
    'active', 30
  ),
  (
    'EW-SERPENTINA', 'Serpentina', 'serpentina', 400.00, 35,
    NULL,
    'Serpentina herbal pack.',
    'Demo catalogue entry. The official product description, pack size and price replace this text before launch.',
    'active', 40
  ),
  (
    'EW-KOJIC', 'Kojic Soap', 'kojic-soap', 100.00, 100,
    NULL,
    'Kojic soap bar.',
    'Demo catalogue entry. The official product description, pack size and price replace this text before launch.',
    'active', 50
  ),
  (
    'EW-STONE', 'Stone Buster', 'stone-buster', 600.00, 25,
    'assets/img/products/stone-buster.webp',
    'EcoWealth herbal pack.',
    'Demo catalogue entry. The official product description, pack size and price replace this text before launch.',
    'active', 60
  )
ON DUPLICATE KEY UPDATE name = name;

-- ===========================================================================
--  SECTION 5/7 — PACKAGES
--  source: database/seeds/004_packages.sql sha256=6c23b3cc3125590349d1ee3132e8858b2d2a2da63606bf727f0ece6206ce1609
-- ===========================================================================

-- =============================================================
--  EcoWealth — Phase 3 demo packages (plan §4.5)
--
--  Package A (₱4,498) and Package B (₱6,998) with the 2–10 level
--  ladder and their contents. Level 1 is the package's
--  direct_referral_bonus (₱500) and is never duplicated into
--  package_levels.
--
--  Values follow the plan's worked example; the official price
--  list (decision D10) is still open, so the descriptions say so.
--
--  Idempotent: re-running adds missing rows and never overwrites a
--  package an admin has edited. Levels/contents are only seeded
--  when the package has none — an admin who clears them stays
--  cleared.
-- =============================================================

SET NAMES utf8mb4;

-- Service product bundled with Package B (stock tracked manually).
INSERT INTO products
  (sku, name, slug, price, stock, short_description, description, status, sort_order)
VALUES
  (
    'EW-COLONICS', 'Colonics Session', 'colonics-session', 1500.00, 20,
    'One colonics session, booked after activation.',
    'Service product bundled with Package B. Stock is tracked manually; the session is scheduled with the clinic.',
    'active', 70
  )
ON DUPLICATE KEY UPDATE name = name;

-- Packages -----------------------------------------------------
INSERT INTO packages
  (name, slug, price, product_value, direct_referral_bonus, indirect_enabled,
   description, perks, status, sort_order)
VALUES
  (
    'Package A', 'package-a', 4498.00, 4750.00, 500.00, 1,
    'Entry associate package. Demo values follow the plan''s worked example — confirm the official price list before launch.',
    'Associate status\nShop access\nDirect referral + 10-level unilevel ladder',
    'active', 10
  ),
  (
    'Package B', 'package-b', 6998.00, 8300.00, 600.00, 1,
    'Growth associate package. Demo values follow the plan''s worked example; the referral ladder is 20% above Package A to reward the larger entry package. Confirm the official price list before launch.',
    'Associate status\nShop access\nDirect referral + 10-level unilevel ladder\nLarger product bundle',
    'active', 20
  )
ON DUPLICATE KEY UPDATE name = name;

-- Indirect ladder, levels 2–10.
-- Package A keeps the V3.0 base ladder; Package B pays exactly 20% more
-- at every level (600 / 120,60,48,36,24,12,6,6,6 vs 500 / 100,50,40,30,20,10,5,5,5).
INSERT INTO package_levels (package_id, level, amount)
SELECT p.id, v.level, v.amount
FROM packages p
JOIN (
            SELECT 2 AS level, 100.00 AS amount
  UNION ALL SELECT 3, 50.00
  UNION ALL SELECT 4, 40.00
  UNION ALL SELECT 5, 30.00
  UNION ALL SELECT 6, 20.00
  UNION ALL SELECT 7, 10.00
  UNION ALL SELECT 8, 5.00
  UNION ALL SELECT 9, 5.00
  UNION ALL SELECT 10, 5.00
) v
WHERE p.slug = 'package-a'
  AND NOT EXISTS (SELECT 1 FROM package_levels pl WHERE pl.package_id = p.id);

INSERT INTO package_levels (package_id, level, amount)
SELECT p.id, v.level, v.amount
FROM packages p
JOIN (
            SELECT 2 AS level, 120.00 AS amount
  UNION ALL SELECT 3, 60.00
  UNION ALL SELECT 4, 48.00
  UNION ALL SELECT 5, 36.00
  UNION ALL SELECT 6, 24.00
  UNION ALL SELECT 7, 12.00
  UNION ALL SELECT 8, 6.00
  UNION ALL SELECT 9, 6.00
  UNION ALL SELECT 10, 6.00
) v
WHERE p.slug = 'package-b'
  AND NOT EXISTS (SELECT 1 FROM package_levels pl WHERE pl.package_id = p.id);

-- Package A contents: 1 Coffee, 1 Immune Booster, 2 Spirulina,
-- 1 Serpentina, 2 Kojic Soap -----------------------------------
INSERT INTO package_products (package_id, product_id, quantity)
SELECT p.id, pr.id, v.quantity
FROM packages p
JOIN (
            SELECT 'EW-COFFEE' AS sku, 1 AS quantity
  UNION ALL SELECT 'EW-IMMUNE', 1
  UNION ALL SELECT 'EW-SPIRULINA', 2
  UNION ALL SELECT 'EW-SERPENTINA', 1
  UNION ALL SELECT 'EW-KOJIC', 2
) v
JOIN products pr ON pr.sku = v.sku
WHERE p.slug = 'package-a'
  AND NOT EXISTS (SELECT 1 FROM package_products pp WHERE pp.package_id = p.id);

-- Package B contents: 1 Coffee, 1 Immune Booster, 1 Spirulina,
-- 1 Stone Buster, 1 Colonics session ---------------------------
INSERT INTO package_products (package_id, product_id, quantity)
SELECT p.id, pr.id, v.quantity
FROM packages p
JOIN (
            SELECT 'EW-COFFEE' AS sku, 1 AS quantity
  UNION ALL SELECT 'EW-IMMUNE', 1
  UNION ALL SELECT 'EW-SPIRULINA', 1
  UNION ALL SELECT 'EW-STONE', 1
  UNION ALL SELECT 'EW-COLONICS', 1
) v
JOIN products pr ON pr.sku = v.sku
WHERE p.slug = 'package-b'
  AND NOT EXISTS (SELECT 1 FROM package_products pp WHERE pp.package_id = p.id);

-- ===========================================================================
--  SECTION 6/7 — INSTALL METADATA
--  source: database/install/footer.sql sha256=a74bdd5529f2859b1294f6240fae055c3c6ba3ddee43517cfad7aaa787a05d51
-- ===========================================================================

-- ===========================================================================
--  EcoWealth — install footer (plan IR-2, §6.3–6.5)
--
--  Included in database/install.sql as the last two sections:
--
--      INSTALL METADATA  — the three install_* settings
--      VERIFICATION      — the query pack printed by a `mysql` import
--
--  The line containing @@VERIFICATION@@ is consumed by
--  bin/build_install_sql.php: it separates the two sections and becomes
--  the VERIFICATION banner. Do not remove it.
--
--  INSERT IGNORE rather than ON DUPLICATE KEY UPDATE … VALUES(): the latter
--  is deprecated in MySQL 8.0.20+ and unsupported by older MariaDB. The
--  bundle is fresh-install only, so "ignore an existing key" is the right
--  behaviour for a re-run of a hand-picked section.
-- ===========================================================================

INSERT IGNORE INTO settings (key_name, value) VALUES
  ('install_version',      '1.0.0'),
  ('install_channel',      'install.sql'),
  ('install_completed_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));

-- ===========================================================================
--  SECTION 7/7 — VERIFICATION
--  source: database/install/footer.sql sha256=a74bdd5529f2859b1294f6240fae055c3c6ba3ddee43517cfad7aaa787a05d51
-- ===========================================================================

-- ---------------------------------------------------------------------------
--  Verification pack — run after any import (plan §6.5, Appendix E)
--
--  Expected on a correct production import:
--      tables_created = 42    settings_rows = 86 seeded (+3 install-time = 92 fresh install)    staff_rows    = 2
--      members_rows   = 0     products_rows = 7     packages_rows = 2
--      levels_rows    = 18    contents_rows = 10    codes_rows    = 0
--      team_harvest_levels_rows = 10
--      general_harvest_levels_rows = 10   general_harvest_board_rows = 1
--
--  A reset is expected to add audit_log rows, live_revisions rows and the
--  generated registration codes; a fresh install has none of them.
-- ---------------------------------------------------------------------------

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE())                       AS tables_created,
  (SELECT COUNT(*) FROM settings)                          AS settings_rows,
  (SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')) AS staff_rows,
  (SELECT COUNT(*) FROM users WHERE role = 'member')       AS members_rows,
  (SELECT COUNT(*) FROM products)                          AS products_rows,
  (SELECT COUNT(*) FROM packages)                          AS packages_rows,
  (SELECT COUNT(*) FROM package_levels)                    AS levels_rows,
  (SELECT COUNT(*) FROM package_products)                  AS contents_rows,
  (SELECT COUNT(*) FROM team_harvest_levels)                AS team_harvest_levels_rows,
  (SELECT COUNT(*) FROM general_harvest_levels)             AS general_harvest_levels_rows,
  (SELECT COUNT(*) FROM general_harvest_board)              AS general_harvest_board_rows,
  (SELECT COUNT(*) FROM reg_codes)                         AS codes_rows;

SELECT key_name, value FROM settings
 WHERE key_name IN ('site_name','install_version','install_channel','install_completed_at')
 ORDER BY key_name;

SELECT username, role, member_status, is_active
  FROM users WHERE role IN ('admin','superadmin') ORDER BY id;

-- The General Harvest board ships unarmed: no baseline, no arm instant,
-- nothing settled. A fresh install must never pretend to have a go-live
-- baseline (plan 21A Rule 4).
SELECT id, baseline_n, armed_at, armed_by, settled_n FROM general_harvest_board;
