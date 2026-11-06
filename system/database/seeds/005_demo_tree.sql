-- =============================================================
--  EcoWealth — Phase 9 demo tree, wallets and codes (plan §4.5)
--
--  The sponsor chain the tests and the UAT script walk:
--
--      admin  (company / root account, no package)
--        └── juan      Package A · active   ← root associate
--              └── maria   Package A · active
--                    ├── peter   Package A · active
--                    ├── sarah   Package A · active
--                    └── david   Package B · pending (awaits activation)
--
--  There is NO binary tree: every relationship is users.sponsor_id.
--
--  Wallets (plan §4.5: juan / maria / peter carry a mixed balance)
--    juan   ₱2,000.00 total / ₱1,500.00 withdrawable
--    maria  ₱1,000.00 total /   ₱800.00 withdrawable
--    peter    ₱500.00 total /   ₱400.00 withdrawable
--  Each balance is written with the ledger row that backs it, so
--  bin/wallet_reconcile.php stays clean (the Phase 5 note said the
--  demo balances would arrive together with the demo tree).
--
--  Demo registration codes (printed here so they can be used by hand):
--    DEMO-PKGA-2A7K   Package A, unused, expires in 90 days
--    DEMO-PKGB-3B8L   Package B, unused, expires in 90 days
--    DEMO-EXPD-4C9M   Package A, expired (negative tests only)
--
--  Credentials: every demo member signs in with Demo@1234.
--
--  Idempotent: an existing account is never overwritten, the wallet
--  ledger rows carry fixed reference ids that the ledger's unique key
--  collapses on a re-run, and a code is only inserted once. Values
--  are read into session variables first because MySQL forbids an
--  INSERT whose subquery selects from the table being inserted into.
--
--  This is demo data, not configuration: replace or remove it before
--  a production install (see the go-live checklist).
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. Lookups used by the inserts below
-- -------------------------------------------------------------
SET @admin_id := (SELECT id FROM users WHERE username = 'admin' ORDER BY id LIMIT 1);
SET @pkg_a    := (SELECT id FROM packages WHERE slug = 'package-a' LIMIT 1);
SET @pkg_b    := (SELECT id FROM packages WHERE slug = 'package-b' LIMIT 1);

-- -------------------------------------------------------------
-- 2. Demo members (juan → maria → peter / sarah / david)
--    Each row is only created when the username is free.
-- -------------------------------------------------------------
INSERT INTO users
  (username, email, password_hash, full_name, phone, role, member_status,
   package_id, sponsor_id, referral_code, reg_payment_method, activated_at,
   is_active, ewallet_balance, withdrawable_balance)
VALUES
  (
    'juan', 'juan@demo.local',
    '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
    'Juan Dela Cruz', '09170000001', 'member', 'active',
    @pkg_a, @admin_id, 'JUAN0001', 'code', NOW(), 1, 2000.00, 1500.00
  )
ON DUPLICATE KEY UPDATE username = username;

SET @juan_id := (SELECT id FROM users WHERE username = 'juan' ORDER BY id LIMIT 1);

INSERT INTO users
  (username, email, password_hash, full_name, phone, role, member_status,
   package_id, sponsor_id, referral_code, reg_payment_method, activated_at,
   is_active, ewallet_balance, withdrawable_balance)
VALUES
  (
    'maria', 'maria@demo.local',
    '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
    'Maria Santos', '09170000002', 'member', 'active',
    @pkg_a, @juan_id, 'MARI0001', 'code', NOW(), 1, 1000.00, 800.00
  )
ON DUPLICATE KEY UPDATE username = username;

SET @maria_id := (SELECT id FROM users WHERE username = 'maria' ORDER BY id LIMIT 1);

INSERT INTO users
  (username, email, password_hash, full_name, phone, role, member_status,
   package_id, sponsor_id, referral_code, reg_payment_method, activated_at,
   is_active, ewallet_balance, withdrawable_balance)
VALUES
  (
    'peter', 'peter@demo.local',
    '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
    'Peter Reyes', '09170000003', 'member', 'active',
    @pkg_a, @maria_id, 'PETE0001', 'code', NOW(), 1, 500.00, 400.00
  )
