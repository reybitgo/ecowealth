# EcoWealth — Phase 19 manual QA: Royalty bonus

**Scope.** Phase 19 of the member system (`dmp/plan-ecowealth-royalty.md`): a derived five-rank ladder
(Member, Leader, Supervisor, Manager, Director) and a monthly group-sales override paid once per closed
calendar month. The work is delivered in seven gated phases, **19A → 19G**. This document is created
by 19A with **section A only**; every later phase appends its own section before its gate, and the
file is never rewritten.

**Audience.** The developer/operator who runs the phase gate. Section A is surgical and moves **no
money**: it applies a migration, reads counts and runs the install/reset tools on scratch schemas.
From section C on the checks may move test money, and every one of those runs on a scratch schema —
never on a production database.

**Document status.** Section A (19A — Foundation) written 2026-11-15 with the automated gate green.
Section B (19B — Configuration and the rank ladder) written 2026-11-15 with the automated gate green.
Section C (19C — Money engine) written 2026-11-15 with the automated gate green.
**Section A: 8 checks · Section B: 9 checks · Section C: 12 checks.**

---

## 1. What is already automated

Run these first, from `system/`. If any fails, stop and report before starting the manual pass.

```powershell
php bin\build_install_sql.php --check     # exit 0, no output: the bundle matches its sources
php tests\run.php                         # full suite
powershell -NoProfile -File tests\smoke.ps1
php bin\wallet_reconcile.php              # exit 0: this phase moves no money
```

Expected final lines at `p19b-configuration`:

```text
Cases: 1054   Passed: 1054   Failed: 0
All good.
```

```text
Passed: 232   Failed: 0
```

The counts grow with each phase: **1,026 / 229** at `p19a-foundation`, **1,054 / 232** at
`p19b-configuration`; the suite floor rises to **1,090** at 19C and **1,140** at 19G, and the smoke
walk reaches **248** at 19G. 19B adds **28** PHP cases (`RoyaltyRankTest` P19B-T12 … T22 plus T27
and T28, `RoyaltyConfigurationTest` P19B-T1 … T11 and T26, and `RoyaltyAdminMemberViewTest`
P19B-T23 … T25) and **3** smoke checks (the settings card, the refused cross-field save and the
member rank column/distribution).

Phase 19B's share of the suite:

| Group | Cases | What it proves for 19B |
|---|---:|---|
| `RoyaltyConfiguration` (new) | 12 | The Royalty sub-block, its two switches, thirteen inputs and the read-only ladder; the reference defaults (10/10/10/10, 500k/1M/5M, 3/5 · 5/10 · 10/15, own purchase 8/15/25); the threshold, quota and rate ranges; non-decreasing quotas/rates; group + personal ≤ 100%; no enabled ladder that pays nothing; a real `applySection()` round-trip; one `settings.update` audit row naming the new keys with no money movement; and the saved-ladder preview tracking a stored edit |
| `RoyaltyRank` (new) | 13 | Member/Leader/Supervisor/Manager/Director at the threshold and one below it on the 111-member reference tree and small-threshold trees; counts exclude the candidate; a Leader 12 levels down counts; staff are invisible and never bridged; suspended members count; `created_at < asOf` cutoff; a cycle and a self-sponsor terminate and are unranked; the documented row shape with `withVolumes()` PS→GS and `counts()` (T27); `requirements()` (T28) |
| `RoyaltyAdminMemberView` (new) | 3 | The Rank badge per member row (and the 8-column empty state), the exact five-rank distribution plus `ranked`, and P19B-T25: saving the card and rendering the list moves no wallet, ledger, period or award row |
| `RoyaltyFoundation` | 0 | P19A-T13 is rewritten for 19B: the rank engine may read `users` and the settings, but no application file reads `royalty_periods`/`royalty_awards` or writes a `royalty` wallet movement before 19C |
| `SettingsPageTest` | 0 | The commissions catalogue now carries the thirteen Royalty values and two switches; the labels and rules are partitioned exactly like the other cards |
| `AdminSettingsLiveTest` | 0 | The `settings-commissions` fragment carries the Royalty sub-block, the ladder and no `royalty_ledger_since` |
| `AdminMemberLiveTest` | 0 | The directory feed renders the rank distribution, each row carries a rank badge, and the page has the Rank column |

No automated case in this phase moves money or writes a royalty period/award row. The HTTP walk uses
the development database and restores every setting it touches.

---

## 2. How to read a check

Every check has the same shape:

