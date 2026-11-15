# Evidence — Phase 20 (Team Harvest bonus)

One file per check, named exactly as the check's **Evidence** line. A check that produces only counts,
SQL output or a CLI exit code is captured as a **text transcript** (`.txt`); a check that shows a
browser screen is captured as a screenshot (`.png`). Phase 20 ships no new route, so the sections are
transcripts that assert counts, tables and rendered markup through the application itself.

This folder is created by **20A** and indexed here. Every later phase appends its own rows; the file is
never rewritten.

Index:

| File | Check | What it shows | Status |
|---|---|---|---|
| `QA20A-01.txt` | QA20A-01 | The pre-migration dump outside the repository, and the development copy at **33 tables / 70 settings** with no `harvest%` table | pending (manual gate) |
| `QA20A-02.txt` | QA20A-02 | A **p20-start** scratch schema (from `git show p20-start:…install.sql`), `SHOW TABLES LIKE '%harvest%'` empty before and three rows after, migration `024` applied **twice** (both exit 0), the three column lists and the ledger enum ending `'team_harvest'` | captured |
| `QA20A-03.txt` | QA20A-03 | The four `team_harvest_*` settings with their defaults (`0`, `3`, `10`, `''`), `COUNT(*) = 10` / `SUM(amount) = 5095000.00`, and the ten amounts level by level | captured |
| `QA20A-04.txt` | QA20A-04 | `/admin/settings` with **no** Team Harvest block and a member page with **no** harvest panel | pending (needs the running app) |
| `QA20A-05.txt` | QA20A-05 | Three direct registrations leave `harvest_queue` at **0** and the sponsor's page unchanged | pending (needs the running app) |
| `QA20A-06.txt` | QA20A-06 | The member and admin wallet statement filters list **Team Harvest bonus** and select **no rows** | pending (needs the running app) |
| `QA20A-07.txt` | QA20A-07 | `build_install_sql.php` (64 statements) and `--check` exit 0; a fresh CLI install on `ecowealth_sneat_tool_a_test` reporting **36 / 77 / 10** (plus 2 / 0 / 7 / 2 / 18 / 10 / 0); `wallet_reconcile` exit 0 | captured |
| `QA20A-08.txt` | QA20A-08 | A dry run planning the **harvest** step, the real reset on a scratch schema emptying the queue and awards, the baseline back to `''`, the ten ladder rows and four settings kept, the counters at 1, and a clean verification | captured |
| `QA20B-01.txt` | QA20B-01 | The Team Harvest card on the migrated development copy: ten reference amounts, switch **off**, gate `3`, depth `10`, the strip **Not seeded** naming `bin/harvest_queue_seed.php`, the ₱5,095,000.00 per-member maximum, the four settings and the ten ladder rows, and an empty queue | captured (automated pass) |
| `QA20B-02.txt` | QA20B-02 | Level 1 → ₱1,500 and level 10 → ₱4,000,000 saved; total **₱5,595,500.00**; `team_harvest.ladder.update` with before/after naming `levels.1` and `levels.10`; `updated_by` on all ten rows; restored to the reference ladder | captured (automated pass) |
| `QA20B-03.txt` | QA20B-03 | Three levels at ₱10,000,000 (₱30,195,000.00) refused with the `sum` error; the stored ladder unchanged; the plan's ₱20,000,000 level-10 step is refused by the **per-level** ceiling first (deviation recorded) | captured (automated pass) |
| `QA20B-04.txt` | QA20B-04 | The preset rides on each input's `data-reference` (`1000.00 … 3500000.00`); saving the preset restores ₱5,095,000.00 (the **Load reference ladder** button itself is JavaScript and needs the browser) | captured (server-side equivalent) |
| `QA20B-05.txt` | QA20B-05 | Clearing the ten and enabling the stream is refused on the switch with the exact message; the stored switch stays `0` and the ladder is untouched | captured (automated pass) |
| `QA20B-06.txt` | QA20B-06 | Gate `11` refused (stored `3` survives); depth `3` accepted and levels 4–10 marked **Not paid** with their amounts kept; depth restored to `10` | captured (automated pass) |
| `QA20B-07.txt` | QA20B-07 | Three registrations under one sponsor leave `harvest_queue` at **0**, no award and no `team_harvest` wallet row | pending (needs a signed-in browser session on `/register`) |
| `QA20B-08.txt` | QA20B-08 | The card saves and validates with JavaScript disabled; only the live totals are missing | pending (needs a browser with JS off) |
| `QA20B-09.txt` | QA20B-09 | 390 px: the ten inputs stack, the saved table scrolls inside the card, no page-level horizontal scroll; 1440 px: two columns | pending (needs a browser at both widths; `P20B-T18` pins the markup) |
| `QA20C-01.txt` | QA20C-01 | Three real `RegistrationService` registrations under `QA20C_Low` on an unseeded scratch copy: `harvest_queue` **0**, no award, `team_harvest_ledger_since` still `''` — the hook is inert | captured (automated pass) |
| `QA20C-02.txt` | QA20C-02 | The seed's dry run: the 3 gate holders (including the member who crossed the gate while unseeded), positions 1–3 in `(created_at, id)` order, the first 20 rows, the baseline instant, the excluded money — and **no** row, setting or audit change | captured (automated pass) |
| `QA20C-03.txt` | QA20C-03 | The real seed: a contiguous 1…3, `baseline_n = teammates` on every row (2/2, 1/1, 0/0), `directs_at_qualification = 3`, `source = seed`, the baseline instant written, `team_harvest_awards` still 0 | captured (automated pass) |
| `QA20C-04.txt` | QA20C-04 | A second real seed: exit **1**, the "already seeded … holds 3 position(s)" message, zero rows written, positions and baseline unchanged, one audit row | captured (automated pass) |
| `QA20C-05.txt` | QA20C-05 | Three registrations under a member with no directs: the newcomer appends at **position 4** with `baseline_n 0`, and every queued ancestor moves by exactly one (Low 0→1, Mid 1→2, Top 2→3); `verify()` clean | captured (automated pass) |
| `QA20C-06.txt` | QA20C-06 | The rebuild drill: 0 drift on a clean queue, a hand-edited counter reported as `stored 7 / recount 2` with nothing written, `--apply` repairing it to 2 without moving a position, and one `harvest.queue.repair` audit row naming before/after | captured (automated pass) |
| `QA20C-07.txt` | QA20C-07 | After 20C-01…06: `team_harvest_awards` **0**, no `team_harvest` ledger row, both wallet buckets untouched (0.00), the ten ladder rows still ₱5,095,000.00 and the switch still `0` | captured (automated pass) |
| `QA20C-08.txt` | QA20C-08 | The reset's dry run planning the **harvest** step; the reset emptying the queue and the awards, clearing the baseline and keeping the ladder; the next seed starting at **#1** again | captured (automated pass) |
| `QA20D-01.txt` | QA20D-01 | The 13-account live fixture: one `credited` level-1 row (threshold 3, n 3, ₱1,000.00, ledger linked), the withdrawable bucket at ₱1,000.00 and one `team_harvest` movement | captured (automated pass) |
| `QA20D-02.png` | QA20D-02 | The member's `/wallet`: the **Team Harvest bonus** credit, the filter option matching it and the running balance | pending (needs the app in a browser) |
| `QA20D-03.png` | QA20D-03 | `/admin/wallets`: the same movement with `ref_type = team_harvest` and the award id as `ref_id` | pending (needs the app in a browser) |
| `QA20D-04.txt` | QA20D-04 | `wallet_reconcile.php` exit 0 with the Team Harvest section: positions, credited awards/amount, baseline 0, drift 0 and the `--json` block | captured (automated pass) |
| `QA20D-05.txt` | QA20D-05 | `php tests/run.php TeamHarvestEngine` 28/28 with the five amount cases named (T2 7,000 · T3 3,000 · T4 1,000 · T6 13,000 · T7 25,000/18 rows/13 members) and the level distribution SQL | captured (automated pass) |
| `QA20D-06.txt` | QA20D-06 | A fourth direct and a second qualifying direct: award count and wallet total unchanged, the catch-up reporting `paid: 0` | captured (automated pass) |
| `QA20D-07.txt` | QA20D-07 | Switch off: 4 positions (13 accounts), 0 awards; switch on: the dry run's 1 level / 1 member / ₱1,000.00, the real run's one award, the second run's `paid: 0` | captured (automated pass) |
| `QA20D-08.txt` | QA20D-08 | `teammates 12 / baseline_n 12`; after growth levels 1–2 `baseline` (ledger NULL) and level 3 `credited` ₱4,000.00; the wallet at ₱4,000.00 | captured (automated pass) |
| `QA20D-09.txt` | QA20D-09 | `--doctor` exit 0 with nine green checks; after a hand-edited counter exit 1 with `1 counter(s) disagree with a recount`; nothing repaired | captured (automated pass) |
| `QA20D-10.txt` | QA20D-10 | A deleted movement reported as `missing_ledger` with its award id, `wallet_reconcile` exit 1; restored from the dump, exit 0 | captured (automated pass) |
| `QA20E-01.txt` | QA20E-01 | Fixture A on the fourth tab: **₱25,000.00** credited, **18** awards, **13** recipients, baseline ₱0.00, open liability ₱0.00, the header badges **Seeded · Paying**, and the SQL that agrees to the centavo | captured (automated pass) |
| `QA20E-02.txt` | QA20E-02 | Level `3` → one row (the root's ₱4,000.00); status `Before go-live` on the seeded baseline network → two rows with no wallet link; a bad level falls back to no filter (18 rows) | captured (automated pass) |
| `QA20E-03.png` | QA20E-03 | A row → its member panel (position, teammates, levels, next level) → its wallet movement, in one click each | pending (needs the app in a browser) |
| `QA20E-04.txt` | QA20E-04 | The filtered CSV has the Appendix C.5 header and one row matching the screen, and writes one `team_harvest.export` audit row with `level = 3` and `rows = 1` | captured (automated pass) |
| `QA20E-05.txt` | QA20E-05 | `/admin/members`: the Harvest column reads the stored positions (`#N` / `—`), the queue card shows the queued count, the next position and the open liability, and `?harvest=yes` / `no` page and combine with status | captured (automated pass) |
| `QA20E-06.txt` | QA20E-06 | `/admin/members/{id}` for a member with two directs: **Not qualified: 2 of 3 direct members.** and no award table | captured (automated pass) |
| `QA20E-07.txt` | QA20E-07 | `/admin/reports`: the Team Harvest block carries the ledger's own figures (₱6,000.00 on the smaller live tree), the dashboard KPI reads the last 30 days, and the range CSV has `Award` rows plus one `Queue` row | captured (automated pass) |
| `QA20E-08.txt` | QA20E-08 | The Package, Repeat and Royalty tabs keep their fragments, byte-identical partials and counts after the fourth pill | captured (automated pass, fragment-level) |

Notes for the reader:

- `mysql` in the transcripts is `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` run as the
  application account through a temporary option file (never on the command line); `php` is Laragon's
  PHP 8.3 CLI. Every command ran from `system/`.
- `QA20A-02` and `QA20A-03` run on `ecowealth_sneat_tool_a_test` built from the **`p20-start`
  bundle** (`git show p20-start:system/database/install.sql`), so they prove the migration upgrades a
  real Phase 19 database, not a synthetic one.
- `QA20A-07` and `QA20A-08` use the pre-provisioned scratch slots (`…_tool_a_test`,
  `…_tool_b_test`); the development copy (`ecowealth_sneat`) is not touched by them.
- The development copy is **migration-built**: it carries no `install_*` metadata rows, so it reads
  **70 settings** before migration `024` and **74** after it. A fresh install reads **73** and **77**
  respectively — the bundle's footer adds the three install metadata rows.
- `QA20A-01`, `04`, `05` and `06` are the human part of the gate: the backup, the two **absence**
  checks on the running app, the three-registration proof that no hook exists, and the wallet filter.
  They are recorded when the project lead runs the manual pass; the automated pass captured the other
  four on scratch schemas.
- Section B's transcripts `QA20B-01` … `QA20B-06` were captured by the automated pass **on the
  development copy** (`ecowealth_sneat`, migrated to `024`): the card is rendered through
  `AdminSettingsController::settingsContext()` + `View::page(...)` with the seeded `admin` signed in,
  and saves/refusals go through the controller's real `applySection()` path. `QA20B-04` is the
  **server-side equivalent** of the JavaScript button — the preset's `data-reference` values and a
  save round-trip; the click itself needs a browser. `QA20B-07` (a signed-in `/register` session),
  `QA20B-08` (JavaScript off) and `QA20B-09` (390/1440 px) are the human part and were left for the
  lead, exactly as section A's page checks were.
- The automated pass restored the reference ladder, gate `3`, depth `10` and the switch `0` on the
  development copy before it exited; its only lasting trace is the `team_harvest.ladder.update` and
  `settings.update` rows it wrote (the audit trail the checks describe).
- Section C's eight transcripts were captured by the automated pass on a **scratch schema**
  (`ecowealth_sneat_tool_b_test`, rebuilt from `database/schema.sql` + seeds 001–004), because the
  section seeds a queue, registers members and finally runs a business reset. The app-level steps
  (the tree, the registrations, the counter edit) went through the real `RegistrationService` and
  `HarvestQueue`; the CLI steps went through `bin/harvest_queue_seed.php` and `bin/db_reset.php`
  unchanged. The development copy (`ecowealth_sneat`) was **not** seeded: it stays unseeded, which is
  the shipped default. The browser part of `QA20C-01`/`05` (a signed-in `/register` session) is the
  only human step and is left for the lead, as in sections A and B.
- Section D's transcripts were captured by the automated pass on a **scratch schema**
  (`ecowealth_sneat_tool_b_test`) and with the real commands (`bin/harvest_queue_seed.php`,
  `bin/team_harvest_catchup.php`, `bin/wallet_reconcile.php`); the fixtures were grown through
  `TeamHarvestService::onQualified()` — the registration hook's exact call — so the money the
  transcripts show was written by production code. The section deliberately does **not** enable the
  stream on the development copy: `ecowealth_sneat` stays seeded-or-not as it was and with the switch
  off. The two browser screenshots (`QA20D-02`, `QA20D-03`) are the only human steps.
