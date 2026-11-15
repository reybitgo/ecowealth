<?php
declare(strict_types=1);

/* ==============================================================
 *  Royalty month close — web entry (plan §10 Phase 19F, §14.10)
 *
 *  Lets the same job run from a URL, which is handy for local
 *  testing and for hosts whose cron only calls a URL:
 *
 *      http://localhost/ecowealth/system/cron/royalty_month_close.php
 *      https://site.com/ecowealth/system/cron/royalty_month_close.php?key=…
 *
 *  The whole implementation, the access rules (loopback or CRON_KEY),
 *  the lock, the log, the doctor and every flag live in
 *  bin/royalty_month_close.php — this file only hands the request to it.
 *  Keep it that way: two copies of the close rules is how a timer and a
 *  cron start disagreeing about which months are closed.
 *
 *  ROYALTY_CLOSE_HTTP tells the shared file that this is the HTTP way
 *  in, so the access rules, the response headers and the 200/500 mapping
 *  apply even when the URL entry is exercised from a shell.
 * ============================================================== */

define('ROYALTY_CLOSE_HTTP', true);

require dirname(__DIR__, 2) . '/bin/royalty_month_close.php';
