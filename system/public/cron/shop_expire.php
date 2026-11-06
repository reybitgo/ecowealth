<?php
declare(strict_types=1);

/* ==============================================================
 *  Shop expiry — web entry (plan §5.4, §7 Phase 7 follow-up)
 *
 *  Lets the same job run from a URL, which is handy for local
 *  testing and for hosts whose cron only calls a URL:
 *
 *      http://localhost/ecowealth/system/cron/shop_expire.php
 *      https://site.com/ecowealth/system/cron/shop_expire.php?key=…
 *
 *  The whole implementation, the access rules (loopback or CRON_KEY),
 *  the lock, the log and every flag live in bin/shop_expire.php — this
 *  file only hands the request to it. Keep it that way: two copies of
 *  the expiry rules is how a timer and a cron start disagreeing.
 * ============================================================== */

require dirname(__DIR__, 2) . '/bin/shop_expire.php';
