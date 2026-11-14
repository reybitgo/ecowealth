# EcoWealth admin quick guide

This is the one-page-per-area guide for the back office. It assumes you are signed in as an
**admin** (or **superadmin**) at `http://localhost/ecowealth/system/`. The member portal is the
other half of the same account; admins can shop, hold a wallet and withdraw like any member, with
one exception: **you cannot verify or reject your own order** (the self-review guard).

> The full runbook — backups, cron, reconciliation and incidents — is
> [`runbook.md`](runbook.md). Daily finance checks are in §1 there.

---

## 1. Signing in and the dashboard

| Item | What it tells you |
|---|---|
| **Sales today / Sales 30 days** | paid orders only; the same numbers as the reports desk |
| **Orders needing action** | payment review, packing, ready-to-ship and shipped orders |
| **Commissions · 30 days** | awards paid in the window plus the all-time total |
| **Repeat bonus (30 d)** | repeat-purchase awards paid in the window, with the members and recipients they reached |
| **Wallet float** | total member money and the withdrawable portion |
| **Pending repeat bonuses** | repeat-purchase money **earned but not yet in a wallet** — a separate liability, not part of the float |
| **Held for payout** | money promised to open withdrawal requests |
| **Orders by status / Top products** | the lifecycle and the best sellers in the last 30 days |
| **Catalogue and stock cards** | the older operational view, kept on the same screen |

The cards refresh themselves while the tab is open; the page does not need a reload. A red “page may
be out of date” banner means another admin changed something while you were typing — reload when
you are ready.

---

## 2. Products

**Admin → Products**

- **New product**: name, price, stock, optional SKU and image, status. The slug is generated once and
  kept, so renaming never breaks a link.
- **Inactive** hides a product from the shop and blocks new cart additions; it never rewrites an
  order that already contains it.
- **Delete** is refused while an order or a package still references the product. Deactivate it
  instead. A product that has already paid a repeat-purchase award can never be deleted.
- Stock is the physical count. The shop shows `stock − reserved`; reserved units belong to unpaid
  orders. Shipping deducts the physical stock.

### The repeat-purchase ladder card (Phase 17B)

A product pays a **repeat-purchase bonus** to the buyer's sponsor chain on every paid order. The whole
configuration lives on the product form, in the **Unilevel repeat-purchase bonus** card:

| Field | What it does |
|---|---|
| **Point value (P.V.)** (*Pricing & stock*) | The per-unit value the ladder is applied to when the base is *Auto* or *P.V.*. `0` means "not set". |
| **Bonus base** | **Auto** — the P.V. when it is above 0, otherwise the retail price; **P.V.** — refuse to save without one; **Retail price** — the shop price. |
| **Levels 1–10** | One percentage per sponsor level. Level 1 is the direct sponsor, level 10 the tenth upline; there is no separate direct/indirect split. |
| **Total / per-unit amounts** | Re-read as you type (and server-rendered with JavaScript off): the ladder's total and what each level pays on one unit. |
| **Repeat-purchase bonus for this product** | The per-product switch. A new product starts **off** with the reference ladder pre-filled; a product that existed before 17A reads `0%` everywhere. |

Rules the form enforces: a rate is `0–100` with up to three decimals; the ten levels may not add up to
more than **100%**; switching the product on with every level at `0` is refused; a *P.V.* base needs a
P.V. above `0`. **Load reference ladder** fills 5/5/5/3/3/2/2/2/1/1 (**29%**); **Clear** empties it.
Every save that changes the ladder writes one `product.unilevel.update` audit entry with the
before/after values — a save that changes nothing writes nothing. The product list badge reads
**Repeat bonus 29%** when the switch is on with a ladder, **Repeat bonus 0%** when it is on with an
empty ladder (a configuration error you can see), and nothing when it is off; `?unilevel=on|off`
filters the list.

> The global half lives on **Settings → Commissions → Repeat-purchase bonus** (§8). **Both** the global
> master and the product switch must be on before an order can pay.

---

## 3. Packages

**Admin → Packages**

A package is the associate entry bundle: price, product value, direct referral bonus, the level 2–10
matrix and the contents list.

- The **direct referral bonus** is level 1 and is paid once to the sponsor.
- **Levels 2–10** pay each eligible upline the amount for that level, up to the global depth on
  **Settings → Commissions**.