| Field | Meaning |
|---|---|
| **Why** | the phase rule or contract the check proves |
| **Where** | the exact shell, URL or SQL client |
| **Evidence** | the file name under `docs/qa/evidence/phase-19/` |
| **Result** | `☐ Pass  ☐ Fail  ☐ Blocked` — replace the box |

A check that produces only counts is captured as a **text transcript**, pasted into its evidence file;
a check that shows a browser screen is captured as a screenshot. Section A produces only text.

---

## 3. Section A — Phase 19A: Foundation (`QA19A-01` … `QA19A-08`)

**Prerequisites.** Tag `p19-start` on `feature/royalty` (the baseline before 19A); the development copy
at migration **022** (31 tables); the app running (Laragon → Start All) for the smoke walk in
`QA19A-08`; the scratch schemas `ecowealth_sneat_tool_a_test` and `ecowealth_sneat_tool_b_test`
provisioned (they are recreated by the tools themselves). Expected suite count for this section:
**1,026**.

> **Notation.** `mysql` below means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the
> app account; `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`.

### QA19A-01 — Back up the development copy and confirm it is at migration 022

| Why | 19A is the first schema change of Phase 19; the standing rule is a dump outside the repository before any migration. |
|---|---|
| Where | PowerShell + `mysqldump` |
| Evidence | `QA19A-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysqldump -u ecowealth -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-19\ecowealth_sneat-pre-19a-<stamp>.sql`
2. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM ecowealth_sneat.settings"`
4. `SHOW TABLES LIKE 'royalty%'` on that schema.

Expected

- The dump exists, is non-empty and lives **outside** the repository.
- **31** tables and **54** settings (the development copy was built by migrations `001`–`022`, so it
  carries no `install_*` metadata rows; a fresh install at `p19-start` holds **57** settings).
- `SHOW TABLES LIKE 'royalty%'` returns **no rows** — 19A has not run yet.
- If the table count is not 31, stop: the copy is not at migration `022`.

### QA19A-02 — Apply migration 023, then apply it a second time

| Why | Plan §5 Rule 1: the migration is idempotent and safe to re-run. |
|---|---|
| Where | `mysql` |
| Evidence | `QA19A-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysql -u ecowealth -p ecowealth_sneat < database\migrations\023_royalty_bonus.sql`
2. Repeat step 1.
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
4. `... "SELECT COUNT(*) FROM ecowealth_sneat.settings"`

Expected

- Both runs exit **0**; the second prints no error and changes nothing.
- **33** tables and **70** settings after the first run (the sixteen new keys; no `install_*` rows).
- Re-running does not duplicate a setting and does not fail on the enum guard.

### QA19A-03 — The two tables exist and the wallet ENUM names royalty

| Why | Plan §13.2/§13.3/§13.4: the tables are the phase's deliverable and `royalty` is appended to the movement vocabulary. |
|---|---|
| Where | `mysql` |
| Evidence | `QA19A-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysql -u ecowealth -p ecowealth_sneat -e "SHOW TABLES LIKE 'royalty%'"`
2. `mysql -u ecowealth -p ecowealth_sneat -e "SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'"`
3. `mysql -u ecowealth -p ecowealth_sneat -e "SHOW CREATE TABLE royalty_awards\G"` and
   `... royalty_periods\G`

Expected

- Exactly **two** tables: `royalty_awards`, `royalty_periods`.
- The `Type` column ends with `'royalty')` and still contains `'unilevel_reversal'` before it; there is
  no `royalty_reversal`.
- `royalty_periods` has `PRIMARY KEY (period)` and **no** `AUTO_INCREMENT` column;
  `royalty_awards` has `AUTO_INCREMENT` id, `UNIQUE KEY uq_royalty_award (period, user_id)` and the
  `chk_ra_*` CHECKs.

### QA19A-04 — The sixteen settings exist with the reference defaults

| Why | Plan §13.4: an operator must see the reference preset and the stream must ship off. |
|---|---|
| Where | `mysql` |
| Evidence | `QA19A-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysql -u ecowealth -p ecowealth_sneat -e "SELECT key_name, value FROM settings WHERE key_name LIKE 'royalty\_%' ORDER BY key_name"`

Expected

- **16** rows.
- `royalty_enabled = 0`, `royalty_pay_enabled = 1`.
- The four thresholds are `10`; the quotas `500000`, `1000000`, `5000000`; the rates `3.000/5.000`,
  `5.000/10.000`, `10.000/15.000`.
- `royalty_ledger_since` is a `YYYY-MM-DD HH:MM:SS` datetime.

### QA19A-05 — Rebuild the install bundle and prove it matches its sources

