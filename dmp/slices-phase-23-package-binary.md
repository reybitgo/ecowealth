# Phase 23 — Package Binary Bonus: Execution Slices

Source: `dmp/plan-ecowealth-package-binary-sliced.md` §2.7
Branch: `feature/package-binary`, cut from `feature/product-binary` at `p22i-release` · Tag at start: `p23-start`
Delivery: seven gated phases 23A → 23G

## Phase 23A — Foundation (2 slices)
| Slice ID | Title |
|---|---|
| 23A-S1 | Build the migration and fresh-install schema amendment |
| 23A-S2 | Wire install/reset counts and shared vocabulary; prove no product regression |

Parent gate tag: `p23a-foundation`

## Phase 23B — Configuration and readiness (2 slices)
| Slice ID | Title |
|---|---|
| 23B-S1 | Package-level controls and package-list visibility |
| 23B-S2 | Global settings, readiness panel and phase gate |

Parent gate tag: `p23b-configuration`

## Phase 23C — Money engine (5 slices)
| Slice ID | Title |
|---|---|
| 23C-S1 | Extend the shared binary primitives for a second source |
| 23C-S2 | Implement package activation orchestration and package qualification |
| 23C-S3 | Wire the activation transaction and legacy placement completion |
| 23C-S4 | Implement the support-only activation reversal |
| 23C-S5 | Finish source-aware reconciliation, integration proof and phase hand-off |

Parent gate tag: `p23c-money`

> **Boundary note for 23C:** this is one indivisible money release. The slices separate implementation concerns only; they do not permit a partial package payment rule, a temporary second engine, or an intermediate release. Keep the package path transactional and keep the product `source = 'order'` path unchanged.

## Phase 23D — Admin visibility (2 slices)
| Slice ID | Title |
|---|---|
| 23D-S1 | Add the source-aware admin binary ledger |
| 23D-S2 | Add activation/member audit surfaces and package reports |

Parent gate tag: `p23d-admin`

## Phase 23E — Member visibility (2 slices)
| Slice ID | Title |
|---|---|
| 23E-S1 | Add the package card and source-aware binary earnings tab |
| 23E-S2 | Add dashboard and genealogy package indicators; close member QA |

Parent gate tag: `p23e-member`

## Phase 23F — Operations (2 slices)
| Slice ID | Title |
|---|---|
| 23F-S1 | Extend binary audit for activation-source invariants |
| 23F-S2 | Health checks, operational docs and acceptance |

Parent gate tag: `p23f-operations`

## Phase 23G — Release (2 slices)
| Slice ID | Title |
|---|---|
| 23G-S1 | Release marker, release-specific tests and documentation completion |
| 23G-S2 | Cold-read acceptance, evidence and final sign-off |

Parent gate tag: `p23g-release` / `phase-23`

## Summary
- **Total slices:** 17 (2 + 2 + 5 + 2 + 2 + 2 + 2)
- **Phases:** 7 (23A → 23G)
- **Slice ID format:** `23X-Sn` (e.g. `23D-S1`)
- **Critical path:** 23A → 23B → 23C → 23D → 23E → 23G ≈ 7.5 ideal days of sequential work

## Usage
Use one prompt per slice, per the reusable template in the plan §2.7.9:
> You are implementing exactly [SLICE ID — TITLE] from the Package Binary Bonus plan.
> ...

Each slice is a bounded agent task, not a release phase. Stop after the slice, report the slice's completion record, and wait for the next instruction. Do not create release tags or `APP_PHASE` changes at slice boundaries — those belong only to the full parent-phase exit gate.
