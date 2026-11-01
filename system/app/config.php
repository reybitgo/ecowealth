<?php

declare(strict_types=1);

/* ==============================================================
 *  Sneat Auth — Application Configuration
 *  Basic Login & Registration system (PHP 8 + MySQL)
 * ============================================================== */

/* ---------- Database (MySQL) ---------- */
const DB_HOST    = '127.0.0.1'; // sql102.infinityfree.com
const DB_PORT    = 3306;
const DB_NAME    = 'if0_42820471_ew1_db';
const DB_USER    = 'if0_42820471';
const DB_PASS    = 'zM0t8q6kS6au';
const DB_CHARSET = 'utf8mb4';

/* MySQL session time zone (Asia/Manila, UTC+8) */
const DB_TIMEZONE = '+08:00';

/* ---------- Application ---------- */
const APP_NAME    = 'Eco-wealth';
const APP_TAGLINE = 'Login & Registration — PHP + MySQL';

/* PHP display timezone (Asia/Manila) */
const APP_TIMEZONE = 'Asia/Manila';

/* ---------- URL routing -------------------------------------------------------
 * Nothing to configure here. The app auto-detects where it is installed:
 *
 *   • Document root (vhost / php -S)  →  /login, /register, /dashboard
 *   • Sub-folder (e.g. XAMPP htdocs)  →  /sneat-auth/public/login …
 *   • This demo's gateway             →  links keep ?XTransformPort=…
 *
 * Clean URLs on Apache are enabled by public/.htaccess (shipped).
 * ----------------------------------------------------------------------------- */

/* ---------- Sessions ---------- */
const SESSION_NAME     = 'sneat_auth_sid';
const SESSION_IDLE_MAX = 30 * 60;   // 30 minutes of inactivity

/* ---------- "Remember me" ---------- */
const REMEMBER_COOKIE   = 'sneat_remember';
const REMEMBER_LIFETIME = 60 * 60 * 24 * 30;   // 30 days
