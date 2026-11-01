# Cron Jobs — AltasLive

PHP CLI scripts in this folder run on the server's crontab (Laragon/Linux). All paths below assume
the app lives at `/var/www/html/altaslive` — adjust if deployed elsewhere.

## Installed jobs

```bash
# Daily midnight reset: daily pair counters, cap expiry, DFI payout
0 0 * * * /usr/bin/php /var/www/html/altaslive/cron/midnight_reset.php >> /var/www/html/altaslive/cron/logs/reset_$(date +\%Y-\%m).log 2>&1

# Fund-transfer limits: daily reset, weekly (Monday) reset inside
0 0 * * * /usr/bin/php /var/www/html/altaslive/cron/fund_transfer_limit_reset.php >> /var/www/html/altaslive/cron/logs/transfer_$(date +\%Y-\%m).log 2>&1

# Shop order expiry: every 15 minutes (see caveat below)
*/15 * * * * /usr/bin/php /var/www/html/altaslive/cron/shop_expiry.php >> /var/www/html/altaslive/cron/logs/shop_expiry_$(date +\%Y-\%m).log 2>&1
```

### `midnight_reset.php`

Runs `ShopOrder::expireOverdue()`-style resets for the MLM core — daily pair counters back to
zero, expired capped members moved to `perminact`, and the DFI nightly payout. Logs monthly.

### `fund_transfer_limit_reset.php`

Resets `ewallet_sent_today` daily and `ewallet_sent_this_week` on Mondays. Logs monthly.

### `shop_expiry.php`

Runs two timer legs against `shop_orders`:

1. `expireOverdue()` — cancels `pending`/`payment_failed` orders past
   `payment_deadline`/`correction_deadline`; reason `payment_expired`; lines released. **Never**
   touches `payment_review` (a submitted proof is exempt from expiry).
2. `autoComplete()` — auto-completes `delivered` orders past `completion_due_at` with no open
   refund.

Cadence is `*/15` minutes; deadline granularity is hours-scale, so a quarter-hour tick is
sufficient. Every expiry moves through `ShopOrder::transition()` (never a raw UPDATE) and emits
`shop_order_events` rows with `actor_type='system'`, `source='timer'`.

Stale holds are NOT auto-expired here — the hold owner decides; a runaway hold is closed by an
admin as `cancelled` (reason `customer_unreachable`).

## Logging

Each script appends to `cron/logs/<name>_YYYY-MM.log` (one file per month). Ensure `cron/logs/`
is writable by the PHP user:

```bash
mkdir -p cron/logs && chown www-data:www-data cron/logs && chmod 755 cron/logs
```

## Manual / HTTP fallback

`midnight_reset.php` is additionally reachable via HTTP for Hostinger-style cron panels. It is
key-protected in the root doc, restricted to localhost by `.htaccess`, and uses the `$cronKey`
variable at the top of the file.

## Removing `test_cron.php`

`test_cron.php` is a dev-only smoke script (localhost-restricted by `.htaccess`) and must be
deleted before production deploy, along with `reset.php` in the web root.

## Production checklist

- [ ] Confirm the crontab lines above point at the real deployment path.
- [ ] Confirm `cron/logs/` exists and is writable.
- [ ] Remove `test_cron.php` and `reset.php` from the web root.
- [ ] Verify shop expiry with a deliberately overdue pending order and check the log line.

---

*Last reviewed: 2026-10-09 (shop expiry cron added).*
