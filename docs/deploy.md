# Deploying EcoWealth to shared hosting (Hostinger)

> **Status: preliminary.** This guide covers the Phase 10 build plus the live-update
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
| A cron job for live updates | **No** | Cron is only for shop expiry and order auto-complete. |

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

Import these files **in order** through phpMyAdmin:

1. `<app>/database/schema.sql`
2. `<app>/database/seeds/001_settings.sql`
3. `<app>/database/seeds/002_accounts.sql`
4. `<app>/database/seeds/003_products.sql`
5. `<app>/database/seeds/004_packages.sql`

Do **not** import `<app>/database/seeds/005_demo_tree.sql` on a production site. It
creates the demo sponsor tree (`juan`, `maria`, `peter`, `sarah`, `david`), demo wallets
and sample registration codes. Import it only on a test site, then remove the demo data
before go-live.

The fresh `schema.sql` already contains the `live_revisions` table.

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

If the dump is older than Phase 10, apply **every missing migration** in
`<app>/database/migrations/` in numeric order (for example `012_commissions.sql`, then
`013_live_revisions.sql`), not just 013.

> **Import size limit:** if phpMyAdmin says the file is too large, export as `.sql.gz`
> (gzip) and import the `.sql.gz`, or split the dump. Hostinger's phpMyAdmin accepts
> gzipped SQL.

### Import order matters

`database/schema.sql` creates the tables. The seeds fill them. Import the schema first,
then the seeds in numeric order. If you import a seed before its parent tables exist,
foreign keys will fail.

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

In phpMyAdmin:

```sql
SHOW TABLES LIKE 'live_revisions';
SELECT * FROM live_revisions;
```

The table may be empty until the first product/order write. That is normal. After a
product change, it should have a row for `admin.products`.

---

## 12. Secure and clean up

The example URLs below use Layout A (`/ecowealth/system/`). Adjust the path if you chose
another layout.

Before calling the site live:

- [ ] Change the `admin` password. Use **Profile → Change password**.
- [ ] Sign in as `superadmin` and change that password too.
- [ ] Remove demo members if you imported `005_demo_tree.sql`.
- [ ] Replace demo products/packages with real catalogue data.
- [ ] Keep `APP_ENV=production` and do not enable `APP_DEBUG`.
- [ ] Remove `system/tests/`, `docs/`, `dmp/` and `backups/` from the server if they
      were uploaded by mistake.
- [ ] Confirm `config.local.php` is not web-accessible (`https://example.com/ecowealth/system/app/config.local.php`
      should be a 404, not the file contents).
- [ ] Confirm `https://example.com/ecowealth/system/storage/logs/` is denied.
- [ ] Confirm `https://example.com/ecowealth/system/public/uploads/` does not execute PHP.
- [ ] Export a database backup and download a copy of `public/uploads/`.
- [ ] If you use the URL cron form, set a long random `CRON_KEY`.

### Remove the demo data

If you imported `005_demo_tree.sql` for testing, do not leave the demo accounts and
wallets on the production site. The cleanest approach is to rebuild the database from
`schema.sql` + seeds **without** `005_demo_tree.sql`, then import only the real data.
Deleting demo rows by hand is possible but easy to get wrong because of foreign keys.

---

## 13. Optional: cron jobs

**Live updates do not need cron.** Skip this section if you only care about the live
product list.

The scheduled jobs are for:

- shop payment expiry (`bin/shop_expire.php`);
- order auto-complete (`bin/order_autocomplete.php`);
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
- [ ] Database imported (existing dump or `schema.sql` + seeds).
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
- [ ] Default passwords changed; demo data removed.
- [ ] Database and uploads backed up.
- [ ] Error log reviewed.
- [ ] Optional cron jobs created and tested with `--doctor`.

---

## Appendix A — Path reference

Replace `<app>` with the folder from [Step 3](#3-choose-where-the-app-will-live).

| What | Path / URL |
|---|---|
| App folder | `<app>` (contains `app/`, `public/`, `storage/`) |
| Config | `<app>/app/config.local.php` |
| Schema | `<app>/database/schema.sql` |
| Seeds | `<app>/database/seeds/` |
| Migrations | `<app>/database/migrations/` |
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

When a later phase adds a migration, a cron job or a new hosting requirement, update
[Step 5](#5-import-the-database), [Step 8](#8-set-the-php-version-and-extensions),
[Step 13](#13-optional-cron-jobs) and the checklist above.
