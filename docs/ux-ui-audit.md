# UX/UI audit — EcoWealth `system/` application

**Date:** 25 November 2026
**Scope:** every page and view under `system/app/views/` (313 templates), the three layouts (`app_header.php`, `auth_header.php`, `app_footer.php`), the shared partials, the CSS modules that define the shared components (`system/public/assets/css/`), and the behaviour in `system/public/assets/js/app.js`.
**Method:** source review. The layouts, shared partials and the page templates of every module were read in full or in substantial part; the CSS modules were inspected for the component patterns cited; all 313 templates were then swept for recurring patterns (page headings, spacing, tab markup, filter bars, empty states, date/currency formatting, phase references and accessibility attributes).

**What this audit did not do:** it did not click through the running application or take screenshots. Every finding below is reproducible from the source. No data-state, responsive or browser testing was performed.

---

## Summary

| Severity | Count | Meaning |
|---|---:|---|
| **High** | 8 | Wrong or misleading content, a dead control, a consent default, or a broken navigation context. A user can hit these on a normal path. |
| **Medium** | 23 | Inconsistent patterns that make the product feel assembled from parts; friction, extra scanning, or a11y gaps. |
| **Low** | 18 | Polish, copy and consistency clean-up. |

Counts exclude UX-40, which is a process follow-up rather than a separate screen-level finding.

### Fix first

1. **UX-01** — the login "Forgot Password?" link is a JavaScript `alert()` apologising for the demo.
2. **UX-02** — the login page prints working admin credentials for every visitor.
3. **UX-03** — checkout's terms checkbox is pre-ticked (consent by default).
4. **UX-04** — the product page still tells members "checkout opens in Phase 6".
5. **UX-05** — the member record's "Open in tree" button is disabled and cites a phase that has since shipped.
6. **UX-06** — Settings displays internal phase numbers ("Phase 15 · 19B · 20B · 21B") to administrators.
7. **UX-07** — the binary tree view exists but has no member-reachable entry point.
8. **UX-08** — an admin who changes their password is dropped into the member portal layout.

---

## 1. Content, state and correctness

### UX-01 — "Forgot Password?" is a fake link with a `alert()` (High)

**Evidence:** `system/public/login.php:139` — `<a href="javascript:;" onclick="alert('Password reset is not part of this basic demo.');">`.
**Impact:** a primary recovery path, offered next to the password field, does nothing except show a browser alert. Members who forget a password have no route forward, and the alert exposes prototype copy on a production-looking sign-in screen.
**Fix:** either wire the existing `reset.php`/reset flow into a real request page, or remove the link and replace it with support contact copy. Never ship a dead recovery affordance.

### UX-02 — Demo admin credentials are printed on the login page (High)

**Evidence:** `system/public/login.php:80-86` — an always-visible info alert reads "Demo account · Email / username: `admin` · Password: `Admin@123`".
**Impact:** if this build is reachable by anyone other than the developer, it advertises a working administrator account; even in a demo it trains users to expect shared credentials. It also puts a large block ahead of the form.
**Fix:** gate the block behind `APP_DEBUG` (or a `DEMO_MODE` setting) and remove it entirely for any deployed build.

### UX-03 — Terms acceptance is pre-ticked at checkout (High)

**Evidence:** `system/app/views/shop/checkout.php:43` — `$termsChecked = … : true;` and `:238` renders `checked` by default.
**Impact:** the member places an order without an affirmative act of consent. That is a consent-by-default pattern and a legal/traceability weakness for a commerce flow that records a terms version (`$terms_version`).
**Fix:** default to unchecked on a fresh checkout; keep the posted state only after a validation error. Leave the "Place order" button disabled until it is ticked.

### UX-04 — Product page advertises a feature that has shipped as if it has not (High)

**Evidence:** `system/app/views/shop/product/_card.php:103` — "Added items stay in your cart — checkout opens in Phase 6."
**Impact:** every member on every product page reads a stale build note; checkout has existed for many phases. It reads as an unfinished product.
**Fix:** replace with the real next step, e.g. "Added items stay in your cart — continue to checkout when you are ready", linking to `cart`.

### UX-05 — "Open in tree" is disabled and cites a phase that already shipped (High)

**Evidence:** `system/app/views/admin/members/_binary.php:448-465` — an anchor to `genealogy?view=binary&root=…` carries `disabled`/`aria-disabled="true"`, a confirmation dialog whose text says "ships in Phase 22G", and a caption "Tree navigation arrives in Phase 22G."
**Impact:** the binary explorer is implemented and routable (`?view=binary`), yet the one link an administrator would use is presented as unavailable. Worse, the anchor still has an `href`, so the disabled state is only cosmetic — the control contradicts itself.
**Fix:** remove the disabled state and phase copy; make it a normal link. If the tree must stay hidden for some roles, remove the anchor rather than faking a disabled link.

### UX-06 — Settings exposes internal phase numbers (High)