| Why | Plan §13.6: `install.sql` is generated; a stale bundle is a failed gate. |
|---|---|
| Where | PowerShell |
| Evidence | `QA19A-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\build_install_sql.php`
2. `php bin\build_install_sql.php --check; echo "exit=$LASTEXITCODE"`
3. `Select-String -Path database\install\header.sql,database\install\footer.sql -Pattern '33|73'`

Expected

- The rebuild reports **60 statements** and a byte size (it differs from the `022` bundle).
- `--check` exits **0** with no output.
- `header.sql` says **33 tables · 73 settings**; `footer.sql` says `tables_created = 33` and
  `settings_rows = 73`.

### QA19A-06 — A fresh install on a scratch schema reports 33 / 73 / …

| Why | Plan §13.6: the fresh-install path and the migrated path must agree. |
|---|---|
| Where | PowerShell (scratch schema `ecowealth_sneat_tool_a_test`) |
| Evidence | `QA19A-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Recreate the scratch schema empty (phpMyAdmin or `DROP DATABASE`/`CREATE DATABASE` for the tool
   schema).
2. `php bin\db_install.php --database=ecowealth_sneat_tool_a_test --force --json --admin-password=AdminPassw0rd!23 --superadmin-password=SuperPassw0rd!23`
3. `mysql -u ecowealth -p ecowealth_sneat_tool_a_test -e "SHOW TABLES LIKE 'royalty%'"`
4. `... "SELECT COUNT(*) FROM settings"` and `... "SELECT COUNT(*) FROM royalty_awards"`

Expected

- Exit **0**; the JSON `counts` read `tables_created 33`, `settings_rows 73`, `staff_rows 2`,
  `members_rows 0`, `products_rows 7`, `packages_rows 2`, `levels_rows 18`, `contents_rows 10`,
  `codes_rows 0`.
- Both royalty tables exist and are empty.
- `storage/install.lock` is written (a guided install always locks). Delete it before any further
  install on a scratch schema; the next check does.

### QA19A-07 — The reset previews and empties a royalty award and period

| Why | Plan §13.7/§5 Rule 6: a reset leaves both tables empty, resets only the award counter and
keeps the settings. |
|---|---|
| Where | PowerShell (scratch schema `ecowealth_sneat_tool_b_test`) |
| Evidence | `QA19A-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Install the `QA19A-06` bundle into `ecowealth_sneat_tool_b_test`, or run
   `php bin\db_install.php --database=ecowealth_sneat_tool_b_test --force ...` as above.
2. Insert one header and one award (the fixture SQL is in the evidence file):
   `INSERT INTO royalty_periods (...) VALUES ('2026-10', ...)` and
   `INSERT INTO royalty_awards (...) VALUES ('2026-10', <member id>, ...)`.
3. `php bin\db_reset.php --database=ecowealth_sneat_tool_b_test --dry-run --json` (or `--force
   --confirm=... --json` for the real run).
4. `mysql ... -e "SELECT COUNT(*) FROM royalty_awards; SELECT COUNT(*) FROM royalty_periods; SELECT COUNT(*) FROM settings WHERE key_name LIKE 'royalty\_%'"`
5. `... "SELECT auto_increment FROM information_schema.tables WHERE table_schema='ecowealth_sneat_tool_b_test' AND table_name='royalty_awards'"`

Expected

- The dry run's `counts` name `royalty_periods` and `royalty_awards`, `missing_tables` is empty and the
  plan carries a `royalty` step with **2** statements.
- After the reset both tables are **0** and the sixteen settings survive.
- `royalty_awards`'s counter is back at **1** (or NULL); `royalty_periods` has no counter (NULL).
- The reset's verification is `ok` (including the wallet, commission, transfer, payout and
  repeat-purchase reconciles).

### QA19A-08 — The money is untouched and the full gate is green

| Why | The universal gate (§2.4): wallet reconcile, suite and smoke. |
|---|---|
| Where | PowerShell |
| Evidence | `QA19A-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\wallet_reconcile.php; echo "exit=$LASTEXITCODE"`
2. `php tests\run.php` (expect `Cases: 1026   Passed: 1026   Failed: 0`)
3. `powershell -NoProfile -File tests\smoke.ps1` (expect `Passed: 229   Failed: 0`)
4. `php bin\build_install_sql.php --check; echo "exit=$LASTEXITCODE"`

Expected

- `wallet_reconcile` exits **0** and prints no mismatch: 19A moved no money.
- The suite is **1,026 / 1,026**, exit 0.
- The smoke walk is **229 / 229**, exit 0 — the phase adds no route.
- The bundle check exits **0**.

