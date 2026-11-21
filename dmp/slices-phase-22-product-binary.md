# Phase 22 — Product Binary Bonus: Execution Slices

Source: `dmp/plan-ecowealth-product-binary-sliced.md` §2.7
Branch: `feature/product-binary`, cut from `feature/general-harvest` at `p21g-release` · Tag at start: `p22-start`
Delivery: nine gated phases 22A → 22I

## Phase 22A — Foundation (3 slices)
| Slice ID | Title |
|---|---|
| 22A-S1 | Canonical schema and wallet vocabulary |
| 22A-S2 | Install, reset and generated bundle parity |
| 22A-S3 | QA scaffold and foundation gate |

Parent gate tag: `p22a-foundation`

## Phase 22B — Configuration and readiness (3 slices)
| Slice ID | Title |
|---|---|
| 22B-S1 | Global binary settings and validation |
| 22B-S2 | Product flag, badge, filter and product card |
| 22B-S3 | Readiness panel, documentation and phase gate |

Parent gate tag: `p22b-configuration`

## Phase 22C — Placement, the tree and registration (5 slices)
| Slice ID | Title |
|---|---|
| 22C-S1 | Binary node read model and tree invariants |
| 22C-S2 | Placement service, root and immutable-move rules |
| 22C-S3 | Registration transaction and placement form |
| 22C-S4 | Admin placement console and audited move route |
| 22C-S5 | Backfill, demo tree, ownership guards and integration gate |

Parent gate tag: `p22c-placement`

## Phase 22D — Money engine (5 slices)
| Slice ID | Title |
|---|---|
| 22D-S1 | Points and award persistence, order snapshots and quote API |
| 22D-S2 | Paid-order event, volume routing, Self Bonus and pair matching |
| 22D-S3 | Eligibility, carry, daily cap, switches and concurrency |
| 22D-S4 | Escrow release primitives and cancellation reversal |
| 22D-S5 | Reconciliation, audit primitives and complete money-engine gate |

Parent gate tag: `p22d-money`

> **Boundary note for 22D:** these are implementation slices of one indivisible payment engine. No slice may be enabled or released on its own; the global switch (`binary_enabled`) stays off until the full 22D gate passes.

## Phase 22E — Admin visibility (3 slices)
| Slice ID | Title |
|---|---|
| 22E-S1 | Admin award ledger, filters and CSV |
| 22E-S2 | Admin order and member detail panels |
| 22E-S3 | Reports, readiness, dashboard and admin gate |

Parent gate tag: `p22e-admin`

## Phase 22F — Member visibility (4 slices)
| Slice ID | Title |
|---|---|
| 22F-S1 | Member binary earnings tab and award statement |
| 22F-S2 | Left/Right team cards, progress and roster pagination |
| 22F-S3 | Checkout, product, order and dashboard surfaces |
| 22F-S4 | Member integration, copy guards and phase gate |

Parent gate tag: `p22f-member`

## Phase 22G — The binary tree (5 slices)
| Slice ID | Title |
|---|---|
| 22G-S1 | Tree query service, JSON contract and server-side scope |
| 22G-S2 | Server-rendered tree shell and no-JavaScript list |
| 22G-S3 | d3 explorer, node cards and navigation controls |
| 22G-S4 | In-node registration modal and invite links |
| 22G-S5 | Live updates, accessibility, responsive QA and tree gate |

Parent gate tag: `p22g-tree`

## Phase 22H — Operations (3 slices)
| Slice ID | Title |
|---|---|
| 22H-S1 | Shared release job and binary stream selection |
| 22H-S2 | Binary points audit CLI and repair |
| 22H-S3 | Health checks, operational docs and operations gate |

Parent gate tag: `p22h-operations`

## Phase 22I — Release (2 slices)
| Slice ID | Title |
|---|---|
| 22I-S1 | Release marker, release tests and measured counts |
| 22I-S2 | Cold read, acceptance evidence and signed release |

Parent gate tag: `p22i-release` / `phase-22`

## Summary
- **Total slices:** 33 (3 + 3 + 5 + 5 + 3 + 4 + 5 + 3 + 2)
- **Phases:** 9 (22A → 22I)
- **Slice ID format:** `22X-Sn` (e.g. `22D-S1`)
- **Critical path:** 22A → 22B → 22C → 22D → 22F → 22G → 22I ≈ 13.5 ideal days of sequential work

## Usage
Use one prompt per slice, per the reusable template in the plan §2.7.12:
> You are implementing exactly [SLICE ID — TITLE] from the Product Binary Bonus plan.
> ...

Each slice is a bounded agent task, not a release phase. Stop after the slice, report the §2.7.2 completion record, and wait for the next instruction. Do not create release tags or `APP_PHASE` changes at slice boundaries — those belong only to the full parent-phase exit gate.