**Evidence:** `system/app/views/admin/settings/index.php:157`, `:294`, `:345` — badges read "Phase 15", "Phase 15 · 19B · 20B · 21B".
**Impact:** internal project bookkeeping is shown to administrators as if it were product information. It has no operational meaning and dates the screen.
**Fix:** delete these badges. If provenance is useful, use a user-facing label such as "Last updated" with a date.

### UX-07 — The binary tree has no discoverable member entry point (High)

**Evidence:** `system/app/views/member/genealogy.php:80` renders the binary view only when `$view === 'binary'`; the sponsor view (`:284` onward) has no link to it. A search for `view=binary` finds links only inside the binary view itself, the registration return URL, and the disabled admin button (UX-05).
**Impact:** members cannot find a major feature. The sidebar item is "Genealogy", which lands on the sponsor tree; nothing suggests a second view exists.
**Fix:** add a visible view switcher in the Genealogy heading ("Sponsor tree" / "Binary tree") using the same pattern as the earnings/commissions tabs, and link to it from the admin member record.

### UX-08 — Admin change-password drops the admin into the member portal (High)

**Evidence:** `system/app/Controllers/ProfileController.php:352` renders `profile/password` with `View::page('member', …)`, unlike every other shared page (`profile`, `wallet`, `payouts`, `checkout`, `orders`), which select `$isAdmin ? 'admin' : 'member'`.
**Impact:** an administrator clicks "Change password" in the back-office sidebar or user menu and the sidebar changes to the member portal, losing their place. The page is the only one in the admin's Account section with this behaviour.
**Fix:** pass the same layout decision as the rest of the shared pages (`$isAdmin ? 'admin' : 'member'`), and give the page a normal page header consistent with Profile.

---

## 2. Layout, spacing and visual hierarchy

### UX-09 — Two different vertical rhythms, applied inconsistently (Medium)

The template uses two spacing steps in parallel: `mb-4`/`g-4` and `mb-6`/`g-6`. Sibling pages in the same portal disagree.

| Page | Page header | Card grid |
|---|---|---|
| `member/dashboard.php:58` | `mb-4` | `g-4` |
| `member/earnings.php:266` | `mb-4` | mixed |
| `member/membership/_header.php:13` | `mb-4` | — |
| `member/genealogy.php:99` | `mb-4` | `g-4` |
| `member/wallet.php:69` | `mb-6` | `g-6` |
| `member/payouts.php:68` | `mb-6` | `g-6` |
| `member/transfer.php:48` | `mb-6` | `g-6` |
| `member/profile.php:63` | `mb-6` | `g-6` |
| `admin/dashboard.php` | `mb-6` | `g-6` |
| `admin/codes/index.php:51` | `mb-4` | `g-4` |
| `admin/audit/index.php:86` | `mb-4` | `g-4` |
| `admin/reports/index.php:86` | `mb-4` | `g-6` |
| `admin/products/index.php` | `mb-6` | `g-6` (stats) |

**Impact:** moving between Dashboard → E-wallet → Membership produces a visible jump in heading-to-content spacing and card gutters. The report and audit pages are visibly tighter than the catalogue pages.
**Fix:** choose one page rhythm (the 24 px `mb-6`/`g-6` is the dominant one in commerce screens) and apply it to the page header, the first row and the gaps between sections. Encode it once in a page-header partial so new pages cannot drift.

### UX-10 — Four stat-card implementations and three heading levels for the same object (Medium)

| Component | CSS | Used by |
|---|---|---|
| `.stat-card` / `.stat-value` | `app.css:99-118` | Admin dashboard |
| `.commission-stat-card` / `.commission-stat-value` | `commissions.css:10` | Commissions, member earnings |
| `.wallet-stat-card` / `.wallet-stat-value` | `wallet.css:10` | Wallets, payouts |
| `.report-kpi-card` / `.report-kpi-value` | `reports.css:10` | Reports, audit |

The markup also changes the value's heading level and size: `<h2>` in `member/transfer/_balance.php:25` and `member/payouts/_balance.php:42`, `<h3>` in `member/wallet/_buckets.php:87`, `<h4>` almost everywhere else. Some cards use `.stat-value`, others plain `h4`; some put the label above the value, some beside an icon.

**Impact:** four slightly different card paddings, number sizes and icon treatments appear across the product; the same "total money" figure changes size depending on which page you are on.
**Fix:** keep one stat-card partial with a `size`/`tone` option (or one CSS class family), and standardise the value on a single heading level. Delete the three copies.

### UX-11 — Four tab treatments and two placements (Medium)

| Page | Markup |
|---|---|
| Admin commissions, member earnings | `nav nav-pills mb-4 commission-tabs` (`admin/commissions/index.php:355`, `member/earnings.php:311`) |
| Admin payouts, admin wallets | `nav nav-pills mb-4 wallet-tabs` (`admin/payouts/_tabs.php:19`, `admin/wallets/_tabs.php:18`) |
| Admin activations | `nav nav-pills card-header-pills` inside the card header (`admin/activations/_tabs.php:24`) |
| Admin impersonations | `nav nav-tabs card-header-tabs` (`admin/impersonations/index.php:111`) |
| Admin settings | Anchor pills in a sticky card, not page tabs (`admin/settings/_tabs.php:32`) |