---

## 4. Section B — Phase 19B: Configuration and the rank ladder (`QA19B-01` … `QA19B-09`)

**Prerequisites.** Tag `p19a-foundation` on `feature/royalty`; the development copy at migration
**023** with the sixteen `royalty_*` settings; the app running (Laragon → Start All) for the browser
steps and the smoke walk in `QA19B-09`. Expected suite count for this section: **1,054**; smoke
**232**. No scratch schema is needed: 19B changes no schema and moves no money.

> **Notation.** `mysql` below means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the
> app account; `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`.

### QA19B-01 — The Royalty sub-block renders on the Commissions card

| Why | Plan §6 deliverable: an operator must see the whole ladder, its two switches and the saved ladder. |
|---|---|
| Where | Browser: `/admin/settings#settings-commissions` |
| Evidence | `QA19B-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as an administrator and open `/admin/settings#settings-commissions`.
2. Scroll below the *Repeat-purchase bonus* block.
3. Confirm the **Royalty bonus** sub-block: **Enable the Royalty bonus** (unchecked), **Pay Royalty
   awards** (checked), four *Rank requirements*, three *Monthly group quota* and six *Payout rates*
   inputs, and the five-row **Saved ladder** table.
4. `mysql -u ecowealth -p ecowealth_sneat -e "SELECT key_name, value FROM settings WHERE key_name LIKE 'royalty\_%' ORDER BY key_name"`

Expected

- Two switches and **thirteen** number inputs, each named `royalty_…`; no `royalty_ledger_since` input
  anywhere on the page.
- The saved ladder reads Member / Leader / Supervisor / Manager / Director with **10/10/10/10**,
  **₱500,000.00 / ₱1,000,000.00 / ₱5,000,000.00**, **3.000 / 5.000**, **5.000 / 10.000**,
  **10.000 / 15.000**, and **8.000 / 15.000 / 25.000%** in the own-purchase column.
- The sixteen settings still carry their defaults; `royalty_enabled` is `0` and `royalty_pay_enabled`
  is `1`.

### QA19B-02 — A threshold change saves and is audited once

| Why | Plan §6 rules 9–10: the card saves inside `section=commissions` and writes one `settings.update`. |
|---|---|
| Where | Browser + `mysql` |
| Evidence | `QA19B-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Note the current audit id: `mysql … -e "SELECT COALESCE(MAX(id), 0) FROM audit_log"`.
2. Change **Supervisor: downline Leaders** from `10` to `3`, keep every other value, press
   **Save settings**.
3. Reload the card and confirm the input and the saved ladder's Supervisor requirement now say `3`.
4. `mysql … -e "SELECT action, meta_json FROM audit_log WHERE id > <id> ORDER BY id"`

Expected

- The flash says **Commissions: 1 setting saved.** and the value persists.
- Exactly **one** audit row, action `settings.update`, section `commissions`, with
  `"royalty_supervisor_leaders":{"from":"10","to":"3"}` in `changes`.
- No `royalty` wallet movement and no `royalty_periods`/`royalty_awards` row is created
  (`SELECT COUNT(*)` on both stays `0`, and `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type =
  'royalty'` stays `0`).
- **Restore `10` and save again before the next check** — the manual run must leave the reference
  ladder in place.

### QA19B-03 — A Manager quota below the Supervisor quota is refused

| Why | Plan §12.9: quotas are non-decreasing by rank. |
|---|---|
| Where | Browser |
| Evidence | `QA19B-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set **Supervisor: monthly group quota** to `500000` and **Manager: monthly group quota** to
   `400000`, press **Save settings**.
2. Confirm the field error and reload the card.
3. Confirm the stored Manager quota is unchanged (`500000` or whatever it was before the attempt).

Expected

- A red field error on the Manager quota: *“The Manager quota cannot be below the Supervisor quota.”*
- Nothing is written: the stored value is the pre-attempt value and no `settings.update` row appears.

### QA19B-04 — Group + personal above 100% is refused

