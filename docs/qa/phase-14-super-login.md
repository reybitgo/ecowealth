# Phase 14 QA — Super-login

| | |
|---|---|
| **Phase** | 14 — the superadmin S-Login console, URL-token member sessions, Exit/TTL, the member-layout banner and the impersonation audit |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §3.4, §3.7, §4.4, §7 Phase 14, Appendix B (`impersonation_log`) |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `superadmin / Super@123` · `admin / Admin@123` · `juan`, `maria`, `peter` / `Demo@1234` |
| **Checks** | 8 (QA14-01 … QA14-08) |
| **Time** | 25–35 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-14/` |

## What this phase is meant to prove

A superadmin can open any active member's portal **exactly as that member sees it**, without a password,
without disturbing the superadmin's own signed-in session, and with a durable audit trail. The member tab
is keyed by a random token in the URL — not a cookie — and the database holds only the token's SHA-256
hash, so a stolen database dump cannot re-open a session.

Five rules matter most:

1. **The URL is the key.** `POST /slogin` answers with `302 /dashboard?imp=<64 hex>`. Every app URL rendered
   inside that tab carries the token (links, forms, background refreshes, redirects). Static assets,
   uploaded files and shareable referral links never do.
2. **The superadmin's own session stays intact.** No cookie is issued for the member session; the
   superadmin's `ecowealth_sid` cookie is never read on an `?imp=` request. After Exit — or in another
   tab — `/admin` still opens as the superadmin.
3. **The session ends on its own.** An 8-hour TTL, a different IP or user-agent, a deleted audit row or a
   suspended target all kill the session, mark the row `expired` and bounce the tab to `/slogin`.
4. **Only active members can be opened.** Staff accounts are refused, and so are suspended/deactivated
   members. Five failed attempts for one target in 15 minutes rate-limit that target.
5. **Every session is audited.** `impersonation_log` records the superadmin, the member, the IP, the
   user-agent, the start, the expiry and how it ended (`logged_out` on Exit/Sign out, `expired` on TTL or
   binding failure). The raw token is never stored or shown.

**Already proven automatically:**

- P14-T1 … P14-T11 (the plan's table) plus the extra cases in `ImpersonationTest` / `ImpersonationViewTest`:
  hashed-only audit rows, the target rules, the six-attempt rate limit, tampering (`tampered`,
  `missing_log`), TTL, IP and user-agent changes, target suspension after opening, a deleted log row,
  `bind()` refusals, the console picker, the banner/Exit form, URL token propagation and the audit screen.
  The suite is **675 cases**; `SLoginLiveTest` adds six of them for the console's `admin.slogin` live surface
  (the revision bumps on open/exit/expiry, the feed's two cards and the 200/304 contract).
- The HTTP smoke script walks a real session over HTTP: console, `POST /slogin` for `juan`, dashboard
  banner, a token-bearing member link, Exit, dead-token replay, TTL expiry, user-agent expiry, the
  admin/member/guest guards, an admin target, six rate-limited attempts, a tampered token and the audit
  page. **174 checks** (P14-T13 is the console's X-Live feed and its 304 revalidation).
- `php bin\wallet_reconcile.php` is untouched by Phase 14 and still exits 0; the phase moves no money.

> **Before you start:** Phases 1–13 signed off, the demo tree seeded (`database/seeds/005_demo_tree.sql`)
> and migration `018_impersonation.sql` applied. The automated suites must be green:
> `php tests\run.php` → **675 cases**, `powershell -NoProfile -File tests\smoke.ps1` → **174 checks**.
>
> Use a private browser window for the superadmin checks so your ordinary member session (if any) does not
> confuse the "own session survives" step. The S-Login tab and the superadmin tab are different surfaces
> of the same browser; keep both open side by side for QA14-02 / QA14-03.
>
> The screenshots named in the *Evidence* rows were optional for this pass. The `automated:` citations are
> green from the PHP suite and the HTTP smoke run; add screenshots if your sign-off requires them.

---

## A. Opening a member session

### QA14-01 — The console opens `juan` through a URL token

| | |
|---|---|
| **Steps** | 1. Sign in as `superadmin` at `/login`. 2. Open **S-Login console** in the **System** section of the sidebar (or `/slogin`). 3. Type `juan` (or pick him from the datalist) and click **Open member session**. 4. Watch the address bar and the page banner. |
| **Expected** | The console shows the member form, the security notes, **Active sessions** and **Recent sessions**. Submitting lands on `/dashboard?imp=<64 hex>` as **juan**: the member sidebar (Dashboard, Shop, Cart, Orders, Membership, Earnings, Wallet, Payouts), juan's own wallet shortfall/figures, and a persistent yellow banner reading **“Viewing as @juan. This is a super-login session opened by @superadmin … Exit member session”**. No `ecowealth_imp` cookie is created (DevTools → Application → Cookies). The row in `impersonation_log` is `active`, `target_username = juan`, `nonce_hash` is 64 characters and is **not** the URL token. |
| **Evidence** | `phase-14/console.png`, `phase-14/impersonated-dashboard.png` |

### QA14-02 — Every link in the tab keeps the token

| | |
|---|---|
| **Steps** | 1. From the impersonated dashboard, click **Shop**, then **My orders**, then **E-wallet**, then **Payouts**. 2. On any page, submit a harmless form (for example, add a product to the cart, then change its quantity). 3. Watch the address bar of each page and the form's redirect. |
| **Expected** | Every URL carries `?imp=<same 64-hex token>` and the banner stays visible on every page. A form POST redirects back to a page that still has the token. Member data is juan's (not the superadmin's). Background refreshes keep working (the live region updates without losing the banner). |
| **Evidence** | `phase-14/token-navigation.png` |

## B. Ending the session

### QA14-03 — Exit returns to the console and kills the token

| | |
|---|---|
| **Steps** | 1. In the impersonated tab, click **Exit member session** in the banner. 2. Confirm the console appears and says the session was exited. 3. Copy the old `…/dashboard?imp=<token>` URL and open it again in the same tab. 4. In a second tab signed in as the superadmin, open `/admin`. |
| **Expected** | Exit lands on `/slogin?exited=1` with **“You exited the member session.”** and the impersonation row becomes `logged_out`. Re-opening the old URL redirects to `/slogin?expired=1` (“That super-login session has ended.”) and never shows juan's pages. The superadmin's own `/admin` still opens — their cookie session was never destroyed. The audit trail shows the session under **Logged out**. |
| **Evidence** | `phase-14/exit.png`, `phase-14/audit.png` |

### QA14-04 — The 8-hour TTL ends the session

| | |
|---|---|
| **Steps** | 1. Open a fresh session for `maria`. 2. In phpMyAdmin (or the SQL console), run:<br>`UPDATE impersonation_log SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE target_username = 'maria' AND status = 'active';` 3. Reload the impersonated tab. |
| **Expected** | The tab is bounced to `/slogin?expired=1`; the row is `expired`; the audit page's **Expired** tab lists it. The old token is dead on every later request. |
| **Evidence** | `phase-14/expired.png` |

### QA14-05 — A different browser or network ends the session

| | |
|---|---|
| **Steps** | 1. Open a fresh session for `peter` in Chrome. 2. Copy the URL. 3. Open it in a **different browser** (or a different Chrome profile / with a changed User-Agent), or use a proxy/VPN to change the source IP. |
| **Expected** | The first request with a different user-agent (or IP) is refused: the tab lands on `/slogin?expired=1`, the row becomes `expired`, and the original tab is dead on its next request too. The audit shows the IP and device the session was opened from. |
| **Evidence** | `phase-14/ua-mismatch.png` |

### QA14-06 — Rendered URLs do not leak the token

| | |
|---|---|
| **Steps** | 1. While impersonating, open the **Dashboard** and use **Copy referral link** (or inspect the referral link in the page source). 2. Inspect any product image / stylesheet URL. 3. Open the audit page as the superadmin and search the rendered HTML for the token. |
| **Expected** | The referral link and static asset URLs do **not** contain `imp=`. The audit page never renders the raw token or even the stored hash. Only same-app navigational URLs carry the token. |
| **Evidence** | `phase-14/no-token-leak.png` |

## C. Guards, refusals and the audit

### QA14-07 — Guards and target refusals

| | |
|---|---|
| **Steps** | 1. While signed in as `admin` (not superadmin), open `/slogin`. 2. Sign out, then open `/slogin` as a guest. 3. As the superadmin, try `admin` as the target. 4. Try a suspended member (suspend `sarah` temporarily from `/admin/members/…` if you need one), then restore her. |
| **Expected** | The plain admin gets the branded **403 Access denied** page; the guest is redirected to `/login`; an audit list still opens for an admin (`/admin/impersonations`). The admin target is refused with “S-Login is only available for member accounts.”; the suspended target with “That member account is suspended or deactivated.” Neither writes an `impersonation_log` row, and each refusal is a `slogin.denied` audit entry. |
| **Evidence** | `phase-14/403.png`, `phase-14/target-refused.png` |

### QA14-08 — Rate limit and the audit trail

| | |
|---|---|
| **Steps** | 1. As the superadmin, try the same **nonexistent** username six times in a row (e.g. `qa_no_such_member`). 2. Read the sixth message. 3. Open `/slogin` → **Audit trail** (`/admin/impersonations`). 4. Filter by status and search by superadmin/member/IP/device. 5. Run `SELECT id, superadmin_name, target_username, nonce_hash, status, ip, expires_at FROM impersonation_log;`. |
| **Expected** | The first five attempts say “No member with that username was found.”; the sixth says “Too many S-Login attempts for that member. Please wait 15 minutes and try again.” No session row was created, and six `slogin.denied` audit entries exist (five `not_found`, one `rate_limited`). The audit page shows the four stat cards, the status tabs, the filter form and one row per session with superadmin, member, IP, human-readable device, start, expiry and status. The raw URL token appears nowhere in the page or the database — only a 64-character `nonce_hash`. |
| **Evidence** | `phase-14/rate-limit.png`, `phase-14/audit-filters.png` |

---

## Clean-up after the manual pass

Sign out of the superadmin session. Delete the test sessions you created if you do not want them in the
development audit:

```sql
DELETE FROM audit_log WHERE action LIKE 'slogin.%';
DELETE FROM impersonation_log;
```

The demo accounts are unchanged by this phase; no wallet or order data is touched by S-Login itself.

## Sign-off criteria

- All eight checks pass; the screenshots (or the automated citations) are attached.
- `php tests\run.php` → **669 / 669**; `powershell -NoProfile -File tests\smoke.ps1` → **173 / 173**.
- The audit trail has no raw token and every session has a terminal status (or is visibly active).
- The superadmin's own session is still signed in after the Exit check.
