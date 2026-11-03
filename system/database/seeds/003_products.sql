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