| Why | Plan §12.9: each rank's group + personal ≤ 100, so an award can never exceed its group sales. |
|---|---|
| Where | Browser |
| Evidence | `QA19B-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set **Supervisor: group %** to `60` and **Supervisor: personal %** to `50`, press **Save settings**.
2. Confirm the error names Supervisor and the 100% rule.

Expected

- A field error: *“Supervisor group + personal cannot exceed 100% (currently 110%).”*
- Nothing is written.

### QA19B-05 — Restore the reference defaults

| Why | The manual run must leave the documented preset in place. |
|---|---|
| Where | Browser + `mysql` |
| Evidence | `QA19B-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set 10/10/10/10, 500,000 / 1,000,000 / 5,000,000, 3/5, 5/10, 10/15 and save.
2. `mysql … -e "SELECT key_name, value FROM settings WHERE key_name LIKE 'royalty\_%' ORDER BY key_name"`
3. Confirm **Enable the Royalty bonus** is still off.

Expected

- The ladder reads the reference defaults exactly, `royalty_enabled = 0`, `royalty_pay_enabled = 1`,
  and the saved ladder shows 8.000 / 15.000 / 25.000% own purchase.

### QA19B-06 — The member directory shows the Rank column and the distribution

| Why | Plan §6 deliverable: the derived rank is visible on `/admin/members`. |
|---|---|
| Where | Browser: `/admin/members` |
| Evidence | `QA19B-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/members`.
2. Confirm the table header has **Rank** between **Status** and **Wallet**, and every row carries a
   badge (Member, Leader, Supervisor, Manager or Director).
3. Confirm the **Rank distribution** strip under the five counters lists the five exclusive counts
   and **N ranked (Supervisor+)**.

Expected

- One badge per row; a row without a rank renders **—** (never “Member” as a guess).
- The distribution totals equal the member count; Member + Leader + Supervisor + Manager + Director =
  the number of ranked member accounts; `ranked` = Supervisor + Manager + Director.
- The page loads with no PHP error and no Royalty money anywhere.

### QA19B-07 — The seeded demo tree matches the 10-direct rule

