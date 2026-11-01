# Sneat Auth — Login & Registration System (PHP + MySQL)

A complete, security-first login & registration system written in **plain PHP 8** with a **MySQL** database, styled with the **Sneat** Bootstrap 5 admin template.

---

## ✨ Features

| Area | What you get |
|---|---|
| **Registration** | Server-side validation (username 3–50 chars, unique email, password ≥ 8 chars + confirmation, terms checkbox) synced with the template's client-side FormValidation |
| **Login** | Email **or** username sign-in, brute-force lockout (5 failed attempts → 15-minute cooldown), friendly flash messages |
| **Remember me** | Secure 30-day cookie using a `selector : validator` token pair — only the SHA-256 hash of the validator is stored in MySQL, tokens rotate on every use |
| **Password storage** | `password_hash()` / `password_verify()` (bcrypt, cost 10) |
| **SQL injection** | 100% PDO prepared statements, `ATTR_EMULATE_PREPARES = false` |
| **CSRF** | Per-session token, `hash_equals()` verification on every POST form |
| **XSS** | All output escaped through the `e()` helper (`htmlspecialchars`, ENT_QUOTES) |
| **Sessions** | HttpOnly + SameSite=Lax cookies, strict mode, ID regeneration on login, 30-minute idle timeout |
| **Accounts** | `role` (user/admin), `is_active` flag, `last_login_at` tracking, unique username & email |
| **Pages** | Login, Register, protected Dashboard, Logout, 404 |

## 📦 What's in this package

```
sneat-auth/
├── README.md                 ← this file
├── app/
│   ├── .htaccess             ← denies direct web access to this folder
│   ├── bootstrap.php         ← shared bootstrapper (required by every page)
│   ├── config.php            ← DB credentials & app settings  ← EDIT ME
│   ├── database.php          ← lazy PDO/MySQL connection
│   ├── auth.php              ← all auth logic (login/register/remember/guards)
│   ├── helpers.php           ← url(), e(), csrf, flash, old(), redirect
│   └── layout/               ← shared UI partials (headers, footers, logo)
├── database/
│   ├── .htaccess             ← denies direct web access to this folder
│   ├── schema.sql            ← pristine schema + demo account (idempotent)
│   └── database-export.sql   ← full mysqldump (schema + live data)
└── public/                   ← docroot
    ├── .htaccess             ← clean URLs on Apache (/login → login.php)
    ├── index.php             ← entry point (redirects guest → login)
    ├── login.php  register.php  logout.php
    ├── dashboard.php         ← protected page
    ├── 404.php  router.php   ← pretty URLs for the built-in server
    ├── cf-fonts/             ← Public Sans webfont (woff2)
    └── assets/               ← Sneat template assets (CSS/JS/img/vendor)
```

## 📋 Requirements

| Component | Minimum | Notes |
|---|---|---|
| **PHP** | **8.1+** | Demo runs 8.3. Needs the `pdo_mysql` extension (bundled in Laragon / XAMPP / WAMP). |
| **MySQL** | 5.7+ (or MariaDB 10.4+) | Demo runs 8.4. |
| **Web server** | Apache with `mod_rewrite` — or none at all | Laragon, XAMPP, WAMP ship it pre-enabled. PHP's built-in server works via `public/router.php`. |

---

## 🚀 Quick start — Laragon (recommended on Windows)