ON DUPLICATE KEY UPDATE username = username;

INSERT INTO users
  (username, email, password_hash, full_name, phone, role, member_status,
   package_id, sponsor_id, referral_code, reg_payment_method, activated_at,
   is_active, ewallet_balance, withdrawable_balance)
VALUES
  (
    'sarah', 'sarah@demo.local',
    '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
    'Sarah Lim', '09170000004', 'member', 'active',
    @pkg_a, @maria_id, 'SARA0001', 'code', NOW(), 1, 0.00, 0.00
  )
ON DUPLICATE KEY UPDATE username = username;

INSERT INTO users
  (username, email, password_hash, full_name, phone, role, member_status,
   package_id, sponsor_id, referral_code, reg_payment_method,
   is_active, ewallet_balance, withdrawable_balance)
VALUES
  (
    'david', 'david@demo.local',
    '$2y$10$0rytMfLB7wZLyPOBwG9EBuDN3bnZ2IpHquXH85tFyOH0gkc1MDbr6',
    'David Tan', '09170000005', 'member', 'pending',
    NULL, @maria_id, 'DAVI0001', 'pending', 1, 0.00, 0.00
  )
ON DUPLICATE KEY UPDATE username = username;

-- -------------------------------------------------------------
-- 3. Demo wallet ledger rows.
--
--    The balance lives on `users`; the ledger row is the immutable
--    record WalletService would have written. The fixed reference
--    ids (9001–9003, ref_type `adjustment`) make a re-run a no-op
--    through uq_ewallet_idempotency.
-- -------------------------------------------------------------
INSERT INTO ewallet_ledger
  (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
SELECT u.id, 'credit', u.ewallet_balance, u.ewallet_balance, u.withdrawable_balance,
       'adjustment', 9001, 'Demo wallet seed (plan §4.5)'
  FROM users u
 WHERE u.username = 'juan'
ON DUPLICATE KEY UPDATE note = note;

INSERT INTO ewallet_ledger
  (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
SELECT u.id, 'credit', u.ewallet_balance, u.ewallet_balance, u.withdrawable_balance,
       'adjustment', 9002, 'Demo wallet seed (plan §4.5)'
  FROM users u
 WHERE u.username = 'maria'
ON DUPLICATE KEY UPDATE note = note;

INSERT INTO ewallet_ledger
  (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
SELECT u.id, 'credit', u.ewallet_balance, u.ewallet_balance, u.withdrawable_balance,
       'adjustment', 9003, 'Demo wallet seed (plan §4.5)'
  FROM users u
 WHERE u.username = 'peter'
ON DUPLICATE KEY UPDATE note = note;

-- -------------------------------------------------------------
-- 4. Demo registration codes (format ABCD-EFGH-JKLM)
--    Codes are sold offline; the row is the auditable record.
-- -------------------------------------------------------------
INSERT INTO reg_codes
  (code, package_id, price, code_type, status, created_by, expires_at)
SELECT 'DEMO-PKGA-2A7K', p.id, p.price, 'registration', 'unused', @admin_id,
       DATE_ADD(CURDATE(), INTERVAL 90 DAY)
  FROM packages p
 WHERE p.slug = 'package-a'
ON DUPLICATE KEY UPDATE code = code;

INSERT INTO reg_codes
  (code, package_id, price, code_type, status, created_by, expires_at)
SELECT 'DEMO-PKGB-3B8L', p.id, p.price, 'registration', 'unused', @admin_id,
       DATE_ADD(CURDATE(), INTERVAL 90 DAY)
  FROM packages p
 WHERE p.slug = 'package-b'
ON DUPLICATE KEY UPDATE code = code;

INSERT INTO reg_codes
  (code, package_id, price, code_type, status, created_by, expires_at)
SELECT 'DEMO-EXPD-4C9M', p.id, p.price, 'registration', 'expired', @admin_id,
       DATE_SUB(CURDATE(), INTERVAL 1 DAY)
  FROM packages p
 WHERE p.slug = 'package-a'
ON DUPLICATE KEY UPDATE code = code;