- **Contents** are the products the member receives; they are a fulfilment list, not a stock move at
  activation.
- Deactivating a package stops new activations and code sales; existing members keep it.

---

## 4. Members and activation

**Admin → Members** and **Admin → Activations**

| Task | Where |
|---|---|
| Find a member | Members: search by username, name, email or sponsor; filter by status/role |
| Suspend / restore | Member page → status action (a suspended member cannot sign in) |
| Review a manual activation | Activations: open the proof, approve or reject with a reason |
| See the sponsor chain | Member page → genealogy link; the chain is the sponsor chain only (no binary legs) |

Approving an activation:
1. sets the member active and their package,
2. pays the direct and indirect commissions **inside the same transaction**,
3. stamps the activation as processed; a second approval is refused.

A rejection returns the member to pending with the reason stored. If a commission row is *skipped*,
the recipient was not an active package holder; the level is consumed (no compression) and no money
moved — that is visible on **Commissions**.

---

## 5. Registration codes

**Admin → Codes**

- Generate a batch for one package: quantity, price per code, expiry date. Codes use the
  unambiguous `ABCD-EFGH-JKLM` format and are case-insensitive.
- **Export CSV** honours the current filter; each download is audited.
- Status is `unused`, `used` (with the member and time) or `expired`. An expired code is flipped by
  validation and can never be redeemed.
- A code is single-use: redemption is an atomic conditional update, so two tabs cannot share it.
- Codes are sold offline; record the buyer outside the app if you need a paper trail.

---

## 6. Orders and payments

**Admin → Orders** / **Admin → Orders → one order**

| Status | What it means | What you do |
|---|---|---|
| `pending` | waiting for the member to pay | nothing; the expiry job cancels it when the window closes |
| `payment_review` | a proof was uploaded | verify or reject |
| `payment_failed` | you rejected the proof | the member has the correction window to re-upload |
| `paid` | money received; stock committed | move it to packing |
| `packing` → `ready_to_ship` | being prepared | ship it with courier + tracking |
| `shipped` | on the way | mark delivered when the courier confirms |
| `delivered` | received by the member | the member confirms, or the auto-complete job closes it |
| `completed` | closed | nothing |
| `cancelled` | closed with stock released | refunds are handled outside this build |

**Verifying a proof**: the amount and the reference must both match; a reference already used by
another order is refused. A rejection releases the reserved stock and opens the correction window.
The invoice is frozen when the order becomes paid — reprinting returns the same snapshot, never a
recalculated one.

Order actions are recorded in the order timeline with the actor and time.

**The repeat-purchase card** sits under the payment panel. It reads the order's own stamp:

| The card says | Meaning |
|---|---|
| "Paid orders earn the repeat-purchase bonus. This order is `pending`." | no money has been received, so nothing is due |
| "This order was paid before the repeat-purchase stream was switched on…" | the order was paid before the feature was enabled; it is never paid retroactively |
| "No bonus was due: no product on this order had an active ladder…" | processed, but this product or chain pays nothing |
| a table of L1–L10 rows with a total | the award this order **earned** — released, pending, cancelled or a mix |

Each row shows the level, recipient, product, base, rate, amount and status, and the footer reads
**Awarded … · Released … · Pending …**. The buyer is never a recipient. A skipped level keeps its number
(no compression); a voided row shows how much was recovered and, in red, the shortfall. The escrow states
are explicit:

| Order | Badge | Rows | Wallet link |
|---|---|---|---|
| paid, nothing released yet | **₱25.00 pending release** | **Pending release** with the due date, or "after delivery + N days" | none — no money has moved |
| released | **₱25.00 released** | **Credited** with the release date | wallet icon to the movement |
| cancelled before release | **No bonus released** | **Cancelled before release** — "no money moved" | none |
| paid, mixed (legacy) | both totals | pending rows and credited rows together | only on the credited rows |

The rows stay visible after a cancellation even though the order is no longer paid: the audit trail is
the point. This card is read-only — there is no "release now" button and no way to edit an award.

---

## 7. E-wallet, transfers and payouts

**Admin → E-wallet monitor** shows every member's two buckets, the float, the top-up history, the
transfers tab and the full ledger. The KPI row adds **Pending repeat bonuses** — repeat-purchase money
that is earned and outside both buckets, shown as a separate liability (**not** part of the float). A
member's wallet page repeats the line as **Repeat-purchase pending release (not in wallet balance)**.
**Admin → Wallets → a member** is where an **admin top-up** is
made: it credits the non-withdrawable bucket, writes one ledger row and one audit row.

