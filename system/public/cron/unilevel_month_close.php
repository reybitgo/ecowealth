<?php
declare(strict_types=1);

/* ==============================================================
 *  Unilevel month close — web entry (plan §10 Phase 17F)
 *
 *  Lets the same job run from a URL, which is handy for local
 *  testing and for hosts whose cron only calls a URL:
 *
 *      http://localhost/ecowealth/system/cron/unilevel_month_close.php
 *      https://site.com/ecowealth/system/cron/unilevel_month_close.php?key=…
 *
 *  The whole implementation, the access rules (loopback or CRON_KEY),
 *  the lock, the log and every flag live in bin/unilevel_month_close.php
 *  — this file only hands the request to it. Keep it that way: two
 *  copies of the close rules is how a timer and a cron start
 *  disagreeing about which months are closed.
 * ============================================================== */

require dirname(__DIR__, 2) . '/bin/unilevel_month_close.php';
