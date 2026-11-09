<?php
declare(strict_types=1);

/* ==============================================================
 *  Transfer-limit reset — web entry (plan §5.8, §7 Phase 12)
 *
 *  Lets the same job run from a URL, which is handy for local
 *  testing and for hosts whose cron only calls a URL:
 *
 *      http://localhost/ecowealth/system/cron/reset_transfer_limits.php
 *      https://site.com/ecowealth/system/cron/reset_transfer_limits.php?key=…
 *
 *  The whole implementation, the access rules (loopback or CRON_KEY),
 *  the lock, the log and every flag live in bin/reset_transfer_limits.php
 *  — this file only hands the request to it.
 * ============================================================== */

require dirname(__DIR__, 2) . '/bin/reset_transfer_limits.php';