**Impact:** tabs that change the page's dataset (commissions, payouts) and tabs that merely scroll the page (settings) look alike; one page uses underline tabs while every other multi-view page uses pills; one puts pills inside the card header while the rest put them above the card.
**Fix:** define two patterns: (a) **view tabs** — pills above the card, changing the dataset; (b) **section nav** — sticky anchors, explicitly styled as navigation. Convert impersonations and activations to the matching one.

### UX-12 — Six long tabs on the member earnings page (Medium)

**Evidence:** `system/app/views/member/earnings.php:311-374` — "Package commissions", "Repeat-purchase bonus", "Royalty", "Team Harvest", "General Harvest", "Binary bonus".
**Impact:** on a phone the tab row wraps into three lines before any content; the labels are long and two of them ("Team Harvest", "General Harvest") mean nothing to a new member without the page copy. The admin ledger has the same six-tab structure (`admin/commissions/index.php:355`).
**Fix:** group the secondary streams (Royalty, Team Harvest, General Harvest, Binary) under a "More statements" overflow, or use a select on small screens. Keep Package commissions and Repeat-purchase as the primary two.

### UX-13 — "Change password" has no page header (Medium)

**Evidence:** `system/app/views/profile/password.php:16` starts with a card whose title is `<h5 class="mb-1">Change password</h5>`; every other member page opens with a page-level `<h4>` + description, then cards.
**Impact:** the page reads like a widget dropped into the layout; its title is smaller than the card titles on Profile, and the "Cancel" link goes to the dashboard (not back to Profile), so the page has no consistent entry/exit.
**Fix:** add the standard page header ("Change password" + one-line description) and make Cancel return to `profile`, where the user came from.

### UX-14 — Reports is a 15+ section page with no section navigation (Medium)

**Evidence:** `system/app/views/admin/reports/index.php` stacks at least 15 captures (`_kpis`, `_health`, `_orders`, `_sales`, `_top_products`, `_commissions`, `_payouts`, `_unilevel`, `_unilevel_levels`, `_unilevel_products`, `_unilevel_periods`, `_royalty`, `_royalty_ranks`, `_royalty_periods`, `_team_harvest`, …). There is no tab strip, jump menu or table of contents.
**Impact:** the page is a very long scroll; reaching "Royalty" or "Binary" means scanning past everything else. Settings solved the same problem with sticky anchor pills; Reports did not.
**Fix:** reuse the settings section-nav pattern (sticky pills with the section names), or split the report families into tabs like Commissions.

---

## 3. Forms, filters and actions

### UX-15 — Filter buttons disagree on emphasis and label (Medium)

The same "apply the filters" action is styled `btn-primary` on some pages and `btn-label-primary` on others, and labelled five different ways:

| Label | Style | Page |
|---|---|---|
| `Filter` | `btn-primary` | Admin orders `admin/orders/index.php:91`, audit `admin/audit/index.php:165`, commissions `admin/commissions/index.php:475`, member earnings `member/earnings.php:426` |
| `Filter` | `btn-label-primary` | Products `admin/products/index.php:89`, packages `:79`, activations `admin/activations/index.php:87`, wallets `admin/wallets/index.php:120`, payouts `admin/payouts/index.php:98`, members `admin/members/_filters.php:74` |
| `Search` | `btn-primary` | Storefront `shop/catalog.php:57`; admin wallets top-ups tab uses `Search` at `admin/wallets/index.php:215` while other tabs there use `Filter` |
| `Apply filters` | `btn-primary` | Codes `admin/codes/index.php:228` |
| `Apply window` | `btn-primary` | Reports `admin/reports/index.php:120` |
| `Apply` | `btn-sm btn-label-secondary` | Pagination no-script fallback `partials/pagination.php:83` |

**Impact:** the strongest colour in the toolbar appears on different controls from page to page, and the vocabulary changes. Users cannot build a reliable "the blue button applies my filter" habit.
**Fix:** standardise on one label ("Filter") and one style for the primary action inside a filter bar; use `Apply` only where the control is not filtering (e.g. a date window, if you keep that concept).

### UX-16 — "Clear" means five different things (Medium)

**Evidence:** plain `Clear` (`admin/products/index.php`, `admin/orders/index.php`, `admin/members/_filters.php`), `Clear filters` (`partials/wallet_statement.php:113`), an icon-only `×` (`shop/catalog.php:59`), `Start over` (`shop/checkout.php` draft notice), `Last 30 days` as the reset for Reports (`admin/reports/index.php:121`).
**Impact:** the escape hatch from a filtered view has no consistent name or position; on the storefront it is an unlabelled icon.
**Fix:** use "Clear filters" everywhere, with the icon+label combination; for Reports keep "Last 30 days" as a separate preset and add "Clear filters" beside it.

### UX-17 — Filter forms alternate between visible labels and `aria-label` only (Medium)

- **Labels visible:** admin orders, member orders, wallet statement, audit, commissions, reports.
- **No visible labels:** admin members (six controls), admin activations, products, packages, wallets, payouts. These rely on `aria-label` and placeholder text.

