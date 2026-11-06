<?php
declare(strict_types=1);

/* ==============================================================
 *  Order auto-complete — web entry (plan §5.5, §7 Phase 8)
 *
 *  Lets the same job run from a URL, which is handy for local
 *  testing and for hosts whose cron only calls a URL:
 *
 *      http://localhost/ecowealth/system/cron/order_autocomplete.php
 *      https://site.com/ecowealth/system/cron/order_autocomplete.php?key=…
 *
 *  The whole implementation, the access rules (loopback or CRON_KEY),
 *  the lock, the log and every flag live in bin/order_autocomplete.php —
 *  this file only hands the request to it.
 * ============================================================== */

require dirname(__DIR__, 2) . '/bin/order_autocomplete.php';