| Why | Plan §12.2: ranks count people, so the demo tree must rank exactly as the rule says. |
|---|---|
| Where | Browser: `/admin/members` |
| Evidence | `QA19B-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On `/admin/members`, inspect `juan`, `maria`, `peter`, `sarah` and `david`.
2. With the default 10-direct threshold, confirm **every** demo member reads **Member**.
3. Optionally lower **Leader: direct members** to `1` and save: `juan` (sponsor of `maria`) and
   `maria` (sponsor of `peter`, `sarah`, `david`) become **Leader**, everyone else stays **Member**;
   the distribution updates to Leader 2, Member 3.
4. Restore the threshold to `10` and save.

Expected

- The badges follow the direct-count rule exactly; nobody reaches Supervisor, Manager or Director in
  the five-member demo tree.
- No wallet, ledger, period or award row changes during either render.

### QA19B-08 — Wallet pages and the commission ledger show no Royalty money

| Why | Plan §6 Rule 8: 19B moves no money; 19C is the only producer. |
|---|---|
| Where | Browser + `mysql` |
| Evidence | `QA19B-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/commissions` and `/admin/commissions?ledger=repeat`; confirm no Royalty tab, card or
   row appears (the package and repeat ledgers are unchanged).
2. Open `/admin/wallets` and a member statement (`/admin/wallets/{id}`); confirm the Source filter has
   **Royalty bonus** as an option but no Royalty row exists.
3. Open the member's `/earnings` and `/dashboard`; confirm no Royalty tile or statement row.
4. `mysql … -e "SELECT COUNT(*) FROM royalty_periods; SELECT COUNT(*) FROM royalty_awards; SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'royalty';"`

Expected

- All three counts are **0**; no page renders a Royalty amount, pending award or period.
- `php bin\wallet_reconcile.php` still exits **0**.

### QA19B-09 — The Royalty group and the full gate are green

| Why | The universal gate (§2.4): suite, smoke, bundle and wallet reconcile. |
|---|---|
| Where | PowerShell |
| Evidence | `QA19B-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php tests\run.php Royalty` (the whole stream, expect **46 / 46**)
2. `php tests\run.php` (expect `Cases: 1054   Passed: 1054   Failed: 0`)
3. `powershell -NoProfile -File tests\smoke.ps1` (expect `Passed: 232   Failed: 0`)
4. `php bin\wallet_reconcile.php; echo "exit=$LASTEXITCODE"`
5. `php bin\build_install_sql.php --check; echo "exit=$LASTEXITCODE"`

Expected

- The Royalty group is **46 / 46**, the full suite **1,054 / 1,054**, the smoke walk **232 / 232**,
  wallet reconcile exit **0** and the bundle check exit **0**.
- No new PHP warning, notice or deprecation in `system/storage/logs/` after the pass.

---

## 5. Section C — Phase 19C: Money engine (`QA19C-01` … `QA19C-12`)

**Prerequisites.** Tag `p19b-configuration` on `feature/royalty`; the development copy at migration
**023**; PHP 8.3 CLI (no browser needed — 19C adds no page and no route, D-16). A **scratch schema**
is required for every check that moves money: `ecowealth_sneat_tool_a_test`, provisioned as in
`QA19A-06` (the app account has ALL on it; the checks drop and recreate it). Expected suite count:
**1,106** (Royalty group **98**); smoke stays **232** because no URL is added.

> **Notation.** `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`, with
> `ECO_DB_NAME=ecowealth_sneat_tool_a_test` in the environment so no check can touch
> `ecowealth_sneat`. `mysql` means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the
> app account, pointed at the scratch schema.

> **Never** run section C against the development copy or production. It pays real wallet money; the
> scratch schema exists so the money is disposable.

### C.1 The fixture

Section C uses the plan's §16.2 A tree — **31 member accounts**: 1 Director, 2 Managers,
4 Supervisors, 8 Leaders and 16 directs (the “15-member” shape is the 15 rank holders; each Leader
needs two directs). With the low thresholds `2/2/2/2` and the reference quotas and rates:

| Member | Rank | Directs | Leader+ below | Supervisor+ below | Manager+ below |
|---|---|---:|---:|---:|---:|
| `qa_director` | Director (4) | 2 | 14 | 6 | 2 |
| `qa_mgr_a` / `qa_mgr_b` | Manager (3) | 2 | 6 | 2 | 0 |
| `qa_sup_*` | Supervisor (2) | 2 | 2 | 0 | 0 |
| `qa_leader_*` | Leader (1) | 2 | 0 | 0 | 0 |
| `qa_member_*` | Member (0) | 0 | 0 | 0 | 0 |

The paid orders are placed in the **last completed month** (`RoyaltyPeriod::periodOf()` → previous
month): the Director buys ₱100,000 and each Supervisor buys ₱1,225,000. That produces:

| Recipient | GS | PS | Quota | Rates | Award |
|---|---:|---:|---:|---|---:|
| Director | 5,000,000.00 | 100,000.00 | 5,000,000 | 10% / 15% | **515,000.00** |
| each Manager | 2,450,000.00 | 0.00 | 1,000,000 | 5% / 10% | **122,500.00** |
| each Supervisor | 1,225,000.00 | 1,225,000.00 | 500,000 | 3% / 5% | **98,000.00** |

**Seven awards, ₱1,152,000.00 in total** — the reference example B.3 inside a larger tree.

### QA19C-01 — The fixture ranks exactly as the ladder says

| Why | Plan §12.2–§12.3: the rank is derived, so the fixture's shape must be provable before any money. |
|---|---|
| Where | CLI + `mysql` on the scratch schema |
| Evidence | `QA19C-01.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. Recreate the scratch schema from `database/schema.sql` + `database/seeds/001_settings.sql`.
2. Set the sixteen `royalty_*` settings: `enabled=1`, `pay_enabled=1`, thresholds `2/2/2/2`,
   quotas `500000/1000000/5000000`, rates `3/5 · 5/10 · 10/15`, and
   `royalty_ledger_since` = the first instant of the fixture month.
3. Create the 31 member accounts (created before the month end) and the five paid orders above.
4. `php tests/run.php RoyaltyRank` is the automated equivalent; here, read the derived ranks:
   `php -r` a small script using `RoyaltyRank::map('<period end>')`, or open `/admin/members`.

Expected

- The 31 accounts rank **Director 1, Manager 2, Supervisor 4, Leader 8, Member 16**.
- `period 2026-10  bounds [2026-10-01 00:00:00, 2026-11-01 00:00:00)`.
- No `royalty_periods`, `royalty_awards` or `royalty` ledger row exists yet.

### QA19C-02 — `--dry-run` shows the month and writes nothing