**Evidence:** `admin/members/_filters.php:42-68` renders five selects whose only visible text is the current option ("All statuses", "All packages", "Team Harvest: all", "General Harvest: all", "Binary placement: all"). `admin/products/index.php:62-78` and `admin/packages/index.php:60-76` do the same.
**Impact:** once a non-default option is chosen, the control no longer says what it filters ("Active" could be status, package state, or account state). The six-control member filter is also very dense in one row.
**Fix:** adopt the labelled field pattern from the order/wallet filters (`form-label` above each control) at least for the member directory and other multi-filter bars; put the label inside the select's default option only when a visible label is impossible.

### UX-18 — Required fields are marked three different ways, and one marker has no style (Medium)

- Register: `<span class="required">*</span>` (`auth/register_form.php:237, 254, 295, 311, 472`).
- Checkout: `<span class="text-danger">*</span>` (`shop/checkout.php:130, 146, 190`; `:238` terms has no marker).
- Everything else: `required` on the input but **no marker at all** — admin codes, products, packages, member transfer, payouts, profile name/address, admin placement, S-Login.
- Some fields instead say `(optional)` (codes expiry, `admin/codes/index.php:140`).

No `.required` rule exists in the application's CSS (custom or vendor), so the register-page asterisks are unstyled and visually inconsistent with checkout's red ones.
**Impact:** users cannot tell which fields they must fill until the browser or server rejects them; the strongest form in the product (registration) marks requirements differently from the second strongest (checkout).
**Fix:** add a single required marker component (red asterisk with an accessible "required" label), style `.required` (or replace it), and use it on every required field. Mark optional fields only when they are the minority.

### UX-19 — Same-role buttons use different emphasis (Medium)

| Action | Page | Class |
|---|---|---|
| Export CSV | Codes `admin/codes/index.php:56` | `btn-label-primary` |
| Export CSV | Commissions `admin/commissions/index.php` | `btn-label-secondary` |
| Export CSV | Member earnings `member/earnings.php` | `btn-label-secondary` |
| Export CSV | Reports `admin/reports/index.php` | `btn-label-secondary` |
| Open statement | E-wallet `member/wallet.php` | `btn-label-primary` |
| Open statement | Payouts `member/payouts.php` | `btn-label-primary` |
| "View statement" | Dashboard binary tile `member/dashboard/_binary.php` | `btn-label-primary` |
| "Open e-wallet" | Earnings `member/earnings.php:306` | `btn-primary` |

**Impact:** export is sometimes the most prominent button on the page and sometimes a quiet one; the same destination link changes weight between pages.
**Fix:** define one hierarchy: primary = the page's main forward action; secondary (label) = exports, cross-links and utility navigation. Apply it consistently.

---

## 4. Tables and empty states

### UX-20 — Empty states come in three visual languages (Medium)

1. **Illustrated empty state** (avatar, heading, explanation, optional CTA) via `partials/empty_state.php` — used by member orders, cart, wallet statement, member directory, most main tables.
2. **Bare grey row** — `No accounts yet.` (`admin/dashboard/_recent.php:41`), `No orders yet.` (`admin/dashboard/_order_status.php:45`), `No product paid a repeat-purchase bonus in this window.` (`admin/reports/_unilevel_products.php:68`), and most report tables.
3. **Info alert / paragraph** — e.g. the no-commissions message in `member/dashboard/_earnings.php` and some sub-cards.

**Impact:** the same "nothing here" condition is loud on one page and nearly invisible on the next; on dashboard widgets the muted one-liner is easy to miss and looks like a rendering bug.
**Fix:** use the shared partial for every list that can be empty. Add a `compact` variant for dashboard/report cards so the illustrated version can scale down without dominating a small widget.

### UX-21 — Member directory empty state is one column short (Medium)

**Evidence:** `system/app/views/admin/members/index.php:91-101` defines **10** columns (Member, Package, Sponsor, Status, Rank, Harvest, GH, Wallet, Joined, Actions). `system/app/views/admin/members/_rows.php:28` renders the empty row with `colspan="9"`.
**Impact:** with no members (or a filter that matches none), the empty-state panel stops one column short and leaves a visible blank cell at the right edge of the table.
**Fix:** change to `colspan="10"`, or derive the count from a shared constant so the header and the empty row cannot drift again.

### UX-22 — Member directory empty-state text ignores one of its own filters (Medium)

**Evidence:** `admin/members/_rows.php:17-21` computes `$hasFilters` from `q`, `status`, `package`, `harvest` and `board` — but not `placement`, although `admin/members/_filters.php:63` exposes a "Binary placement" filter.
**Impact:** filtering by "Floating nodes" or "No binary node" and getting no rows shows the first-run message "No members yet — members appear here as soon as they register," which is false.
**Fix:** include `placement` in `$hasFilters` (better: build `$hasFilters` from the same defaults array the controller uses).

### UX-23 — "GH" is an unexplained abbreviation in the member table (Low)

