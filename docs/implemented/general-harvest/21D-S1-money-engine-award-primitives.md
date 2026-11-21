# Phase 21D — Money engine — Slice 21D-S1 (implemented)

**Parent phase:** 21D — Money engine
**Slice:** 21D-S1 — Extend `GeneralHarvestAward` with immutable award creation/write primitives and the atomic wallet-link operation
**Status:** complete
**Branch / checkpoint:** `feature/general-harvest` (working tree, uncommitted); last release tag `p21c-baseline`
**Plan reference:** `dmp/plan-ecowealth-general-harvest-sliced.md` §2.7.3 (Phase 21D slice map), §12.5 (award rule), §14.2 (public API), §14.7 (transaction boundary)

## Summary

Slice 21D-S1 extended the `GeneralHarvestAward` ledger model (shipped read-only + baseline-only in 21C) with the **credited award write path**: `create()`, `linkLedger()`, and the combined `writeCredited()` primitive. It proves the award/wallet row relationship in isolation, before the settlement engine (21D-S2) wires registration. No money is exposed, armed, enabled, or released at this slice boundary.

## What was built

### `system/app/Models/GeneralHarvestAward.php`

Added public methods (the model never opens its own transaction; it runs inside the caller's):

- **`create(int $seq, array $snapshot, ?PDO $pdo): int`** — inserts one `credited` award row with `ledger_id = NULL`. Validates node index, level (1–10), positive user_id, decision instant, positive amount, and `n_at_award >= threshold_n`. Returns the new award id.
- **`linkLedger(int $awardId, int $ledgerId, ?PDO $pdo): bool`** — the only UPDATE in the class: sets `ledger_id` on the row just inserted, only where `status = 'credited' AND ledger_id IS NULL`, so an award can never point at a second movement. Returns true when a row was linked.
- **`writeCredited(int $seq, array $snapshot, ?PDO $pdo): array`** — award + wallet credit in **one transaction** (joins the caller's when one is open, opens its own otherwise): `create()` → `WalletService::credit(ref_type='general_harvest', ref_id=awardId)` → `linkLedger()`. Rolls back on any failure. Returns `['award_id' => ..., 'ledger_id' => ...]`.
- **`levelMap(int $userId, ?PDO $pdo): array`** — one member's decided levels as `level => status`.
- **`levelMapFor(array $userIds, ?PDO $pdo): array`** — several members' decided levels in one query (the batch read of §14.9).
- **`paidLevels(int $userId, ?PDO $pdo): array`** — the member's credited levels, ascending.
- **`gridFor(int $seq, int $n, ?PDO $pdo): array`** — node `$seq`'s ten-cell board card with per-level state, slots, range, threshold, filled, amount, status.

Also updated the file header comment (21C baseline-only → 21D credited path added).

### `system/tests/Cases/GeneralHarvestEngineTest.php` (new)

Slice S1 proof cases:

- **P21D-T8** — an award carries one General Harvest wallet credit: `ref_type = 'general_harvest'`, `ref_id` = the award id, withdrawable bucket up by the award amount.
- **P21D-T9** — an award row: `status = 'credited'`, `ledger_id` set, `credited_at`/`created_at` set, amount = the snapshotted ladder amount; plus a second level writes its own award and movement.
- **P21D-T28** — a duplicate `(seq, level)` insert is refused with `PDOException 23000` (the unique anchor).
- **S1 manifest** — `create`, `linkLedger`, `writeCredited`, `levelMap`, `paidLevels`, `gridFor` all exist on `GeneralHarvestAward`.

## How it satisfies the contract

| Plan rule | How S1 meets it |
|---|---|
| D-12 (award + credit commit together) | `writeCredited()` writes the award and the wallet credit inside one transaction; `linkLedger()` pins `ref_id` on the same row. |
| §14.7 Rule 3 (award written before credit; ref_id = award id) | `create()` inserts with `ledger_id NULL`; the credit's `ref_id` is the award id; `linkLedger()` names the movement after. |
| §14.6 layer 3 (unique anchor) | `UNIQUE (seq, level)` rejects a duplicate `(seq, level)` insert (P21D-T28). |
| §14.6 layer 4 (wallet anchor) | `WalletService::credit()` collapses a replayed credit for the same `(user_id, ref_type, ref_id, type)`. |
| D-13 (no reversal/void; row written once) | Only `linkLedger()` updates a row (once, `ledger_id`); `create()` never updates. |
| §12.7 (integer centavos) | Amounts normalised via `self::decimal()`/`self::cents()` before storage. |

## Tests run

- `php tests/run.php GeneralHarvestEngine` → **4 passed, 0 failed** (P21D-T8, T9, T28, S1 manifest)
- `php tests/run.php GeneralHarvestFoundationTest` → 15 passed, 0 failed (no regression)
- `php tests/run.php GeneralHarvestConfigurationTest` → green (no regression)

## Contract changes

None.

## Known risks / follow-up

None for S1. The settlement engine (`settle`, `settleAll`, `onQueueChanged`, `statusFor`, `openLiability`, `reconcile`, `doctor`) and the RegistrationService hook are 21D-S2 onward. The `writeCredited()` primitive here does **not** enforce the baseline check, depth, zero-amount skip, or on/armed guards — those belong to `GeneralHarvestService::settle()` in 21D-S2.

## Next allowed slice

**`21D-S2`** — Implement the service's core state-based settlement rules, proving P21D-T1–T7, T11–T25.