- Members move money to each other on **Wallet → Send funds**, with password confirmation, a fee and
  daily/weekly limits set on **Settings → Transfers**.
- The fee is credited to the primary admin account.
- **Admin → Payouts** is the withdrawal queue:
  - **Approve** records the decision; the money was already held when the member asked.
  - **Complete** settles it; it never debits a second time.
  - **Reject** refunds the held amount exactly once and stores the reason.
  - You cannot process your own payout.
- **My payouts** is your own member view, rendered in the back office.

The Reports **Finance health** card is the fastest way to confirm every transfer and payout still has
its ledger legs.

---

## 8. Settings

**Admin → Settings** has eight cards; each saves on its own and every change is written to the audit
log with a before/after per key.

| Card | What it controls |
|---|---|
| **General** | Site name (title and header), tagline, maintenance mode (503 for visitors, admins bypass) |
| **Shop** | The shop switch (closing it blocks new cart/checkout but not pending payments) and **Enable Cancel of Order** (off by default) |
| **Receiving accounts** | GCash / Maya / bank details shown at checkout and on the order page |
| **Payout methods** | Minimum withdrawal, USDT rate, each channel's fee and switch, network fees |
| **Payment windows** | Payment deadline, correction window, proof attempts per order, expiry kill switch |
| **Fulfilment & invoices** | Auto-complete days and switch, seller name/TIN/phone/address on invoices |
| **Commissions** | Direct switch, unilevel switch and depth, active-sponsor rule, free registration; the *Repeat-purchase bonus* block: global switch, upline depth, monthly personal-purchase minimum, Active rule, **release delay after delivery**, **release repeat-purchase bonuses**, reversal rule |
| **Transfers** | Fee, minimum, daily and weekly caps (0 = unlimited) |

Rules that the page enforces: a weekly limit cannot be below the daily limit, a minimum cannot
exceed the daily limit, percentages stop at 100, and money keeps the precision of its column.
A failed save shows the field errors and writes nothing.

---

## 9. Reports and exports

**Admin → Reports** (default window: the last 30 days; both dates inclusive, up to 366 days)

| Report | Tells you |
|---|---|
| **Sales by day** | paid orders and value per day, with the method breakdown and the average order |
| **Commissions by level** | awards, amounts and skips per level and leg, with all-time totals |
| **Repeat-purchase bonus** | the product ladder's released, pending, skipped, cancelled and voided money, with the unrecovered shortfall |
| **Repeat-purchase by level** | awards, amounts and skips for levels 1–10 (one ladder, no direct/indirect split) |
| **Repeat-purchase by product** | what each product's ladder cost in the window — the first place to check a margin |
| **Closed months** | the Active snapshots the month close wrote, with members, qualified count and award total |
| **Payout register** | requests by status and method, the amount held now, completed value and fees |
| **Registration-code revenue** | codes issued/redeemed/expired per package and their face/realised value |
| **Finance health** | the seven invariants; a red row lists examples and links to the fixing screen |
| **Orders by status** | the lifecycle at a glance |
| **Top products** | best sellers in the window |

**Export CSV** offers one file per report for the selected window. Every download is audited.

**The repeat-purchase ledger** is **Finance → Commissions → Repeat-purchase bonus**
(`/admin/commissions?ledger=repeat`). Its eight tiles are **Total credited** (released money),
**Pending release** (earned, not in a wallet), **This month**, **Awards**, **Recipients**, **Skipped**,
**Cancelled before release** and **Unrecovered**. Its filters are recipient/buyer/product/order (`q`),
level 1–10, status (**pending**, **credited**, **skipped**, **voided**, **cancelled**), product and date.
The rows carry the same states as the order card: **Pending release** with its due date and no wallet
movement, **Cancelled before release** with "no money moved", **Credited** with a wallet link, and
**Skipped** with the reason. A skipped row is an audit note, never money: it shows what was due and why
it was not paid.

**Unrecovered means `voided` only** — the part of a *released* award the wallet could not return after a
post-release reversal. A **cancelled** award was never paid, so it is not a shortfall and never appears in
that tile.