**Evidence:** `system/app/views/admin/members/index.php:95` — column header `GH`; the cells show `N / 10` (`_rows.php:102-107`).
**Impact:** only someone who knows "General Harvest" can interpret the column, and the `N / 10` value has no unit or tooltip. The neighbouring "Harvest" column at least has a `title`.
**Fix:** label the column "General Harvest" (or "GH levels" with a `title`/tooltip and a header note), and give the cell a `title` describing what N/10 counts.

### UX-24 — Numeric alignment and header `scope` drift (Low)

- `admin/commissions/index.php` prints the "Lvl" column as `<th scope="col">Lvl</th>` with left-aligned numeric cells, while amounts are `text-end`.
- Several tables omit `scope="col"` on their headers (`admin/members/index.php`, `admin/activations/index.php`, the dashboard widget tables), while others include it (`admin/products/index.php`, `admin/orders/index.php`).
- Some tables use `table-sm`, others only `table`; report tables use a separate `report-table` treatment.

**Impact:** small, but columns of numbers that do not align make comparison slower, and inconsistent header semantics hurt screen-reader table navigation.
**Fix:** add `scope="col"` to every `<th>` in list tables, right-align all numeric columns (including "Lvl" if you keep it numeric), and choose one table density per context (list vs report).

---

## 5. Navigation and information architecture

### UX-25 — The notification bell has no member content and always says "0 new" (Medium)

**Evidence:** `app/layout/app_header.php:218-236` always renders the bell and a `count($nav_notes) . ' new'` badge. `app/Core/View.php:80` defaults `nav_notes` to `[]`, and only `AdminDashboardController.php:55` ever supplies notes.
**Impact:** members see a bell that only ever opens "Nothing new right now" with a "0 new" badge. Admins see content only on the dashboard; on every other page it is empty. The control promises a notification centre that does not exist.
**Fix:** hide the bell when there is nothing to show, or populate it from real events (new order, activation decision, payout decision, commission credited). At minimum, do not render the "0 new" badge.

### UX-26 — The global search searches only one destination (Medium)

**Evidence:** `app_header.php:59` sets the placeholder to "Search products…" (admin) or "Search the shop…" (member); `app_header.php:182-186` passes `data-search-url`; `app.js:139, 297` navigates to that URL with `?q=`.
**Impact:** a search field in the global navbar reads as "search the app", but it searches products/shop only and takes the user out of the current module (e.g. away from the payout queue). On admin pages other than products it silently jumps to the product list.
**Fix:** either scope the label explicitly with the current context ("Search products…"), which is already the admin placeholder, or make it a true command palette (products, members, orders, codes) with grouped results.

### UX-27 — Admin sidebar mixes personal and back-office destinations under near-identical names (Medium)

