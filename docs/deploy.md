# Deploying EcoWealth to shared hosting (Hostinger)

> **Status: preliminary.** This guide covers the Phase 12 build plus the live-update
> layer. It will be updated as later phases add migrations, cron jobs or hosting
> requirements. Hostinger's hPanel labels can change; the steps stay the same.
>
> Verified against the local build (PHP 8.3.16, MySQL 8.4.3). Hostinger's current
> LiteSpeed/Apache + PHP 8.3 plans meet the same requirements.

This guide is written for a complete beginner. You do **not** need SSH, Node.js, npm,
React, Next.js, Composer, WebSockets or a background daemon. EcoWealth is a plain
PHP 8 + MySQL application, and the live updates are browser-side JavaScript plus normal
HTTP requests.

---

## Contents

1. [What you need](#1-what-you-need)
2. [What you do not need](#2-what-you-do-not-need)
3. [Choose where the app will live](#3-choose-where-the-app-will-live)
4. [Create the database](#4-create-the-database)
5. [Import the database](#5-import-the-database)
6. [Upload the files](#6-upload-the-files)
7. [Create `config.local.php`](#7-create-configlocalphp)
8. [Set the PHP version and extensions](#8-set-the-php-version-and-extensions)
9. [Set folder permissions](#9-set-folder-permissions)
10. [Enable SSL](#10-enable-ssl)
11. [Test the deployment](#11-test-the-deployment)
12. [Secure and clean up](#12-secure-and-clean-up)
13. [Optional: cron jobs](#13-optional-cron-jobs)
14. [Updating the app later](#14-updating-the-app-later)
15. [Troubleshooting](#15-troubleshooting)
16. [Final checklist](#16-final-checklist)

---

## 1. What you need

| Item | Requirement |
|---|---|
| Hosting | Hostinger shared hosting (any plan with PHP 8.1+ and MySQL) |
| PHP | **8.1 or newer**; 8.3 recommended |
| PHP extensions | `pdo_mysql`, `fileinfo`, `mbstring` (Hostinger enables these by default) |
| Database | MySQL 8.x or a current MariaDB with `utf8mb4` |
| Web server | Apache 2.4 or LiteSpeed with `.htaccess` / `mod_rewrite` (Hostinger) |
| Domain | A domain or subdomain pointed at Hostinger |
| Local backup | Your current database export **and** your `system/public/uploads/` folder |

You will use only these Hostinger hPanel areas:

- **Files → File Manager** — upload and edit files.
- **Databases → MySQL Databases** — create the database and user.
- **Databases → phpMyAdmin** — import the SQL.
- **Advanced → PHP Configuration** — set the PHP version and limits.
- **Security → SSL** — enable HTTPS.
- **Advanced → Cron Jobs** — optional scheduled jobs.
- **Advanced → Error Log** — read PHP errors.

---

## 2. What you do not need

| Tool | Needed? | Why |
|---|---|---|
| Node.js / npm / yarn | **No** | No build step, no bundler, no `package.json`. |
| React / Next.js | **No** | The UI is server-rendered PHP with plain JavaScript. |
| Composer | **No** | The app uses a small custom autoloader. |
| SSH | **No** | File Manager + phpMyAdmin cover everything. |
| WebSocket / SSE / Redis / queue | **No** | Live updates use browser `BroadcastChannel` plus a 30-second HTTP poll. |
| A permanent background process | **No** | Each request is ordinary PHP. |
| A cron job for live updates | **No** | Cron is only for shop expiry, order auto-complete and the transfer-limit reset. |

In deployment terms, the live-update feature adds two new artifacts you must not forget:

- one static script, `public/assets/js/app-live.js` (loaded by the footer on every page),
  plus the per-page hooks it calls (`admin-dashboard.js`, `admin-live.js`);
- one small table: `live_revisions` (one row per page surface).

Everything else ships as part of the application files. There is no separate service to
install or keep alive.

---

## 3. Choose where the app will live

The application folder is the folder that contains `app/`, `public/`, `storage/`,
`database/` and `bin/`. In this repository that folder is `system/`.

Pick **one** of these layouts. The steps below use `<app>` for the application folder
and `<app-url>` for the browser URL. Substitute the values from your chosen row.

| Layout | What you upload | Where | App URL (`<app-url>`) | `<app>` on the server |
|---|---|---|---|---|
| **A — recommended** | The **contents** of the project folder | `public_html/ecowealth/` | `https://example.com/ecowealth/system` | `public_html/ecowealth/system` |
| **B — app at `/system/`** | The contents of the project folder | `public_html/` | `https://example.com/system` | `public_html/system` |
| **C — app at the domain root** | The contents of `system/` | `public_html/` | `https://example.com` | `public_html` |

Layout A keeps the local URL shape (`/ecowealth/system/`) and the landing page at
`/ecowealth/`. Layout C gives the cleanest app-only URL but does not deploy the static
landing page. For most users, **Layout A** is the easiest to reason about.

The app detects its own base URL. Do **not** hard-code `APP_BASE_URL`.

> **Do not point the domain at `<app>/app` or `<app>/storage`.** The public front door is
> `<app>/public/`. The included `.htaccess` pair forwards requests there. If your plan lets
> you set a custom document root (usually for a subdomain), point it at `<app>/public`
> instead; then the `.htaccess` forwarding is not needed.

---

## 4. Create the database

1. Sign in to Hostinger hPanel.
2. Open **Databases → MySQL Databases**.
3. Create a new database. Hostinger will show a name such as `u123456_ecowealth`.
4. Create a database user, or use the user Hostinger offers. Note:
   - **DB host** — usually `localhost` (sometimes a hostname such as `mysql.hostinger.com`).
   - **DB name** — e.g. `u123456_ecowealth`.
   - **DB user** — e.g. `u123456_ecowealth`.
   - **DB password** — the one you set.
5. Make sure the user is attached to the database with all privileges on that database.

Write these four values down. You will put them in `config.local.php` in
[Step 7](#7-create-configlocalphp).

The app does not use a table prefix. Do not add one.

---

## 5. Import the database

You have two paths. Pick the one that matches your situation.

### 5A. Moving your existing site (recommended if you already have data)

1. On your local machine, open phpMyAdmin (`http://localhost/phpmyadmin`).
2. Select your local database (`ecowealth_sneat`).
3. Choose **Export → Quick → SQL → Go** and save the `.sql` file.
4. On Hostinger, open **Databases → phpMyAdmin**, select the new database, and choose
   **Import**.
5. Choose the `.sql` file and click **Import**.

Your current local database already includes the `live_revisions` table, because the
live-update migration was applied locally. It also includes your products, members,
orders, wallets and settings.

If the export contains `CREATE DATABASE` or `USE` lines and Hostinger's database has a
different name, the import may complain. Re-export with **Custom** and uncheck
**Add CREATE DATABASE / USE statement**, or remove those lines from the `.sql` file
before importing.

### 5B. Fresh install

You have two paths on shared hosting. **A** is the phpMyAdmin import; **B** is the
browser installer for an owner who wants to choose the staff passwords instead of using
the published defaults. Both import the same bundle into the same empty schema.

#### 5B-A. Import `install.sql` through phpMyAdmin

Import **one file**, `database/install.sql`, through phpMyAdmin:

1. Select the new **empty** database in phpMyAdmin.
2. Choose **Import** → choose `<app>/database/install.sql` → select **Stop on error** →
   **Go**.
3. Read the output at the bottom. A correct import prints the verification pack:

   | Tables | Settings | Staff | Members | Products | Packages | Levels | Contents | Codes |
   |---:|---:|---:|---:|---:|---:|---:|---:|---:|
   | 27 | 48 | 2 | 0 | 7 | 2 | 18 | 10 | 0 |

The bundle creates the schema, the 48 settings, both staff accounts, the seven starter
products and the two packages in one pass. It contains no `CREATE DATABASE` or `USE`, so
it installs into whichever schema you selected.

It is **fresh-install only**: a non-empty schema is refused by a guard
(`ERROR 1146` naming `ecowealth_install_requires_an_empty_database`) before any table is
created. If phpMyAdmin was allowed to continue after that error, drop the database and
recreate it rather than re-running the bundle.

Do **not** import `<app>/database/seeds/005_demo_tree.sql` on a production site, and never
pass `--with-demo` / tick the demo option when the environment is production: both
installers and the reset console refuse it. The seed creates the demo sponsor tree
(`juan`, `maria`, `peter`, `sarah`, `david`), demo wallets and sample registration codes
(`DEMO-*`). Import it only on a test site; the go-live checklist (§12) checks that no demo
user or `DEMO-%` code survived.

The staff accounts start with the published defaults (`admin` / `Admin@123`,
`superadmin` / `Super@123`). Change both passwords immediately after the first sign-in
(§12).

`database/install.sql` is generated from `database/schema.sql` + seeds 001–004. When those
sources change, rebuild it with `php bin/build_install_sql.php` (and check it with
`--check`); never hand-edit the bundle.

#### 5B-B. Install through the web installer (guided credentials, no SSH)

Use this path when you want to choose the staff passwords in a browser instead of
importing the bundle and then changing the published defaults. It imports the same
`install.sql`, verifies the result and writes `storage/install.lock`.

1. Create the **empty** database and user in hPanel (Step 4). The web installer never
   creates a database: shared hosting usually grants `ALL` on one prefixed schema but not
   the global `CREATE DATABASE` privilege.
2. Upload the application **without** your local `app/config.local.php`, then create the
   production one (Step 7) with `APP_ENV=production`, the database credentials,
   `APP_URL`, and a new line:

   ```php
   define('INSTALL_KEY', 'paste-at-least-32-random-characters-here');
   ```

   The key must be non-empty in production; the page refuses to run without it. Keep it
   in a password manager, not in the repository.
3. Open `https://example.com/ecowealth/system/install.php`.
4. Read the preflight table. Every row must pass; the form stays disabled until it does.
   If "INSTALL_KEY" is red, the constant is missing from `config.local.php`.
5. Enter the site name, both staff usernames/emails/passwords, the `INSTALL_KEY`, and
   type `INSTALL`. Submit.
6. The success page lists the created accounts (**never their passwords**), the
   verification counts and the lock path. It does not print the key or the DSN.
7. **Delete `public/install.php` immediately**, remove `INSTALL_KEY` from
   `config.local.php` (or rotate it), and sign in with the admin account.
8. Reopening the URL before deleting it shows "already installed": the lock and the
   non-empty schema both refuse a second run.

| Limit | What to do |
|---|---|
| `max_execution_time` below 30 s | The page asks PHP for 120 s; if the host ignores it, install through phpMyAdmin (5B-A) instead. |
| The page says the database does not exist | Create it in hPanel first; the web installer never creates one. |
| The page says the database is not empty | It is a fresh-install tool. Drop/recreate the schema (after a backup), or upgrade the existing database with migrations. |
| A WAF blocks `install.php` | Use phpMyAdmin + 5B-A and delete the file. |
| You lost the lock or need to re-install | Delete `storage/install.lock`, drop/recreate the database, then install again. Treat this as a change window. |

> **Never leave `install.php` online after go-live.** It refuses a second run, but it is a
> tool, not a page: delete it together with `reset.php` and confirm both URLs return 404
> (§12).

### 5C. If your dump is older than the live-update change

Run this migration once through phpMyAdmin, after the base dump:

```
<app>/database/migrations/013_live_revisions.sql
```

You can confirm the table exists by running this in phpMyAdmin:

```sql
SHOW TABLES LIKE 'live_revisions';
```

If the table is missing, the pages still work, but the live-update client switches
itself off and the feed returns an error. Do not skip this table.

If the dump is older than Phase 14, apply **every missing migration** in
`<app>/database/migrations/` in numeric order (for example `012_commissions.sql`,
`013_live_revisions.sql`, `014_indirect_commissions.sql`, `015_package_b_premium_ladder.sql`,
`016_transfers.sql`, `017_payouts.sql`, then `018_impersonation.sql`), not just the newest one.

> **Import size limit:** if phpMyAdmin says the file is too large, export as `.sql.gz`
> (gzip) and import the `.sql.gz`, or split the dump. Hostinger's phpMyAdmin accepts
> gzipped SQL.

### Import order matters

`database/install.sql` is self-contained: schema, settings, accounts, catalogue and
packages are concatenated in dependency order, so a fresh install is one import. Existing
databases never import the bundle — apply `database/migrations/NNN_*.sql` in numeric order
instead (schema first in a fresh path, migrations in an upgrade).

### 5D. CLI installer (only if your host gives you SSH)

Most shared plans have no SSH — use 5B-A (phpMyAdmin) or 5B-B (the web installer). If yours does, `bin/db_install.php`
imports the same bundle, replaces both seeded passwords with ones you choose, verifies the
result and writes `storage/install.lock`:

```bash
# 1. preflight: every check, no writes, nothing created
php bin/db_install.php --check

# 2. install (the database must be empty)
php bin/db_install.php --database=u123456_ecowealth --force \
  --admin-user=admin --admin-email=owner@example.com --admin-password-stdin \
  --superadmin-user=superadmin --superadmin-email=owner+super@example.com \
  --superadmin-password-stdin --site-name="EcoWealth"
```

Each `--…-password-stdin` reads one line from standard input (admin first), so no password
lands in the shell history. Without `--force` the command asks you to type the database
name first; `--force` skips only that confirmation, never the preflight.

| Exit | Meaning |
|---:|---|
| 0 | installed and verified |
| 1 | a preflight check or the verification failed |
| 2 | a flag or a credential is wrong (usage) |
| 3 | refused: the schema is non-empty, an install lock exists, or the production key is missing/wrong |

On a `production` site the command also requires `INSTALL_KEY` in `config.local.php` and a
matching `--key=…` (or `ECO_INSTALL_KEY`). `--json` prints one machine-readable report
instead of the human tables.

> **After the install:** change both staff passwords, then delete `bin/db_install.php` and
> `database/install.sql` from the server (§12). `storage/install.lock` is proof of the
> guided install — keep it. Removing the lock is the only way to install into that tree
> again, so treat deleting it as "wipe and re-install".

---

## 6. Upload the files

### 6A. Prepare what to upload

Upload the application, not every local folder. **Do not upload**:

| Folder / file | Why |
|---|---|
| `.git/` | Contains repository history; never expose it. |
| `backups/` | May contain database dumps or secrets. |
| `dmp/` | The old reference application; not part of the production site. |
| `system/tests/` | Developer tests; not needed on the server. |
| `docs/` | Documentation; keep it local (optional). |
| `system/tests/tmp/` | Test scratch files. |
| any `*.log` | Local logs. |
| your local `system/app/config.local.php` | Contains local database credentials; replace it with the host version. |

You **do** need the hidden `.htaccess` files:

- `<app>/.htaccess`
- `<app>/public/.htaccess`
- `<app>/public/uploads/.htaccess`
- `<app>/storage/.htaccess`

If your ZIP or FTP client hides dotfiles, the app will be missing its rewrite rules or
its upload protection. Check after uploading.

**Install/reset tool files.** A *first* install may also upload `database/install.sql` (for
5B-A), and/or `public/install.php` (for 5B-B) with `bin/db_install.php` if you use 5D. They are
temporary: delete them from the server after the install (§12). A site that installs through
phpMyAdmin does not need `install.php` or `bin/db_install.php` at all. Never upload
`public/reset.php` to a production site.

### 6B. Upload with File Manager

1. In hPanel, open **Files → File Manager**.
2. Open `public_html` (or the folder for your subdomain/addon domain).
3. Create the folder for your chosen layout:
   - Layout A: create `public_html/ecowealth/`.
   - Layout B: use `public_html/`.
   - Layout C: use `public_html/`.
4. Upload a ZIP of the chosen files.
5. Right-click the ZIP → **Extract**.
6. Delete the ZIP after extraction.
7. Open **Show hidden files** in File Manager and confirm the `.htaccess` files are
   present.

**ZIP pitfall:** if your ZIP contains a top-level `ecowealth/` folder, extract it directly
in `public_html/`. If it contains the files at the root, extract it inside
`public_html/ecowealth/`. You want `public_html/ecowealth/index.html`, **not**
`public_html/ecowealth/ecowealth/index.html`. For Layout C, you want
`public_html/app/`, **not** `public_html/system/app/`.

If you use FTP/FileZilla instead, enable **Force showing hidden files** before uploading.

### 6C. Check the result

For Layout A, the server should look like this:

```
public_html/
└── ecowealth/
    ├── index.html                 (landing page)
    ├── assets/                    (landing page assets)
    ├── .htaccess                  (marketing cache rules)
    └── system/
        ├── .htaccess              (forwards to public/)
        ├── app/
        │   ├── config.php
        │   └── config.local.php   (you create this in Step 7)
        ├── public/
        │   ├── .htaccess
        │   ├── index.php
        │   ├── assets/
        │   └── uploads/
        │       ├── .htaccess
        │       ├── products/
        │       ├── packages/
        │       └── proofs/
        ├── database/
        ├── storage/
        └── bin/
```

For Layout C, the contents of `system/` sit directly in `public_html/`:

```
public_html/
├── .htaccess
├── app/
├── public/
├── database/
├── storage/
└── bin/
```

---

## 7. Create `config.local.php`

The file is at:

```
<app>/app/config.local.php
```

It is git-ignored, so a fresh Git clone will not contain it. If you uploaded your local
copy, replace its contents.

Create or edit the file with Hostinger's File Manager (right-click → **Edit**):

```php
<?php

declare(strict_types=1);

/* ---------- Production environment ---------- */
define('APP_ENV', 'production');
define('APP_URL', 'https://example.com');

/* ---------- Hostinger database ---------- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456_ecowealth');
define('DB_USER', 'u123456_ecowealth');
define('DB_PASS', 'paste-the-database-password-here');

/* ---------- Optional ---------- */
// define('DB_PORT', 3306);                 // only if Hostinger gives a non-default port
// define('APP_TIMEZONE', 'Asia/Manila');   // change only if the business is elsewhere
// define('DB_TIMEZONE', '+08:00');         // keep aligned with APP_TIMEZONE
// define('CRON_KEY', 'a-long-random-string'); // only for the URL cron form
// define('INSTALL_KEY', 'a-long-random-string'); // only for a guided installer (CLI or install.php)
```

### What each value means

| Constant | What to put |
|---|---|
| `APP_ENV` | `production`. This hides debug detail and uses the friendly error pages. |
| `APP_URL` | The **origin only**: `https://example.com`, not `https://example.com/ecowealth/system`. The install prefix is detected automatically. |
| `DB_HOST` | Usually `localhost`. Use the value Hostinger shows. |
| `DB_NAME` | The full Hostinger database name, including the `u123456_` prefix. |
| `DB_USER` | The full database user name. |
| `DB_PASS` | The database password. |
| `DB_PORT` | Leave it out unless Hostinger shows a custom port. |
| `APP_TIMEZONE` | Defaults to `Asia/Manila`. Change only if the business runs elsewhere. |
| `DB_TIMEZONE` | Defaults to `+08:00`. Keep it aligned with `APP_TIMEZONE`. |
| `CRON_KEY` | Optional. Required only if you use the **URL** cron form. |
| `INSTALL_KEY` | Optional. Required only if you run a guided installer on a production site: `bin/db_install.php` (SSH) or `public/install.php` (browser, §5B-B). At least 32 random characters. Both installers refuse to run in production when it is empty, and neither ever prints, stores or returns it. |
| `DB_RESET_ENABLED` | **Leave absent or `false`.** The reset console (`public/reset.php`) is a development/QA tool; in production it is a branded 404 unless this is explicitly `true`, and even then it requires a superadmin and the database name. If you ever enable it for an approved change window, delete `public/reset.php` and set it back to `false` immediately afterwards (§12). |

> **About `DB_NAME`:** the [repository README](../system/README.md) says a local
> `config.local.php` should not define `DB_NAME`, because the local test runner switches
> to `ecowealth_sneat_test` when `APP_ENV=test`. On the production server you are not
> running that test runner, so
> defining the real Hostinger name here is the simplest option. If you ever run
> `php tests/run.php` on the server, use a separate test configuration or the
> `ECO_DB_NAME` environment variable instead.

Do **not** define `APP_BASE_URL`; the app detects it from the request.
Do **not** set `APP_DEBUG=true` on production. It is derived from `APP_ENV`.

### Check the file permissions

`config.local.php` should be readable by PHP but not writable by the world. `644` is
normal. It lives outside `public/`, and the rewrite rules prevent it from being served.

---

## 8. Set the PHP version and extensions

1. In hPanel, open **Advanced → PHP Configuration** (the label may be **PHP Options**).
2. Select **PHP 8.3** (or the newest 8.1+ version available).
3. Confirm these extensions are enabled:
   - `pdo_mysql`
   - `fileinfo`
   - `mbstring`
4. If product images or payment proofs fail to upload, raise the upload limits:
   - `upload_max_filesize` = `10M`
   - `post_max_size` = `12M`
   - `memory_limit` = `256M` (128M is usually enough)
   - `max_execution_time` = `60`

The application caps uploads at 5 MB (`UPLOAD_MAX_BYTES`). The PHP limits must be larger
than the app cap, otherwise PHP rejects the file before the app sees it.

Hostinger's PHP 8.3 build normally has all three extensions. If one is missing, enable
it in the PHP configuration or ask Hostinger support.

---

## 9. Set folder permissions

The app needs to write runtime files in two places:

| Folder | Why |
|---|---|
| `<app>/storage/` | Cron logs and locks. |
| `<app>/public/uploads/` | Product/package images and payment proofs. |

In Hostinger File Manager:

1. Right-click `<app>/storage` → **Permissions**.
2. Set directories to **755** (try **775** only if PHP still cannot write).
3. Do the same for:
   - `<app>/storage/logs`
   - `<app>/storage/locks`
   - `<app>/public/uploads`
   - `<app>/public/uploads/products`
   - `<app>/public/uploads/packages`
   - `<app>/public/uploads/proofs`
4. Files should normally be **644**.

The upload code creates the `products`, `packages` and `proofs` subfolders if the parent
is writable, but creating them yourself avoids surprises.

**Do not use 777.** Hostinger runs PHP as your account user, so 755 is normally enough.

Keep the guard files:

- `<app>/public/uploads/.htaccess` — disables PHP execution in uploaded files.
- `<app>/storage/.htaccess` — denies web access to logs and locks.

If these are missing, re-upload them.

---

## 10. Enable SSL

1. In hPanel, open **Security → SSL**.
2. Install the free SSL certificate for your domain/subdomain.
3. Enable **Force HTTPS**.
4. Set `APP_URL` to the `https://` origin in `config.local.php`.

HTTPS is strongly recommended:

- the session cookie is marked `Secure` when the request is HTTPS;
- browser clipboard features require a secure context;
- live updates work on HTTP too, but HTTPS avoids mixed-content and cookie warnings.

If you use Cloudflare in front of Hostinger, set the SSL mode to **Full (strict)**.
Do not enable full-page caching for the admin pages (for example `/system/admin/*` or
`/admin/*`) or for the live feed.

---

## 11. Test the deployment

Open `<app-url>` in a browser. Work through this list in order.

### 11.1 The page loads

- [ ] The login page appears.
- [ ] CSS and JavaScript load (the page is styled, not plain text).
- [ ] The browser console has no red errors.
- [ ] `https://` is active.

If the page is unstyled, the app is probably at a different URL than you think, or the
`.htaccess` files are missing. Check the browser's Network tab for 404s on
`/assets/...`.

### 11.2 Sign in

Fresh install:

| Username | Password | Role |
|---|---|---|
| `admin` | `Admin@123` | admin |
| `superadmin` | `Super@123` | superadmin |

If you imported your existing database, use your existing credentials.

- [ ] You reach the dashboard.
- [ ] The sidebar shows the admin or member menu.
- [ ] You can open **Products** (`<app-url>/admin/products`).

### 11.3 Check the catalogue

- [ ] The product list loads.
- [ ] The stats cards show numbers.
- [ ] A product's Edit page opens.
- [ ] Uploading a product image works (no "folder not writable" error).
- [ ] Saving the product works and the change appears in the list.

### 11.4 Check live updates

1. Open `<app-url>/admin/products` in **two browser tabs**.
2. Toggle a product's status in one tab.
3. The other tab should update within about a second, without a refresh.
4. If you hide one tab and change the status in the other, the hidden tab should update
   when you switch back to it.
5. Edit a product and save. The list in the other tab should update when the save lands.
6. Open `<app-url>/admin/orders` in two tabs and open one order in each. Upload a proof
   as the member, then verify it in the first tab: the second order page should show the
   new status and review pane without a refresh. Type into a note field in that second
   tab first; the page must keep the typed value and show the "another administrator
   updated this order" banner instead of replacing the form.
7. Open `<app-url>/admin/wallets` in two tabs and open one member's wallet in each. Credit
   the wallet from the first tab: the second tab's monitor and wallet page should update
   without a refresh. Type into a ledger filter in the second tab first; its statement
   card must keep the typed filter and show the stale banner instead of replacing it.
8. Sign in as that member in another browser, open `<app-url>/dashboard`, then top their
   wallet up from the admin wallet page. The member's E-wallet KPI and card should update
   on the next poll (within 30 seconds) without a reload.
9. Open `<app-url>/admin/members` in two tabs and suspend a member from one. The other
   tab's row, status counters and "joined this week" badge should update. Open that
   member's page in a second tab and top up their wallet from the monitor: the balance
   and wallet summary should update without a reload.
10. Open `<app-url>/admin/activations` in two tabs. Type a rejection reason in one but do
    not submit; approve or reject a different request in the other tab. The first tab must
    keep the typed reason and show the stale banner instead of replacing the row.
11. Open `<app-url>/admin/codes` and `<app-url>/admin/commissions` in two tabs each. Generate
    a code batch in one codes tab and approve an activation in a commissions tab: the other
    tab of the same page should update without a refresh.
12. Sign in as a pending member in two browser tabs, both on `<app-url>/membership`. Submit
    a manual proof in one tab: the other tab should show the "activation in progress"
    notice without a reload. Type into the registration-code field first; the typed code
    must survive the refresh and the stale banner must explain why the forms were kept.
13. Approve that member's activation as the administrator: the member's open `/membership`
    tab should switch from the forms/notice to the active-associate state on its next poll.
14. Open `<app-url>/shop` in two tabs as a member. Edit a product's price or stock in the
    back office: the other tab's card and its "left"/"Sold out" badge should update without
    a refresh. Place an order from a product page and check that the catalogue availability
    drops on the other tab.
15. Open one product in two tabs. Change the product's status to inactive: the other tab
    should swap the card for the "no longer available" state; restore it and the card comes
    back. Open the quick-view modal after a catalogue refresh and confirm it still opens
    (the grid was replaced, not the modal).
16. Sign in as a member with orders and open `<app-url>/orders` in two tabs. Pack or ship
    one of that member's orders from the admin desk: the history row's status should update
    without a refresh.
17. Open one order in two tabs. Type a proof reference in one but do not submit; upload a
    proof or cancel the order in the other tab. The first tab must keep the typed value and
    show the stale banner. Submit a proof and check that the "Payment attempts" card appears
    live in the other tab.
18. Sign in as a member and open `<app-url>/wallet` in two tabs. Credit the wallet from the
    admin monitor (or let a commission land): the three bucket cards and the statement
    should update on the next poll without a reload. Type into a ledger filter in one tab
    first; the statement card must keep the typed filter and show the stale banner.
19. Open `<app-url>/earnings` in two tabs and approve an activation that pays the member:
    the summary cards, the award count and the rows should update without a refresh. A
    skipped award must reveal the "was skipped" notice live.
20. Open `<app-url>/genealogy` in two tabs. Register a new member under the signed-in
    member (or approve an activation in their downline): the "in team" badges, the
    direct-recruit count and rows, and the team-by-level cards should update without a
    reload. A status change in the downline should also update the row's status badge.
21. Open `<app-url>/profile` in two tabs. Save an address in one: the other tab's address
    book should update without a reload. Type a new full name or payout account in the
    second tab first; the form must keep the typed value and show the stale banner
    instead of being replaced.
22. Open `<app-url>/checkout` and `<app-url>/profile` in two tabs. Add an address on the
    profile tab: the checkout tab's saved-address card should appear or update without a
    reload, while the delivery form keeps whatever was typed.
23. Open `<app-url>/admin/packages` in two tabs and `<app-url>/packages` in a member tab.
    Edit a package's price or toggle its status in one admin tab: the other admin tab's
    counters and row, and the member's package card, should update without a refresh.
24. Open `<app-url>/admin/settings` in two tabs. Change the receiving accounts in one:
    the other tab's card should update on its next poll. Type into a different card first;
    the typed value must survive and the stale banner must appear instead of replacing it.
25. Open `<app-url>/checkout` in two tabs (with items in the cart). Change a quantity in
    the cart tab: the other checkout tab's order summary should re-price without a
    reload. Top up the wallet: the e-wallet option and balance note should update; if a
    payment method was typed/selected first, the card is held and the banner explains why.
26. Open `<app-url>/cart` in two tabs (with lines in the cart). Change a quantity or remove
    a line in one tab: the other tab's table, totals and checkout button should update
    without a reload. Type a different quantity in one tab and change the cart in the
    other: the typed quantity must survive and the stale banner must appear. Add a product
    from `<app-url>/shop` in a third tab and change a product's price/stock in the back
    office: the cart tab should re-render its lines and badges, and its drawer/badge should
    follow. Close the shop as an administrator: the closed notice should appear live.
27. Open `<app-url>/dashboard` in two tabs as a member. Top up the wallet, place an order
    for that member, or approve their activation: the header status pill, KPI row, E-wallet
    card, membership card, recent-orders card and earnings card should update without a
    refresh, while the greeting and the referral card (with its copy button) stay
    untouched. Register a new member under them: the team/earnings figures should move on
    the next poll.

If the page works but live updates do not:

- Check that `live_revisions` exists (Step 5C).
- In the browser Network tab, the feed request should return JSON with `"ok":true`.
- Check the browser console for errors.
- Make sure the admin pages are not being cached by LiteSpeed/Cloudflare.
- The 30-second fallback poll means a change can take up to 30 seconds if the browser
  did not receive the tab-to-tab signal.

### 11.5 Check the server log

Open **Advanced → Error Log** in hPanel. Fix any repeated PHP warnings/errors before
go-live. A one-off warning during the first request is still worth reading.

### 11.6 Check the database

Open phpMyAdmin → select the database → **SQL** and run the verification pack from
`dmp/plan-ecowealth-install-reset.md` Appendix E, or at least these queries:

```sql
-- 1. 31 tables and 54 settings on a fresh production install
SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()) AS tables_created,
       (SELECT COUNT(*) FROM settings)                                                 AS settings_rows,
       (SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin'))               AS staff_rows,
       (SELECT COUNT(*) FROM users WHERE role = 'member')                              AS members_rows,
       (SELECT COUNT(*) FROM products)                                                 AS products_rows,
       (SELECT COUNT(*) FROM packages)                                                 AS packages_rows,
       (SELECT COUNT(*) FROM package_levels)                                           AS levels_rows,
       (SELECT COUNT(*) FROM package_products)                                         AS contents_rows,
       (SELECT COUNT(*) FROM reg_codes)                                                AS codes_rows;

-- 2. No demo leakage: both must be 0
SELECT COUNT(*) AS demo_users FROM users
 WHERE username IN ('juan','maria','peter','sarah','david');
SELECT COUNT(*) AS demo_codes FROM reg_codes WHERE code LIKE 'DEMO-%';

-- 3. Install/reset metadata and the live surfaces
SELECT key_name, value FROM settings
 WHERE key_name IN ('install_version','install_channel','install_completed_at',
                    'last_reset_at','last_reset_by')
 ORDER BY key_name;
SELECT COUNT(*) AS surfaces FROM live_revisions;
SHOW TABLES LIKE 'live_revisions';
```

A fresh production install prints **31 / 54 / 2 / 0 / 7 / 2 / 18 / 10 / 0**. `live_revisions`
is empty after a static `install.sql` import; it may hold a row for `admin.products` after the
first product change, and a reset leaves 18 standard surfaces. Record the output with the
go-live evidence (§12).

---

## 12. Secure and clean up

The example URLs below use Layout A (`/ecowealth/system/`). Adjust the path if you chose
another layout.

Before calling the site live:

- [ ] Delete `public/install.php`; confirm `https://example.com/ecowealth/system/install.php`
      returns 404 (not the installer page).
- [ ] Delete `public/reset.php`; confirm `https://example.com/ecowealth/system/reset.php`
      returns 404 (the branded page, not the console). A production site keeps
      `DB_RESET_ENABLED` absent or `false`, so even a forgotten file answers 404; deletion is
      the second lock. If the console was enabled for a change window, set the constant back to
      `false` in the same step.
- [ ] Delete `database/install.sql` from the server (and `bin/db_install.php` if it was
      uploaded); a static import needs neither at runtime.
- [ ] Confirm `storage/install.lock` exists when a guided installer (CLI or web) was used and
      cannot be read over the web (`https://example.com/ecowealth/system/storage/install.lock`
      must be 403/404). A **static** `install.sql` import has no lock file — that is expected:
      the populated schema and the deleted tools are the guard. Then remove `INSTALL_KEY` from
      `config.local.php` (or rotate it).
- [ ] Confirm `DB_RESET_ENABLED` is absent or `false` in `config.local.php`.
- [ ] Change the `admin` password. Use **Profile → Change password**.
- [ ] Sign in as `superadmin` and change that password too. Neither password may still be a
      documented default (`Admin@123`, `Super@123`): a fresh `install.sql` import seeds both,
      so this step is mandatory on that path.
- [ ] Confirm no demo data leaked into production:
      `SELECT COUNT(*) FROM users WHERE username IN ('juan','maria','peter','sarah','david');`
      and `SELECT COUNT(*) FROM reg_codes WHERE code LIKE 'DEMO-%';` must both return `0`.
- [ ] Run the verification pack (§11.6) and file its output: 31 tables, 54 settings, 2 staff,
      0 members, 7 products, 2 packages, 18 levels, 10 contents, 0 codes.
- [ ] Replace demo products/packages with real catalogue data.
- [ ] Keep `APP_ENV=production` and do not enable `APP_DEBUG`.
- [ ] Remove `system/tests/`, `docs/`, `dmp/` and `backups/` from the server if they
      were uploaded by mistake.
- [ ] Confirm `config.local.php` is not web-accessible (`https://example.com/ecowealth/system/app/config.local.php`
      should be a 404, not the file contents).
- [ ] Confirm `https://example.com/ecowealth/system/storage/logs/` is denied.
- [ ] Confirm `https://example.com/ecowealth/system/public/uploads/` does not execute PHP.
- [ ] Export a database backup and download a copy of `public/uploads/` **after** the checklist.
- [ ] Re-run `php bin/wallet_reconcile.php` if the host gives you SSH; exit `0`.
- [ ] If you use the URL cron form, set a long random `CRON_KEY`.

### Remove the demo data

If demo fixtures were installed for testing (`--with-demo`, the installer checkbox, a reset
with `--demo`, or a manual `005_demo_tree.sql` import), do not leave the demo accounts and
wallets on the production site. A reset **without** `--demo` clears them together with
every other member row; the alternative is to rebuild the database from `schema.sql` +
seeds **without** `005_demo_tree.sql`, then import only the real data. Deleting demo rows by
hand is possible but easy to get wrong because of foreign keys.

---

## 13. Optional: cron jobs

**Live updates do not need cron.** Skip this section if you only care about the live
product list.

The scheduled jobs are for:

- shop payment expiry (`bin/shop_expire.php`);
- order auto-complete (`bin/order_autocomplete.php`);
- transfer-limit reset (`bin/reset_transfer_limits.php`) — once a day, just after midnight;
- optionally `bin/wallet_reconcile.php` for a manual reconciliation audit.

The full guide, access rules, logs and troubleshooting are in [cron.md](cron.md).

Hostinger gives you two ways to run them. The examples below use Layout A
(`/ecowealth/system/`); substitute your own `<app>` path and `<app-url>`.

### 13A. CLI cron (recommended)

In hPanel → **Advanced → Cron Jobs**, create a job with a command like:

```cron
15,45 * * * * /usr/bin/php /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/bin/shop_expire.php --limit=200 >> /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/storage/logs/cron.out 2>&1
```

Adapt the absolute path to your `<app>` folder. Hostinger's hPanel may offer a PHP
binary picker; choose the PHP 8.3 binary if so.

### 13B. URL cron (no SSH)

1. Add a long random key to `<app>/app/config.local.php`:

   ```php
   define('CRON_KEY', 'a-long-random-string');
   ```

2. Create the cron job with a URL:

   ```cron
   15,45 * * * * curl -s "https://example.com/ecowealth/system/cron/shop_expire.php?key=a-long-random-string" > /dev/null
   ```

3. Open the same URL in a browser with `?doctor=1` first. The doctor output tells you
   whether the database, time zone, log folder and lock are healthy.

The URL form puts the key in the access log. Prefer the CLI form if your plan provides
it.

### Cron folder permissions

The cron writes to `<app>/storage/logs/` and `<app>/storage/locks/`. If those are not
writable, the job exits with an error. See [Step 9](#9-set-folder-permissions).

---

## 14. Updating the app later

Before any update:

1. Export the database.
2. Download `<app>/public/uploads/` (product images and proofs).
3. Keep a copy of `<app>/app/config.local.php` somewhere safe.

Then:

1. Upload the changed application files.
2. **Do not overwrite** `config.local.php` or `public/uploads/`.
3. Apply any new migration files in `<app>/database/migrations/` in numeric order,
   through phpMyAdmin.
4. If a new phase adds a new table, confirm it with `SHOW TABLES`.
5. Clear Hostinger/LiteSpeed cache and, if available, PHP OPcache.
6. Re-test login, the product list, an image upload and the two-tab live update.
7. Check the error log.

The repository's [phase log](phase-log.md) records migrations and behavior changes. The
[live-updates guide](live-updates.md) explains how to add live regions to future pages.

---

## 15. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| 500 error on every page | PHP < 8.1, missing extension, `.htaccess` ignored, bad DB credentials | Set PHP 8.3; enable `pdo_mysql`/`fileinfo`/`mbstring`; check the error log; test `config.local.php`. |
| 404 on `/admin/products` or other clean URLs | `.htaccess` missing or `mod_rewrite` off | Re-upload the `.htaccess` files; enable hidden files in File Manager/FTP; Hostinger LiteSpeed should honor them. |
| Page loads but CSS/JS are missing | Wrong install folder, wrong URL, missing rewrite | Check `<app-url>` includes the right subfolder; open `<app-url>/assets/css/app.css` directly; confirm `.htaccess`. |
| "Database connection failed" | Wrong host/name/user/password, user not attached to DB | Copy the values from hPanel exactly; Hostinger usually uses `localhost`; make sure the user has privileges on the DB. |
| Login fails or "user not found" | Accounts seed not imported, or wrong database | Import `002_accounts.sql`; confirm the `users` table has rows; clear cookies. |
| "The upload folder is not writable" | `public/uploads/` permissions | Set `public/uploads` and its subfolders to 755/775; create `products`, `packages`, `proofs`; keep the `.htaccess`. |
| "The file is too large" | PHP `upload_max_filesize`/`post_max_size` too low | Set 10M/12M in PHP Configuration; the app cap is 5 MB. |
| Live updates do not happen | Missing `live_revisions`, feed returns HTML/404, page cache, browser console error | Run migration 013; check the Network request for `X-Live: 1` returns JSON; exclude admin pages from cache; wait 30s for the fallback poll. |
| Feed returns 500 | `live_revisions` missing or DB error | Check the error log; run `SHOW TABLES LIKE 'live_revisions'`; import `013_live_revisions.sql`. |
| Live updates work in one tab but not another | The other tab is hidden | Switch to it; it refreshes once on visibility. |
| Cron returns 403 | URL cron has no/wrong `CRON_KEY`, or the caller is not loopback | Use the CLI cron, or add `?key=...` matching `CRON_KEY`. |
| Cron says storage not writable | `storage/logs` or `storage/locks` permissions | Set them to 755/775. |
| Site shows a blank page | PHP fatal, debug off | Check **Advanced → Error Log**; temporarily set `APP_DEBUG=true` **only** while debugging, then set it back. |
| 403 Forbidden for the whole site | A deny rule from a misplaced `.htaccess` | Check that `storage/.htaccess` is not in `public_html`; keep each `.htaccess` in its own folder. |
| "Session expired" repeatedly | HTTPS/HTTP mismatch, `APP_URL` wrong, cookie blocked | Use HTTPS everywhere; set `APP_URL` to the `https://` origin; clear cookies. |
| phpMyAdmin import fails | File too large or contains `CREATE DATABASE`/`USE` | Import a `.sql.gz`, or remove the `CREATE DATABASE`/`USE` lines and select the target DB first. |

### Where to find errors

- **Hostinger hPanel → Advanced → Error Log** — PHP errors.
- **File Manager → `error_log`** — sometimes a per-folder log.
- **Browser DevTools → Console/Network** — JavaScript, missing assets, the live feed.
- **`<app>/storage/logs/`** — cron job logs, not web errors.

---

## 16. Final checklist

- [ ] Database created; user attached; credentials recorded.
- [ ] Database imported (existing dump, or `database/install.sql` for a fresh install).
- [ ] `live_revisions` table exists.
- [ ] Application uploaded to the chosen layout.
- [ ] The required `.htaccess` files are present.
- [ ] `config.local.php` created with Hostinger DB credentials and `APP_ENV=production`.
- [ ] `APP_URL` set to the HTTPS origin.
- [ ] PHP 8.3 selected; `pdo_mysql`, `fileinfo`, `mbstring` enabled.
- [ ] `storage/` and `public/uploads/` writable.
- [ ] SSL installed and HTTPS forced.
- [ ] Login works; admin dashboard opens.
- [ ] Product list opens; image upload and save work.
- [ ] Two-tab live update works.
- [ ] Default passwords changed; no demo users (`juan`, `maria`, `peter`, `sarah`, `david`)
      and no `DEMO-%` codes (the two queries in §12 return `0`).
- [ ] `public/install.php` and `public/reset.php` deleted; both tool URLs return 404, and
      `database/install.sql` removed from the server.
- [ ] `INSTALL_KEY` removed from `config.local.php`; `storage/install.lock` present when a
      guided installer was used (absent for a static import).
- [ ] `DB_RESET_ENABLED` absent or `false`.
- [ ] Verification pack (§11.6) run and filed: 27 / 48 / 2 / 0 / 7 / 2 / 18 / 10 / 0.
- [ ] Database and uploads backed up **after** the checklist.
- [ ] Error log reviewed.
- [ ] Optional cron jobs created and tested with `--doctor`; `wallet_reconcile.php` exits 0.

---

## Appendix A — Path reference

Replace `<app>` with the folder from [Step 3](#3-choose-where-the-app-will-live).

| What | Path / URL |
|---|---|
| App folder | `<app>` (contains `app/`, `public/`, `storage/`) |
| Config | `<app>/app/config.local.php` |
| Schema | `<app>/database/schema.sql` |
| Install bundle | `<app>/database/install.sql` (generated; delete after a production install) |
| Seeds | `<app>/database/seeds/` |
| Migrations | `<app>/database/migrations/` |
| CLI installer / reset | `<app>/bin/db_install.php`, `<app>/bin/db_reset.php` (delete after go-live) |
| Web installer / reset console | `<app>/public/install.php`, `<app>/public/reset.php` (delete after go-live) |
| Install lock | `<app>/storage/install.lock` (guided installs; never web-readable) |
| Cron scripts | `<app>/bin/` |
| Runtime storage | `<app>/storage/` |
| Uploads | `<app>/public/uploads/` |
| Public front door | `<app>/public/index.php` |
| App URL | `<app-url>` |
| Login URL | `<app-url>/login` |
| Products URL | `<app-url>/admin/products` |
| Live feed | Same as the page URL, requested with `X-Live: 1` |

## Appendix B — Quick reference: what not to upload

| Path | Reason |
|---|---|
| `.git/` | Repository history and secrets. |
| `backups/` | Database dumps and local backups. |
| `dmp/` | The old reference application. |
| `system/tests/` | Developer tests; not needed in production. |
| `docs/` | Documentation; optional and better kept local. |
| local `system/app/config.local.php` | Local DB credentials; replace with the host version. |
| `*.log`, `system/storage/logs/*` | Local runtime logs. |
| `system/tests/tmp/` | Test scratch files. |

## Appendix C — Document status

| Date | Change |
|---|---|
| 2026-11-07 | Initial preliminary guide for the Phase 10 build plus live updates. |
| 2026-11-08 | Phase 11: add migration 014 and note the indirect-commission settings. |
| 2026-11-11 | Phases 12–15: migrations 015–019, transfer/payout settings, S-Login, and the Phase 15 hardening/reports addendum below. |
| 2026-11-12 | Install/reset workstream (IR-1 … IR-8): §5B becomes the one-file `install.sql` bundle plus the key-gated web installer and the optional CLI installer; §6 lists the temporary tool files; §7 documents `INSTALL_KEY` and `DB_RESET_ENABLED`; §11.6 is the verification pack; §12/§16 add tool deletion, lock, key, demo, default-password and verification checks. |

---

## Phase 15 addendum — hardening, reports and the audit desk

The launch build adds **no new hosting requirement**. Everything below is PHP, an index or a page.

### Migration 019

Apply `database/migrations/019_audit_and_reporting.sql` after `018`. It adds the report indexes
(`shop_orders.paid_at`, `audit_log.action`, `commissions.level`, …) and re-creates `audit_log` if a
very old install skipped it. Applying it twice is a no-op; every `ALTER` is guarded by an
`information_schema` lookup.

### Security headers

Every PHP response now carries the shared headers from `app/Core/SecurityHeaders.php` (sent from the
bootstrap): `X-Content-Type-Options`, `X-Frame-Options: DENY`, a CSP with `frame-ancestors 'none'`,
`Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `X-Robots-Tag` and, only over
TLS, HSTS. An Apache front end must not strip them; a proxy that rewrites headers should preserve
them.

A strict content policy is deliberately not used: the EcoWealth layout ships inline theme and page
scripts, so a nonce-based policy is a later frontend task (documented in the class).

### Uploads and errors

- `public/uploads/.htaccess` denies every script extension and switches the PHP engine off. Verify
  after deployment: a `.php` file placed under `uploads/proofs/` must answer **403**.
- In production (`APP_DEBUG` off) PHP error display is forced off by the bootstrap; error pages stay
  branded and never show a trace. `error_log` keeps the detail.
- `.gitignore` keeps `config.local.php`, `backups/`, uploads, logs and locks out of the repository.

### New pages to smoke-test after an upgrade

| Page | Expect |
|---|---|
| `/admin/reports` | every report + the **Finance health** card; CSV export menu |
| `/admin/reports/export?type=sales` | `text/csv` download |
| `/admin/audit` | the append-only audit list with filters |
| `/admin/settings` | eight independently saving cards (General, Shop, Accounts, Payouts, Windows, Fulfilment, Commissions, Transfers) |

### Cron

The three jobs and their Windows Task Scheduler registration are documented in
[`cron.md` §4](cron.md). Register them once on the host; a missed run only ever makes a limit
stricter, never wider.

### Backups

A real backup → restore drill was executed for the launch gate; the commands and the recorded result
are in [`runbook.md` §3](runbook.md). Take a dump before applying `019` and before any later
migration.

### Go-live

Use the checklist in [`runbook.md` §7](runbook.md); it extends this guide's §16 with the health,
headers, uploads, cron and restore checks.


When a later phase adds a migration, a cron job or a new hosting requirement, update
[Step 5](#5-import-the-database), [Step 8](#8-set-the-php-version-and-extensions),
[Step 13](#13-optional-cron-jobs) and the checklist above.

---

## Phase 17 addendum — the repeat-purchase bonus settings

Migration `020_unilevel_repeat_purchase.sql` (Phase 17A) adds four tables, four columns, two indexes,
ten `ewallet_ledger.ref_type` values in total and **six settings**. Phase 17B makes **five** of them
editable on `/admin/settings` → **Commissions** → *Repeat-purchase bonus*:

| Key | Default | Shown as |
|---|---|---|
| `unilevel_enabled` | `0` | **Repeat-purchase bonus enabled** — the global master switch |
| `unilevel_max_levels` | `10` | **Upline depth** (1–10) |
| `unilevel_requires_active_upline` | `1` | **Require the monthly Active rule** |
| `unilevel_active_threshold` | `500` | **Monthly personal-purchase minimum** (₱) |
| `unilevel_reversal_enabled` | `1` | **Reverse awards when a paid order is cancelled** |

The sixth key, `unilevel_ledger_since`, is a reconciliation baseline written by the migration and is
never rendered or editable.

The **per-product** half is configured on the product form: **Point value (P.V.)** in *Pricing &
stock*, then the *Unilevel repeat-purchase bonus* card (bonus base, ten rates, the per-product switch).
A new product is pre-filled with the reference ladder 5/5/5/3/3/2/2/2/1/1 but its switch is **off**;
a product that existed before the migration reads 0% everywhere. Nothing is enabled by default and
**no money moves until Phase 17C** ships the engine: a paid order still writes no award row and no
`ref_type = 'unilevel'` ledger row.

Upgrade checklist additions:

- [ ] Apply migration `020` (it is additive and re-runnable), or import the regenerated
      `install.sql` on an empty schema.
- [ ] Confirm the six keys: `SELECT key_name, value FROM settings WHERE key_name LIKE 'unilevel%';`
      — six rows, `unilevel_enabled = 0`.
- [ ] Open a product's edit page and confirm the *Unilevel repeat-purchase bonus* card renders and
      saves (P.V., base, ten rates). Nothing is paid, whatever is configured.
- [ ] Leave `unilevel_enabled` at `0` until the operations runbook for the stream exists (Phase 17F/17G).
