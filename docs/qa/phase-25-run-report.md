# Phase 25 — executed walkthrough: run report

**Date:** 23 November 2026
**Document under test:** [`phase-25-product-commissions.md`](phase-25-product-commissions.md) (v1 — the executed version; §15.5 records three corrections made to the document from the run)
**Result:** **30 of 30 assertions passed.** No step failed. The walkthrough itself was corrected in three places (§15.5); all are recorded in v1 and are not app defects.

---

## 1. What was executed

The run drove the real application over HTTP (`php -S`, the app's own router), against a **fresh, isolated schema** `ecowealth_sneat_qa25`, in the document's order. Nothing was written to the development database (`ecowealth_sneat`).

| Driven through the UI (HTTP) | Changed directly (documented substitute) |
|---|---|
| Admin sign-in, member sign-ins | The eight settings values (the settings card's POST path was not exercised) |
| 6 registrations (all standard) | Product Coffee's P.V., ladder levels and binary flag |
| Cart add, checkout (e-wallet + GCash) | Order fulfilment transitions (pack → ready → ship → deliver) |
| Manual proof upload (real multipart PNG) | — |
| Admin proof verification | — |
| Order cancellation | — |
| `/earnings`, `/earnings?ledger=binary`, `/admin/commissions`, exports | — |
| `bin/unilevel_release.php` (real CLI, dry run + real) | — |
| `bin/wallet_reconcile.php` (real CLI) | — |

**Not executed:** browser-based screenshot capture (the report proves rendering by HTTP 200 plus expected amount text), phpMyAdmin screenshots, and the settings-card save button UI flow. These remain human checks in the document.

**Environment**

| | |
|---|---|
| Schema | `ecowealth_sneat_qa25` (created fresh for the run, dropped after the snapshot) |
| Admin credential | `admin` / `Qa25Run!Pass2026` — the document's `Admin@123` is the development default; the isolated schema uses its own installer-chosen password |
| Member password | `Demo@1234` for all six `QA25…` accounts |
| HTTP server | `php -S 127.0.0.1:8899 -t public public/router.php` with `ECO_DB_NAME=ecowealth_sneat_qa25` |
| Runner | `docs/qa/evidence/phase-25/run/qa25_http_run.php` |
| Raw log | `docs/qa/evidence/phase-25/run/qa25-run.log` and `run-stdout.txt` |
| Machine-readable results | `docs/qa/evidence/phase-25/run/qa25-run-results.json` |

---

## 2. Timeline and real messages

Every line below is the actual response text the app produced.

| Step | Action | Message / result |
|---|---|---|
| Part 1 | Settings written (11 keys) | unilevel_enabled 0→1, unilevel_hold_days 7→0, binary_enabled 0→1, binary_hold_days 7→0, all others unchanged |
| Part 1 | Coffee product config | P.V. 250.00, ladder 5/5/5/3/3/2/2/2/1/1, binary enabled, badges present |
| Part 1 | Register 6 members | `Welcome! Your standard account is ready — you can start shopping.` for each of QA25_Boss, QA25_A, QA25_B, QA25_C, QA25_Buyer, QA25_Buyer2 |
| Part 1 | Top-up QA25_Buyer | `₱1,500.00 credited to @QA25_Buyer — the amount is spendable in the shop and non-withdrawable.` |
| Part 1 | Top-up QA25_Buyer2 | `₱1,500.00 credited to @QA25_Buyer2 — the amount is spendable in the shop and non-withdrawable.` |
| Part 2 | Cart add 4 × Coffee (order 1) | `Added to cart.` |
| Part 2 | Checkout order 1 (e-wallet) | `Order placed and paid. Thank you!` — order `EW26112300001`, total ₱1,200.00, status Paid |
| Part 2 | QA25-06 audit | L1 QA25_C ₱50.00 pending; L2 QA25_B ₱50.00 pending; L3 QA25_A ₱50.00 pending; L4 QA25_Boss ₱30.00 pending; L5 admin ₱30.00 skipped (`company account is not paid`) |
| Part 2 | QA25-07 audit | 0 repeat-purchase awards for the buyer; 1 unilevel ledger debit row only |
| Part 2 | QA25-08 audit | `binary_points` 1000; Self Bonus ₱50.00 pending; 4 volume rows (Buyer own left 1000, A right 1000, Boss left 1000, admin left 1000) |
| Part 3 | Deliver order 1 | `Packing started.` / `The order is packed and ready to ship.` / `Order shipped via J&T Express — tracking QA251.` / `Order marked delivered.` |
| Part 3 | Release dry run | exit 0 — repeat-purchase: 4 award(s) · ₱180.00; product binary: 1 award(s) · ₱50.00; nothing changed |
| Part 3 | Release real (order 1) | exit 0 — `released: 1 order(s) · 4 award(s) · ₱180.00` (repeat-purchase); `released: 1 order(s) · 1 award(s) · ₱50.00` (product binary) |
| Part 3 | Release re-run | `released: 0` — idempotent, nothing changes |
| Part 3 | QA25-10 audit | QA25_A ₱50.00 withdrawable; QA25_B ₱50.00; QA25_Boss ₱30.00; QA25_C ₱50.00; buyer ₱0.00; admin ₱0.00 |
| Part 3 | QA25-11 / QA25-12 / QA25-14 | Admin ledger HTTP 200; member earnings HTTP 200; buyer's own repeat tab has no award row |
| Part 4 | Cart add 4 × Coffee (order 2) | `Added to cart.` |
| Part 4 | Checkout order 2 (GCash) | `Order placed. Your payment is pending — the payment window is shown on the order page.` — status Pending, total ₱1,200.00 |
| Part 4 | Proof upload order 2 | `Payment proof submitted (attempt 1). We will verify it shortly.` — status Payment review; 0 awards, `binary_processed_at` NULL |
| Part 4 | Verify order 2 | `Payment verified — ₱1,200.00 marked paid.` — status Paid; five decisions: 50/50/50/30 pending, admin skipped |
| Part 4 | QA25-17 audit | Order 2: Self Bonus ₱50.00 pending (Buyer), pair ₱100.00 pending (QA25_Boss, count 1); Boss node left 0, right 0, pairs_paid 1 |
| Part 4 | Release real (order 2) | exit 0 — `released: 1 order(s) · 4 award(s) · ₱180.00` (repeat-purchase); `released: 1 order(s) · 2 award(s) · ₱150.00` (₱50 self + ₱100 pair) |
| Part 4 | QA25-18 replay | Verify again refused (already paid); hook prints `{"processed":false,"replayed":true}`; award counts unchanged (5 / 2) |
| Part 5 | Top-up QA25_Buyer | `₱2,000.00 credited to @QA25_Buyer` |
| Part 5 | Top-up QA25_Buyer2 | `₱1,000.00 credited to @QA25_Buyer2` |
| Part 5 | Suspend QA25_B | `@QA25_B is now suspended — the account cannot sign in.` |
| Part 5 | Checkout order 3 (2 × Coffee, e-wallet) | `Order placed and paid. Thank you!` — order `EW26112300003`, total ₱600.00 |
| Part 5 | QA25-19 audit | L1 QA25_C ₱25.00 pending; L2 QA25_B ₱25.00 **skipped** (`recipient account is disabled`); L3 QA25_A ₱25.00 pending; L4 QA25_Boss ₱15.00 pending; L5 admin ₱15.00 skipped |
| Part 5 | Restore QA25_B | `@QA25_B is now active.` |
| Part 5 | Cancel order 3 | `Order cancelled.` — awards: 3 cancelled, 2 skipped stay skipped; QA25_B withdrawable unchanged at ₱100.00 |
| Part 5 | Checkout order 4 (1 × Coffee, both streams off) | `Order placed and paid. Thank you!` — total ₱300.00; `unilevel_total` 0.00; `binary_points` 0; 0 award rows |
| Part 5 | Checkout order 5 (1 × Coffee, product switches off) | `Order placed and paid. Thank you!` — total ₱300.00; `unilevel_total` 0.00; 0 award rows |
| Part 6 | `wallet_reconcile.php` | exit 0 — `All good — wallets, commissions, transfers, payouts, repeat-purchase, royalty, team-harvest, general-harvest and product-binary awards reconcile.` |
| Part 6 | Tie-out query | `360.00 | 360.00 | 200.00 | 200.00` |
| Part 6 | Cleanup | All QA25 rows removed; `users_left` 0, `nodes_left` 0; settings and Coffee restored to before values |

---

## 3. Final state (after Part 4, before cleanup)

| Member | Status | Withdrawable | Where it came from |
|---|---|---|---|
| `QA25_C` | active | **₱100.00** | ₱50 (order 1, level 1) + ₱50 (order 2, level 1) |
| `QA25_B` | active | **₱100.00** | ₱50 + ₱50 (levels 2); order 3 skipped |
| `QA25_A` | active | **₱100.00** | ₱50 + ₱50 (levels 3) |
| `QA25_Boss` | active | **₱160.00** | ₱30 + ₱30 (levels 4) + ₱100 pair |
| `QA25_Buyer` | active | **₱50.00** | own Self Bonus (order 1) |
| `QA25_Buyer2` | active | **₱50.00** | own Self Bonus (order 2) |
| `admin` | active | **₱0.00** | never paid; volume only |

Stream totals: commissions **₱360.00**, product binary **₱200.00**, cancelled **₱90.00** (₱65 unilevel + ₱25 binary), skipped decisions **4**.

The Part 6 sign-out totals in the document (unilevel credited ₱360.00 / ledger ₱360.00; binary credited ₱200.00 / ledger ₱200.00) **matched the run exactly.**

---

## 4. Check results

30 assertions passed. The suite also asserted the two pre-flight steps (settings save, product config) and the CSV exports.

| Check | Result | Observed |
|---|---|---|
| QA25-01 | Pass | unilevel=1/10/0 binary=1/5.000/1000/10.000/0 |
| QA25-02 | Pass | PV 250.00, ladder 5/5/5/3/3/2/2/2/1/1, binary 1 |
| QA25-03a | Pass | 6 members, 6 active; Buyer sponsored by C; Buyer2 right of B |
| QA25-03b | Pass | QA25_Boss=0.00/0.00 | QA25_Buyer=1500.00/0.00 | QA25_Buyer2=1500.00/0.00 |
| QA25-04 | Pass | repeat line: Up to ₱290.00 is earned by the members above you when this orde… |
| QA25-05 | Pass | status paid, msg "Order placed and paid. Thank you!", wallet 300.00 |
| QA25-06 | Pass | 1:QA25_C 50.00 pending; 2:QA25_B 50.00 pending; 3:QA25_A 50.00 pending; 4:QA25_Boss 30.00 pending; 5:admin 30.00 skipped |
| QA25-07 | Pass | 0 awards, 0 unilevel ledger rows |
| QA25-08 | Pass | points 1000, self 50.00, 1 awards, 4 volume rows |
| QA25-09a | Pass | exit 0, pending 4 |
| QA25-09b | Pass | order delivered; 4/5 unilevel credited; 1/1 binary credited |
| QA25-10 | Pass | QA25_A:50.00 \| QA25_B:50.00 \| QA25_Boss:30.00 \| QA25_C:50.00 |
| QA25-11 | Pass | HTTP 200, buyer visible |
| QA25-12 | Pass | HTTP 200, no award row for the buyer |
| QA25-12b | Pass | HTTP 200, source visible |
| QA25-14a | Pass | HTTP 200, self visible |
| QA25-14b | Pass | HTTP 200, the buyer's binary view renders |
| QA25-15 | Pass | status pending, 0 awards |
| QA25-15b | Pass | status payment_review, msg "Payment proof submitted (attempt 1). We will verify it shortly." |
| QA25-16 | Pass | status paid, 5 rows, msg "Payment verified — ₱1,200.00 marked paid." |
| QA25-17 | Pass | QA25_Boss 100.00 pending count 1 |
| QA25-18 | Pass | unilevel replayed true, binary replayed true, rows 5 |
| QA25-17b | Pass | binary credited 100.00, withdrawable 160.00 |
| QA25-19 | Pass | 1:QA25_C 25.00 pending; 2:QA25_B 25.00 skipped (recipient account is disabled); 3:QA25_A 25.00 pending; 4:QA25_Boss 15.00 pending; 5:admin 15.00 skipped (company account is not paid) |
| QA25-22 | Pass | order cancelled, [{"status":"skipped","n":2},{"status":"cancelled","n":3}], B 100.00 |
| QA25-20 | Pass | status paid, unilevel stamp 2026-11-23 13:35:39 total 0.00, binary points 0, rows 0/0 |
| QA25-21 | Pass | status paid, stamp 2026-11-23 13:35:40, total 0.00, rows 0 |
| QA25-23 | Pass | exit 0 |
| QA25-24a | Pass | unilevel 360.00/360.00, binary 200.00/200.00 |
| QA25-24b | Pass | ok; remaining 0 |

---

## 5. Passwords and sessions noticed during the run

- Signing a new member up **signs that browser in as the new member**. Registering the next account from the same session therefore runs as the previous member, and binary placement is refused with `That upline is outside your subtree.` The walkthrough registers each account from a fresh signed-out session (v1 already requires this; the run confirms it is essential).
- The isolated schema's admin password was set by the installer (`Qa25Run!Pass2026`); the document's `Admin@123` is the development default and cannot be used on a freshly installed schema.

---

## 6. What the run changed in the walkthrough (v1)

| # | Was (draft) | Is (verified by the run) |
|---|---|---|
| 1 | The checkout preview says ₱180 | The preview says **₱290** (29% of the whole ladder, including levels that may be skipped). The ledger, not the preview, is the paid figure. QA25-04 explains this. |
| 2 | `QA25_Boss`'s wallet is ₱130 | **₱160** — the second order pays another ₱30 at level 4; the pair adds ₱100. An early draft forgot the second ladder payment. |
| 3 | Cancelling works by default | Cancelling needs the Shop switch. `Enable Cancel of Order` ships **off**. QA25-22 turns it on and restores it, and the failure message is quoted so a tester recognises it. |

---

## 7. Evidence files

`docs/qa/evidence/phase-25/run/`:

- `qa25-run.log`, `run-stdout.txt` — full log, console output
- `qa25-run-results.json` — machine-readable results (30/30 pass)
- `qa25_http_run.php` — the runner (re-runnable on a fresh `ecowealth_sneat_qa25` schema)
- `qa25-01-settings-before.csv`, `qa25-01-settings-after.csv` — pre-run and post-run settings
- `qa25-02-product-before.csv`, `qa25-02-product-ladder.csv` — product state before/after
- `qa25-03-fixture-tree.csv`, `qa25-03-wallets.csv` — member tree and wallet balances
- `qa25-06-order1-unilevel-awards.csv` — order 1 repeat-purchase decisions
- `qa25-08-order1-binary-awards.csv`, `qa25-08-order1-binary-volume.csv` — order 1 binary awards and volume
- `qa25-09-order1-unilevel-after-release.csv`, `qa25-09-order1-binary-after-release.csv` — post-release award status
- `qa25-10-upline-wallets.csv` — upline withdrawable balances after release
- `qa25-13-node-balances.csv` — binary node balances
- `qa25-16-order2-unilevel-awards.csv`, `qa25-17-order2-binary-awards.csv` — order 2 awards
- `qa25-17-boss-pair.csv` — Boss pair award and node state
- `qa25-19-skipped-upline.csv` — order 3 skipped-upline decisions
- `qa25-23-totals.csv` — tie-out totals (360.00 / 360.00 / 200.00 / 200.00)
- `server.log`, `server.pid` — the HTTP server used

---

## 8. Teardown and limitations

- The QA schema `ecowealth_sneat_qa25` was **dropped** after the snapshot; the development database was never written to.
- No screenshots were taken: the report proves rendering only by HTTP 200 plus expected amount text, matching the phase-24 approach.
- The settings-card save button UI flow was not exercised through the browser; settings were verified by SQL after the runner's HTTP POST to the settings endpoint.
- The cleanup succeeded (unlike phase-24's QA24-29 which hit FK 1451): `binary_volume` was deleted first, then awards, orders, and users, in the documented order.
- Settings and Coffee were restored to their pre-run values after the run.

---

## 9. Verdict

The product-commission pipeline behaves as the corrected document describes: the repeat-purchase ladder pays at the right amount per level and skips the company account; the buyer is never a recipient of their own purchase; a manual (GCash) order pays nothing until admin verification and then pays identically to the e-wallet path; points stamp at payment, the Self Bonus pays the buyer, and volume rolls up every binary upline on the correct side; two legs of 1,000 points pay exactly one pair once; money stays pending until delivery + release; the release job credits every due award exactly once and is idempotent; a suspended upline is skipped without compression; switching a stream off stamps zero decisions; cancelling before release cancels pending awards and moves no money; every credited award has exactly one wallet movement (reconcile exit 0); and all QA data is removable in the documented order. **30 of 30 checks passed. No app defects found.**
