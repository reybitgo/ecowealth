# Phase 21 — General Harvest: Execution Log

**Parent phase:** 21 — General Harvest bonus
**Plan:** dmp/plan-ecowealth-general-harvest-sliced.md

## Completed slices

| Slice | Title | Commit | Status |
|-------|-------|--------|--------|
| 21F-S1 | Earnings tab, member-scoped award rows, pagination, wallet links, member CSV export | 43c1dc4 | done |
| 21F-S2 | Ten-block card, progress/range/roster presentation, mandatory copy, baseline wording, subtree-only username privacy | 449bc72 | done |
| 21F-S3 | Dashboard tile, genealogy badge, live refresh, mobile behaviour, query budget, complete member gate | working tree | done |

## Changed files (this session)

- `system/tests/Cases/GeneralHarvestFoundationTest.php`
  - Extended P21A-T15 allow list with 21D/21E/21F files that reference `general_harvest` (controllers, models, services, admin and member views)
  - Extended P21A-T16 view naming list with the same 21E/21F view files, sorted to match the recursive directory scan order
  - Removed `app/Controllers/GenealogyController.php` from both lists (it references `GeneralHarvest` in CamelCase comments, not the literal `general_harvest` string)

## Tests run and results

| Test group | Cases | Passed | Failed | Notes |
|------------|-------|--------|--------|-------|
| GeneralHarvestFoundationTest | 15 | 15 | 0 | P21A-T15 and P21A-T16 now green after manifest update |
| GeneralHarvestMemberViewTest | 21 | 21 | 0 | Includes P21F-T17 (live refresh), T18 (mobile), T21 (stream off) |
| MemberDashboardLiveTest | 10 | 10 | 0 | Includes P21F-T10 (dashboard tile) |
| MemberGenealogyLiveTest | 8 | 8 | 0 | Includes P21F-T11 (genealogy badge) |

S3-relevant plan checks: P21F-T10, T11, T17, T18, T21 all green.

## Decisions / deviations

- None. All S3 code (dashboard tile view, genealogy badge views, controller wiring, live refresh via `Live::touchGeneralHarvestMember()`) was already committed in 21F-S2. The only work needed was fixing the two pre-existing 21A foundation-test manifest checks that had not been updated for 21D/21E/21F files.

## Unresolved issues

- None.

## Next slice

21G-S1 (Synchronize product documentation) / 21G-S2 (Final QA pack, evidence index, sign-off sheet).