**Export CSV** writes **15 columns**, in this order: `Award, Date, Order, Buyer, Recipient, Product,
Level, Base, Rate %, Amount, Status, Reason, Recovered, Hold days, Released at`. A pending row has an
empty `Released at` and its snapshotted `Hold days`; a cancelled row has an empty `Released at`,
`Recovered` `0.00` and its reason; a credited row carries the release date. Every row links to its order,
and a credited row links to the recipient's wallet statement filtered to that order. The **Closed months**
card is a history only — closing a month is an operations job, not a button here. The package commission
ledger is the other tab and is unchanged.

---

## 10. Audit log

**Admin → Audit log** is the append-only record of admin and money actions: settings changes with
their before/after, wallet top-ups, exports, member status changes, activation decisions, payout
decisions, transfers, code generation/redemption and S-Login starts, refusals and exits.

- Filter by action, subject, actor/action text and date; paginate like any table. The repeat-purchase vocabulary is `product.unilevel.update` (a ladder or base change, with before/after), `unilevel.export` (a repeat ledger CSV) and `report.export` with `type = unilevel`.
- The row shows who (with role), what, on which subject, the compact detail and the IP.
- Order transitions also have their own timeline on the order page; S-Login sessions have their own
  console. The audit desk links to both.
- There is no edit or delete button — by design.

---

## 11. Super-login (superadmin only)

**Admin → S-Login console** opens a member's portal exactly as they see it — through a URL token in a
separate tab, never a cookie, so your own session is untouched.

1. Pick or type the member's username and open the session.
2. The member layout shows a banner: **Viewing as @user — Exit member session**.
3. Every link in that tab keeps the token; closing the tab ends access at the 8-hour TTL.
4. **Exit** marks the session `logged_out` and returns to the console.
5. The console and the audit list show the session, its IP, device, TTL and end state. A raw token is
   never stored.

Only active members can be impersonated (never another admin), and every refusal is audited. The
session is a member session: you cannot reach admin pages from inside it.

---

## 12. Routine calendar

| When | Task |
|---|---|
| Every morning | Finance health, reconciliation command, orders needing action, open payouts (§1 of the runbook) |
| As they arrive | Verify proofs, approve activations, ship paid orders, answer payout requests |
| Weekly | Back up and copy the dump off the machine; review the audit log for unexpected settings changes; check the cron logs; run `php bin\unilevel_release.php --doctor` (pending, due, undelivered, `cancelled_due` 0) |
| Monthly | Restore drill into a scratch schema; rotate admin passwords; review stock and best sellers; run `php bin\unilevel_month_close.php --doctor` before the 1st and confirm the previous month closed (the Active rule reads it) |
| Before any upgrade | Backup, then follow [`deploy.md`](deploy.md); after it, Finance health must be All clear |

---

## 13. Safety rules

1. **Never** edit `users.ewallet_balance` or delete ledger rows with SQL. Corrections are new
   movements, written by a service, and audited.
2. **Never** verify a payment you cannot match on amount *and* reference.
3. **Never** process your own order or payout; the app refuses it, and the audit trail would show any
   attempt to work around it.
4. **Never** share a registration code you did not sell or issue; codes are single-use and the used
   member is recorded.
5. **Never** commit `config.local.php`, a database dump, or the `uploads/` contents.
6. **Never hand-edit an award row** (`unilevel_awards`) or a `unilevel_periods` snapshot. A pending
   award becomes money only through the release job; a status or `ledger_id` changed by SQL breaks the
   reconciliation and is unauditable. If a bonus looks wrong, pause releases, run
   `php bin\unilevel_release.php --doctor` and `php bin\wallet_reconcile.php`, and escalate.
7. When in doubt, take a backup first and read the audit trail before changing anything.

---

## 14. What members see (repeat-purchase bonus)

The member-facing half of the repeat-purchase stream is read-only. Nothing a member can click pays,
changes or claims a bonus; every number comes from the same engine and ledger the admin screens read
(Phase 17E).