**Evidence:** `app/layout/nav.php:89-129` — Finance contains "E-wallet monitor", "My e-wallet", "Payouts", "My payouts"; System contains "My profile" and "Change password"; Membership contains "My membership", "Register", "My genealogy" next to "Members", "Activations", "Codes".
**Impact:** "Payouts" (the approval queue) and "My payouts" (the admin's own withdrawals) differ by one word and sit two rows apart; on a phone the sidebar collapses and the distinction is easy to miss.
**Fix:** group the admin's personal destinations into one "My account" section at the bottom (profile, wallet, payouts, membership, genealogy, password), separate from the operational sections. Keep "Payouts" for the queue.

### UX-28 — Two genealogy experiences with different chrome and no switcher (Medium)

**Evidence:** `member/genealogy.php` renders two distinct pages under one route. The binary view uses `<h4>Binary Tree</h4>` at `:101`, `mb-4` headings, and `btn-outline-secondary` / `btn-sm` controls (`:118-188`); outline buttons elsewhere are limited to the cart quantity steppers, while page-level actions use `btn-primary`/`btn-label-*`. The sponsor view uses `<h4>Genealogy</h4>` at `:288` and the standard card/table language.
**Impact:** switching views (once a link exists, see UX-07) changes heading, spacing, button style and even the page title. The two views feel like two different products.
**Fix:** one page header ("Genealogy") with a view switcher; move the binary-only controls into a toolbar card; replace `btn-outline-secondary` with the standard `btn-label-secondary`/`btn-label-primary`.

---

## 6. Language, dates, money and terminology

### UX-29 — Five date formats in one product (Medium)

| Format | Helper / call | Example use |
|---|---|---|
| `M j, Y g:i A` | `format_datetime()` (`helpers.php:473`) | Wallet ledger, order dates, member joined |
| `j M Y` | `format_date()` (`helpers.php:486`) | Escrow release dates |
| `M Y` | `date('M Y', …)` | Profile "Member since" (`member/profile.php`) |
| `F Y` | `date('F Y', …)` | Earnings "Active for November 2026" (`member/dashboard/_earnings.php`) |
| `D, M j, Y` | `date('D, M j, Y')` | Admin dashboard welcome (`admin/dashboard.php`) |

**Impact:** the same kind of fact is written different ways depending on the page ("Nov 1, 2026 2:05 PM" vs "1 Nov 2026" vs "November 2026"), which slows scanning and looks unpolished in tables where dates sit in adjacent columns.
**Fix:** standardise on `format_datetime()` for timestamps and `format_date()` for dates; use a dedicated month-label helper for periods. Remove raw `date()` calls from views.

### UX-30 — Brand spelling alternates between "EcoWealth" and "Eco-wealth" (Medium)

**Evidence:** `APP_NAME` is `EcoWealth` (`app/config.php:58`); the login heading says "Welcome to Eco-wealth!" (`public/login.php:63`); the logo's `alt` text is "Eco-wealth" (`app/layout/logo.php:20`) and is read on every page.
**Impact:** the brand is spelled two ways in the two places users see first (the sign-in screen and the logo's accessible name).
**Fix:** pick "EcoWealth" (the configured name) and use it in the login heading, logo alt, footer and legal copy.

### UX-31 — The "product binary" program is given five names (Medium)

Evidence in user-visible labels:
- "Product binary" — dashboard tile (`member/dashboard/_binary.php`), settings/commissions copy.
- "Binary bonus" — member earnings tab (`member/earnings.php:364`).
- "Binary Tree" — page heading (`member/genealogy.php:101`), "Binary placement tree" card.
- "Binary placement" — admin member filter (`admin/members/_filters.php:63`) and registration block.
- "Binary" — admin package/product columns and filters.

**Impact:** a member reading the dashboard, earnings and genealogy could reasonably conclude these are different programs.
**Fix:** define a small glossary and use one term per concept: **Product binary** (the payout program), **Binary tree** (the placement view), **Binary placement** (the act/setting). Keep "Binary bonus" only if it is officially a separate stream; otherwise rename the tab.

### UX-32 — "Repeat bonus" vs "Repeat-purchase bonus" (Low)

**Evidence:** the catalogue badge says "Up to N% repeat bonus" (`partials/product_card.php:81`), while every statement, ledger and settings card says "Repeat-purchase bonus".
**Impact:** minor, but the catalogue is the first place a member meets the program and it uses a shorthand used almost nowhere else.
**Fix:** use "repeat-purchase bonus" on the card, or add the short form consistently after first mention.

### UX-33 — Login copy is generic template text (Low)

**Evidence:** `public/login.php:64-67` — "Please sign-in to your account and start the adventure"; "Remember Me" is title-cased; the heading is an emoji wave.
**Impact:** the tone does not match the operational vocabulary of the rest of the product ("Sign in to manage your membership, orders and e-wallet").
**Fix:** rewrite in the product's voice; sentence-case "Remember me".

---

## 7. Accessibility

### UX-34 — Pinch-zoom is disabled on every app and auth page (Medium)

**Evidence:** `app/layout/app_header.php:80` and `app/layout/auth_header.php:22` — `user-scalable=no, minimum-scale=1.0, maximum-scale=1.0`. (The 503 error page does not disable zoom, so the behaviour is also inconsistent.)
**Impact:** low-vision users cannot zoom on the screens where they must read dense financial tables and forms; this is a WCAG 1.4.4 (Resize text) failure.
**Fix:** use `width=device-width, initial-scale=1` and allow zoom.

### UX-35 — The login password toggle is not a control (Medium)

**Evidence:** `public/login.php:119-121` — the show/hide password affordance is `<span class="input-group-text cursor-pointer">` containing an icon. It has no `button` role, no `tabindex`, no `aria-label` and no pressed state, so it cannot be reached or announced by assistive technology. The password input also has `aria-describedby="password"` (`:117`) pointing at its own `id`, which describes nothing.
**Impact:** keyboard and screen-reader users cannot toggle the password; the broken `aria-describedby` adds noise.
**Fix:** make it a `<button type="button">` with `aria-label="Show password"` and `aria-pressed`, and remove the self-referencing `aria-describedby`.

### UX-36 — Login inputs lack browser-level validation and autocomplete (Low)

**Evidence:** `public/login.php` — the username input has no `autocomplete="username"` and neither field has `required`; the page relies on a server round-trip to report empties.
**Impact:** password managers fill less reliably, and the browser cannot help with an empty submit, unlike every other form in the app.
**Fix:** add `autocomplete="username"` / `autocomplete="current-password"` and `required`.

### UX-37 — The quick-view modal is not named (Low)

**Evidence:** `shop/catalog.php` — `#productQuickView` has `.modal-title` but no `aria-labelledby`, and `aria-hidden="true"` is the only state attribute. (Bootstrap moves focus, so this is a naming/announcement gap, not a focus trap.)
**Impact:** screen readers announce a generic "dialog" without the product name.
**Fix:** add `aria-labelledby="quickViewTitle"` and keep the title updated (the script already sets its text).

### UX-38 — Small icon-only controls below touch-target guidance (Low)

**Evidence:** row actions use `btn btn-sm btn-icon` (e.g. `admin/members/_rows.php:113-137`), the tree toolbar uses `btn-sm` icon controls (`member/genealogy.php:150-175`), and the wallet copy affordance is `btn btn-icon` (`member/dashboard.php`). Many carry good `title`/`aria-label`, which is a strength, but the rendered hit area is well below the 44×44 px guidance.
**Impact:** on phones and for motor-impaired users, adjacent destructive/suspend actions are easy to mis-tap.
**Fix:** raise the touch area to at least 44×44 px on small screens (padding or a min-size utility) and keep more space between destructive actions.

### UX-39 — Table header semantics are inconsistent (Low)

**Evidence:** `admin/products/index.php` and `admin/orders/index.php` use `scope="col"` on every header; `admin/members/index.php:91-101`, `admin/activations/index.php:99-108` and the dashboard widget tables omit it.
**Impact:** screen readers lose column associations in the tables operators use most.
**Fix:** add `scope="col"` consistently (see UX-24).

### What is already accessible and should be kept

- Live regions are used deliberately (`aria-live="polite"`, `role="status"`) for background refreshes and status text.
- Destructive actions use a confirmation dialog with an explicit consequence sentence, a destructive tone, and a typed reason where one is needed.
- Icon-only actions generally carry both `title` and `aria-label`.
- The binary tree has a semantic no-JS list fallback, and the SVG is marked `aria-hidden`.
- The cart's quantity controls, pagination and per-page selector all have labels.

---

## 8. Developer concerns that leak into the UI

### UX-40 — Internal phase vocabulary appears in user-facing copy (process follow-up; fixes in UX-04, UX-05, UX-06)

Three separate screens show build-phase numbers to end users: the product page, the admin member binary panel, and Settings. Treat this as one content-governance issue rather than three copy fixes: add a lint/review rule that rejects `Phase \d+` in `app/views/`.

### UX-41 — `flash_render()` is called inside page templates as well as in the layout (Low)

**Evidence:** the layout renders `partials/flash.php` (which calls `flash_render()`) before the page; 14 page templates call it again, e.g. `member/dashboard.php:56`, `admin/members/index.php:48`, `admin/commissions/index.php:317`, `admin/codes/index.php:50`.
**Impact:** currently harmless because `flash_render()` drains the session messages on the first call, so the page call returns an empty string. But the second call is dead code, and any future change to render order would duplicate or swallow messages silently.
**Fix:** remove the page-level calls and keep one render point in the layout; document the invariant in the helper.

### UX-42 — The admin welcome copy describes a different dashboard (Low)

**Evidence:** `admin/dashboard.php:103` — "Here is the latest picture of your catalogue, stock and team." The dashboard's KPI row shows accounts, products, inventory value and packages; "team" is not among them.
**Impact:** the greeting sets an expectation the screen does not meet.
**Fix:** match the copy to the cards, or add the missing team metric.

### UX-43 — A duplicated referral widget on three member pages (Low)

**Evidence:** the referral link appears on the dashboard hero (`member/dashboard.php`), the Membership page referral card (`member/membership.php`) and the Genealogy team-chain card (`member/genealogy.php`). Copy/labels differ slightly ("Your referral link" vs "Referral link", "Code" vs "Referral code").
**Impact:** three implementations to keep in sync; the labels already differ.
**Fix:** extract one `partials/referral_link.php` and use it in all three places.

---

## 9. Small polish

| ID | Issue | Evidence | Fix |
|---|---|---|---|
| UX-44 | The notification badge shows "0 new" when there is nothing | `app_header.php:226` | Hide the count when zero |
| UX-45 | "Change password" cancel goes to the dashboard, not the page the user came from | `profile/password.php:76` | Return to `profile` (or use the referrer) |
| UX-46 | The settings heading badge repeats the section list ("General, payments, payouts, fulfilment, commissions & transfers") next to a tab strip that already lists them | `admin/settings/index.php:139` | Remove the badge |
| UX-47 | "Storefront" from the admin sidebar opens the shop inside the back-office layout | `nav.php` (`admin.storefront`), `ShopController.php:36` | Consider a member-layout preview or label it "Shop (admin view)" |
| UX-48 | Dashboard and earnings repeat the same "View statement"/"Open statement"/"Open e-wallet" destination with three labels | `member/dashboard/_binary.php`, `member/wallet.php`, `member/earnings.php:306` | One label, one style |
| UX-49 | Success/error flash alerts use `mb-6` while in-page stale/info banners use `mb-4`, so stacked notices have uneven gaps | `helpers.php:309`, page banners | One notice spacing token |
| UX-50 | Storefront search and admin product search use different visual patterns (input group with button vs plain search + Filter) | `shop/catalog.php:43-62`, `admin/products/index.php:55-80` | Keep the labelled filter bar pattern; make the storefront search a labelled field too |

---

## 10. Recommended sequence

**Week 1 — trust and correctness (UX-01 to UX-08)**
Remove the fake reset, gate demo credentials, uncheck terms, delete phase copy, enable the tree link, remove Settings phase badges, add the binary view switcher, fix the admin password layout. These are small diffs with high user impact.

**Week 2 — shared components (UX-09, 10, 11, 15, 17, 18, 20)**
Build four shared pieces and migrate the pages: page header (title, description, actions, spacing), stat card, filter bar (labelled fields, one primary action, one clear action), and empty state (default + compact). This removes most of the visual drift in one pass.

**Week 3 — data display and language (UX-21 to UX-33)**
Fix the member-table colspan and filter detection, standardise dates through the helpers, settle the binary/repeat-purchase glossary, and rewrite the login/brand copy.

**Week 4 — accessibility and polish (UX-34 to UX-50)**
Re-enable zoom, fix the password toggle and modal labelling, raise touch targets, add `scope="col"`, then clear the small copy and spacing items.

---

## Appendix A — Coverage

| Area | Templates reviewed |
|---|---|
| Layouts | `app_header.php`, `app_footer.php`, `nav.php`, `sidebar.php`, `auth_header.php`, `auth_footer.php`, `logo.php` |
| Shared partials | `empty_state`, `pagination`, `flash`, `error_panel`, `wallet_statement`, `product_card`, `ladder_toolbar`, `impersonation_banner`, `order_timeline` |
| Member | Dashboard (all tiles), wallet, transfer, payouts, membership, genealogy (both views), earnings (all tabs), profile, password |
| Shop | Catalogue, product, cart, checkout, orders, order, packages (the printable invoice was not evaluated as a print layout) |
| Admin | Dashboard, products, packages, orders, members (list + detail), activations, codes, commissions (six tabs), wallets, payouts, reports, settings, audit, impersonations |
| Auth | Login, register, reset, S-Login console |
| CSS | `app.css`, `commissions.css`, `orders.css`, `wallet.css`, `reports.css` (rules for the components cited); the remaining modules were not needed for these findings |
| JS (behaviour relevant to UX) | `app.js` (search, copy, pagination), `app-cart.js`, `app-live.js` |

## Appendix B — Limitations

- No browser session was run, so issues that depend on rendered width, data volume or third-party JS (charts, d3 tree, Bootstrap modals) are assessed from markup/CSS only.
- Contrast was not measured; no colour-contrast claims are made.
- Some findings depend on configuration (demo credentials, binary stream enabled/disabled); the audit notes where a finding is conditional.
- The 313 templates include partials and live-feed fragments; page-level findings cite the page template, but the same fragment may render inside a live refresh.
- The printable invoice and the standalone database tools (`install.php`, `reset.php`) were outside the screen-level review.

## Appendix C — Page map

Which findings land on which page template. "—" means no page-specific finding beyond the global ones (spacing, empty states, dates) already listed.

| Area | Page | Findings |
|---|---|---|
| Auth | Login | UX-01, UX-02, UX-30, UX-33, UX-35, UX-36 |
| Auth | Register | UX-18 |
| Auth | S-Login console | — |
| Auth | 403 / 404 / 500 / 503 | — |
| Member | Dashboard | UX-09, UX-10, UX-19, UX-20, UX-43 |
| Member | E-wallet | UX-09, UX-10, UX-16, UX-19 |
| Member | Send funds | UX-09, UX-10, UX-18 |
| Member | Payouts | UX-09, UX-10, UX-18, UX-19 |
| Member | Membership | UX-09, UX-43 |
| Member | Genealogy (sponsor) | UX-07, UX-09, UX-28, UX-43 |
| Member | Genealogy (binary) | UX-07, UX-28, UX-31, UX-38 |
| Member | Earnings | UX-09, UX-11, UX-12, UX-19, UX-31 |
| Member | Profile | UX-09, UX-18 |
| Member | Change password | UX-08, UX-13, UX-18, UX-45 |
| Shop | Catalogue | UX-15, UX-16, UX-50 |
| Shop | Product detail | UX-04, UX-32 |
| Shop | Cart | UX-20 |
| Shop | Checkout | UX-03, UX-16, UX-18 |
| Shop | My orders | UX-15 |
| Shop | Order detail | — |
| Shop | Packages | — |
| Admin | Dashboard | UX-09, UX-10, UX-20, UX-24, UX-39, UX-42 |
| Admin | Products | UX-09, UX-15, UX-17, UX-18 |
| Admin | Product form | UX-18 |
| Admin | Packages | UX-15, UX-17 |
| Admin | Package form | UX-18 |
| Admin | Orders | UX-15, UX-17 |
| Admin | Order detail | — |
| Admin | Members | UX-17, UX-21, UX-22, UX-23, UX-39 |
| Admin | Member detail | UX-05 |
| Admin | Activations | UX-11, UX-39 |
| Admin | Codes | UX-09, UX-15, UX-18, UX-19 |
| Admin | Commissions | UX-09, UX-11, UX-12, UX-15, UX-24 |
| Admin | E-wallet monitor | UX-10, UX-11, UX-15, UX-17 |
| Admin | Wallet detail | — |
| Admin | Payouts | UX-10, UX-11, UX-15, UX-17 |
| Admin | Reports | UX-09, UX-14, UX-15, UX-20 |
| Admin | Settings | UX-06, UX-11, UX-18, UX-46 |
| Admin | Audit log | UX-09, UX-15, UX-20 |
| Admin | Super-login audit | UX-11 |
