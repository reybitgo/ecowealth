# EcoWealth — Phase Log

Running record of decisions, migrations, backups and test results. One section per phase; append, never rewrite history.

---

## Phase 0 — Setup, baseline and decisions

**Date:** 2026-11-03 · **Branch:** `feature/shop` · **Baseline tag:** `phase-0-start` (on `b96b745` "updated phased plan for ecowealth cart system") · **Status:** complete except a partial visual baseline (see *Deviations*).

### Environment

| Item | Value |
|---|---|
| PHP | 8.3.16 (Laragon `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64`) |
| MySQL | 8.4.3 (Laragon) — listening on `127.0.0.1:3306` |
| Web server | Apache on `http://localhost` (ports 80/443) |
| App URL | `http://localhost/ecowealth/system/` |
| Front door | `http://localhost/ecowealth/` → repo-root `index.html` (D23) |
| PHP extensions | `pdo_mysql` present |

### Repository

- Branch `feature/shop` created from `main`; annotated tag `phase-0-start` on the pre-migration commit.
- `.gitignore` added: `config.local.php`, `system/public/uploads/*` (keeps `.gitkeep`), `*.log`, `backup_*.sql`, `backups/`, `tests/tmp/`, `system/tests/tmp/`.
- Verified ignored: `system/app/config.local.php` (`.gitignore:7`), `backups/x.sql` (`backups/`), `tests/tmp/a.txt`.

### Backups (outside the repo)

| File | Size | Contents |
|---|---|---|
| `C:\laragon\www\ecowealth-backups\phase-0\app-baseline-20261103.zip` | 33.5 MB | `system/`, `index.html`, `assets/` |
| `C:\laragon\www\ecowealth-backups\phase-0\db-if0_42820471_ew1_db-20261103.sql` | 4 KB | `mysqldump --databases if0_42820471_ew1_db` |
| `login.png` | 51 KB | 1440×900 baseline screenshot (login page) |
| `register.png` | 47 KB | 1440×900 baseline screenshot (register page) |

### Database

- **Created** `ecowealth_sneat` (`utf8mb4` / `utf8mb4_unicode_ci`) — intentionally empty; the target schema arrives in Phase 1.
- **Dedicated account** `ecowealth`@`localhost` and `ecowealth`@`127.0.0.1`; password lives only in `system/app/config.local.php`. Grants: `ALL` on `ecowealth_sneat.*` and on the pre-existing app DB `if0_42820471_ew1_db.*` (so the baseline keeps working until Phase 1 migrates).
- **Retained** the existing app DB `if0_42820471_ew1_db` (1 user: `admin`, role `admin`, active).
- Root (local, empty password) was used only for provisioning — never by the app.

### Configuration

- `system/app/config.php` — now **defaults only**: loads `config.local.php` first (when present), then declares every constant with `defined() || define()` so local settings and the Phase 1 test harness can override them. Branding: `APP_NAME = EcoWealth`, session cookie `ecowealth_sid`, remember cookie `ecowealth_remember`.
- `system/app/config.local.php` — created and git-ignored: local DB host/name/user/password. `DB_NAME` stays `if0_42820471_ew1_db` for the baseline; **Phase 1 flips it to `ecowealth_sneat`** when the target schema is imported.
- No credentials remain in a tracked file.

### Decisions signed off (plan §12)

| # | Decision | Recorded choice |
|---|---|---|
| D1 | App/brand name | **EcoWealth** (configurable `APP_NAME`) |
| D2 | Guests browse the shop? | **No** — members only |
| D3 | Sponsor required at registration? | **Yes** (seeded root accounts excepted) |
| D4 | Package required to shop? | **No** — shopping open to logged-in members |
| D8 | Shipping fees | **None** for now (`shipping_fee = 0`, field reserved) |
| D9 | Manual payment channels | **GCash / Maya / bank**, set in settings |
| D15 | Registration codes | **In scope** — Phase 9 |
| D17 | Payout fund handling | **Hold at request**, refund on reject |
| D18 | USDT payout rates | **Server-side** rate + gas fee from settings |
| D19 | Super-login transport | **URL-token**, 8 h TTL, IP/UA bound, hashed nonce |
| D22 | Transfer password confirmation | **Required** |
| D23 | Public front door | **`index.html` now**; EcoWealth frontend rebuild later |
| **D10** | **Official product price list & PV** | **OPEN** — placeholder seeds approved for development; the official list is still required before the Phase 2 seed freeze |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P0-T1 | `php -v`, `mysql --version` | **PASS** | PHP 8.3.16; MySQL 8.4.3 |
| P0-T2 | DB connection as the app account | **PASS** | PDO OK (8.4.3) as `ecowealth`; DB `if0_42820471_ew1_db`; 1 user row; `admin` hash verifies with `Admin@123` |
| P0-T3 | Baseline auth flow | **PASS** | `GET /` → 302 `/login`; `GET /login` → 200 (CSRF 64 hex); `POST /login` (admin) → 302 `/dashboard`; `GET /dashboard` → 200 (66,591 bytes, shows `admin`); `GET /register` while signed in → 302 `/dashboard`; `POST /logout` → 302 `/login`. Registration: `POST /register` for `phase0_smoke` → 302 `/login`, row created (`user`, active), login → 302 `/dashboard`, dashboard 200, logout → 302; smoke row deleted afterwards (DB back to 1 user) |
| P0-T4 | Branch/tag + backup | **PASS** | `feature/shop` + `phase-0-start`; backups exist outside the repo; working tree contains only Phase 0 changes |
| P0-T5 | Decisions log populated | **PASS** | This document |

### Deviations and notes

- **Visual baseline.** `login.png` and `register.png` captured at 1440×900. The authenticated dashboard is verified by HTTP only (P0-T3) — headless Chromium cannot hold a signed-in session from the command line. Capturing the browser also required wider one-off access because Chromium needs named pipes, which the sandbox blocks.
- `mysqldump` for the baseline ran as MySQL `root` (local, empty password); the application itself always runs as the dedicated `ecowealth` account.
- The plan's note about InfinityFree credentials is now historical: the repo copy of `config.php` had them, `config.local.php` replaces them with the local account.
- **Plan §2.5 reconciliation:** done in plan v2.1 — 98/98 reference pages mapped, dropped or deferred; re-checked in v2.2 against the completed reference. No further action.

### Gate checklist (plan §7 Phase 0)

- [x] Environment green (PHP / MySQL / Apache; app responds)
- [x] Baseline auth flow captured and passing (P0-T3)
- [x] Decisions recorded (D10 remains open, tracked above)
- [x] Phase log started
- [x] Branch + baseline tag + backups in place
- [x] Visual baseline: login + register captured; dashboard covered by the HTTP smoke test (P0-T3)

### Next

Phase 1 — core skeleton: front controller, route table seeded from §2.5, layouts/nav, role guards, settings, error pages, test harness. First migration step: point `DB_NAME` at `ecowealth_sneat` and import the extended schema.
