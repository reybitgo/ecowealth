# EcoWealth install, reset and demo data — a complete beginner's guide

**How to install the EcoWealth application, reset a development database, add demo data, back everything up, and retire the tools before going live.**

This guide is for someone who has **never installed a PHP/MySQL application before**. It assumes you can open a browser and copy a command, and explains everything else. You do not need to understand the application's source code. The application lives in the `system/` folder of the repository; the tools that install and reset it live inside that folder too.

> **Status.** Everything described here is implemented and was signed off on **2026-11-12**. The work is recorded in [`dmp/plan-ecowealth-install-reset.md`](../dmp/plan-ecowealth-install-reset.md), and the acceptance evidence is in [`docs/qa/phase-16-install-reset.md`](qa/phase-16-install-reset.md).
>
> At sign-off the automated suite passed **816 / 816** cases, the HTTP smoke script passed **195 / 195** checks, and all **16 / 16** manual install/reset checks passed. This document describes how to *use* those tools; the plan describes how they were built.

> **Read this first — two warnings.**
>
> 1. **`install.sql` is for an empty database only.** It creates the schema and the starter data. It does not upgrade an existing site, and it refuses to run when the database already has tables.
> 2. **A reset permanently deletes data.** It has no undo. Before you reset anything, take a backup (Section 8) or make sure the database is disposable.

---

## Contents