| Why | Plan §14.10: an operator reviews the month before any row exists. |
|---|---|
| Where | CLI |
| Evidence | `QA19C-02.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. `php bin\royalty_month_close.php --period=<P> --dry-run --json`

Expected

- `"dry_run": true`, `"periods": ["<P>"]`, `"award": 7`, `"paid": 7`, `"amount": 1152000`,
  `"status": "evaluated"`.
- `rank_counts`: member 16, leader 8, supervisor 4, manager 2, director 1.
- The seven drafts carry the amounts in C.1 (515,000 / 122,500 × 2 / 98,000 × 4).
- **Nothing is written:** periods 0, award rows 0, ledger rows 0, audit rows 0.

### QA19C-03 — `--evaluate-only` writes the ledger and no wallet money

| Why | Plan §19C Rule 1: evaluation may only insert `royalty_periods` and `royalty_awards`. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-03.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. `php bin\royalty_month_close.php --period=<P> --evaluate-only`
2. `mysql … -e "SELECT period,user_id,rank_level,group_volume,personal_volume,quota,group_pct,personal_pct,amount,status FROM royalty_awards"`
3. `mysql … -e "SELECT status,enabled,members_count,leaders_count,supervisors_count,managers_count,directors_count,award_count,paid_count,skipped_count,award_total,paid_total,as_of FROM royalty_periods"`

Expected

- Seven `pending` rows with the C.1 amounts and `reason IS NULL`, `ledger_id IS NULL`.
- Header `evaluated`, `enabled=1`, `as_of` = the period end, counts 31/8/4/2/1, `award_count 7`,
  `paid_count 0`, `award_total 1152000.00`, `paid_total 0.00`.
- **No wallet money:** `ewallet_ledger` royalty rows 0, every member's `withdrawable_balance` 0.00.
- The command prints `awards: 7 written` and `paid: 0 award(s)`.

### QA19C-04 — `--pay-only` credits the wallets and finalises the month

| Why | Plan §12.6.4: payment is the only Royalty creditor, one movement per award. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-04.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. `php bin\royalty_month_close.php --period=<P> --pay-only`
2. `mysql … -e "SELECT l.id,u.username,l.amount,l.ref_id,l.note FROM ewallet_ledger l JOIN users u ON u.id=l.user_id WHERE l.ref_type='royalty' ORDER BY l.id"`
3. `mysql … -e "SELECT status,paid_count,paid_total,paid_at FROM royalty_periods"`

Expected

- Seven credits, `ref_id` = the award id, note `Royalty bonus — <P> — <Rank>`, ids ascending by
  `user_id`.
- Wallet totals **1,152,000.00** withdrawable across the seven recipients.
- Header `paid`, `paid_count 7`, `paid_total 1152000.00`, `paid_at` set.
- `php bin\wallet_reconcile.php` exits 0 (checked in QA19C-06).

### QA19C-05 — Re-running a final month pays nothing twice

| Why | Plan §2.5, D-13: a closed month is immutable. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-05.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. `php bin\royalty_month_close.php --period=<P>` (the month is already paid)
2. Re-read the ledger count and the wallet total.

Expected

- Exit 0 and `result: already final (Paid) — a closed month is never rewritten.`
- Ledger rows stay **7**, the wallet total stays **1,152,000.00**, no new audit row.

### QA19C-06 — `wallet_reconcile.php` has a Royalty section and exits 0

| Why | Plan §14.12: finance audits the month from one read-only command. |
|---|---|
| Where | CLI on the scratch schema |
| Evidence | `QA19C-06.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. `php bin\wallet_reconcile.php`
2. `php bin\wallet_reconcile.php --json` and read the `royalty` object.

Expected

- A **Royalty** section: 1 period (paid), 7 paid awards (₱1,152,000.00), 0 pending, 0 skipped,
  `royalty ledger 1,152,000.00`, and `OK — every paid award has its wallet movement and every period
  header agrees with its rows.`
- Exit **0**; `"royalty": {"ok": true, ...}`.

### QA19C-07 — A cancellation **before** the close is excluded

| Why | Plan §12.4 rule 4, §14.13: only paid, non-cancelled orders are volume. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-07.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. Rebuild the scratch fixture with **one Supervisor** (2 Leaders, 4 directs), a paid order of
   ₱1,225,000 and a second order of ₱200,000 whose `shop_orders.status` is already `cancelled`.
2. `php bin\royalty_month_close.php --period=<P>`
3. Read the award row.

Expected

- GS and PS **1,225,000.00** (not 1,425,000.00), award **98,000.00**, status `paid`.
- The cancelled order contributes nothing to any row.

### QA19C-08 — A cancellation **after** the close changes nothing

| Why | D-13: the closed month is a financial record; v1 has no clawback. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-08.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. Close the C.1 fixture (`php bin\royalty_month_close.php --period=<P>`).
2. `mysql … -e "UPDATE shop_orders SET status='cancelled', cancelled_at=NOW() WHERE id=<the director's order>"`
3. Re-read the director's award row and `RoyaltyService::preview('<P>')`.

Expected

- The stored Director award is **still 515,000.00** with the same GS/PS; the header is still `paid`.
- `preview('<P>')` returns the **stored** header (it never recomputes a closed month).
- No ledger change.