| Surface | What it shows | Where |
|---|---|---|
| **Product page panel** | all ten levels with the per-unit rate and amount on the product's bonus base, the "up to" total, the monthly minimum, and the sentence that the member's own purchase does not pay them; it says the level income is **earned when the order is paid** and **released after the return window** | any product whose switch is on |
| **Catalogue badge** | *Up to 29% repeat bonus* on a card whose ladder is on | Shop |
| **Checkout preview** | "Up to ₱145.00 is earned by the members above you when this order is paid. It is released to their e-wallets after the return window." — the whole cart's ladder, one line — then the Team chain pivot | Checkout |
| **Order page card** | the order's total that is **reserved** (or **was released**) for the members above the buyer, with the estimated release date once the order is delivered; never which member received which amount | `/orders/{id}` |
| **Earnings → Repeat-purchase bonus** | the member's own awards, a **Pending release** tile and a **Next release** line, pending/cancelled notes, the Team chain by level and a two-clock Active panel | `/earnings?ledger=repeat` |
| **Dashboard tile** | **Credited**, **Pending release** and **Total earned** from the Team chain, plus the current Active status | `/dashboard` |
| **Membership paragraph** | the same status, in one sentence, with the credited + pending split | `/membership` |

The tiles, the panel and the preview are **absent** while the global repeat-purchase master is off or
the product's ladder is off/empty. The earnings tab and its export stay reachable either way, because a
disabled stream can still have history. Every member surface that describes the repeat-purchase bonus
calls the reader's downline their **Team chain**; "sponsor chain" is admin wording and is never shown
to a member.

### The two clocks a member asks about

The flag that decides **this month** comes from **last month's** purchases; this month's purchases
decide next month. The threshold is **Settings → Commissions → Active threshold** (₱500 by default).

- A member who buys ₱2,000 on the 2nd does **not** become Active that month — they become Active for
  the *next* month.
- A member with **no flag yet** (a fresh account, or a month that has not been closed) counts as
  **Active**. After the first monthly close every account gets an explicit row.
- A level that falls on someone who is **not Active** is *skipped*: no money, no compression, the next
  upline keeps its own level number.

The member's own purchases never pay them a bonus; they only keep them Active so the income their
Team chain produces can be credited. This is the sentence to use in support replies.

### Support checklist: "my repeat-purchase bonus is zero"

1. **Global switch** — `unilevel_enabled` on **Settings → Commissions**.
2. **Product switch and rates** — the product's ladder card; a zero rate at a level means that level
   pays nothing.
3. **Was the order paid?** — a `pending` order pays nothing. On a paid order the award is written
   **Pending release**; the wallet is credited only after the order is **delivered** and the award's
   hold window has passed (see §15).
4. **Chain depth** — the buyer's sponsor chain may be shorter than the level in question.
5. **Active flag** — the monthly rule above; the admin ledger's **Skipped** rows show the reason.
6. **Membership** — an inactive/suspended recipient is skipped, exactly like a not-Active one.