[Laragon](https://laragon.org/download) gives you Apache + MySQL + PHP in one portable app,
with pretty URLs and automatic virtual hosts already configured.

1. **Install & start**
   - Download the **Full** edition from <https://laragon.org/download> and install it.
   - Click **Start All** — Apache and MySQL both light up green.
   - Need a newer PHP? Laragon Menu → *PHP → Version* — pick **8.1 or newer**.

2. **Unzip the package into Laragon's web root**
   ```
   C:\laragon\www\sneat-auth
   ```
   With Laragon's *Auto Virtual Hosts* (on by default) the app is also reachable at
   `http://sneat-auth.test` — both URLs work.

3. **Import the database**
   - Laragon Menu → **MySQL → phpMyAdmin** (<http://localhost/phpmyadmin>)
     → *Import* → choose `database/schema.sql` → **Go**
   - Or from Laragon's terminal (Menu → *Terminal*):
     ```bash
     mysql -u root < database/schema.sql
     ```
   The script creates the `sneat_auth` database, the `sneat_user` MySQL account
   (password `Sneat#2026Secure`), both tables and the demo admin.

4. **Check `app/config.php`** — pick whichever MySQL account you prefer:
   ```php
   const DB_HOST = '127.0.0.1';
   const DB_NAME = 'sneat_auth';
   const DB_USER = 'sneat_user';        // created by schema.sql …
   const DB_PASS = 'Sneat#2026Secure';  // … or use Laragon's stock root:
   // const DB_USER = 'root';           // stock Laragon root has no password
   // const DB_PASS = '';
   ```
   Nothing else to edit — URLs are auto-detected (docroot, sub-folder or vhost).

5. **Open & sign in**
   ```
   http://sneat-auth.test/public/        (auto virtual host)
   http://localhost/sneat-auth/public/   (plain localhost)
   ```
   Log in with **admin / Admin@123**, or register a brand-new account.

### Laragon tips

- **Pretty URLs** (`/login`, `/dashboard`) work out of the box — Laragon's Apache has
  `mod_rewrite` and `AllowOverride All` enabled, and the shipped `public/.htaccess`
  does the rewriting. Trailing slashes (`/dashboard/`) are handled gracefully too.
- **Prefer Nginx?** Laragon can switch to Nginx, but you'd need to translate the
  rewrite rules from `public/htaccess-for-apache.txt` into an Nginx `location` block.
- **Different MySQL port?** Update `DB_PORT` in `app/config.php` to match.

---

## 🚀 Quick start — XAMPP / WAMP / LAMP

1. **Copy** the `sneat-auth` folder into your web root:
   - XAMPP: `C:\xampp\htdocs\sneat-auth`
   - WAMP: `C:\wamp64\www\sneat-auth`
   - LAMP (Linux): `/var/www/html/sneat-auth`
2. **Start** Apache + MySQL (XAMPP/WAMP control panel, `systemctl start apache2 mysql` on LAMP)
3. **Import the database** — open <http://localhost/phpmyadmin> → *Import* → choose
   `database/schema.sql` → Go.
   The script creates:
   - database `sneat_auth` (utf8mb4)
   - MySQL user `sneat_user` with password `Sneat#2026Secure`
   - tables `users` + `remember_tokens`
   - demo account **admin / Admin@123**
4. **Check credentials** in `app/config.php` (defaults already match `schema.sql`;
   change them if your MySQL root has no password or you use different values)
5. **Open** <http://localhost/sneat-auth/public/> and log in with `admin` / `Admin@123`

---

## 🚀 Quick start — PHP built-in server (no Apache needed)

Works on Windows, macOS and Linux — any folder, no web-server configuration:

```bash
cd sneat-auth
mysql -u root -p < database/schema.sql
php -S localhost:8080 -t public public/router.php
# open http://localhost:8080
```

The included `router.php` maps clean URLs (`/login`, `/register`, `/dashboard`)
to their page scripts and streams static assets untouched.

---

## ⚙️ Configuration (`app/config.php`)

| Constant | Default | Notes |
|---|---|---|
| `DB_HOST / DB_PORT` | `127.0.0.1` / `3306` | MySQL server |
| `DB_NAME` | `sneat_auth` | database name |
| `DB_USER / DB_PASS` | `sneat_user` / `Sneat#2026Secure` | created by `schema.sql` |
| `APP_TIMEZONE` | `Asia/Manila` | PHP display timezone |
| `DB_TIMEZONE` | `+08:00` | keep aligned with `APP_TIMEZONE` |
| `SESSION_IDLE_MAX` | `1800` | auto-logout after 30 min idle |
| `REMEMBER_LIFETIME` | `2592000` | 30-day remember-me |

### Works anywhere — no URL configuration

The app **auto-detects where it is installed**, so you never have to edit
path settings:

| Install type | Example URL | What the app generates |
|---|---|---|
| Document root (vhost / `php -S`) | `http://localhost:8080/login` | `/login`, `/dashboard`, … |
| Laragon auto vhost | `http://sneat-auth.test/public/login` | `/sneat-auth/public/login`, … |
| Sub-folder (XAMPP `htdocs/sneat-auth/`) | `http://localhost/sneat-auth/public/login.php` | `/sneat-auth/public/login`, … |
| The live demo gateway | `…/?XTransformPort=8080` | links keep the gateway query |

- Clean URLs on Apache/Laragon/XAMPP are handled by the shipped `public/.htaccess`
  (`/login` → `login.php`). If your host disables `AllowOverride`, the direct
  `.php` URLs (`login.php`, `register.php`, …) keep working too.
- `app/` and `database/` ship with deny-all `.htaccess` files so source code
  and SQL are never web-readable on Apache.

---

## 🔧 Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page / HTTP 500 on login | PHP version too old — the code needs **PHP 8.1+** (`never` return type, `str_contains`). Switch version in Laragon/XAMPP or upgrade. |
| `Access denied for user 'sneat_user'@'localhost'` | `schema.sql` wasn't imported as root, or the grants failed. Re-run `mysql -u root < database/schema.sql`, or switch `app/config.php` to your local root account. |
| Pretty URLs (`/login`) return 404 | Enable `mod_rewrite` + `AllowOverride All` (already default in Laragon/XAMPP), or use the `.php` URLs directly. |
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL isn't running or listens on a different port — start it (Laragon: *Start All*) and check `DB_PORT`. |
| Redirects back to login right after signing in | Cookies blocked — the app needs session cookies; allow cookies for `localhost` / `*.test`. |
| Wrong timestamps on the dashboard | Align `APP_TIMEZONE` / `DB_TIMEZONE` in `app/config.php`. |

---

## 🔐 Security checklist (already done for you)

- ✅ bcrypt password hashing, never plaintext or MD5
- ✅ PDO prepared statements for every query
- ✅ CSRF tokens with timing-safe comparison
- ✅ Output escaping via `e()`
- ✅ Session cookie hardening + `session_regenerate_id(true)` on privilege change
- ✅ Brute-force throttling (session-based, 5 attempts / 15 min)
- ✅ Remember-me tokens stored hashed, rotated on use, expire server-side
- ✅ Generic login errors (no user-enumeration hints)

## 🧪 Demo account

| Field | Value |
|---|---|
| Username | `admin` |
| Email | `admin@sneat.dev` |
| Password | `Admin@123` |
| Role | `admin` |

## 📄 License

- **Your code** (app logic in `app/`, `public/*.php`, `database/`): free to use, modify, ship.
- **Sneat template assets** (`public/assets/`, `public/cf-fonts/`): subject to the
  [Sneat](https://themeselection.com/sneat-bootstrap-html-admin-template/) license — free for
  personal/open-source use; review ThemeSelection's licensing for commercial projects.

---

Built with PHP 8.3 · MySQL 8.4 · Sneat (Bootstrap 5) · no framework required.