0. [Start here](#start-here)
1. [What you have now](#1-what-you-have-now)
2. [Before you begin](#2-before-you-begin)
3. [Install locally (four ways)](#3-install-locally-four-ways)
4. [Install online on shared hosting](#4-install-online-on-shared-hosting)
5. [Passwords, the install lock and keys](#5-passwords-the-install-lock-and-keys)
6. [Demo data (optional)](#6-demo-data-optional)
7. [Reset a database](#7-reset-a-database)
8. [Backup, restore and recovery](#8-backup-restore-and-recovery)
9. [Troubleshooting](#9-troubleshooting)
10. [Going live: retire the tools](#10-going-live-retire-the-tools)
11. [Reference](#11-reference)

---

## Start here

Pick the row that matches your situation; each one gives the shortest safe path.

| Your situation | Read |
|---|---|
| **First local install, comfortable with a terminal** | Section 2.5 → 2.6, then **3.3** (CLI installer) |
| **First local install, prefer clicking** | Section 2.5 → 2.6, then **3.2** (phpMyAdmin) |
| **Throwaway local database, fastest possible** | Section 2.5, then **3.1** (MySQL import) |
| **Put the site on a real host** | **4.2 → 4.5**, then the checklist in 4.6 |
| **Clear a development/QA database** | **7.4 → 7.7** (backup, preview, reset), then 7.15 |
| **No terminal at all** | **3.4** for installing, **7.13** for resetting |
| **Something is broken** | **9** (troubleshooting), starting with the exact message |
| **About to make the site public** | **10** (retire the tools) |

> **If you read only one warning:** a reset has no undo. Section 7.4 shows how to take a backup before you run it.

---

## 1. What you have now

Before this work, setting up EcoWealth meant importing **six SQL files by hand** in the right order, and "resetting" a development database meant dropping the whole schema or re-importing seeds. There was no install guard, no verification, no lock, and no reset command.

Now there is:

- **one production bundle** that installs the entire starter database in one import;
- **two guided installers** (command line and browser) that also set your own staff passwords, verify the result and leave a marker so nobody can install twice;
- **one reset engine** with a command-line front end and a browser console;
- **one optional demo-data path** that is refused in production;
- **automatic verification** after both install and reset.

### 1.1 The tools at a glance

| Tool | Where it lives | What it does | Use it when |
|---|---|---|---|
| **Production bundle** | [`system/database/install.sql`](../system/database/install.sql) | One SQL file containing 27 tables, 48 settings, 2 staff accounts and the starter catalogue. Seeds no members, codes or wallet balances. | You have an **empty** database and a way to import SQL (MySQL client, phpMyAdmin, Adminer). |
| **CLI installer** | [`system/bin/db_install.php`](../system/bin/db_install.php) | Runs preflight checks, imports the bundle, sets staff passwords **you choose**, verifies the result and writes an install lock. | You have a terminal (local machine or a host with SSH). **Recommended.** |
| **Browser installer** | [`system/public/install.php`](../system/public/install.php) | The same install in a web page. Never creates the database; refuses a non-empty one. | Shared hosting with no SSH. |
| **Reset engine (CLI)** | [`system/bin/db_reset.php`](../system/bin/db_reset.php) | Previews what will be deleted, resets in one transaction, optionally generates codes and reloads the catalogue/demo data, then verifies the result. | Everyday development/QA reset. **Primary reset path.** |
| **Reset console (browser)** | [`system/public/reset.php`](../system/public/reset.php) | The same reset with a dark console UI: current counts, what will be cleared, options, typed confirmation and a report. | You have no terminal, and you are **not** on production. |
| **Bundle builder** | [`system/bin/build_install_sql.php`](../system/bin/build_install_sql.php) | Rebuilds `install.sql` from `schema.sql` and seeds 001–004, and checks that the committed bundle is current. | A developer changed the schema or the seeds. Not needed for a normal install. |
| **Demo seed** | [`system/database/seeds/005_demo_tree.sql`](../system/database/seeds/005_demo_tree.sql) | Five demo members, their sponsor tree, three wallets and three sample codes. | You want a populated QA site. **Never on production.** |

The CLI installer and the browser installer import the **same bundle**, set the **same kind of credentials**, run the **same verification**, and write the **same lock**. They differ only in how you reach them and which channel name they record (`cli` or `web`).

The CLI reset and the browser reset call the **same reset service**. The browser cannot do anything the CLI cannot; it only saves you a terminal.

### 1.2 What a fresh install creates

Every normal install path — without the optional demo data — ends at exactly this state:

| Tables | Settings | Staff | Members | Products | Packages | Levels | Contents | Codes |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| **27** | **48** | **2** | **0** | **7** | **2** | **18** | **10** | **0** |

What those numbers mean:

- **27 tables** — the complete database structure: users, shop, carts, orders, wallet, commissions, payouts, audit, live updates, and so on.
- **48 settings** — 45 seeded configuration values plus 3 rows that record the install (`install_version`, `install_channel`, `install_completed_at`). If you read "45 settings" inside the bundle's own header, that is the seed count; the installed total is 48.
- **2 staff accounts** — one `admin` and one `superadmin`. Both are rows in the `users` table.
- **0 members** — real customer/member accounts are never seeded. The first member appears only when someone registers (or when you explicitly add demo data).
- **7 products** — six catalogue products from `seeds/003_products.sql`, plus one bundled service product (`EW-COLONICS`) that belongs to Package B.
- **2 packages** — the starter packages `package-a` and `package-b`, with their 18 ladder levels and 10 package contents.
- **0 codes** — registration codes are inventory. A fresh install creates none.

### 1.3 What a reset produces

A reset returns a database to the **clean install state** while keeping your configuration and both staff identities:

| Kept | Deleted or reset |
|---|---|
| Both staff accounts: username, email, password, full name, phone, payout accounts, role, `created_at` | Every member account |
| All settings, plus `last_reset_at` and `last_reset_by` | Orders, payments, proofs, invoices, shipments |
| The catalogue (starter counts `7 / 2 / 18 / 10`), unless you ask for a catalogue reset | Carts, cart items, addresses |
| The audit log, unless you ask to clear it; one `system.db_reset` row is always appended | Wallet ledger, admin top-ups, transfers, payouts |
| Install metadata (`install_version`, `install_channel`, …) | Commissions, activations, registration codes |
| | S-Login sessions, remember-me sessions |
| | Staff package, sponsor, registration, referral, wallet and transfer counters |

A reset is **not** a fresh schema: the tables and settings stay. It is the same database with the business rows removed and the staff rows put back to their install defaults.

### 1.4 The words you need

You can read this guide without knowing databases, but these terms come up constantly.

| Word | What it means here |
|---|---|
| **Database** (or **schema**) | A container inside MySQL that holds all of the site's tables. Locally it is usually `ecowealth_sneat`; on shared hosting it has a prefix such as `u123456_ecowealth`. This guide uses "database" and "schema" interchangeably. |
| **Table** | One grid of data, like a spreadsheet. `users` holds accounts, `shop_orders` holds orders, `settings` holds configuration. |
| **Row** | One record in a table — one user, one order, one setting. |
| **Column** | One field on every row — `username`, `price`, `status`. |
| **Seed** | A SQL file that inserts starting data (settings, staff, products, packages, demo members). |
| **Migration** | A small SQL file that upgrades an **existing** database. Migrations are numbered (`001_…` to `019_…`). They are **not** used for a fresh install. |
| **Bundle** | `install.sql`: the schema plus the production seeds, assembled into one generated file. |
| **CLI** | "Command-line interface": you type commands in a terminal instead of clicking. |
| **phpMyAdmin** | A web page (usually provided by Laragon or your host) for browsing and importing MySQL databases. |
| **Staff** | An account with role `admin` or `superadmin`. Staff can sign in to the back office. |
| **Member** | A customer/associate account (`role = 'member'`). Members use the shop, wallet and genealogy pages. |
| **Catalogue** | The products, packages, package levels and package contents that the shop sells. |
| **Environment** | A label that changes safety behavior: `development` locally, `production` online, `test` for the automated suite. |
| **Install lock** | A small JSON file, `system/storage/install.lock`, written after a guided install. Its presence makes the installers refuse to run again. |
| **Advisory lock** | A named database lock that stops two installs or resets from running at the same time. |
| **S-Login** (super-login) | A superadmin opening a member's portal in an audited, temporary session. Also called impersonation; the reset console refuses these sessions. |
| **CSRF token** | A hidden, per-session value in a form that stops another website from submitting the form as you. |
| **AUTO_INCREMENT** | MySQL's counter for the next `id` it will assign. A reset resets it on tables that end up empty. |
| **DSN** | "Data source name": the host/port/database connection string. The tools never print it. |
| **JSON** | A text format for structured data. The install lock and the `--json` reports use it. |
| **Transaction** | A group of database changes that either all succeed or all roll back. The reset uses one, so a failure does not leave a half-cleared database. |
| **Foreign key** | A database rule that says "this order must belong to a real user". It is why rows must be deleted in a specific order. |
| **Verification / reconciliation** | Automatic checks that prove the result: row counts, staff state, and four money reconciliations (wallets, commissions, transfers, payouts). |

### 1.5 How the pieces fit together

Installing:

```text
   You
    │
    ├── import database/install.sql ────────────────┐
    │                                               │
    ├── php bin/db_install.php ──┐                  ├──▶  MySQL database
    │                            ├──▶ DatabaseInstaller ──▶  27 tables
    └── browser: install.php ────┘                  │       48 settings
                                                    │       2 staff
                                                    │       7 products / 2 packages
                                                    │       no members, no codes
                                                    │
                                        storage/install.lock  (guided installs only)
```

Resetting:

```text
   php bin/db_reset.php  ──┐
                           ├──▶ DatabaseResetService
   browser: reset.php  ────┘        │
                                    ├─ 1. preview (read-only)
                                    ├─ 2. ONE transaction:
                                    │     delete business rows
                                    │     reset staff rows
                                    │     keep settings + catalogue
                                    │     append audit row
                                    │     bump live revisions
                                    ├─ 3. after commit: counters, uploads, codes
                                    └─ 4. verify: zero counts, staff, catalogue,
                                          four money reconciliations
```

The important design rule for you as an operator: **all destructive SQL lives in one service.** The terminal and the browser are only front ends. That is why both show the same options, apply the same gates, and produce the same report.

### 1.6 What this does not do

| Need | This guide's tools | Correct tool |
|---|---|---|
| Create a brand-new database | The bundle installs **into** an existing empty database; the CLI can create one with `--create-database`, but the web installer never does | Your hosting panel, Laragon, or `CREATE DATABASE` |
| Upgrade an existing site after code changes | Not supported by `install.sql` | `database/migrations/NNN_*.sql` in numeric order |
| Undo a bad reset or recover lost data | A reset has no undo | Restore the backup (Section 8) |
| Back up automatically on a schedule | Not included | `mysqldump` + your own schedule (Section 8) |
| Remove the application completely | A reset keeps the schema and settings | Drop the database and delete the files |
| Clean one member's data | Reset is global | Use the admin pages, or restore a backup |
| Install demo data on production | Refused by design | Don't; demo is QA only |

### 1.7 What the plan delivered

The workstream in [`dmp/plan-ecowealth-install-reset.md`](../dmp/plan-ecowealth-install-reset.md) is organised as phases IR-0 to IR-8. All of them are complete; this table maps each one to the part of this guide that uses it.

| Plan phase | What it delivered | Read |
|---|---|---|
| **IR-0** | Baseline, backups, safety rails, frozen names and flags | Section 8 (backups) |
| **IR-1** | Shared database plumbing (`DbTarget`, `SqlScript`, the typed exception) and test-harness helpers | Not operator-facing; covered in 11.1 and 11.8 |
| **IR-2** | The generated production bundle, the bundle builder, the seed fixes and the drift/parity tests | 3.1, 3.2 and 11.8 |
| **IR-3** | The CLI installer, the install lock and the install verification | 3.3 and 5.3 |
| **IR-4** | The browser installer, `INSTALL_KEY`, the shared tool gates and the tool stylesheet | 3.4, 4.5 and 5.4 |
| **IR-5** | The reset engine, its CLI, the mandatory verification and the `CommissionService` fix | Section 7 (CLI) |
| **IR-6** | The authenticated reset console and `DB_RESET_ENABLED` | 7.13 and 7.14 |
| **IR-7** | Demo data, the demo preconditions and reset ≡ fresh-install parity | Section 6 |
| **IR-8** | Security review, documentation, go-live checklist and sign-off | Sections 9 and 10 |

---

## 2. Before you begin

### 2.1 Local or online?

"Local" means the site runs on your own computer (for example with Laragon on Windows). "Online" means a hosting account such as Hostinger, usually with no SSH terminal.

| | Local (Laragon) | Online (shared hosting) |
|---|---|---|
| Who it is for | Development and QA | The real, public site |
| Typical URL | `http://localhost/ecowealth/system/` | `https://example.com/ecowealth/system/` |
| Database creation | You, in Laragon/MySQL | You, in the hosting panel (hPanel) |
| Environment (`APP_ENV`) | `development` | `production` |
| Install paths | MySQL client, phpMyAdmin, CLI installer, browser installer | phpMyAdmin, browser installer (or CLI if the host gives SSH) |
| Reset console | Available to a signed-in admin | Branded **404** unless explicitly enabled; not a production feature |
| Reset command | `php bin/db_reset.php` | Only during an approved change window, with `--allow-production` |

Both paths are documented. If you are setting up your own machine for the first time, follow Section 3. If you are putting the site on a host, follow Section 4.

> **Not using Laragon?** The examples use `C:\laragon\www\ecowealth` and `http://localhost/ecowealth/system/` because that is the reference development setup. With XAMPP, WAMP or a plain PHP install, substitute your own project path and local URL; the commands and the database steps are identical.

### 2.2 The software you need

| Component | Minimum | Why |
|---|---|---|
| **PHP** | 8.1 or newer (the project runs on 8.3.16) | Runs the application, the installers and the reset command. |
| **PHP extensions** | `pdo_mysql`, `fileinfo`, `mbstring`, `json` | Database access, upload checks, text handling and JSON reports. |
| **MySQL** | 8.0.16+ or MariaDB 10.4+ | Stores the data. The schema uses `CHECK` constraints and `JSON` columns. |
| **Web server** | Apache 2.4 with `mod_rewrite`, or PHP's built-in server | Serves the browser pages. Not needed for the CLI tools. |

Laragon includes PHP, MySQL and Apache in one installer. On shared hosting these are provided by the host; you may need to select PHP 8.3 and enable the extensions in the panel.

### 2.3 Check your tools

Open a terminal and run:

```bash
php -v
```

> On Windows, use **Laragon → Terminal**, or **Start → Windows PowerShell**. On macOS/Linux, use the Terminal application.

You should see a first line like:

```text
PHP 8.3.16 (cli) (built: Jan 14 2025 ...)
```

Then check MySQL:

```bash
mysql --version
```

You should see something like:

```text
mysql  Ver 8.4.3 for Win64 on x86_64 (MySQL Community Server - GPL)
```

**If `php` is not recognized:** on Windows, open **Laragon → Terminal** (it puts PHP on the path), or use the full path to PHP. On macOS/Linux, install PHP or use your package manager's PHP binary.

**If `mysql` is not recognized:** the MySQL client is installed but not on your PATH. On this Laragon machine it is at:

```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" --version
```

In PowerShell, the leading `&` means "run the quoted path". You can use that full path anywhere this guide shows `mysql`.

### 2.4 Open a terminal in the right folder

All commands in this guide that start with `php bin\…` assume you are inside the **application folder**, not the repository root. Commands are shown for **PowerShell** on Windows and **bash** on macOS/Linux; the `php` command itself is the same in Command Prompt (`cmd.exe`), only the path separators and some redirection differ (noted where relevant).

```text
C:\laragon\www\ecowealth\        ← project root (contains system/, docs/, dmp/)
C:\laragon\www\ecowealth\system\ ← application folder (contains bin/, app/, public/, database/)
```

On Windows:

```powershell
cd C:\laragon\www\ecowealth\system
```

On macOS/Linux:

```bash
cd /path/to/ecowealth/system
```

Check where you are:

```powershell
Get-Location          # PowerShell
```

```bash
pwd                   # macOS/Linux
```

The last part of the path must be `system`, and `Get-ChildItem` (or `ls`) must show `bin`, `app`, `public`, `database` and `tests`.

> **Tip.** If you close the terminal, you must `cd` again. The commands do not remember the folder.

### 2.5 Create the local database and MySQL user

A database first, then a user that is allowed to use **only that database**. This is safer than using `root` for the application.

Open phpMyAdmin (in Laragon: **Database** button, or `http://localhost/phpmyadmin`), choose the **SQL** tab, paste this, and click **Go**:

```sql
CREATE DATABASE ecowealth_sneat
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'ecowealth'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
CREATE USER 'ecowealth'@'127.0.0.1' IDENTIFIED BY 'choose-a-strong-password';

GRANT ALL PRIVILEGES ON ecowealth_sneat.*
  TO 'ecowealth'@'localhost', 'ecowealth'@'127.0.0.1';

FLUSH PRIVILEGES;
```

What each part does:

- `CREATE DATABASE ...` makes the empty container. `utf8mb4` is the character set that can store the peso sign (`₱`), emoji and every language the shop may use.
- `CREATE USER` makes a MySQL login. Two host forms are created because `localhost` and `127.0.0.1` are treated as different clients.
- `GRANT ALL PRIVILEGES ON ecowealth_sneat.*` gives that user full control of **only** the `ecowealth_sneat` database. It cannot touch other databases.
- `FLUSH PRIVILEGES` makes MySQL reload its permission tables.

If you prefer the command line, run `mysql -u root` (Laragon's root account usually has no password) and paste the same statements, or execute them one line at a time.

> **Database name rules.** EcoWealth's tools accept only letters, numbers and underscores, 1–64 characters (`^[A-Za-z0-9_]{1,64}$`). A hyphen or a space in the name will be refused. On shared hosting the panel adds a prefix such as `u123456_`, which is fine.

### 2.6 Create `app/config.local.php`

The application reads its database credentials from a file that is **not committed to Git**:

```text
system/app/config.local.php
```

Create it (or edit the existing one) with this shape. Use a plain-text editor (Notepad++, VS Code, or Laragon's editor) and save it as **`config.local.php`**, not `config.local.php.txt`; the file must be UTF-8 without a byte-order mark.

```php
<?php

declare(strict_types=1);

/* Local development */
define('APP_ENV', 'development');

/* Database */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'ecowealth');
define('DB_PASS', 'choose-a-strong-password');
```

Notes for beginners:

- `APP_ENV` is `development` on your own machine. Section 4 uses `production` for the live site.
- `DB_PASS` must be exactly the password you used in the `CREATE USER` statement.
- **Do not add `DB_NAME` locally.** The application defaults to `ecowealth_sneat`; the automated test runner switches to `ecowealth_sneat_test` when `APP_ENV=test`. If you define `DB_NAME` in the local file, the test runner may be forced onto the wrong database. (On the production server, defining `DB_NAME` is normal and recommended; see Section 4.3.)
- The file is git-ignored. If you clone the project fresh, you must create it again.

To point a one-off command at a **different** database without editing the file, set the `ECO_DB_NAME` environment variable for that terminal session:

```powershell
$env:ECO_DB_NAME = 'ecowealth_sneat_qa'
php bin\db_reset.php --dry-run
Remove-Item Env:ECO_DB_NAME
```

```bash
export ECO_DB_NAME=ecowealth_sneat_qa
php bin/db_reset.php --dry-run
unset ECO_DB_NAME
```

`ECO_DB_NAME` wins over the default for that session only. The file is not changed.

### 2.7 Understand "environment"

`APP_ENV` is a safety switch, not a label:

| Value | Where it is used | What changes |
|---|---|---|
| `development` | Your local machine | Debug detail is shown; the reset console is available to a signed-in admin; the installers do **not** require `INSTALL_KEY`. |
| `production` | The live site | Friendly error pages; the reset console is a **404** unless `DB_RESET_ENABLED` is explicitly `true`; both installers require a non-empty `INSTALL_KEY`. |
| `test` | The automated suite | Selects `ecowealth_sneat_test`. Never set this by hand for normal work. |

If `config.local.php` does not set `APP_ENV`, the application defaults to `production`. That is deliberate: a missing configuration should be safe, not open.

### 2.8 Folder permissions and PHP extensions

Two folders must be writable by PHP:

| Folder | Why |
|---|---|
| `system/storage/` (and `storage/logs`, `storage/locks`) | The install lock and cron logs/locks. |
| `system/public/uploads/` (and its `products`, `packages`, `proofs` subfolders) | Product/package images and payment proofs. |

On Laragon these are usually writable already. On shared hosting, set directories to `755` (try `775` only if PHP still cannot write). Files can stay `644`.

Check the required extensions:

```bash
php -m
```

The output must contain `pdo_mysql`, `fileinfo`, `mbstring` and `json`. On shared hosting, enable them in the panel (see [`docs/deploy.md`](deploy.md) §8).

### 2.9 The backup rule

Before any destructive action, take a backup.

- For a **fresh install**, the target database must be empty, so there is no business data to lose. Still back up `config.local.php` and `public/uploads/` if you are replacing a working copy of the site.
- For a **reset**, always take a dump first. A reset deletes members, orders, ledger rows and codes; there is no undo.

Section 8 has the exact commands, including a phpMyAdmin-only path if you do not want to use a terminal.

> **The one-sentence rule.** If you would be upset to lose it, back it up before you run anything in this guide that says "delete", "reset" or "import".

---

## 3. Install locally (four ways)

You need **one** of the four paths. They all produce the same database; they differ in how much typing you do and whether you get to choose the staff passwords.

| Path | Best for | Staff passwords | Creates the database? | Writes the install lock? |
|---|---|---|---|---|
| **A — MySQL command line** | The fastest, most exact install; you already use a terminal | Published defaults (`Admin@123`, `Super@123`) | No | No |
| **B — phpMyAdmin** | You prefer clicking to typing | Published defaults | No | No |
| **C — CLI installer** | You want your own passwords and an automatic verification | **You choose** | Only with `--create-database` | Yes |
| **D — Browser installer** | You have no terminal, or you want a guided form | **You choose** | No | Yes |

> **Recommendation for a first local install:** use **Path C**. It is one command, it checks everything before writing, it lets you choose real passwords, it verifies the result, and it writes the lock so you cannot accidentally install twice. Path A is the fastest if you only need a throwaway database.

### 3.1 Path A — MySQL command line (fastest)

**Before you start:** the database must exist and be **empty** (zero tables, not zero rows). Section 2.5 creates it.

**Step 1 — open a terminal in the application folder.**

```powershell
cd C:\laragon\www\ecowealth\system
```

**Step 2 — import the bundle.**

In **Command Prompt (`cmd.exe`)**:

```bat
mysql -u root ecowealth_sneat < database\install.sql
```

In **PowerShell**, the `<` character is reserved, so wrap the command in `cmd /c`:

```powershell
cmd /c "mysql -u root ecowealth_sneat < database\install.sql"
```

In **bash** (macOS/Linux):

```bash
mysql -u root ecowealth_sneat < database/install.sql
```

What this means:

- `mysql -u root` connects as the MySQL administrator. Laragon's root account usually has no password; if yours does, add `-p` and type it when asked.
- `ecowealth_sneat` is the **target database**. The bundle contains no `CREATE DATABASE` and no `USE`, so this argument is the only thing that decides where the data goes.
- `< database\install.sql` feeds the file into the MySQL client.

**Step 3 — read the output.**

On success, the footer prints three small result tables. The first one is the important one:

```text
tables_created  settings_rows  staff_rows  members_rows  products_rows  packages_rows  levels_rows  contents_rows  codes_rows
27              48             2           0             7              2              18           10             0
```

Then a settings table:

```text
install_channel        install.sql
install_completed_at   2026-11-12 07:03:17
install_version        1.0.0
site_name              EcoWealth
```

And the two staff rows:

```text
admin       admin       active  1
superadmin  superadmin  active  1
```

If you see those numbers, the install worked.

**Step 4 — sign in.** Open `http://localhost/ecowealth/system/` and use:

| Username | Password |
|---|---|
| `admin` | `Admin@123` |
| `superadmin` | `Super@123` |

**Change both passwords immediately** (Section 3.5). These are published defaults, not secrets.

> **If the import stopped with `ERROR 1146` and a table named `ecowealth_install_requires_an_empty_database`:** the database was not empty. That is the bundle's guard working as intended. Do **not** re-run the bundle over the partial result. Drop the database, recreate it empty, and import once more (Section 8.4).

> **Path A does not create `storage/install.lock`.** That is normal: a static import has no installer to write it. The populated schema is the guard. If you later run the guided installer against that database, it will refuse because the database is not empty.

### 3.2 Path B — phpMyAdmin

This is the same import as Path A, with clicks instead of a command.

1. Open phpMyAdmin. In Laragon, click **Database** in the menu, or open `http://localhost/phpmyadmin`.
2. In the left sidebar, click the **empty** database (`ecowealth_sneat`). Do not select a table.
3. Click the **Import** tab at the top.
4. Under **File to import**, click **Choose File** (or **Browse**) and select:

   ```text
   C:\laragon\www\ecowealth\system\database\install.sql
   ```

5. **Important:** set **Stop on error** (the checkbox or the partial-import option, depending on your phpMyAdmin version). If the bundle hits the empty-database guard, you want the import to stop, not to continue and leave a half-touched schema.
6. Leave the format as **SQL**, character set as **utf-8**, and click **Go**.
7. Scroll to the bottom. You should see the same three result tables and the counts `27 / 48 / 2 / 0 / 7 / 2 / 18 / 10 / 0`.

Then sign in with the same defaults as Path A and change both passwords.

> **If phpMyAdmin says the file is too large:** the bundle is only about 64 KB, far below the usual 2 MB limit. If your host has lowered the limit, export/import it as `.sql.gz` (gzip), or import it section by section at the banner comments (`SCHEMA`, `SETTINGS`, `STAFF ACCOUNTS`, `STARTER PRODUCTS`, `PACKAGES`, `INSTALL METADATA`, `VERIFICATION`).

> **If phpMyAdmin reports the guard error and you are not sure whether it continued:** treat the database as half-imported. Drop it, recreate it empty, and import again. Never try to "repair" it by re-running the bundle.

### 3.3 Path C — guided CLI installer (recommended)

The command-line installer does more than import a file:

1. checks PHP, extensions, the config file, the MySQL connection, the database name, emptiness, the bundle, the storage folder and the absence of a lock;
2. imports the bundle into the empty database;
3. replaces both seeded passwords with ones **you choose**;
4. writes the `site_name` and install metadata;
5. verifies the whole result;
6. writes `system/storage/install.lock`.

Nothing is written until every preflight check passes. Two installs cannot run at the same time: the installer takes a MySQL advisory lock, so a second run waits briefly and then refuses instead of racing the first.

#### Step 1 — run the preflight

```powershell
php bin\db_install.php --check
```

You should see a table like this (the version numbers will match your machine):

```text
EcoWealth database installer
  database: ecowealth_sneat at 127.0.0.1:3306
  channel:  cli
  mode:     preflight only (no writes)

Preflight
  OK    PHP version          8.3.16
  OK    pdo_mysql extension  loaded
  OK    fileinfo extension   loaded
  OK    mbstring extension   loaded
  OK    json extension       loaded
  OK    config.local.php     app/config.local.php is readable
  OK    MySQL server         connected — MySQL 8.4.3
  OK    Database name        ecowealth_sneat
  OK    Database exists      ecowealth_sneat exists
  OK    Database empty       no tables — ready for the bundle
  OK    install.sql          database/install.sql — 54 statements, 63,695 bytes
  OK    Storage writable     storage is writable
  OK    Lock directory       storage is writable
  OK    Install lock         storage/install.lock is absent
  OK    INSTALL_KEY          not required outside production

Preflight passed — ecowealth_sneat can be installed.
```

What to do if a row is red:

| Failing row | What it means | Fix |
|---|---|---|
| `PHP version` | Older than 8.1 | Switch PHP in Laragon/hPanel. |
| `pdo_mysql` / `fileinfo` / `mbstring` / `json` | An extension is disabled | Enable it in `php.ini` or the hosting panel. |
| `config.local.php` | The file is missing or unreadable | Create it (Section 2.6). |
| `MySQL server` | MySQL is not running or the host/port is wrong | Start MySQL; check `DB_HOST`/`DB_PORT`. |
| `Database exists` | The name is not created yet | Create it (Section 2.5), or add `--create-database` if your MySQL user has the `CREATE` privilege. |
| `Database empty` | The schema already has tables | Use a fresh empty schema, or drop/recreate it (after a backup). |
| `install.sql` | The bundle is missing or unreadable | Restore `database/install.sql` from the repository. |
| `Storage writable` / `Lock directory` | PHP cannot write `storage/` | Fix folder permissions (Section 2.8). |
| `Install lock` | A previous guided install wrote the lock | See Section 5.3. Delete the lock only if you intend to wipe and reinstall. |
| `INSTALL_KEY` | You are in production and the key is missing/wrong | Section 5.4. |

> `--check` is read-only. It does not create the database, import anything, or write the lock. It is safe to run as often as you like.

#### Step 2 — choose the staff credentials

The installer always sets **two** accounts:

| Role | What it is for |
|---|---|
| `admin` | Day-to-day back office: products, orders, members, codes, wallets, settings. |
| `superadmin` | Everything an admin can do, plus the S-Login console and the production-only reset gate. |

The rules, enforced by both installers:

- **Username:** 3–50 letters, numbers, dots, underscores or dashes (`^[A-Za-z0-9_.-]{3,50}$`). The two usernames must differ.
- **Email:** a valid address, different for the two accounts. It is used for sign-in and notifications.
- **Password:** **12–72 characters**, and not one of the published defaults (`Admin@123`, `Super@123`, `password`, `Password123`, `admin123`). Use a password manager; do not reuse a password from another site.

#### Step 3 — run the install

**Easiest (interactive).** This asks you to type the database name and then each password:

```powershell
php bin\db_install.php --database=ecowealth_sneat --admin-email=owner@example.com --superadmin-email=owner+super@example.com
```

The session looks like:

```text
About to install a fresh EcoWealth database into "ecowealth_sneat".
Type the database name to continue, or press Enter to abort: ecowealth_sneat

The admin password is echoed by the terminal; use --admin-password-stdin to type it without echo.
Admin password: ************
The superadmin password is echoed by the terminal; use --superadmin-password-stdin to type it without echo.
Superadmin password: ************
```

Typing the database name is deliberate: pressing Enter by accident aborts, and you cannot confirm the wrong target.

**Recommended for real use (no echoed passwords, no prompt).** Pipe the two passwords into the command, admin first:

PowerShell:

```powershell
"YourAdminPassword123!`nYourSuperPassword123!" |
  php bin\db_install.php --database=ecowealth_sneat `
    --admin-email=owner@example.com `
    --superadmin-email=owner+super@example.com `
    --admin-password-stdin --superadmin-password-stdin --force
```

bash:

```bash
printf '%s\n%s\n' "$ADMIN_PW" "$SUPER_PW" |
  php bin/db_install.php --database=ecowealth_sneat \
    --admin-email=owner@example.com \
    --superadmin-email=owner+super@example.com \
    --admin-password-stdin --superadmin-password-stdin --force
```

The two `--…-password-stdin` flags read **one line each**, admin first. `--force` skips only the typed confirmation; it **never** skips the preflight. If the preflight fails, `--force` does not override it.

> **Do not pass passwords with `--admin-password=` on a shared machine.** The value appears in the shell history and process list. Use the stdin form or the interactive prompt.

#### Step 4 — read the result

A successful install prints:

```text
Installing
  OK    lock              Advisory lock acquired
  SKIP  create_database   Database already exists
  OK    guard             Target schema is empty
  OK    bundle            Import database/install.sql
  OK    staff             Set both staff accounts
  OK    settings          Write the install metadata
  OK    verify            Verify the installation
  OK    lock_file         Write the install lock

Verification
  OK    Tables                 27 of 27 tables
  OK    Settings               48 of 48 settings
  OK    Staff accounts         admin (admin), superadmin (superadmin)
  OK    Staff sign-in          2 passwords verified
  OK    Members                0 of 0 members
  OK    Catalogue              7 products / 2 packages / 18 levels / 10 contents
  OK    Registration codes     0 of 0 codes
  OK    No transactional rows  every shop, wallet, membership and session table is empty
  OK    Install metadata       version 1.0.0 · channel cli · 2026-11-12 ...
  OK    Install lock           storage/install.lock records the install into ecowealth_sneat

Installed and verified.
  lock:    storage/install.lock
  sign in: admin (admin)
  sign in: superadmin (superadmin)
  next:    change both staff passwords, then delete bin/db_install.php and database/install.sql before going live.
```

A completed run exits with code `0`. You can now sign in with the passwords you chose.

#### Step 5 — verify the lock

```powershell
Get-Content storage\install.lock
```

```bash
cat storage/install.lock
```

You should see JSON like:

```json
{
    "installed_at": "2026-11-12T07:03:17+08:00",
    "database": "ecowealth_sneat",
    "app_version": "1.0.0",
    "install_sql_sha256": "3e94fd28…",
    "staff": ["admin", "superadmin"],
    "channel": "cli"
}
```

It contains **no password and no password hash**. Its presence is what makes a second guided install refuse.

> **What if the lock cannot be written?** The installer still reports the database as installed, but marks the report as needing attention and prints the exact JSON to create manually. Do not leave a working install that silently looks uninstalled. Fix the `storage/` permission and create the file, or delete the database and retry.

#### Step 6 — re-running is refused

Run the installer again. It exits with code `3` and a message like:

```text
FAIL  Install lock   installed 2026-11-12T07:03:17+08:00 into ecowealth_sneat (cli)
      This installation is already locked. Delete storage/install.lock only if you really intend to
      wipe the database and install from scratch.
```

That is the intended behavior. See Section 5.3 before deleting the lock.

### 3.4 Path D — browser installer

Use this when you want a form instead of flags, or when you are simulating shared hosting.

**Step 1 — make sure the site is being served.**

With Laragon, the normal Apache site already serves `http://localhost/ecowealth/system/`. If you are using PHP's built-in server, run this from the `system/` folder:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

Then the installer is at `http://127.0.0.1:8080/install.php` (there is no `/system/` prefix in this mode, because `public/` is the document root).

**Step 2 — open the installer.**

```text
http://localhost/ecowealth/system/install.php
```

You will see a dark **Database tool** page with three steps: Preflight → Install → Done.

**Step 3 — read the preflight table.**

The page runs the same checks as `db_install.php --check`, plus the install-key check. Every **non-key** row must show a green **pass** pill before the form is enabled; red rows include a hint underneath. The common fixes are the same as Section 3.3. In production the `INSTALL_KEY` row is validated when you submit (it can show **pending** before that), but if `INSTALL_KEY` is not configured at all, the form stays disabled.

The form stays disabled while any non-key check fails. This is intentional: the page writes nothing while it is showing a preflight failure.

**Step 4 — fill the form.**

| Field | What to enter |
|---|---|
| **Site name** | Optional. Shown in the back office and on the shop. Leave blank to keep `EcoWealth`. |
| **Admin account** | Username, email, password, password again. The password follows the 12–72 rule. |
| **Superadmin account** | The same four fields, with a different username and email. |
| **INSTALL_KEY** | Only shown when `APP_ENV=production`. In development the field is absent. |
| **Type INSTALL to confirm** | Literally `INSTALL`. Case does not matter; the server trims and compares case-insensitively. |
| **Seed the demo tree** | Only shown outside production. Leave it off for a normal install (Section 6). |

**Step 5 — submit.**

Click **Install EcoWealth**. The form carries a per-session CSRF token and the server revalidates every field; a forged or missing token is rejected before any SQL runs. The page then imports the bundle, sets the credentials, verifies the result and writes the lock.

On success you see:

- a green **Installation complete / Verified** banner;
- a stats row: `27` tables, `48` settings, `2` staff, `0` members, `7` products, `2` packages, `18` levels, `10` contents, `0` codes (if you ticked the demo box, expect 5 members and 3 codes instead);
- a **Staff accounts** table with the usernames and emails — **never the passwords**;
- the bundle path and its SHA-256 prefix;
- the install-lock path and whether it was written.

**Step 6 — delete the installer when you are finished.**

The success page says it: delete `public/install.php` on a real site. On your local machine you may keep it for QA, but it will refuse to run again anyway because of the lock.

**What the other states mean:**

| Page state | When it appears | What to do |
|---|---|---|
| **Already installed** | `storage/install.lock` exists | Sign in. Delete the lock only if you intend to wipe and reinstall. |
| **Database not empty** | No lock, but the schema has tables | It is not a fresh database. Use migrations for an upgrade, or drop/recreate the schema. |
| **The install form is disabled** | One or more non-key preflight checks fail (or the production key is not configured) | Fix the red rows; the page has written nothing. |
| **Install completed with warnings** | The data was written but a verification check failed | Read the failing check; do not re-run the bundle on top of it. |
| **Install failed** | An exception occurred | Read the message and hint; the page never prints a DSN, a password or a raw SQL stack trace. |

> **The browser installer never creates the database and never writes `app/config.local.php`.** It imports into the schema that `DB_NAME` already points at. Shared hosting rarely grants `CREATE DATABASE`, so creating the schema in the panel first is the supported model.

### 3.5 Sign in for the first time

Open:

```text
http://localhost/ecowealth/system/
```

The application redirects guests to the sign-in page (`/login`). Use the account that matches your install path:

| Install path | Username | Password |
|---|---|---|
| Path A (MySQL) or Path B (phpMyAdmin) | `admin` | `Admin@123` |
| Path A or B | `superadmin` | `Super@123` |
| Path C (CLI) or Path D (browser) | the username you chose | the password you chose |

**Change both staff passwords immediately** if you used Path A or B:

1. Sign in as `admin`.
2. Open the account menu in the top-right corner → **Change password**.
3. Enter the current password and a new password of at least 8 characters (12+ recommended).
4. Sign out, sign in as `superadmin`, and repeat.

If sign-in fails:

- Check the **database name** in `config.local.php` (or the `ECO_DB_NAME` variable) points at the database you installed into.
- Check `DB_USER` / `DB_PASS`.
- If you used the CLI installer, re-read the last lines of its output: it prints the exact usernames it set.
- Do not edit the `password_hash` column by hand. Use **Change password** in the account menu, the installer, or a small PHP snippet that calls `password_hash()`.

### 3.6 Check the result

Run this in phpMyAdmin's **SQL** tab (select the database first) or through `mysql`:

```sql
SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE())                                AS tables_created,
  (SELECT COUNT(*) FROM settings)                                   AS settings_rows,
  (SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')) AS staff_rows,
  (SELECT COUNT(*) FROM users WHERE role = 'member')                AS members_rows,
  (SELECT COUNT(*) FROM products)                                   AS products_rows,
  (SELECT COUNT(*) FROM packages)                                   AS packages_rows,
  (SELECT COUNT(*) FROM package_levels)                             AS levels_rows,
  (SELECT COUNT(*) FROM package_products)                           AS contents_rows,
  (SELECT COUNT(*) FROM reg_codes)                                  AS codes_rows;
```

Expected:

```text
tables_created  settings_rows  staff_rows  members_rows  products_rows  packages_rows  levels_rows  contents_rows  codes_rows
27              48             2           0             7              2              18           10             0
```

Then browse the application:

- `/admin/products` → 7 products;
- `/admin/packages` → 2 packages with their ladders and contents;
- `/admin/members` → 0 members;
- `/admin/codes` → 0 codes;
- `/shop` → the catalogue renders;
- `/admin` → the health widget is green.

If a number is different, do not start adding data. Fix the install first: a wrong count now is much easier to diagnose than a wrong count after a week of orders.

### 3.7 Optional: add demo data

If you want a populated QA site (five members, a sponsor tree, wallets and codes), see Section 6. Do it **after** the clean install is verified, and never on a production site.

---

## 4. Install online on shared hosting

This section assumes a Hostinger-style panel (hPanel) and a site at `https://example.com/ecowealth/system/`. The clicks differ slightly on other hosts; the concepts are the same. The full hosting guide is [`docs/deploy.md`](deploy.md).

### 4.1 What is different online

| Difference | Consequence |
|---|---|
| **No SSH terminal** on most plans | Use phpMyAdmin (4.4) or the browser installer (4.5). The CLI installer is optional and only for hosts that give SSH. |
| **Database names are prefixed** | The panel may create `u123456_ecowealth`. Use the **full** name everywhere. |
| **`APP_ENV` must be `production`** | The reset console becomes a 404 by default; the installers require `INSTALL_KEY`; debug output is hidden. |
| **The site is public** | Delete `install.php` and `reset.php` after setup, remove `INSTALL_KEY`, and change any default password. |
| **The web server owns the files** | `storage/` and `public/uploads/` must be writable by PHP (usually `755` directories, `644` files). |

### 4.2 Create the database and user in the hosting panel

The exact labels vary, but the sequence is standard:

1. Sign in to hPanel.
2. Open **Databases → MySQL Databases** (or **MySQL**).
3. Create a **database**. The panel may ask for a short name and add a prefix. Write down the **full** name, for example `u123456_ecowealth`.
4. Create a **database user** with a strong password. Write down the full username and password.
5. **Add the user to the database** and grant **all privileges** on that one database.
6. Open **phpMyAdmin** from the panel and confirm the new database appears in the left sidebar. It must have **no tables**.

> **Shared hosts usually do not grant `CREATE DATABASE`.** That is why the web installer never offers to create one, and why the panel is the correct place to do it.

### 4.3 Upload the application and create the production config

Upload the application folder, but **not** your local development leftovers:

| Do not upload | Why |
|---|---|
| `.git/` | Repository history; never expose it. |
| `backups/` | May contain database dumps. |
| `dmp/` | Planning documents, not part of the site. |
| `system/tests/` | Developer tests. |
| `system/app/config.local.php` (your local copy) | It points at your local database; create a production one instead. |

Then create `system/app/config.local.php` **on the server**. In hPanel, use **File Manager → public_html → … → app → New File** (or upload a file with that exact name and no `.txt` suffix), then right-click → **Edit** and paste the contents:

```php
<?php

declare(strict_types=1);

/* Production environment */
define('APP_ENV', 'production');
define('APP_URL', 'https://example.com');

/* Hostinger database — use the full prefixed names from the panel */
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456_ecowealth');
define('DB_USER', 'u123456_ecowealth');
define('DB_PASS', 'the-database-password-from-the-panel');

/* Only needed for a guided installer; remove after the install */
// define('INSTALL_KEY', 'a-long-random-string-at-least-32-characters');
```

Key points:

- `APP_URL` is the **origin only** (`https://example.com`), not the `/ecowealth/system` path. The app detects the install prefix.
- `DB_HOST` is usually `localhost` on shared hosting. Use the value the panel shows.
- `DB_NAME` **does** belong here on production. The local "do not define DB_NAME" rule exists for the test runner, which you are not running on the live server.
- Do **not** set `APP_DEBUG=true`.
- The file must not be web-readable: `https://example.com/ecowealth/system/app/config.local.php` must return **403 or 404**, never the file contents. (`system/app/.htaccess` denies direct access.)

Also create the writable folders if the upload did not preserve them:

```text
system/storage/
system/storage/logs/
system/storage/locks/
system/public/uploads/
system/public/uploads/products/
system/public/uploads/packages/
system/public/uploads/proofs/
```

Directories `755`; files `644`. If PHP still cannot write, try `775` on the directories.

### 4.4 Path A — import the bundle through phpMyAdmin

1. In hPanel, open **phpMyAdmin** for the database you created.
2. Select the **empty** database in the left sidebar.
3. Click **Import**.
4. Choose `system/database/install.sql` from the uploaded files.
5. Select **Stop on error**.
6. Click **Go**.
7. Read the footer. The counts must be:

   ```text
   27 tables · 48 settings · 2 staff · 0 members · 7 products · 2 packages · 18 levels · 10 contents · 0 codes
   ```

8. **Delete `database/install.sql` from the server after the import.** It is not read at runtime, and leaving it online serves no purpose.

Sign in with `admin` / `Admin@123` and `superadmin` / `Super@123`, then change both passwords immediately. These are the same published defaults as the local import.

> **If the import is interrupted,** or phpMyAdmin continues after the guard error: drop the database in hPanel, recreate it empty, and import once more. Do not re-run the bundle on a partial schema.

### 4.5 Path B — the browser installer (no SSH)

This path lets you choose the staff passwords and never leaves a default credential in place.

**Step 1 — generate an install key.**

On any machine with PHP:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

That prints 48 random hexadecimal characters. You can also use a password manager's generator; use **at least 32 characters**.

**Step 2 — add the key to the production config.**

```php
define('INSTALL_KEY', 'the-48-character-value-you-just-generated');
```

The key is read by the installer. It is **never** written to the database, displayed back, or stored in the report. Keep it in a password manager.

**Step 3 — open the installer.**

```text
https://example.com/ecowealth/system/install.php
```

You should see a red **Production** badge and the three-step page.

**Step 4 — resolve the preflight.**

Every non-key row must pass. The row list is the same as Section 3.3, plus:

- **INSTALL_KEY** — must be non-empty in `config.local.php`. If the row says it is not configured, add the line above and reload. The key itself is checked when you submit; before that the row may say **pending**.
- **Database empty** — must show `no tables`. If it shows tables, the database is not fresh; drop/recreate it (after a backup) or use migrations for an existing site.

The form is disabled until every non-key row is green.

**Step 5 — fill the form.**

- Site name (optional).
- Admin username, email, password, password again.
- Superadmin username, email, password, password again.
- **INSTALL_KEY**: paste the exact value from `config.local.php`.
- Type **INSTALL**.
- Do **not** tick "Seed the demo tree" (it is hidden in production).

**Step 6 — submit and read the success page.**

The page lists the two accounts (without passwords), the verification counts and the lock path. It does not print the key, the DSN or any password.

**Step 7 — immediately after success:**

1. Delete `system/public/install.php` from the server.
2. Delete the `define('INSTALL_KEY', …)` line from `config.local.php` (or rotate it).
3. Confirm `https://example.com/ecowealth/system/install.php` returns **404**.
4. Sign in with your chosen admin account.

If you reopen the URL before deleting it, you see **Already installed** (the lock exists) or **Database not empty** (the schema has tables). Both refuse a second run.

> **The install-key attempt limit is 5 failures in 15 minutes.** After that, the page refuses with a generic "too many attempts" message. Wait 15 minutes, or fix the key in `config.local.php`. The counter is stored in the installer's session, so a new browser session is not the control — the key itself is.

**If your host gives you SSH (optional).** The CLI installer works on production too, with the same gate:

```bash
php bin/db_install.php --check --database=u123456_ecowealth --key="$INSTALL_KEY"

php bin/db_install.php --database=u123456_ecowealth --force \
  --key="$INSTALL_KEY" \
  --admin-user=admin --admin-email=owner@example.com --admin-password-stdin \
  --superadmin-user=superadmin --superadmin-email=owner+super@example.com \
  --superadmin-password-stdin --site-name="EcoWealth"
```

The key must match `INSTALL_KEY` from `config.local.php` (or `ECO_INSTALL_KEY`). Without `--key`, a production CLI install is refused even on an empty database. The two `--…-password-stdin` flags read one line each, admin first; Section 3.3 shows the PowerShell and bash piping forms. Delete `bin/db_install.php` from the server afterwards.

### 4.6 After any online install

- [ ] Sign in as `admin`; change any default password.
- [ ] Sign in as `superadmin`; change that password too.
- [ ] Confirm `/admin/products` shows 7 products, `/admin/packages` shows 2, `/admin/members` shows 0, `/admin/codes` shows 0.
- [ ] Confirm `APP_ENV` is `production` and `APP_DEBUG` is not `true`.
- [ ] Delete `public/install.php` and `public/reset.php`; confirm both URLs return 404.
- [ ] Remove `INSTALL_KEY` from `config.local.php`.
- [ ] Confirm `DB_RESET_ENABLED` is absent or `false`.
- [ ] Confirm no demo users (`juan`, `maria`, `peter`, `sarah`, `david`) and no `DEMO-%` codes exist.
- [ ] Take a post-install database backup (Section 8).
- [ ] Run the verification pack in Section 3.6.

### 4.7 Hosting limits and fallbacks

| Limit | What to do |
|---|---|
| `max_execution_time` below 30 seconds | The browser installer asks PHP for 120 seconds; if the host ignores it, use phpMyAdmin (4.4). |
| phpMyAdmin import limit below 64 KB | Import as `.sql.gz`, or split at the section banners. |
| No `CREATE DATABASE` privilege | Create the database in the panel first; the browser installer never creates one. |
| A WAF (web application firewall) blocks `install.php` | Use phpMyAdmin (4.4), then delete `install.php`. |
| `fileinfo` or `mbstring` missing | Enable them in the panel; the preflight names the exact extension. |
| No SSH | Use phpMyAdmin or the browser installer. The CLI installer is optional. |

---

## 5. Passwords, the install lock and keys

Three separate things control access and re-installation. Beginners often confuse them, so keep them apart:

| Thing | What it protects | Where it lives |
|---|---|---|
| **Staff passwords** | Signing in to the application | Hashed in the `users` table |
| **Install lock** | Preventing a second guided install into the same application folder | `system/storage/install.lock` |
| **`INSTALL_KEY`** | Preventing a stranger from running the installer first on a production host | `system/app/config.local.php` (or `ECO_INSTALL_KEY`) |

### 5.1 Which password comes from which install path

| Install path | Admin password | Superadmin password | Can you choose them? |
|---|---|---|---|
| Path A — MySQL import | `Admin@123` | `Super@123` | No; change them after signing in |
| Path B — phpMyAdmin | `Admin@123` | `Super@123` | No; change them after signing in |
| Path C — CLI installer | what you typed or piped | what you typed or piped | Yes |
| Path D — browser installer | what you typed in the form | what you typed in the form | Yes |

The guided installers **never** accept a published default as a password. That is why Path C and Path D always ask for a new one.

### 5.2 Changing a staff password

1. Sign in at `http://localhost/ecowealth/system/` (or the production URL).
2. Open the account menu in the top-right corner → **Change password**.
3. Enter the current password and a new password. The profile form requires **at least 8 characters** (and rejects a password you have already used); using **12 or more** is recommended and matches the installer's rule.
4. Sign out and sign back in to confirm.

Do this for **both** accounts after a static `install.sql` import. A site that still uses `Admin@123` is compromised, even if it is "only" a staging site.

> **Do not edit the `password_hash` column by hand.** Passwords are hashed with PHP's `password_hash()`; pasting a plain password into that column will not work. Use **Change password**, the installer, or a small PHP snippet that calls `password_hash()`.

### 5.3 The install lock

After a **guided** install (CLI or browser), the installer writes:

```text
system/storage/install.lock
```

It is a small JSON file:

```json
{
    "installed_at": "2026-11-12T07:03:17+08:00",
    "database": "ecowealth_sneat",
    "app_version": "1.0.0",
    "install_sql_sha256": "3e94fd28554294a051e43d88d8837c95a5d8aafb210121ae3caaf949764897bb",
    "staff": ["admin", "superadmin"],
    "channel": "cli"
}
```

What it means:

- **It is not a secret.** It contains no password, no password hash, no install key and no DSN. It records what was installed and when.
- **It is per application folder, not per database.** If you point `config.local.php` at a different database, the old lock still blocks the installer. That is deliberate: one application tree has one installed database.
- **It is written only by the guided installers.** A static `install.sql` import through MySQL or phpMyAdmin does **not** create one.
- **It is not read by the application.** It does not affect sign-in, the shop, cron jobs or the reset tool.
- **It lives outside `public/`**, under `storage/.htaccess` (deny-all). It should not be reachable over the web.

What it changes:

- `db_install.php` refuses with exit code `3` and a message naming the lock.
- `install.php` shows the **Already installed** page and no form.
- `db_reset.php` ignores it completely. A reset is not an install.

> **What if the lock exists but the database was deleted?** The installer still refuses, because the lock says "this tree was installed". Delete the lock only after you have decided to wipe and reinstall (procedure below). If the database is non-empty, the installer also refuses independently.

#### Re-installing on purpose

The supported way to start over is:

1. **Back up** anything you might need (Section 8).
2. **Drop and recreate** the database, so it is empty:

   ```sql
   DROP DATABASE ecowealth_sneat;
   CREATE DATABASE ecowealth_sneat
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Delete the lock:**

   ```powershell
   Remove-Item storage\install.lock
   ```

   ```bash
   rm storage/install.lock
   ```

4. Run the installer again (Path C or Path D).

Deleting the lock alone is not enough: the database must also be empty. Doing this on a live site is a change window, not a quick fix.

### 5.4 `INSTALL_KEY`

`INSTALL_KEY` is a shared secret that gates the **guided installers** in production. It is not a database password, and the application never reads it.

Why it exists: on shared hosting, the installer URL is public. Without a key, the first visitor to reach an empty site could create the staff accounts and own the site. The key makes that impossible unless the visitor also knows the secret.

How it works:

| Environment | Behavior |
|---|---|
| `development` | Not required. The browser form does not even show the field. |
| `production` | Required by both `db_install.php` and `install.php`. An empty configured key always refuses; an empty submitted key never matches. |

Set it in `config.local.php`:

```php
define('INSTALL_KEY', 'at-least-32-random-characters');
```

Or through the environment:

```bash
# bash
export ECO_INSTALL_KEY='at-least-32-random-characters'
```

```powershell
# PowerShell
$env:ECO_INSTALL_KEY = 'at-least-32-random-characters'
```

For the CLI, the submitted value comes from `--key=…` (or the same `ECO_INSTALL_KEY`). For the browser, it is the **INSTALL_KEY** field on the form.

Rules:

- Use **at least 32 random characters**. The tools only require a non-empty key, but a short key is guessable. Generate one with:

  ```bash
  php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
  ```

- The key is compared with `hash_equals()` (constant time).
- The key is **never** printed, stored in the database, written to `config.local.php` by the tool, or included in a report.
- The browser installer allows **5 failed attempts in 15 minutes**, then refuses with a generic message. Wait 15 minutes, or fix the key.
- **Remove the key after the install** (or rotate it). It has no purpose after the tools are deleted.

> **If you see "INSTALL_KEY is not configured":** add the line to `app/config.local.php` and reload. The preflight row is red because production cannot run a keyless installer.

### 5.5 `DB_RESET_ENABLED`

This constant controls whether the **browser reset console** is reachable in production. Its default is `false`, and it should stay that way.

| Environment | `DB_RESET_ENABLED` | What happens at `/system/reset.php` |
|---|---|---|
| `development` | anything | The console is available to a signed-in `admin` or `superadmin`. |
| `production` | absent or `false` | Branded **404** for every role. |
| `production` | `true` | Requires a signed-in **superadmin**, the exact **database name** as the typed phrase, and the backup acknowledgement. |

`false` does **not** disable the CLI reset. The CLI has its own production gate: `APP_ENV=production` requires `--allow-production`, plus the typed database-name confirmation.

The constant does not affect installations, sign-in, the shop or cron jobs.

### 5.6 Do not edit `database/install.sql`

`install.sql` is a **generated** file. Its first lines say so. It is assembled from:

```text
database/install/header.sql
database/schema.sql
database/seeds/001_settings.sql
database/seeds/002_accounts.sql
database/seeds/003_products.sql
database/seeds/004_packages.sql
database/install/footer.sql
```

If you edit the bundle directly, the next rebuild overwrites your change, and the automated drift test fails. The correct workflow for a developer is:

```bash
php bin/build_install_sql.php          # rebuild database/install.sql
php bin/build_install_sql.php --check  # verify the committed bundle is current
```

Edit the **sources**, rebuild, and commit both the source and the regenerated bundle. Section 11.8 has the developer notes.

---

## 6. Demo data (optional)

Demo data turns a clean install into a small, populated QA site. It is useful for screenshots, training and testing the member pages. It is **never** part of the production bundle and **never** allowed in production.

### 6.1 What demo data creates

| Item | Detail |
|---|---|
| **Members** | Five accounts: `juan`, `maria`, `peter`, `sarah`, `david` |
| **Sponsor tree** | `admin → juan → maria → peter / sarah / david` |
| **Passwords** | Every demo member signs in with `Demo@1234` |
| **Wallets** | Total / withdrawable: `juan` 2000 / 1500, `maria` 1000 / 800, `peter` 500 / 400 |
| **Codes** | Three `DEMO-*` registration codes |
| **Ledger** | Three adjustment rows that justify the wallet balances |
| **Status** | `david` is `pending`; the others are active associates |

These are development credentials. They are documented on purpose so a tester can sign in; that is exactly why they must never reach a live site.

### 6.2 Ways to add demo data

| Path | Command or action | Result |
|---|---|---|
| **CLI install** | Add `--with-demo` to `php bin/db_install.php …` | The bundle plus the demo tree in one run |
| **Browser install** | Tick **Seed the demo tree** on `/system/install.php` | The same, with your chosen staff passwords |
| **CLI reset** | `php bin/db_reset.php --force --confirm=<db> --demo` | The reset, then a fresh demo tree |
| **Browser reset** | Tick **Seed the demo tree** on `/system/reset.php` (development only) | The same, from the console |

The demo seed can also be imported by hand, but only into a database that already has the bundle and is not in production:

```bash
mysql -u root ecowealth_sneat < database/seeds/005_demo_tree.sql
```

```powershell
# PowerShell: '<' is reserved, so use cmd /c
cmd /c "mysql -u root ecowealth_sneat < database\seeds\005_demo_tree.sql"
```

### 6.3 When demo data is refused

The tools check before writing a row:

- `APP_ENV` is `production` → refused.
- The two default packages (`package-a`, `package-b`) are missing → refused. Demo members need a package to belong to.
- The database already contains member or business rows, and this is an **install** (not a reset) → refused rather than merged.
- On a reset with `--reset-catalogue`, the catalogue is re-seeded first, so the default packages are expected to exist; a heavily customized seed set that omits them is reported instead of writing inconsistent demo members.

A refusal does **not** delete or change anything.

The demo work also carries the **parity guarantee**: a database reset with the same options ends in the same state as a fresh install, compared by natural keys (`username`, `slug`, `sku`, `level`) rather than row ids. That is why the same demo data can be reached by installing with `--with-demo` or by resetting with `--demo`.

### 6.4 Verify the demo data

```sql
SELECT username, role, member_status, package_id, sponsor_id
  FROM users
 WHERE role = 'member'
 ORDER BY id;

SELECT code, status FROM reg_codes WHERE code LIKE 'DEMO-%';

SELECT user_id, type, amount FROM ewallet_ledger
 WHERE user_id IN (SELECT id FROM users WHERE role = 'member')
 ORDER BY id;
```

Expected: five members, three `DEMO-*` codes, three adjustment ledger rows. Sign in as `juan` / `Demo@1234` and open `/genealogy` and `/earnings` to see the tree and wallet.

### 6.5 Remove demo data

The clean way is a reset **without** `--demo`:

```bash
php bin/db_reset.php --force --confirm=ecowealth_sneat
```

That deletes every member and every business row, including the demo members, wallets and codes. The staff accounts and settings survive.

If you are retiring a production site that accidentally received demo data, see the go-live checklist (Section 10) and the "Remove the demo data" part of [`docs/deploy.md`](deploy.md) §12. Do not delete rows by hand: the foreign keys make the order easy to get wrong.

> **Demo data is additive only through the explicit option.** There is no hidden "demo mode" and no path that adds demo rows silently.

---

## 7. Reset a database

A reset returns a **development or QA** database to the clean install state while keeping the configuration and both staff identities. It is the supported way to clear test members, orders, wallets and codes between QA rounds.

### 7.1 What reset is — and is not

| It is | It is not |
|---|---|
| A way to clear business data and keep the schema, settings and staff | An uninstall: the tables and settings stay |
| A safe, verified operation with a preview, a transaction and a report | A backup: it deletes data permanently |
| A way to return to the same state a fresh install produces | A migration tool: it never upgrades the schema |
| Usable from the CLI and the browser console | A production feature: the browser console is off by default, and the CLI needs an explicit flag |

A reset is **global**. It does not clean one member or one order; it removes every member, order, ledger row, transfer, payout, code and session.

### 7.2 What gets deleted, kept and reset

**Deleted completely:**

- every **member** account (`users` where `role = 'member'`);
- every **session**: remember-me tokens (staff included) and S-Login/impersonation sessions;
- every **shop** row: orders, order items, order events, payment proofs, shipments, invoices and invoice items;
- every **cart** and cart item;
- every **address**;
- every **e-wallet** row: ledger, transfers, admin top-ups;
- every **payout request**;
- every **commission** and **activation**;
- every **registration code**.

**Reset (the row survives, the state does not):**

- both staff accounts: package, sponsor, registration fields, referral code, membership status, wallet buckets, transfer counters and last login are put back to install defaults;
- `last_reset_at` / `last_reset_by` are written;
- AUTO_INCREMENT counters are reset on the emptied business tables (never `users`, because the staff rows survive);
- live-revision counters are bumped so open pages refresh.

**Kept:**

- both staff **identities**: id, username, email, password hash, full name, phone, payout accounts, `created_at`, role;
- every **setting**, including the install metadata;
- the **catalogue** (products, packages, levels, contents), by default;
- the **audit log**, by default, plus one new `system.db_reset` row;
- `public/uploads/products` and `public/uploads/packages` (the proof images are deleted by default, see 7.11).

> **Staff passwords do not change.** After a reset you sign in with the same admin/superadmin password you used before. That is deliberate: a reset must not lock you out.

### 7.3 What happens step by step

1. **Take a MySQL advisory lock** (a named lock that stops two installs/resets running at once). If another holds it, the command aborts before changing anything.
2. **Preflight.** Check that the target has the 27 required tables, at least one staff account, a safe database name, and a valid connection to the database named on the command line.
3. **Snapshot the counts.** Used for the before/after report.
4. **Begin one transaction.** Every delete, the staff reset, the optional catalogue work, the audit row, the settings writes and the live-revision bump live inside it.
5. **Delete children before parents.** For example, order items go before orders; orders go before members; commissions go before activations. Foreign-key checks stay **on**, so a wrong order would fail loudly rather than create orphan rows.
6. **Delete members, reset staff.** The two staff rows survive with their identity and password.
7. **Optional catalogue reset.** If requested: delete products/packages/levels/contents, then re-run the real seed files `003_products.sql` and `004_packages.sql`.
8. **Optional audit clear.** If requested: delete the audit log, then write the reset row.
9. **Write the audit row, settings and live revisions.**
10. **Commit.** If any step inside the transaction throws, the whole transaction rolls back and the report says **nothing was deleted**.
11. **After commit:** reset the AUTO_INCREMENT counters of empty tables (best effort, logged); delete proof upload files (and product/package images if the catalogue was reset) unless asked to keep them; optionally install the demo tree; optionally generate registration codes.
12. **Verify.** Zero counts, staff state, catalogue counts, code counts, audit row, live revisions, AUTO_INCREMENTs, and the four finance reconciliations.
13. **Report.** Step log, before/after counts, warnings and an exit code.

The reason for the transaction boundary matters to a beginner: if step 5 fails halfway, you do **not** end up with half the orders deleted. The database is put back the way it was, and the command tells you nothing changed.

### 7.4 Back up first

A reset has no undo. Take a dump before every run on a database you care about.

> **Where to run this.** These backup commands run from the **project root** (`C:\laragon\www\ecowealth`), not from `system/`. That is where the `backups/` folder lives.

**PowerShell / Windows (recommended form):**

```powershell
New-Item -ItemType Directory -Force backups | Out-Null
mysqldump -u root --single-transaction --routines --triggers --default-character-set=utf8mb4 `
  --result-file=backups\before-reset-$(Get-Date -Format yyyyMMdd-HHmm).sql `
  ecowealth_sneat
```

If `mysqldump` is not on your PATH, use the full path:

```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe" `
  -u root --single-transaction --routines --triggers --default-character-set=utf8mb4 `
  --result-file=backups\before-reset.sql ecowealth_sneat
```

**bash:**

```bash
mkdir -p backups
mysqldump -u root --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 ecowealth_sneat \
  > "backups/before-reset-$(date +%Y%m%d-%H%M).sql"
```

Why the flags:

- `--single-transaction` takes a consistent snapshot without locking the shop.
- `--routines --triggers` includes stored programs and triggers.
- `--default-character-set=utf8mb4` preserves the peso sign and non-ASCII names.
- `--result-file` avoids PowerShell's text redirection, which can change the file encoding.

**No terminal? phpMyAdmin works:**

1. Select the database.
2. Click **Export**.
3. Choose **Quick** or **Custom** with **SQL** format.
4. Click **Go** and save the `.sql` file somewhere outside the web root.

Check that the file is not empty and starts with `-- MySQL dump` (open it in a text editor). A 0-byte backup is not a backup.

### 7.5 Preview safely with `--dry-run`

Before the real run, look:

```powershell
php bin\db_reset.php --dry-run
```

The target comes from `DB_NAME` in `app/config.php` (or the `ECO_DB_NAME` environment variable for that terminal session). The preview prints it; check it before you continue.

Example output (your counts will differ):

```text
EcoWealth database reset
  target : ecowealth_sneat_qa at 127.0.0.1:3306 (8.4.3)
  mode   : dry run — no writes
  rows   : 24 members · 2 staff · 16 orders · 48 ledger · 19 codes
  catalogue: 7 products / 2 packages (kept)
  options: audit kept (1814 rows) · codes 0 · uploads proofs deleted · demo no
  last reset: 2026-11-10 09:12:44 by admin
  packages: #1 Package A (active) · #2 Package B (active)

Plan (46 statement(s), 0 writes)
  lock                  Acquire the advisory lock                            1
  preflight             Preflight check (read-only)                          0
  ...
  auto_increment        Reset the AUTO_INCREMENT counters (after commit)     19
  ...
  verify                Verify the reset (read-only)                         0

Dry run complete — nothing was written.
```

Read these lines first:

- **target** — is this the database you think it is? Check the host and name.
- **rows** — members, staff, orders, ledger, codes. A surprising count is a reason to stop.
- **catalogue** — products and packages, and whether they will be kept.
- **options** — audit, codes, uploads and demo flags.
- **last reset** — when and by whom the database was last reset.

`--dry-run` writes **nothing**: no transaction, no deletes, no audit row, no counters, and it needs no typed confirmation. It is safe on a database you care about, and it is the best way to see what a reset would do.

### 7.6 Run an interactive reset

```powershell
php bin\db_reset.php
```

The command:

1. prints the preview;
2. asks you to type the database name;
3. runs the reset;
4. prints the step log and the verification checklist.

The prompt looks like:

```text
Type "ecowealth_sneat" to continue, or Ctrl+C to abort:
```

Type the **exact** name. Anything else (or `Ctrl+C`) aborts with exit code `3` and nothing is deleted. There is no `y/n` prompt: the name makes an accidental Enter harmless.

A successful run ends like this:

```text
Reset
   OK   lock              Advisory lock acquired
   OK   preflight         Preflight check
   OK   orders            Shop orders, payments, invoices and fulfilment     16 deleted
   OK   carts             Carts and cart lines                                 4 deleted
   OK   addresses         Address book                                         9 deleted
   OK   wallet            Wallet ledger, transfers and admin top-ups          48 deleted
   OK   payouts           Payout requests                                      2 deleted
   OK   commissions       Commissions and activations                         12 deleted
   OK   codes             Registration codes                                  19 deleted
   OK   sessions          Remember-me sessions (all users)                      6 deleted
   OK   members           Member accounts                                     24 deleted
   OK   staff             Reset the staff accounts                              2 rows
   SKIP catalogue         Keep the existing catalogue                           0 rows
   SKIP audit_clear       Keep the audit log                                    0 rows
   OK   live              Bump live revisions                                  18 rows
   OK   settings          Write the reset metadata                              2 rows
   OK   audit             Append the reset audit row                            1 rows
   OK   auto_increment    Reset AUTO_INCREMENT counters                        19 rows
   OK   uploads           Delete orphaned upload files                          3 rows
   SKIP demo              Install the demo tree                                 0 rows
   OK   codes_generate    Generate registration codes                            0 rows
   OK   verify            Verify the reset                                     12 rows

Verification
   OK   Member accounts            0 member row(s) (expected 0)
   OK   Business rows              0 rows across 19 tables (all zero)
   OK   Staff accounts             2 staff, balances zero, package/sponsor null, active
   OK   Catalogue                  7 products, 2 packages, 18 levels, 10 contents (kept: unchanged)
   OK   Registration codes         0 code row(s) (expected 0)
   OK   Audit trail                1 system.db_reset row(s) (expected 1)
   OK   Wallet reconciliation      WalletService::reconcile clean
   OK   Commission reconciliation  CommissionService::reconcile clean
   OK   Transfer reconciliation    TransferService::reconcile clean
   OK   Payout reconciliation      PayoutService::reconcile clean
   OK   Live revisions             18 of 18 standard surfaces
   OK   AUTO_INCREMENT counters    19 counter(s) at 1

  deleted: 140 rows · members 24 → 0 · orders 16 → 0 · ledger 48 → 0 · codes 19 → 0
  catalogue: 7 products / 2 packages · audit 1815 rows · live 18 surfaces · counters reset 19

Reset complete and verified.
```

(That is a representative transcript; the exact row counts and timings depend on the database.)

The final line means the reset committed and every verification check passed (exit code `0`).

### 7.7 Run non-interactively (scripts and CI)

```powershell
php bin\db_reset.php --force --confirm=ecowealth_sneat
```

Rules:

- `--force` makes the run non-interactive. It **does not** skip the confirmation: you must also provide `--confirm=<database>`.
- `--confirm` must equal the database name. A wrong value aborts with exit code `3`.
- A `--force` run without `--confirm` is a usage error (exit code `2`); it will never silently reset.
- In a non-interactive environment with neither `--force` nor a terminal, the command refuses rather than waiting forever.

For a machine-readable run:

```powershell
php bin\db_reset.php --force --confirm=ecowealth_sneat --codes=5 --json
```

`--json` prints **exactly one** JSON document on STDOUT and sends progress text to STDERR (or suppresses it). This is the contract for CI and automation. The top-level keys are listed in Section 11.5.

### 7.8 Options explained

| CLI option | Browser control | Default | Effect |
|---|---|---|---|
| `--keep-catalogue` | **Keep the existing catalogue** checked | **on** | Products, packages, levels and contents survive. |
| `--reset-catalogue` | uncheck that box | off | Delete the catalogue, then re-seed the real `003_products.sql` + `004_packages.sql`. |
| `--codes=N` | **Generate registration codes** number | `0` | Insert `N` unused registration codes for the chosen package. `N` must be 0–50. |
| `--code-package=ID` | **Code package** select | first active package | The package the generated codes belong to. The price is copied from the package. |
| `--demo` | **Seed the demo tree** (not in production) | off | Install the five demo members, wallets and codes after the reset. |
| `--keep-audit` | **Keep the audit log** checked | **on** | Existing audit rows survive; one `system.db_reset` row is appended. |
| `--clear-audit` | uncheck that box | off | Delete all existing audit rows **before** appending the reset row. |
| `--keep-uploads` | **Keep uploaded files** checked | off | Leave proof image files on disk. The database rows are still deleted. |
| `--allow-production` | `DB_RESET_ENABLED=true` (web) | off | Permit a reset when `APP_ENV=production`. Still requires the database-name confirmation. |
| `--confirm=NAME` | typed `RESET` (dev) / database name (prod) | required | The destructive confirmation. |
| `--dry-run` | the page preview is already read-only | off | Preview + statement plan; zero writes. |
| `--json` | — | off | One JSON report on STDOUT. |
| `--force` | — | off | Non-interactive; requires `--confirm`. |
| `--help` | — | — | Print the full flag list. |

**The safe defaults:** keep the catalogue, keep the audit log, generate no codes, delete proof images, no demo data. If you change nothing, a reset does the least surprising thing.

### 7.9 Registration codes are inventory

Codes are sellable items. A reset should not create them silently, so the default is `--codes=0`.

When you do ask:

- The quantity must be **0–50**. `-1` or `51` is a validation error **before** anything is deleted.
- A `--code-package` / form value that does not exist is also a validation error **before** anything is deleted.
- The package is `--code-package=ID`, or the first active package if you omit it.
- Each code is 12 characters in three groups, from an unambiguous alphabet (no `O/0`, `I/1` confusion), and the service retries on a collision.
- `status = 'unused'`, `code_type = 'registration'`, `price` copied from the package, `created_by` = the primary admin.
- One `code.generate` audit row records the batch, price, count and first/last code.
- The generated codes are printed in the CLI report and shown as click-to-copy chips in the browser report.

> **Codes are deleted by the next reset.** If you generate five codes and then reset again, those five codes are removed with every other code row. The second reset reports `codes: 5 deleted, 0 generated` unless you pass `--codes` again. That is expected: codes are business rows, not configuration.

If no active package exists, the reset still succeeds, prints a warning, and generates no codes.

### 7.10 Audit log and reset metadata

By default:

- existing audit rows are **kept**;
- one `system.db_reset` row is appended, with `actor_id`/`actor_role` (the signed-in staff member on web, `NULL`/`system` on CLI), the tool, database, options, before/after counts, flags and duration, and the IP (`127.0.0.1` on CLI).

With `--clear-audit` / **uncheck "Keep the audit log"**:

- every prior audit row is deleted **inside the same transaction**;
- the reset row is then written, so the trail starts with the reset itself.

The reset also writes two settings:

```text
last_reset_at   the application-timezone timestamp (Asia/Manila by default)
last_reset_by   the staff username on web, or "cli"
```

The settings table therefore grows from **48** rows to **50** on the first reset (the two `last_reset_*` keys are new); later resets update the same two rows. It does **not** change `install_version`, `install_channel` or `install_completed_at`: the application is still the same installation.

### 7.11 Uploaded files

| Folder | Default behavior | With `--keep-uploads` |
|---|---|---|
| `public/uploads/proofs/` | Delete every file except `.gitkeep` and `.htaccess` | Leave the files |
| `public/uploads/products/` | Keep, unless the catalogue is reset | Keep |
| `public/uploads/packages/` | Keep, unless the catalogue is reset | Keep |
| `public/assets/` | Never touched (the seeded catalogue images live here) | Never touched |
| `storage/logs/`, `storage/locks/` | Never touched (operational files) | Never touched |

The reset deletes **files**, not directories. A file that cannot be removed (permissions) becomes a warning, not a failed reset; the report lists it.

> **This is a real file deletion, not just a database row deletion.** If you want to keep the proof images for evidence or a manual review, tick **Keep uploaded files** or pass `--keep-uploads`. The rows are deleted either way; the files simply become orphans.

### 7.12 Read the report and the verification

The reset is not finished when the deletes end. It must **prove** the result. The browser console and the CLI both show the same 12 checks:

| Check | What it proves |
|---|---|
| **Member accounts** | `users` has 0 members (5 when `--demo` was used). |
| **Business rows** | Every transactional table is empty (19 tables in the default path). |
| **Staff accounts** | Both staff rows survive with `package_id` and `sponsor_id` NULL, both balances `0.00`, `member_status='active'`, `is_active=1`. |
| **Catalogue** | Kept: counts match the pre-reset snapshot. Reset: the starter counts are back (7 products, 2 packages, 18 levels, 10 contents). |
| **Registration codes** | Exactly the number generated (plus the 3 demo codes with `--demo`), all `unused` and package-bound. |
| **Audit trail** | Exactly one new `system.db_reset` row; when kept, the total did not shrink. |
| **Wallet reconciliation** | Every wallet balance equals its ledger; no orphan ledger rows. |
| **Commission reconciliation** | Every commission award has its ledger movement. |
| **Transfer reconciliation** | Every transfer has its three ledger legs. |
| **Payout reconciliation** | Every payout request has its hold, and every rejection its refund. |
| **Live revisions** | All 18 standard surfaces exist; existing revisions were incremented. |
| **AUTO_INCREMENT counters** | The emptied tables report next id `1` (or `NULL`, which MySQL treats as empty). |

Verification is **mandatory**. There is no `--no-verify` flag, because a reset tool that cannot prove its result must not report success.

Exit codes:

| Code | Meaning |
|---:|---|
| `0` | Reset completed and every verification check passed. |
| `1` | The reset **committed**, but at least one verification check failed. Read the failing check; the data is already reset. |
| `2` | Usage or setup error — a bad flag, a bad code quantity, an invalid database name, or a preview that cannot run. Nothing was deleted. |
| `3` | Aborted or refused — wrong confirmation, no staff row, missing required tables, production without `--allow-production`, another reset holding the lock, or no terminal for a confirmation. Nothing was deleted. |

> **If the transaction fails,** the report says **nothing was deleted**. Fix the cause and run it again.
>
> **If the reset commits but verification fails,** the data *is* reset. Read the failing check, run `php bin/wallet_reconcile.php`, and restore the pre-reset backup if real money is involved. **Never patch wallet balances by hand.** The services are the only writers; a manual `UPDATE` corrupts the ledger relationship the reconciliation exists to protect.

### 7.13 Reset from the browser console

No terminal? The same engine runs behind a standalone console at:

```text
http://localhost/ecowealth/system/reset.php
```

With PHP's built-in server (`php -S … -t public`), the URL is `http://127.0.0.1:8080/reset.php` — the `/system/` prefix exists only when Apache serves the parent folder.

It is a **standalone file**, not a route: it does not appear in the navigation, and no application page links to it.

**Who can open it:**

| Environment | Who | Result |
|---|---|---|
| `development` | Guest (not signed in) | Redirect to the sign-in page |
| `development` | Signed-in **member** | `403 Forbidden` |
| `development` | Impersonated/S-Login session | `403 Forbidden` |
| `development` | Signed-in **admin** or **superadmin** | The console |
| `production`, `DB_RESET_ENABLED` false/absent | Anyone | Branded **404** |
| `production`, `DB_RESET_ENABLED=true` | Signed-in **admin** | `403 Forbidden` |
| `production`, `DB_RESET_ENABLED=true` | Signed-in **superadmin** | The console, with the database name as the phrase |

**Step by step (development):**

1. Sign in at `http://localhost/ecowealth/system/login` as `admin` (or `superadmin`).
2. Open `http://localhost/ecowealth/system/reset.php`.
3. Review **Current state**: members, staff kept, orders, ledger rows, codes and products. This is read-only; opening the page writes nothing.
4. Read **What will be cleared** (red dots) and **what is kept** (green dots). If a line surprises you, stop.
5. Choose the options:
   - **Keep the existing catalogue** — leave checked for a normal QA reset.
   - **Generate registration codes** — 0 by default; see 7.9.
   - **Code package** — the package for any generated codes.
   - **Keep the audit log** — leave checked unless you want a pristine trail.
   - **Keep uploaded files** — leave unchecked to delete proof images; check it to keep the files.
   - **Seed the demo tree** — only in development; leave off unless you want demo members.
6. Type **RESET** in the confirmation box.
7. Tick **I have a backup, or this database is disposable**.
8. Click **Reset database**. The button stays disabled until both the phrase and the acknowledgement are present; the server re-checks both and the engine checks again.
9. Read the report:
   - green banner: clean install state, every check passed;
   - step log with rows deleted and timings;
   - verification checklist;
   - before/after counts;
   - fresh codes as click-to-copy chips (if any);
   - links to sign in, reset again, or **Done**.

**Refresh is safe.** The POST stores the report in `$_SESSION['db_reset_report']` and redirects to `reset.php?done=1` (the Post/Redirect/Get pattern). Refreshing re-renders the same report; it never runs the reset again. **Done** clears the report from the session.

**Why there is a CSRF token.** The page includes a hidden, per-session token. A form on another website cannot submit a valid reset request. The server rejects a missing or wrong token before any SQL runs.

**Validation errors write nothing.** A wrong phrase, missing acknowledgement, bad code quantity or a non-existent package re-renders the form with an error and does not execute the reset.

### 7.14 Resetting a production database

**The short answer: don't.** The reset tool is a development/QA tool. Production data should be handled with a backup, a change window and a deliberate recovery plan — not a one-click wipe.

If an approved situation truly requires it, the gates are additive:

| Gate | CLI | Browser |
|---|---|---|
| Environment | `APP_ENV=production` | `APP_ENV=production` |
| Explicit opt-in | `--allow-production` | `DB_RESET_ENABLED=true` |
| Actor | whoever can run the command | signed-in **superadmin** only |
| Confirmation | `--confirm=<database>` (exact name) | type the **database name** exactly |
| Backup acknowledgement | — | required checkbox |
| Concurrency | advisory lock | advisory lock |

CLI, during an approved change window:

```bash
php bin/db_reset.php --allow-production --confirm=u123456_ecowealth
```

Browser:

1. Set `define('DB_RESET_ENABLED', true);` temporarily.
2. Sign in as **superadmin**.
3. Open `/system/reset.php`; the header shows a red **PRODUCTION** band.
4. Type the **database name** (not `RESET`) and tick the backup acknowledgement.
5. Run it, read the report, then **immediately**:
   - delete `public/reset.php`;
   - set `DB_RESET_ENABLED` back to `false` (or remove the line);
   - confirm the URL returns 404.

> **The production gates do not make the reset safe; they only make it hard to trigger by accident.** The data is still gone afterwards. Take a verified backup first, and prefer restoring a known-good dump over resetting a live database.

### 7.15 After a reset

- [ ] The command/console reported **verified** (exit `0`).
- [ ] `php bin/wallet_reconcile.php` exits `0`.
- [ ] Sign in as `admin`; the password is unchanged.
- [ ] `/admin/members` shows 0; `/admin/orders` shows 0.
- [ ] `/admin/products` shows the catalogue you kept; `/admin/packages` shows the packages.
- [ ] `/admin/codes` shows the generated codes (or 0).
- [ ] `/admin` health widget is green.
- [ ] If you generated codes, record them now (they are also under `/admin/codes`).
- [ ] If demo data was requested, `juan` / `Demo@1234` works and the tree renders.

### 7.16 Running a reset twice

A reset is designed to be idempotent (running it a second time on an already-clean database reaches the same end state): the second run succeeds and deletes **0 rows** in every business step. The staff rows are reset again, the audit log grows by one more `system.db_reset` row (unless cleared), and the live revisions move forward again.

The one exception is registration codes: codes generated by the previous reset are business rows, so the second reset **deletes** them (and generates a new batch only if you pass `--codes` again). That is expected, not a bug.

### 7.17 If something goes wrong

| Failure point | What state is the database in? | What to do |
|---|---|---|
| Advisory lock not acquired | Nothing changed | Another install/reset is running. Wait and re-run. |
| Preflight refuses (missing table, no staff, production gate) | Nothing changed | Fix the schema/staff/gate, then re-run. |
| Any error inside the transaction | **Rolled back** — nothing changed | Read the error, fix the cause, re-run. The report says no changes were committed. |
| `AUTO_INCREMENT` fails after commit | Data is reset; a counter may be high | Non-fatal warning. Run the `ALTER TABLE <t> AUTO_INCREMENT = 1` manually, or accept the gap. |
| Demo install fails after commit | Data is reset; no/partial demo | Check the default packages and the warning; re-run with `--demo` only if the database is otherwise clean. |
| Code generation fails after commit | Data is reset; no/partial codes | Generate codes from `/admin/codes`; do not re-run the whole reset just for codes. |
| Verification fails after commit | Data is reset and committed | Read the failing check, run `wallet_reconcile.php`, restore the backup if money is wrong. **Never patch balances by hand.** |
| Browser request times out | Depends on the point | Check `last_reset_at`, open the console preview. If the data is clean but codes/live are missing, finish those from the admin pages or re-run. |
| The session report is lost | The database is reset | The audit log has the `system.db_reset` row; generated codes are under `/admin/codes`. |

---

## 8. Backup, restore and recovery

### 8.1 What to back up

| Item | Why |
|---|---|
| The **database** (`ecowealth_sneat`, or the host's full name) | Every order, wallet, ledger row, member and audit entry. |
| `system/app/config.local.php` | Database credentials and local flags; it is git-ignored, so it exists only on the machine/server. |
| `system/public/uploads/` | Product/package images and payment proofs. |
| `docs/` and the repository | Procedures and history. |

Keep backups **outside the web root**. Never put a `.sql` dump in `public/`; it would be downloadable.

### 8.2 Take a dump

Use the commands in Section 7.4. For a no-terminal workflow, use phpMyAdmin's **Export → SQL**.

A good dump:

- is not 0 bytes;
- starts with `-- MySQL dump`;
- contains `CREATE TABLE` for all 27 tables;
- for a large database, completes with exit code `0`.

Test a dump before you rely on it: restore it into a scratch database (Section 8.3) and check the counts.

### 8.3 Restore a dump

**Step 1 — stop writes.** Put the site in maintenance mode if it is live, or stop the queue/cron jobs. A restore over a live database can lose the writes that happen during the restore.

**Step 2 — drop and recreate the database.** Do not import over the damaged schema.

```sql
DROP DATABASE ecowealth_sneat;
CREATE DATABASE ecowealth_sneat
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run that as `root` in phpMyAdmin or the MySQL client. If you recreated the database, grant the application user again (replace the user/host with the ones in your `config.local.php`):

```sql
GRANT ALL PRIVILEGES ON ecowealth_sneat.* TO 'ecowealth'@'localhost', 'ecowealth'@'127.0.0.1';
FLUSH PRIVILEGES;
```

**Step 3 — import the dump.** Run these from the **project root**, where `backups/` lives.

Command Prompt:

```bat
mysql -u root ecowealth_sneat < backups\verified.sql
```

PowerShell:

```powershell
cmd /c "mysql -u root ecowealth_sneat < backups\verified.sql"
```

bash:

```bash
mysql -u root ecowealth_sneat < backups/verified.sql
```

Or phpMyAdmin: select the database → **Import** → choose the `.sql` file → **Go**.

**Step 4 — verify.**

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM shop_orders;
SELECT COUNT(*) FROM ewallet_ledger;
```

Compare with the counts you recorded before the failure. Then run:

```bash
php bin/wallet_reconcile.php
```

It must exit `0`.

**Step 5 — if the dump predates a migration,** apply the missing `database/migrations/NNN_*.sql` files in numeric order **after** the import, then re-run the reconciliations. Never import `install.sql` into an existing database to "catch up".

### 8.4 If an install fails halfway

The bundle's guard stops a second import before it starts, but an interrupted import (a timeout, a dropped connection, phpMyAdmin continuing after an error) can leave a partial schema.

The supported recovery is:

1. **Do not re-run the bundle** on the partial schema.
2. Drop the database.
3. Recreate it empty (`utf8mb4` / `utf8mb4_unicode_ci`).
4. Import the bundle once.
5. If you had a pre-install dump or snapshot, restore that instead.

If a guided installer failed **before** it wrote the lock, there is no lock to remove. If it failed **after** writing the lock (rare — the lock is written after verification), read its report first. Delete the lock only when you have decided to wipe and reinstall.

### 8.5 If a reset goes wrong

There are two different failures:

| Failure | What happened | Recovery |
|---|---|---|
| The transaction threw | **Rolled back**; the database is exactly as it was before the transaction started | Fix the cause, re-run. |
| The reset committed but verification failed | The data **is** reset | Read the failing check; run `wallet_reconcile.php`; restore the pre-reset backup if money is involved. |

In both cases, **do not patch `users` balances by hand**. The ledger, the services and the reconciliation are a set; a manual balance edit makes the next reconciliation fail and hides the original problem.

### 8.6 A restore drill (recommended once per quarter)

A backup that has never been restored is an untested backup. Once in a while:

1. Take a dump of the database.
2. Create a **scratch** database whose name ends in `_test` (for example `ecowealth_sneat_restore_test`).
3. Restore the dump into it.
4. Grant the app user access to the scratch database.
5. Point a one-off command at it with `ECO_DB_NAME` and run `php bin/wallet_reconcile.php`.
6. Drop the scratch database.

Do not run the drill against the live database.

---

## 9. Troubleshooting

Start with the exact message. Most failures are one of the situations below.

### 9.1 `php` or `mysql` is not recognized

| Symptom | Fix |
|---|---|
| `php : The term 'php' is not recognized` | Use **Laragon → Terminal**, or call PHP by its full path. The project needs PHP 8.1+. |
| `mysql : The term 'mysql' is not recognized` | Use the full path: `& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" …`, or add MySQL's `bin` folder to PATH. |
| `php bin\db_install.php` says `Could not open input file` | You are in the wrong folder. `cd` into `system/` first. |
| A `bin/*.php` file returns `Not found` in the browser | These commands are CLI-only. Run them in a terminal, not through Apache. |

### 9.2 Database connection errors

| Message | Meaning | Fix |
|---|---|---|
| `MySQL refused the credentials for this connection.` (1045) | Wrong `DB_USER` or `DB_PASS` | Correct `app/config.local.php`; make sure the password has no stray quotes. |
| `The database "…" does not exist.` (1049) | The schema is missing | Create it in the panel/Laragon, or use `--create-database` with the CLI if the user has the privilege. |
| `MySQL is not reachable at …` (2002/2003/1130) | MySQL is stopped, on another host/port, or the user is not allowed from this host | Start MySQL; check `DB_HOST`/`DB_PORT`; on shared hosting use the host's exact value. |
| `The MySQL user has no access to the database "…"` (1044) | The user exists but has no grant on that schema | Grant `ALL` on that one database to the app user, or fix `DB_NAME`. |
| `SQLSTATE[HY000] [1044] …` when creating a database | The user does not have the global `CREATE` privilege | Create the database in the hosting panel; the CLI's `--create-database` is optional. |

### 9.3 The bundle guard fires: `ERROR 1146 … ecowealth_install_requires_an_empty_database`

The database was **not empty**. The bundle is fresh-install only.

- If this is a brand-new deployment: drop the database, recreate it empty, import again.
- If this is an existing site: do **not** import `install.sql`. Apply `database/migrations/NNN_*.sql` in order instead.
- If phpMyAdmin continued after the error: treat the schema as partial; drop/recreate it. Never re-run the bundle on a partial result.

### 9.4 The installer refuses because of the lock

Message: `This installation is already locked` or the browser's **Already installed** page.

- The application folder has `system/storage/install.lock` from an earlier guided install.
- The lock is per folder, not per database: changing `DB_NAME` does not bypass it.
- If you intend to reinstall: back up, drop/recreate the database, **then** delete the lock, then install.
- If you do not intend to reinstall: sign in and use the existing site.

### 9.5 The installer refuses because the database is not empty

Message: `Database empty` fails, or the browser's **Database not empty** page.

- The target already has tables. A fresh install needs zero tables.
- Use a different, empty database, or drop/recreate this one (after a backup).
- Do not import `install.sql` into an existing site; use migrations.

### 9.6 Credential validation fails

| Message | Fix |
|---|---|
| `username` / `Username must be 3–50 …` | Use 3–50 letters, numbers, dots, underscores or dashes. |
| `password` / `must be 12–72` | Use a longer password. |
| `must not be a known default` | Choose a password that is not `Admin@123`, `Super@123`, `password`, `Password123` or `admin123`. |
| `The two usernames must differ` | Pick a different superadmin username. |
| `email` / `valid email` | Use a real address such as `owner@example.com`. |
| `The two emails must differ` | Use a different address for the second account. |

### 9.7 `INSTALL_KEY` problems

| Symptom | Fix |
|---|---|
| "INSTALL_KEY is not configured" | Add `define('INSTALL_KEY', '…32+ chars…');` to `app/config.local.php`, reload. |
| "The install key does not match" | Paste the exact value; no spaces. The comparison is case-sensitive. |
| "Too many install-key attempts" | Wait 15 minutes. The limit is 5 failures per session window. |
| The key field is missing | You are in `development`; the key is required only in production. |
| The CLI says the key is missing in production | Pass `--key=<the same value>` or set `ECO_INSTALL_KEY`. |

### 9.8 The browser reset page is a 404 or 403

| Symptom | Meaning | Fix |
|---|---|---|
| **404** in production | `DB_RESET_ENABLED` is absent/false | That is the intended production behavior. Use the CLI during a change window, or enable the constant temporarily (Section 7.14). |
| **404** in development | `APP_ENV` is accidentally `production` | Check `config.local.php`; set `APP_ENV` to `development` and reload. |
| **403** as a member | Only staff can use the console | Sign in as `admin`/`superadmin`. |
| **403** in an S-Login tab | Impersonated sessions are refused | Open the console in the superadmin's own tab, not an impersonated one. |
| Redirect to login | You are not signed in | Sign in first. |
| The form is disabled | A preflight check failed (missing table, no staff account) | Read the reason above the form; fix the schema/staff row. |

### 9.9 The reset refuses the confirmation

- Development phrase: `RESET` (case-insensitive, trimmed).
- Production phrase: the **exact database name** (case-sensitive).
- A wrong value aborts with exit code `3` and nothing deleted.
- In the browser, the button stays disabled until the phrase and the backup acknowledgement are present. The server still re-checks both.

### 9.10 The reset refuses on a missing table or no staff account

These are preflight refusals; nothing is deleted.

- **Missing table:** the schema is incomplete. Install the bundle into an empty database, or apply the missing migrations to an existing one.
- **No staff account:** a database with no way to sign in is not a clean state. Restore a staff row (from a backup) or reinstall.

### 9.11 Import limits and timeouts

| Symptom | Fix |
|---|---|
| phpMyAdmin: file too large | The bundle is ~64 KB; if your host's limit is lower, import `.sql.gz` or split at the section banners. |
| Browser installer times out | It asks for 120 s; if the host ignores it, use phpMyAdmin. |
| The connection drops mid-import | Treat the schema as partial: drop, recreate, import once. |
| `Allowed memory size exhausted` | Raise PHP's `memory_limit` to 256 MB, or use phpMyAdmin/the CLI. |

### 9.12 Upload or permission errors

| Symptom | Fix |
|---|---|
| Installer: `Storage writable` fails | Make `system/storage` writable by PHP (`755`, or `775` if needed). |
| Installer: `Lock directory` fails | Same as above; the lock lives in `storage/`. |
| Reset: "N upload file(s) could not be removed" | Fix permissions on `public/uploads/proofs/`; the reset itself succeeded. |
| Product/proof upload fails in the app | Check `upload_max_filesize`, `post_max_size`, `fileinfo`, and the `public/uploads/` permissions. |

### 9.13 "Which database did this folder install into?"

Read the lock:

```powershell
Get-Content storage\install.lock
```

```bash
cat storage/install.lock
```

The JSON records `database`, `installed_at`, `channel` and the bundle checksum. If the lock points at a database you no longer use, that is your answer — and it also explains why the installer refuses.

### 9.14 "I deleted `install.php`, but now I need to install again"

Re-upload `system/public/install.php` from the repository **temporarily**, and follow Section 5.3 (back up, drop/recreate the database, delete the lock). Then delete the file again. Treat a reinstall on a live site as a change window.

### 9.15 The reset committed but a check failed

Do not re-run blindly. Read the failing check:

- **Wallet/commission/transfer/payout reconciliation** — run `php bin/wallet_reconcile.php`, inspect the listed mismatches, restore the backup if real money is wrong.
- **Catalogue** — the catalogue does not match the expected counts; if you used `--reset-catalogue`, check that the seed files exist and are unmodified.
- **Codes** — the generated count differs; check whether the code batch partially failed, and generate from `/admin/codes` instead of re-running the reset.
- **AUTO_INCREMENT** — cosmetic; run the `ALTER TABLE` manually or accept the gap.
- **Live revisions** — the page count differs; check whether a standard surface is missing or a custom surface was added. Do not re-run the reset blindly.

### 9.16 Error-message quick table

| Exact message (or close) | Section |
|---|---|
| `The term 'php' is not recognized` | 9.1 |
| `The term 'mysql' is not recognized` | 9.1 |
| `MySQL refused the credentials` | 9.2 |
| `The database "…" does not exist` | 9.2 |
| `MySQL is not reachable` | 9.2 |
| `The MySQL user has no access` | 9.2 |
| `ecowealth_install_requires_an_empty_database` | 9.3 |
| `This installation is already locked` | 9.4 |
| `Database empty` / `Database not empty` | 9.5 |
| `The install key does not match` | 9.7 |
| `Too many install-key attempts` | 9.7 |
| `Type RESET exactly` | 9.9 |
| `Tick the backup acknowledgement` | 9.9 |
| `The reset form is disabled` | 9.8 |
| `aborted — the typed name did not match` | 9.9 |

---

## 10. Going live: retire the tools

`install.php` and `reset.php` are development/QA tools. Before a production site is called live, delete them. They are not routes and the application does not need them; deleting two files is the whole removal.

### 10.1 The go-live checklist

- [ ] Delete `system/public/install.php`; open `https://…/system/install.php` and confirm **404**.
- [ ] Delete `system/public/reset.php`; open `https://…/system/reset.php` and confirm **404**.
- [ ] Remove `INSTALL_KEY` from `config.local.php` (or rotate it).
- [ ] Confirm `DB_RESET_ENABLED` is absent or `false`.
- [ ] Change both staff passwords from any default; confirm neither is `Admin@123` or `Super@123`.
- [ ] Confirm no demo users:

  ```sql
  SELECT COUNT(*) FROM users
   WHERE username IN ('juan','maria','peter','sarah','david');
  ```

  and no demo codes:

  ```sql
  SELECT COUNT(*) FROM reg_codes WHERE code LIKE 'DEMO-%';
  ```

  Both must return `0`.
- [ ] Run the verification pack (Section 3.6) and file the output.
- [ ] Delete `database/install.sql` and `bin/db_install.php` from the server if they were uploaded. Neither is read at runtime.
- [ ] Keep `system/storage/install.lock` when a guided installer created it. It is proof of the install and lives outside `public/`.
- [ ] Confirm the lock is not web-readable (`https://…/system/storage/install.lock` must be 403/404).
- [ ] Take a post-launch database backup.
- [ ] If the host has SSH, run `php bin/wallet_reconcile.php` and confirm exit `0`.

### 10.2 Commands

**Windows PowerShell** (from `system/`):

```powershell
Remove-Item public\install.php, public\reset.php -ErrorAction SilentlyContinue
Remove-Item database\install.sql -ErrorAction SilentlyContinue
Remove-Item bin\db_install.php -ErrorAction SilentlyContinue
```

**bash** (from `system/`):

```bash
rm -f public/install.php public/reset.php
rm -f database/install.sql
rm -f bin/db_install.php
```

Check the URLs (use `curl.exe` on Windows; PowerShell 5.1's `curl` alias is not the real client):

```bash
curl.exe -I https://example.com/ecowealth/system/install.php
curl.exe -I https://example.com/ecowealth/system/reset.php
```

Both should return `HTTP/1.1 404` (or the host's equivalent "not found" response). A redirect to the app or a login page is **not** a 404; keep deleting until the tool is gone.

### 10.3 What may stay

| File | Can stay? | Why |
|---|---|---|
| `app/Services/DatabaseInstaller.php`, `DatabaseResetService.php`, `DemoDataService.php` | Yes | Unreachable without the pages; harmless. |
| `public/assets/css/db-tools.css` | Yes | Static styling; unused once the pages are gone. Delete it with them if you prefer. |
| `storage/install.lock` | Yes | Proof of the guided install; not web-readable. |
| `database/install.sql` | Keep a copy offline; remove from the server | Not read at runtime; useful for a future disaster-recovery install. |
| `bin/db_reset.php` | Remove on production | No reason to ship a destructive CLI to a live host. |
| `tests/`, `dmp/`, `docs/`, `backups/` | Do not upload | Developer/planning material. |

### 10.4 If disaster recovery needs a reinstall

A production site that must be reinstalled after a disaster:

1. Treat it as a **change window**.
2. Restore the latest verified backup first, if one exists. Reinstalling is the last resort.
3. If a true reinstall is required: re-upload `install.php` + `install.sql` (or `bin/db_install.php` if there is SSH) temporarily.
4. Install into a fresh, empty database.
5. Delete the tools again and re-run the go-live checklist.

The lock and the empty-database guard make an accidental second install hard; they do not make a deliberate reinstall safe. The backup is still the first line of recovery.

---

## 11. Reference

### 11.1 File map

| Path | What it is | Notes |
|---|---|---|
| [`system/database/install.sql`](../system/database/install.sql) | Generated production bundle | 27 tables, 48 settings, 2 staff, 7 products, 2 packages. No demo data. |
| [`system/database/install/header.sql`](../system/database/install/header.sql) | Bundle header (editable) | Usage, warnings, empty-database guard. |
| [`system/database/install/footer.sql`](../system/database/install/footer.sql) | Bundle footer (editable) | Install metadata and verification `SELECT`s. |
| [`system/database/schema.sql`](../system/database/schema.sql) | Authoritative schema | 27 tables and indexes. |
| `system/database/seeds/001_settings.sql` | Seed | 45 settings. |
| `system/database/seeds/002_accounts.sql` | Seed | 2 staff accounts. |
| `system/database/seeds/003_products.sql` | Seed | 6 catalogue products. |
| `system/database/seeds/004_packages.sql` | Seed | 2 packages, 18 levels, 10 contents, 1 service product. |
| [`system/database/seeds/005_demo_tree.sql`](../system/database/seeds/005_demo_tree.sql) | Demo seed | Never in production. |
| [`system/bin/build_install_sql.php`](../system/bin/build_install_sql.php) | Bundle builder | Developer tool. |
| [`system/bin/db_install.php`](../system/bin/db_install.php) | CLI installer | Preflight, install, verify, lock. |
| [`system/bin/db_reset.php`](../system/bin/db_reset.php) | CLI reset | Preview, reset, verify. |
| [`system/public/install.php`](../system/public/install.php) | Browser installer | Standalone; delete after install. |
| [`system/public/reset.php`](../system/public/reset.php) | Browser reset console | Standalone; delete at go-live. |
| [`system/public/assets/css/db-tools.css`](../system/public/assets/css/db-tools.css) | Shared tool styling | Used by both pages. |
| [`system/app/Core/DbTarget.php`](../system/app/Core/DbTarget.php) | Shared plumbing | Connections, name validation, table listing. |
| [`system/app/Core/SqlScript.php`](../system/app/Core/SqlScript.php) | Shared plumbing | Quote-aware SQL file parser/runner. |
| [`system/app/Core/DbToolAccess.php`](../system/app/Core/DbToolAccess.php) | Shared gates | Key, phrase, visibility, actor rules. |
| [`system/app/Services/DatabaseInstaller.php`](../system/app/Services/DatabaseInstaller.php) | Install engine | The browser and CLI both call it. |
| [`system/app/Services/DatabaseResetService.php`](../system/app/Services/DatabaseResetService.php) | Reset engine | The browser and CLI both call it. |
| [`system/app/Services/DemoDataService.php`](../system/app/Services/DemoDataService.php) | Demo engine | Preconditions + `005_demo_tree.sql`. |
| `system/storage/install.lock` | Runtime lock | Written by a guided install; not committed. |

### 11.2 `php bin/db_install.php` flags

| Flag | Default | Notes |
|---|---|---|
| *(no mode flag)* | — | Install into an empty database; asks for confirmation. |
| `--check` | off | Preflight only; no writes, no database creation. |
| `--database=NAME` | `DB_NAME` | Must match `^[A-Za-z0-9_]{1,64}$`. |
| `--create-database` | off | Create the schema if missing; requires the `CREATE` privilege. |
| `--admin-user=NAME` | `admin` | 3–50 chars, same regex as registration. |
| `--admin-email=EMAIL` | `admin@sneat.dev` | Must be valid and differ from the other staff email. |
| `--admin-password=PASS` | — | Visible in shell history; prefer stdin. |
| `--admin-password-stdin` | — | Read one line from STDIN. |
| `--superadmin-user=NAME` | `superadmin` | |
| `--superadmin-email=EMAIL` | `superadmin@sneat.dev` | |
| `--superadmin-password=PASS` / `--superadmin-password-stdin` | — | Same policy. |
| `--site-name=NAME` | `EcoWealth` | 1–80 chars. |
| `--with-demo` | off | Development/QA only; refused in production. |
| `--key=KEY` | `INSTALL_KEY`/env | Required in production. |
| `--force` | off | Skips the typed confirmation only; never the preflight. |
| `--json` | off | One JSON report on STDOUT. |
| `--help`, `-h` | — | Full usage. |

Exit codes: `0` installed and verified · `1` preflight/verification failed · `2` usage/invalid name · `3` refused/locked/non-empty.

### 11.3 `php bin/db_reset.php` flags

| Flag | Default | Notes |
|---|---|---|
| *(no mode flag)* | — | Preview, ask for the typed database name, reset. |
| `--dry-run` | off | Preview + statement plan; writes nothing. |
| `--force` | off | Non-interactive; `--confirm=<database>` is mandatory. |
| `--confirm=NAME` | — | Must equal the database name. |
| `--keep-catalogue` | **on** | Keep products/packages. |
| `--reset-catalogue` | off | Delete and re-seed from `003` + `004`. |
| `--codes=N` | `0` | 0–50 unused registration codes. |
| `--code-package=ID` | first active | Package for the generated codes. |
| `--demo` | off | Demo tree after the reset; development/QA only. |
| `--keep-audit` | **on** | Keep the audit log; append the reset row. |
| `--clear-audit` | off | Delete the audit log before the reset row. |
| `--keep-uploads` | off | Leave proof images on disk; rows still deleted. |
| `--allow-production` | off | Required when `APP_ENV=production`. |
| `--json` | off | One JSON report on STDOUT. |
| `--help`, `-h` | — | Full usage. |

Exit codes: `0` reset and verified · `1` committed but verification failed · `2` usage/invalid option · `3` aborted/refused (including a missing table, no staff row, wrong confirmation, lock, or production gate).

### 11.4 `php bin/build_install_sql.php` flags

| Flag | Effect |
|---|---|
| *(none)* | Rebuild `database/install.sql` from the sources. |
| `--check` | Compare bytes; exit `1` with the first difference when stale. |
| `--stdout` | Print the bundle instead of writing the file. |
| `--help` | Usage. |

Exit codes: `0` built/matches · `1` stale or bad source · `2` usage. This is a developer tool; an operator never needs it.

### 11.5 The `--json` report

Both commands print **one** JSON document when `--json` is passed. The shapes are similar but not identical.

**Install (`db_install`) top-level keys:**

| Key | Meaning |
|---|---|
| `tool` | `db_install` |
| `version` | Report schema version (`1`) |
| `ok` | Overall success, including verification |
| `mode` | `install` |
| `database` | Target schema |
| `channel` | `cli` or `web` |
| `started_at`, `duration_ms` | Timing |
| `options` | Effective options |
| `steps` | `key`, `label`, `status`, `rows`, `ms` |
| `counts` | `tables_created`, `settings_rows`, `staff_rows`, `members_rows`, `products_rows`, `packages_rows`, `levels_rows`, `contents_rows`, `codes_rows` |
| `staff` | `reset: false`, `usernames`, `accounts` (role/username/email — never passwords) |
| `lock` | Path, written flag, database |
| `install_sql` | Path, bytes, SHA-256, statement count |
| `demo` | Demo result, if requested |
| `verification`, `checks` | `ok` + `checks[]` (`key`, `label`, `ok`, `detail`, `hint`) |
| `warnings` | Non-fatal issues |

**Reset (`db_reset`) top-level keys:**

| Key | Meaning |
|---|---|
| `tool` | `db_reset` |
| `version` | `1` |
| `ok` | Overall success |
| `database` | Target schema |
| `started_at`, `duration_ms` | Timing |
| `options` | Effective options |
| `steps` | `key`, `label`, `status`, `deleted`/`rows`, `ms`, optional `error` |
| `counts_before`, `counts_after` | Per-table counts |
| `staff` | `reset`, `usernames` |
| `audit` | `kept`, `rows_before`, `cleared` |
| `auto_increment` | `reset`, `failed` |
| `codes` | `generated`, `package`, `list` |
| `demo` | `installed`, `members`, `codes`, `credentials` |
| `verification` | `ok` + `checks[]` |
| `warnings` | Non-fatal issues |

`--json` is the stable contract for CI. Human progress text does not appear on STDOUT; parse the document, not the pretty tables. A failure report may also carry an `error` object with `kind`, `message`, `hint` and `context`.

### 11.6 The install lock fields

```json
{
    "installed_at": "2026-11-12T07:03:17+08:00",
    "database": "ecowealth_sneat",
    "app_version": "1.0.0",
    "install_sql_sha256": "…64 hex characters…",
    "staff": ["admin", "superadmin"],
    "channel": "cli"
}
```

No secret is stored. The file is written atomically (temp file + rename), and `chmod 0600` is applied where the platform supports it. On Windows it inherits the folder's NTFS ACL; it lives outside `public/` and under the deny-all `storage/.htaccess`.

### 11.7 URLs

| What | Local (Laragon) | Production (example) |
|---|---|---|
| Application | `http://localhost/ecowealth/system/` | `https://example.com/ecowealth/system/` |
| Sign in | `http://localhost/ecowealth/system/login` | `https://example.com/ecowealth/system/login` |
| Installer | `http://localhost/ecowealth/system/install.php` | `https://example.com/ecowealth/system/install.php` (delete after install) |
| Reset console | `http://localhost/ecowealth/system/reset.php` | `https://example.com/ecowealth/system/reset.php` (404 in production unless enabled) |
| Admin | `http://localhost/ecowealth/system/admin` | `https://example.com/ecowealth/system/admin` |
| Shop | `http://localhost/ecowealth/system/shop` | `https://example.com/ecowealth/system/shop` |

If the application is at the domain root, drop the `/ecowealth/system` prefix (for example `https://example.com/install.php`). The tool is a flat file in `public/`; the URL mirrors where you put it.

### 11.8 For developers: rebuild the bundle

The runtime files (`install.sql`, the services, the pages) are not the place to edit schema or seed data. The flow is:

```text
edit database/schema.sql or database/seeds/00X_*.sql
        │
        ▼
php bin/build_install_sql.php
        │
        ▼
commit the source AND the regenerated database/install.sql
        │
        ▼
php bin/build_install_sql.php --check   (must exit 0)
php tests/run.php                       (must pass)
```

Rules:

- **Never hand-edit `install.sql`.** It is generated and the suite fails when it drifts from its sources.
- The bundle is byte-stable: LF line endings, no build timestamp, one trailing newline. `.gitattributes` pins `*.sql text eol=lf`.
- `install.sql` contains **no** executable `CREATE DATABASE`, `USE`, `DELIMITER` or `SOURCE`; the caller selects the schema. The builder rejects a source that introduces one.
- A new **migration** does not change `install.sql` automatically. If a migration adds a setting or a seed row, update the corresponding seed too, or `SeedParityTest` fails. That is the point of the test.
- Existing databases are upgraded with `database/migrations/NNN_*.sql` in numeric order; `install.sql` is only for a new, empty database.

The automated groups that cover this work:

```bash
php tests/run.php SqlScript
php tests/run.php DbTarget
php tests/run.php InstallSql
php tests/run.php SeedParity
php tests/run.php Install
php tests/run.php DbReset
php tests/run.php DbToolAccess
php tests/run.php
```

The suite uses pre-provisioned scratch schemas whose names end in `_test`; it never targets `ecowealth_sneat` or the production database. See [`system/README.md`](../system/README.md) §Tests for the full test documentation.

### 11.9 Related documents

| Document | Why read it |
|---|---|
| [`system/README.md`](../system/README.md) | The application's own quick start, configuration table, test notes and reset section. |
| [`docs/deploy.md`](deploy.md) | The full Hostinger/shared-hosting deployment guide, including `config.local.php`, permissions, §11.6 verification and §12 cleanup. |
| [`docs/runbook.md`](runbook.md) | Operational procedures: backups, restore drills, install/reset, incident playbooks and the go-live checklist. |
| [`docs/qa/phase-16-install-reset.md`](qa/phase-16-install-reset.md) | The 16 manual acceptance checks and the evidence index. |
| [`docs/qa/evidence/install-reset/README.md`](qa/evidence/install-reset/README.md) | The evidence naming rules and the screenshots/transcripts behind the sign-off. |
| [`dmp/plan-ecowealth-install-reset.md`](../dmp/plan-ecowealth-install-reset.md) | The implementation plan: decisions, architecture, SQL specifications, phase gates and the IR-8 sign-off. |

### 11.10 Quick reference card

```text
LOCAL INSTALL (chosen passwords, recommended)
  cd C:\laragon\www\ecowealth\system
  php bin\db_install.php --check
  php bin\db_install.php --database=ecowealth_sneat --force --admin-email=owner@example.com --admin-password-stdin --superadmin-email=owner+super@example.com --superadmin-password-stdin
  # pipe two lines into it (admin first); see 3.3 for the PowerShell/bash form

LOCAL INSTALL (fast, default passwords)
  mysql -u root ecowealth_sneat < database\install.sql     (cmd.exe)
  cmd /c "mysql -u root ecowealth_sneat < database\install.sql"   (PowerShell)

LOCAL RESET
  php bin\db_reset.php --dry-run
  php bin\db_reset.php
  php bin\db_reset.php --force --confirm=ecowealth_sneat --codes=5

BROWSER
  install: http://localhost/ecowealth/system/install.php
  reset:   http://localhost/ecowealth/system/reset.php
  login:   http://localhost/ecowealth/system/login

PROVE IT
  php bin\wallet_reconcile.php

GO LIVE
  delete public/install.php, public/reset.php
  remove INSTALL_KEY; keep DB_RESET_ENABLED false
  change both staff passwords
  confirm no demo users/codes
  take a backup
```

---

*End of guide. If a step in this document does not match what you see, check [`system/README.md`](../system/README.md) and [`docs/deploy.md`](deploy.md) for the current version of the application, and treat the difference as a documentation bug to report rather than a reason to improvise on a live database.*