The admin ledger is **Finance → Commissions → Repeat-purchase bonus** (§9). Copy rules for support:
say **repeat-purchase bonus**, say **Team chain** (never "sponsor chain" to a member), say **up to**,
say **earned when the order is paid and released after the return window** (never "credited at
checkout"), state the **monthly minimum**, and never call it a rebate or cashback — the member's own
purchase pays the members above them, not the member. For a "where is my bonus?" question, open
**Admin → Orders → the order** and read the card: **Pending release** names the due date,
**Cancelled before release** means the order was cancelled, and **Credited** links to the movement.

---

## 15. Repeat-purchase escrow: award, release and cancellation (Phase 18)

Since the escrow change, the repeat-purchase bonus is **earned at payment but paid at release**. This
is the section to read before answering any "my bonus is missing" or "why is my payout limited?"
question.

### The money path

| Step | What happens | Where to see it |
|---|---|---|
| Order **paid** | a **`pending`** award row per level; **no wallet movement, no ledger row** | `/admin/orders/{id}` → **Repeat-purchase bonus**: **₱X pending release** |
| Order **delivered** + the award's **hold** | the release job (hourly) credits the upline's **withdrawable** wallet once, sets `credited_at` and links `ledger_id` | the row becomes **Credited**; the upline's statement shows **Repeat-purchase bonus** |
| Order **cancelled before release** | the pending awards become **`cancelled`**: no debit, no reversal row, **no shortfall** | the card badge reads **No bonus released**; the rows read **Cancelled before release** |
| Order cancelled **after release** | legacy path: `credited` rows are voided and debited only as far as the withdrawable balance allows; the rest is a shortfall | **Unrecovered** on the ledger |

`shop_orders.unilevel_total` is the **awarded** total — `pending + credited`. It does not fall when a
pending award is released (no money “arrives” twice) and it does fall to `0` when an order is cancelled
before release.

### Order cancellation is off by default

**Admin → Settings → Shop → Enable Cancel of Order** ships **off**. While it is off:

- neither a member nor an administrator can cancel an order;
- no e-wallet refund is issued and no `refund` ledger row is written;
- no repeat-purchase award can become `cancelled` or `voided` through a cancellation — pending awards
  simply stay pending and release after delivery + the hold.

Turn the switch on only for a special case that genuinely needs an order cancelled; the normal
operation leaves it off. Automatic expiry of unpaid orders is **not** affected: it keeps its own
**Expire unpaid orders automatically** switch on the Payment windows card, and an expired unpaid order
never produced an award or a refund.

### The two settings

Both live on **Admin → Settings → Commissions → Repeat-purchase bonus**:

| Setting | Default | Label and hint |
|---|---:|---|
| `unilevel_hold_days` | `7` | **Release delay after delivery** — “Days after delivery before a repeat-purchase bonus becomes withdrawable. 0 releases at delivery. Applies to new awards only; each award keeps the value it was given at payment.” Range 0–365. |
| `unilevel_release_enabled` | `1` | **Release repeat-purchase bonuses** — “Off pauses the release job; pending bonuses stay pending. Cancelling an order still cancels its pending awards; order cancellation itself is off by default (Shop → Enable Cancel of Order).” |

The two hints that changed with them:

- `unilevel_enabled` (master): “Off hides the offer and stops new awards. Bonuses already pending still
  release unless **Release repeat-purchase bonuses** is also off.”
- `unilevel_reversal_enabled`: “On: cancelling an order after its bonus was released claws the bonus
  back. Off: a released bonus stays. Pending bonuses are always cancelled when an order is cancelled;
  order cancellation itself is off by default (Shop → Enable Cancel of Order).”

**The hold is snapshotted per award.** Changing the setting moves no existing award's promised date; it
applies to awards created after the change. `0` releases at delivery (useful for a no-returns business
and for QA).

### The release job and its doctor

`bin/unilevel_release.php` runs hourly at `:20` (see [`cron.md` §6](cron.md)) and has its own switch.
When it is off, runs exit 0 and log a `WARN`; pending awards keep their dates and release on the first
run after it is switched back on. When **Enable Cancel of Order** is on, cancelling an order still
cancels its pending awards while the release job is paused; with the cancellation switch off (the
default) there is no cancellation to make.

The quickest health check, from the admin desk or with a terminal:

```
http://localhost/ecowealth/system/cron/unilevel_release.php?doctor=1
php bin\unilevel_release.php --doctor
php bin\unilevel_release.php --dry-run
```

The doctor prints the PHP/MySQL clocks, the two settings, the pending count/amount, the oldest pending
award, what is due now, what is waiting for delivery, pending awards on `shipped` orders older than 30
days, `cancelled_due` (**must be 0**), the lock and the log folder. `--dry-run` lists the orders and
awards the next run would credit and changes nothing; `--order=ID` tries one order under the normal due
rule (it is a diagnostic, not a force). There is **no “release now” button** on any screen.

**An undelivered order never releases.** Its award stays `Pending release` indefinitely; that is the
delivery-based return policy working. Deliver the order, or turn on **Enable Cancel of Order** for the
special case and cancel it — that cancellation moves no money.

### No retroactive lock

Awards that were already `credited` when the escrow shipped stay credited and withdrawable. They are
not re-opened, not moved back to pending and not held. Only awards created after the change have a hold
window. Do not “fix” an older award by editing its status.

### The member's side: “Total funds” and the payout reminder

- The **wallet** page adds **Pending release** and **Total funds** (= wallet balance + pending release,
  presentation only — not a third bucket, not spendable) and hides both lines when nothing is pending.
- The **dashboard** repeat tile shows **Credited / Pending release / Total earned**.
- The **payout** page shows `Pending release` in the balance card, an informational (non-blocking)
  alert on the request form, and names the pending amount in the over-limit refusal and in the success
  flash. There is no modal and no checkbox: the only limit is still the withdrawable balance.
- **Never withdrawable.** Pending money is not spendable in the shop and is never part of the payout
  maximum. If a member asks why their payout is capped, read the pending figure on `/payouts` first.

Support summary: the bonus is **earned when the order is paid**, **released after the return window**,
and **never paid on a cancelled order**.