### QA19C-09 — The payment pause evaluates but pays nothing, and pays once on resume

| Why | Plan §12.6.5, D-12: `royalty_pay_enabled` is the operational pause. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-09.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. Fresh scratch fixture: one Supervisor, ₱500,000 self-funded; set `royalty_pay_enabled = 0`.
2. `php bin\royalty_month_close.php --period=<P>`
3. Confirm `pending=1`, ledger 0, wallet 0.00.
4. Set `royalty_pay_enabled = 1`, then `php bin\royalty_month_close.php --period=<P> --pay-only`

Expected

- Step 2 prints `payments: paused`, `paused: payments are off …`, `awards: 1 written`,
  `paid: 0 award(s)`; the header stays `evaluated` with `award_total 40000.00`, `paid_total 0.00`.
- Step 4 pays **40,000.00** once; header `paid`, ledger rows 1, withdrawable 40,000.00.

### QA19C-10 — The earning switch off records a skipped month

| Why | D-14/D-15: a deployment never starts paying by accident, and a skipped month is never paid later. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-10.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. Fresh scratch fixture: one Supervisor with ₱500,000; set `royalty_enabled = 0`.
2. `php bin\royalty_month_close.php --period=<P>`
3. Read `royalty_periods` and both counts.

Expected

- One header: `status=skipped`, `enabled=0`, `note='royalty stream off'`, every count/total 0,
  `as_of` = the period end.
- **Zero** award rows, zero ledger rows, zero wallet money.
- Re-running with the stream on still refuses the month (it is final) — that is D-15.

### QA19C-11 — A two-month catch-up closes oldest first, each with its own cutoff

| Why | Plan §12.6.6: a missed cron must reproduce the on-time result. |
|---|---|
| Where | CLI + `mysql` |
| Evidence | `QA19C-11.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. Fresh scratch fixture: one Supervisor created before month one; ₱500,000 in month one and
   ₱1,000,000 in month two; `royalty_ledger_since` = the first instant of month one.
2. `php bin\royalty_month_close.php --json`

Expected

- `"periods": ["<M1>", "<M2>"]` (oldest first), `"closed": 2`, `"paid": 2`,
  `"amount": 120000`.
- `royalty_periods.as_of` is each month's own end (`<M1+1>-01 00:00:00`, `<M2+1>-01 00:00:00`).
- Each award row's `period` is its own month; the award amounts are **40,000.00** and **80,000.00**.
- A second run reports `closed: 0` and pays nothing.

### QA19C-12 — The Royalty group, the bundle check and the reconcile command are green

| Why | The universal gate (§2.4). |
|---|---|
| Where | PowerShell from `system/` |
| Evidence | `QA19C-12.txt` |
| Result | ☐ Pass ☐ Fail ☐ Blocked |

Steps

1. `php tests\run.php Royalty` (expect **98 / 98**)
2. `php tests\run.php` (expect `Cases: 1106   Passed: 1106   Failed: 0`)
3. `php bin\build_install_sql.php --check; echo "exit=$LASTEXITCODE"` (expect exit 0)
4. `php bin\wallet_reconcile.php; echo "exit=$LASTEXITCODE"` on the development copy (exit 0, Royalty
   section clean with 0 periods)
5. `powershell -NoProfile -File tests\smoke.ps1` (expect **232 / 232**; 19C adds no URL)

Expected

- The Royalty group **98 / 98**, the full suite **1,106 / 1,106**, the bundle check exit **0**, the
  development reconcile exit **0** and the smoke walk **232 / 232**.
- No new PHP warning, notice or deprecation in `system/storage/logs/` after the pass.

---

## 6. Sign-off

| Section | Checks | Passed | Failed | Blocked | Tester | Date | Evidence |
|---|---:|---:|---:|---:|---|---|---|
| A — Foundation | 8 | 8 | 0 | 0 | Automated QA pass — DeepSeek Harness (DSH) | 2026-11-15 | `docs/qa/evidence/phase-19/` |
| B — Configuration and the rank ladder | 9 | 9 | 0 | 0 | Automated QA pass — DeepSeek Harness (DSH) | 2026-11-15 | `docs/qa/evidence/phase-19/` |
| C — Money engine | 12 | 12 | 0 | 0 | Automated QA pass — DeepSeek Harness (DSH) | 2026-11-15 | `docs/qa/evidence/phase-19/` |

**Decision:** ☑ Pass  ☐ Pass with notes  ☐ Fail

Sections C–G are appended by 19C–19G before their gates; sections A and B are final.
