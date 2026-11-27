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
| **Commissions** | Direct switch, unilevel switch and depth, active-sponsor rule, free registration; the *Repeat-purchase bonus* block: global switch, upline depth, monthly personal-purchase minimum, Active rule, **release delay after delivery**, **release repeat-purchase bonuses**, reversal rule; the *Royalty bonus* block: earning and payment switches, the five-rank ladder, quotas and rates (§16); the *Team Harvest bonus* block: the enable switch, the qualification gate, the payable depth and the ten flat amounts (§17) |
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
| **Finance health** | the eight invariants (including the Royalty stream); a red row lists examples and links to the fixing screen |
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
| **Catalogue badge** | *Up to 29% repeat-purchase bonus* on a card whose ladder is on | Shop |
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

---

## 16. Royalty bonus: the rank ladder, the monthly ledger and operations (Phase 19)

The Royalty bonus is a **monthly override on group sales**, paid once per closed calendar month to
**Supervisor, Manager and Director** only. Phase 19B ships its configuration and the derived rank
ladder; 19C ships the monthly evaluation and payment; 19D ships the read-only admin views — the
ledger, the report cards, the member panel and the dashboard tile; 19E ships the member's own
Royalty statement and dashboard tile; 19F ships the scheduled close, the doctor and the operations
panel; 19G releases the workstream. **The stream ships off**, so a fresh install pays
nothing until an operator turns it on.

### The five ranks

Ranks are **derived from the sponsor tree on every evaluation and never stored** — there is no rank
field on a member and no rank history table. A change to the ladder therefore applies from the
**next** close, and a month that is already closed keeps the snapshot it was evaluated with.

| Rank | How it is earned (default) | Pays Royalty? | Monthly group quota | Group % | Personal % | Own purchase |
|---|---|---|---:|---:|---:|---:|
| **Member** | automatic on registration | no | — | — | — | — |
| **Leader** | 10 direct member accounts | **no** | — | — | — | — |
| **Supervisor** | Leader **and** 10 downline Leaders | yes | ₱500,000 | 3% | 5% | 8% |
| **Manager** | Supervisor **and** 10 downline Supervisors | yes | ₱1,000,000 | 5% | 10% | 15% |
| **Director** | Manager **and** 10 downline Managers | yes | ₱5,000,000 | 10% | 15% | 25% |

Rules that surprise people:

- **Leader is not a paying rank.** It unlocks the Supervisor path and earns nothing by itself, however
  much volume sits under it.
- **The ranks are cumulative and self-prerequisite.** A Supervisor satisfies Leader, and a Director
  satisfies every lower predicate, so “10 downline Leaders” counts Supervisors, Managers and Directors
  too.
- **“Downline” means any depth.** There is no ten-level cap here; the ten-level limit belongs to the
  *sponsor-chain* bonuses, not to ranks.
- **People, not production, make a rank.** A suspended or pending member still counts as a person for
  their upline's rank; only that member's own payout is skipped (in 19C).
- **Staff are invisible.** An `admin`/`superadmin` account is never ranked and never counted, even with
  ten directs; a member sponsored by a staff account starts a fresh member tree.
- **Own purchases are inside group sales and are paid twice** — group % on the group sales plus
  personal % on the member's own purchases. That is why the ladder shows an *Own purchase* column
  (8 / 15 / 25%) beside the two rates.
- **The quota is all-or-nothing.** A member below their quota earns ₱0 for the month, including the
  personal percentage. The quota, once met, is paid on the whole month's group sales.

### Where to see ranks

**Admin → Members** now has a **Rank** column with the derived badge (Member, Leader, Supervisor,
Manager, Director) and a **Rank distribution** strip under the counters: the exclusive count per rank
plus **N ranked (Supervisor+)**. Both are read-only, one pass over the sponsor tree, and change only
when the tree or the thresholds change.

### How to edit the ladder

**Admin → Settings → Commissions → Royalty bonus.** The sub-block holds the two switches, four rank
requirements, three quotas and six rates, and a read-only **Saved ladder** table that shows what is
stored now.

| Setting | Default | Meaning |
|---|---:|---|
| **Enable the Royalty bonus** | **off** | The earning switch. While it is off the next close is a `skipped` period: no awards, no money. Ranks stay visible. |
| **Pay Royalty awards** | **on** | The payment pause. Off leaves evaluated awards `pending`; a later run can pay them. Evaluation keeps running. |
| Leader: direct members | 10 | Integer, 1–1,000 |
| Supervisor / Manager / Director: downline rank holders | 10 each | Integers, 1–100,000 each |
| Monthly group quotas | 500,000 / 1,000,000 / 5,000,000 | Pesos, 0.00–999,999,999.99 |
| Group and personal rates | 3/5, 5/10, 10/15 | Percent with three decimals, 0.000–100.000 |

The card validates the ladder before it saves anything, and every refusal is a field error, not a
flash-only message:

- thresholds are whole numbers in their range; quotas are centavo amounts; rates carry at most three
  decimals;
- quotas and both rate columns **never fall as the rank rises** (a Manager quota below the Supervisor
  quota is refused, and so is a Director group rate below the Manager's);
- **group + personal cannot exceed 100%** for any rank;
- **enabling** the ladder with every rate at zero is refused — *“this ladder would pay nothing”*.

Every successful save writes one `settings.update` audit row with the before/after of each changed key
and the section `commissions`. A save writes no period, award, wallet or other money row.

### What this build does not do

There is still no **Run close** button: closing a month is the scheduled job
(`bin/royalty_month_close.php`, its URL entry or the Windows launcher), never a page — an HTTP button
would need CSRF, a long-running request and a retry story, and a retry story for money is a bug. The
Royalty ledger, the operations panel, the member panel and every member surface are **read-only** — no
screen can evaluate, pay, edit or reverse an award. The scheduled close, the doctor, the URL entry,
the Windows launcher and the operations panel ship with **19F** (see *The operations panel* below).

The money is real and already visible in the member's wallet statement as a **Royalty bonus** credit
whose note carries the month and the rank it was judged at (for example
*Royalty bonus — 2026-10 — Supervisor*); from 19E the member can read the month it came from on their
own statement.

### Reading a "why is nobody ranked" report

1. Check the tree: a rank needs the **direct** member accounts and the downline rank holders; a wide
   team under one member is not ten directs.
2. Check **Admin → Members → Rank distribution**: if every badge is Member, the directs or the
   downline rank holders are genuinely short.
3. Check the four thresholds on the settings card — they may have been raised.
4. Ranks count members regardless of status, but only `role = 'member'` accounts count. A staff account
   in the middle of a chain is not a bridge: the member below it starts a new root.

### Reading the Royalty ledger (Phase 19D)

**Admin → Commissions → Royalty** (`/admin/commissions?ledger=royalty`) is the finance view of the
monthly money engine. The page reads `royalty_periods` and `royalty_awards`; it never writes them.

- **Period** is the primary filter. It defaults to the **latest final month**, and the dropdown lists
  the newest 24 monthly headers (a period is never deleted, so an older month is read through its
  export or the reports desk). `— current month (preview)` opens the open month as a projection
  (see below).
- **The closed-month cards** list the newest 24 months with their status, award/paid/skipped counts, the award
  total, the paid total, the evaluation time and the time it became final. Clicking a month filters
  the ledger to it. A closed month is never rewritten.
- **The counters** describe the selected month: paid in this period, awaiting payment, the period
  total, recipients, skipped, average award, the top rank and the all-time paid figure.
- **The rows** are one member each: member, rank, group sales (GS), personal sales (PS), quota, the
  two rates, the amount, the status, the ledger id with a link to the wallet movement and the paid
  time. GS and PS are the stored values the award was calculated from; the rates are the ones
  snapshotted at the close (`3.000% / 5.000%` for a Supervisor, for example).
- **Only Supervisor, Manager and Director have rows.** A Leader earns nothing, however large their
  group is — Leader is a rank, not a royalty rank. A Leader therefore never appears in this ledger.
- **“If this month closed now.”** The open month is a **read-only preview** computed by the engine
  with the current tree and the month's paid orders so far. It is clearly labelled, it is not a period
  row, and it never enters a period total. The month is only written by the close job.
- **The CSV** is the **Export CSV** button on the same page. It reuses the filters you have selected
  and downloads the columns in the next section. Every download writes one `royalty.export` row to
  **Admin → Audit**.

### The operations panel (Phase 19F)

The top card on **Admin → Commissions → Royalty** answers one question in one screen: **is the month
open, evaluated, paid or stuck?**

| The card shows | What it means |
|---|---|
| **Open month — not closable yet** | the current calendar month, with *“if it closed now”* projected totals. Nothing is stored and it never enters a period total. A month is only ever closed by the job. |
| **Last final month** | the newest `paid`/`skipped` period with its status badge, paid total and award count. |
| **Pending periods** | completed months with **no** period yet — normally **None**. Anything here means the close has not run for that month. |
| **Awaiting payment** | months left `evaluated` (payments paused, or a batch ceiling reached), their unpaid rows and the exact command that finishes them, e.g. `php bin/royalty_month_close.php --pay-only --period=2026-10`. |
| **Royalty earning** / **Payments** | the two switches (`royalty_enabled`, `royalty_pay_enabled`). *Off* means a close records a skipped month; *Paused* means awards stay pending. |
| **Last close** | when the newest period was evaluated. |
| **Next run** | the month the next scheduled run touches and whether it resumes a payment or closes a month. |
| The badge | **Up to date**, **Close due**, **Payment pending** or **Payments paused** — the whole stream in one word. |

The card is **read-only**: it renders no form and no button. It refreshes with the rest of the tab when
a close bumps the surface. When a month is not where it should be, the card names the month and the
command; `docs/runbook.md` §2 is the drill and `docs/cron.md` §7 is the job reference.

### Period states

| Status | What it means | Can it change? |
|---|---|---|
| **Evaluated — awaiting payment** | The month's awards are written and sitting `pending`. This is the review window after `--evaluate-only`, or a run whose payment is paused. | Yes — a later `--pay-only` pays it and the month becomes **Paid**. |
| **Paid** | Final. Every award was paid, or was `skipped` and paid nothing. Money reached the withdrawable wallets. | **No.** A closed month is never recalculated. |
| **Skipped (stream off)** | The stream was off when the month closed. No awards, no money, and a note on the header. | **No.** The month cannot be paid later. |

A month with award rows all `skipped` still ends **Paid**: the month was settled, it just paid nothing.

### Why a row is skipped

A skipped row stores `0.00` and the reason it was not paid. The reason is rendered **verbatim** — the
ledger never invents an explanation:

| Stored reason | What happened |
|---|---|
| `group sales below quota` | The member's group sales missed the rank's monthly quota. The quota is **all-or-nothing**: one centavo short pays nothing, including the personal percentage. |
| `account is not active` | The member account was not active at evaluation. |
| `membership is not active` | The membership status was not `active` at evaluation. |
| `zero amount` | The member was eligible and at quota, but the configured rates produced `0.00`. |

The row also shows **“would have been ₱X”**: the amount the stored GS/PS/rates would have produced.
That is a display-only derivation; the stored amount is `0.00` and never enters a paid total.

### Royalty ledger CSV columns

`Period, Member, Full name, Rank, Group sales, Personal sales, Quota, Group %, Personal %, Amount,
Status, Reason, Evaluated at, Paid at, Ledger id`

- **Member** is the username; **Full name** the profile name.
- **Rank** is the rank the member was judged at, not their rank today.
- **Group sales / Personal sales / Quota / Group % / Personal %** are the stored snapshot values.
- **Status** is `pending`, `paid` or `skipped`; **Reason** is the stored reason (empty when paid).
- **Evaluated at** is when the row was written; **Paid at** is when the wallet credit happened.
- **Ledger id** is the `ewallet_ledger` row that paid it (the on-screen row shows `—` for a skipped
  row; the CSV leaves the cell empty so a spreadsheet reads it as blank, not as text).

### The member panel and the dashboard

- **Admin → Members → one member** now has a **Royalty** card: the derived rank, the quota progress,
  this month's personal and group sales against the quota, the last six awards, the all-time paid
  figure and the member's downline counts. It is read-only and uses the same engine read as the
  member's own (19E) card, so the two can never promise different amounts.
- **Admin → Dashboard** has a **Royalty paid** tile: all-time money in wallets, the amount paid **this
  calendar month** and the pending liability. It is never the open month's projection, so it cannot
  disagree with the ledger after a close.
- **Admin → Reports** has the Royalty summary (including **royalty paid ÷ paid sales**), the rank
  table and the month table, with a `Royalty` CSV export. Use the ratio to watch the plan economics:
  a rising figure means the ladder is paying more of every peso of sales.

### What members see (Phase 19E)

A member reads Royalty in two places, both **read-only** and both on pages that already existed — no
new route is added:

- **`/earnings?ledger=royalty`** — the third tab of the member statement. It holds the **rank card**
  (current rank; the one requirement that unlocks the next rank with its progress bar; the direct and
  ranked team counts; this month's personal and group sales against the rank's quota; and
  **“If this month closed now: ₱X”**), five counters (paid all-time, this period, awaiting payment,
  not paid, last paid) and **one row per closed month** with the rank judged at, GS, PS, quota, the
  two rates, the amount, the status and the paid date.
- **`/dashboard`** — a **Royalty tile**: the rank badge, this month's group sales against the quota
  with a progress bar, the amount if the month closed now, the all-time paid figure and the last paid
  month, with a link to the statement. It is **hidden when the stream is off and the member has no
  history**; otherwise it stays and states *“Royalty is currently off”*.

The copy rules the member surfaces follow (plan §9 Rule 7 and Appendix C.3):

| Rule | Why |
|---|---|
| It says **team** / **Team chain**, never “downline”, “sponsor chain” or “subtree” | The sponsor vocabulary is an admin concept; the member sees their team. |
| It says **Royalty**, never “royalty commission” | Royalty is monthly and rank-gated; a commission reads like a per-order payment. |
| It states the **month close**, never the purchase event | Nothing is paid at checkout; the month ends and the close evaluates it. |
| **Leader is explained, not hidden** | A Leader sees *“Leader is a rank, not a royalty rank — the first rank that pays is Supervisor”* and their progress toward Supervisor, so a good month is not mistaken for an unpaid one. |
| A pending month says **Awaiting payment**, never “Paid” | With the payment switch off, the award stays pending and the row says *“Payment is paused; the award stays pending.”* |
| A skipped month says **Not paid** and names the quota | *“Group sales below the ₱500,000.00 quota”*, with the GS and PS it was judged on still in the row. |

**What members never see:** another member's rows, amounts or rank progress. Every read is scoped to
the signed-in member, a `?user_id=` in the URL is ignored, and the member CSV contains only their own
months. The member statement also never shows the network's calendar: its month filter lists only the
months the member has a row for.

**A member's CSV** is the **Export CSV** button on the Royalty tab and downloads
`Period, Rank, Group sales, Personal sales, Quota, Group %, Personal %, Amount, Status, Reason,
Paid at` — the same stored snapshot values the admin ledger uses, with the member-facing status words
(`Paid`, `Awaiting payment`, `Not paid`). A member export writes no audit row; it is the page itself.

**Support reading:** if a member says “my Royalty is zero”, read the statement in this order — the
rank card (is the rank Supervisor or above? is the quota met?), the status of the latest month row,
then the stored reason. The same facts are on **Admin → Members → one member → Royalty**, so a
support agent and the member read one definition.

---

## 17. Team Harvest bonus: the 3×10 matrix and its configuration (Phase 20B)

The Team Harvest bonus is a **flat amount per completed level** of a member's private **3×10
matrix**. It has no month, no purchase input and no rank: a member enters the queue the moment they
hold the configured number of direct member accounts, and every qualified member who joins their
team **after** them fills the matrix.

**Phase 20B shipped the configuration; 20C–20F built the queue, the money and the visibility.** This
section is the card. §18 is the admin visibility (the ledger, the queue card, the member panel, the
reports and the KPI); the member's own view is the **Team Harvest** tab on `/earnings`, the dashboard
tile and the genealogy badge, which read the same numbers §18 shows you.

### The matrix

| Level | Slots | Teammates needed | Pays (reference) | Cumulative |
|---:|---:|---:|---:|---:|
| 1 | 3 | 3 | ₱1,000.00 | ₱1,000.00 |
| 2 | 9 | 12 | ₱2,000.00 | ₱3,000.00 |
| 3 | 27 | 39 | ₱4,000.00 | ₱7,000.00 |
| 4 | 81 | 120 | ₱6,000.00 | ₱13,000.00 |
| 5 | 243 | 363 | ₱12,000.00 | ₱25,000.00 |
| 6 | 729 | 1,092 | ₱50,000.00 | ₱75,000.00 |
| 7 | 2,187 | 3,279 | ₱120,000.00 | ₱195,000.00 |
| 8 | 6,561 | 9,840 | ₱200,000.00 | ₱395,000.00 |
| 9 | 19,683 | 29,523 | ₱1,200,000.00 | ₱1,595,000.00 |
| 10 | 59,049 | 88,572 | ₱3,500,000.00 | **₱5,095,000.00** |

**Only the amounts are configurable** — the slots and the teammates needed are the matrix and cannot
be changed. The reference ladder totals **₱5,095,000.00 per member**; that is this stream's per-member
ceiling, not a forecast.

### Where to edit it

**Admin → Settings → Commissions → Team Harvest bonus**, below the Royalty block. The card is one
form with one save button:

| Control | Default | Meaning |
|---|---:|---|
| **Enable the Team Harvest bonus** | **off** | Off writes no award. Positions are still recorded (20C), so nothing is lost while it is off. |
| **Direct members needed to qualify** | 3 | Whole number 1–10. A member enters the queue at this many direct **member** accounts; staff accounts never count. |
| **Levels that pay** | 10 | Whole number 1–10. Levels above it still complete on the board and are marked *not paid*; their amounts are kept. |
| **Level 1 … 10 amounts** | the reference ladder | Money per level, 0.00–10,000,000.00 each with two decimals. Blank and 0 are the same value. |

Below the inputs the card shows the **generated ladder**: every level's slots, the teammates it needs,
the stored amount and the cumulative sum — all computed from the matrix, never typed. With JavaScript
on, the cumulative and the full-ladder total re-read as you type; with JavaScript off the card still
saves and still shows the stored numbers.

The card refuses, as field errors, not silent fixes:

- an amount that is not money, is negative, has more than two decimals or exceeds **₱10,000,000.00**;
- a ladder whose ten amounts total more than **₱25,000,000.00** (a typo guard: the reference ladder is
  ₱5,095,000.00);
- **enabling** the stream while every level is 0 — *“Turn the switch off or give at least one level an
  amount above zero.”*;
- a gate or a depth that is 0, 11 or a decimal.

Saving is atomic: the switch, the two numbers and all ten rows commit together or not at all, and a
refused save changes nothing.

### The status strip

The card states the stream's real state in one of four ways, and never claims it is paying when it is
not:

| State | What it means | Next action it names |
|---|---|---|
| **Not seeded** | No position has been assigned; the queue does not exist yet | Run `bin/harvest_queue_seed.php` before enabling (20C) |
| **Seeded and switched off** | Positions are being recorded; nothing can pay | Turn the switch on when the ladder is ready |
| **Seeded, switch on** | Every completed level has been evaluated | None |
| **Seeded, switch on, waiting** | Levels are complete since the baseline and not paid yet, with the amount owed | Run `bin/team_harvest_catchup.php` (20D) |

### What this phase does not do

There is no **Run seed** button and no **Evaluate** button: both are CLI jobs (20C/20D), never a page.
The card writes no position, no award and no wallet row, and changing an amount never rewrites an award
that already paid — a level's amount is snapshotted onto the award when it pays, so the card's copy
says *“every award keeps the amount it was paid at”*.

**Go-live order**: [`runbook.md`](runbook.md) §*Team Harvest go-live order* is the one place the sequence
is written, and it is not repeated here; the card is where the operator sees the state between its
steps. Rollback is simply turning the switch off; the ladder rows are inert and may stay.

**Every save is audited:** `team_harvest.ladder.update` carries the ten amounts and the three knobs,
before and after, and the tenant-wide `settings.update` row records each changed key.

---

## 18. Team Harvest visibility: the ledger, the queue card and the member panel (Phase 20E)

Phase 20E is **read-only**: it puts the rows 20D writes on the admin pages an operator already uses, so
finance can see, filter and export every award, open the member who earned it, and read the queue itself —
who is qualified, in what order, how far each member is, and what the network still owes. No page in this
section writes an award, a wallet row or a queue row; the only thing it writes is the audit row of an
export.

There is **no new route**: every surface below lives on a page that existed before the stream.

| Where | What it shows |
|---|---|
| **Commissions → Team Harvest** (`/admin/commissions?ledger=team_harvest`) | the fourth ledger tab: six counters, the ten-level distribution, filters and a CSV |
| **Members** (`/admin/members`) | the **Harvest** column, the **Team Harvest queue** card and the `?harvest=yes\|no` filter |
| **One member** (`/admin/members/{id}`) | the member's Team Harvest panel |
| **Reports** (`/admin/reports`) | the Team Harvest block and the `Team Harvest bonus` CSV |
| **Dashboard** (`/dashboard`) | the **Team Harvest (30 d)** KPI |

### Reading the ledger tab

The tab answers "what has this stream paid, and what is it about to?" — and it keeps the two questions
apart. One row is **one level a member completed**: the instant it was decided, the member, their stored
**position**, the level, the teammates the decision was taken at, the threshold it crossed, the amount,
the status and the wallet movement.

The six tiles describe the **whole ledger** (filtering the rows below never changes them):

| Tile | Meaning |
|---|---|
| **Credited** | money actually in members' wallets — `status = 'credited'` only |
| **This month** | the part credited since the first of the current month |
| **Awards** | how many levels have been paid |
| **Recipients** | how many members hold at least one paid level |
| **Before go-live** | money recorded but **never paid**: levels already complete when the plan started. It has its own tile and its own row badge and never enters a paid total. |
| **Open liability** | what the engine would pay if every outstanding level were evaluated now — *not yet paid*. It is derived on read and moves with every qualification. |

Below the tiles the **level distribution** always lists the ladder's ten levels, including the levels
nobody completed, with the configured amount, the teammates each needs, the paid awards and the money.
A level above **Settings → Levels that pay** is marked **Not paid** (its block may be complete, but the
depth does not pay it); a level whose amount is zero is marked **No amount** — a zero amount is a switch,
not a promise.

The **row badges** are the two stored states and nothing else: **Paid** (the wallet was credited and the
row links to the movement) and **Before go-live** (recorded, no movement, no link). A row with no wallet
link is not an error: it is the baseline.

### The queue card and the Harvest column

`/admin/members` gains a **Harvest** column that prints `#N` for a queued member and `—` for everyone
else. The number is the member's **stored position** (`harvest_queue.id`): the page never recomputes one
from a count, because a position is a fact that never changes.

The **Team Harvest queue** card above the directory states the queue's real condition and, in each empty
state, the next thing to do:

| Card state | What it means | What to do |
|---|---|---|
| **Not seeded** | no position exists yet, so no level can complete | run `bin/harvest_queue_seed.php` before enabling the bonus |
| **Nobody holds the gate yet** | the queue is seeded and empty | wait for the first member to reach the gate (3 direct members by default) |
| **Positions** | the queue is live | the card lists the first ten positions with each member's teammates and paid levels, plus the counters: *queued*, *next position*, *deepest team* and *Open liability* |

The `?harvest=` filter fits the same form as the other filters: **In the Harvest queue** (`harvest=yes`)
keeps exactly the members with a position, **Not qualified** (`harvest=no`) keeps everyone else, and it
survives pagination and combines with the status, package and search filters. An unknown value means "no
filter", never an error.

### The member panel

`/admin/members/{id}` gains a **Team Harvest** panel. For a queued member it shows the position, the
instant they qualified, their teammate count, the levels paid and the money, the levels recorded before
go-live (badged **Before go-live** and with no wallet link), the next level with its slots and the amount
it will pay, the ten-level list with each level's state, and every award row the ledger holds for them.

For a member who has not qualified it says **Not qualified: n of 3 direct members** and shows no matrix
and no award table — the gate is the answer, not an empty board.

The panel reads `TeamHarvestService::statusFor()`, the same read the member's own earnings tab uses
(20F), so the back office and the member can never be promised different numbers.

### The reports block and the CSV

`/admin/reports` gains a **Team Harvest bonus** block for the selected window: the credited money and its
awards and recipients, the money recorded before go-live (never mixed in), the queue's state and its first
positions, the ten-level ladder for the window and the decisions by month. It calls the same read model as
the ledger tab, so the two screens cannot disagree.

The export menu gains **Team Harvest bonus** (`?type=team_harvest&from=…&to=…`). Its CSV starts with a
`Scope` column: `Award` rows carry the plan's Appendix C.5 columns, and one `Queue` row closes the file
with the go-live instant, the next position, the deepest counter, the open liability and the seeded state.

### The ledger CSV

`Export CSV` on the Team Harvest tab downloads the **filtered** rows with the plan's Appendix C.5 columns:

`Award id, Credited at, Member, Position, Level, Teammates (n), Threshold, Amount, Status, Wallet reference`

Every download writes one `team_harvest.export` audit row carrying the tab, the filters and the row count —
including a download with no rows (a header-only file and its audit row).

### What this section does not do

It writes no position, no award and no wallet movement; it adds no route, no button that pays and no
setting. It **cannot** pay a backlog: only `bin/team_harvest_catchup.php` writes money, and only
`bin/harvest_queue_seed.php` assigns a position.

---

## 19. What the member sees (Phase 20F)

The member reads the same facts you do, on three surfaces that already existed — no new route, no new
permission, no new menu item:

| Surface | What the member sees | What to check when a member calls |
|---|---|---|
| **Earnings → Team Harvest** (`/earnings?ledger=team_harvest`) | their position and the gate sentence that explains it, the five counters (teammates, levels paid, earned, before go-live, next level), the whole 3×10 matrix with each level's state and amount, the progress card, the teammate roster and the level rows with a link to the wallet movement | the page is scoped to the signed-in member: `&user_id=` does nothing. *Before go-live* rows are history, not a missing payment |
| **Dashboard** (`/dashboard`) | the **Team Harvest** tile: position, the next level's progress, what has reached the wallet, the recorded-before-go-live count, and a warning when the stream is not paying | a member with no position and the stream off sees no tile at all; that is the shipped default |
| **Genealogy** (`/genealogy`) | the **Harvest #N** badge in the heading, a **Harvest** column on the direct recruits and the badge in the team-by-level card (`—` when not qualified) | the badge is the stored position, never a rank and never a count |

The copy rules the member sees are the plan's: the gate is stated with the position, the two exclusions
("only members who qualify after you count" and "outside your sponsor team does not count") and the
purchases footnote appear once, no page promises a date or a guaranteed income, and a level above the
configured depth reads *Complete — not paying at this depth* rather than a silent zero. `P20F-T12`…
`P20F-T14` pin the exact sentences; if a member disputes a figure, compare the tab with the member panel
in §18 — both call `TeamHarvestService::statusFor()` and cannot legitimately disagree.

---

## 20. General Harvest bonus: the shared board and its configuration (Phases 21B–21C)

The General Harvest bonus is the **second, shared board**. It is not a member's private matrix: there
is **one board for the whole network**, every Harvest-qualified member holds exactly one **node** — their
position in the Harvest queue minus one — and a node's level-`L` block is the next `3^L` positions in
that same queue.

| Level | Slots in the block | The apex (position #1) needs `N ≥` | Pays (reference) | Cumulative |
|---:|---:|---:|---:|---:|
| 1 | 3 | 4 | ₱2,000.00 | ₱2,000.00 |
| 2 | 9 | 13 | ₱4,000.00 | ₱6,000.00 |
| 3 | 27 | 40 | ₱8,000.00 | ₱14,000.00 |
| 4 | 81 | 121 | ₱12,000.00 | ₱26,000.00 |
| 5 | 243 | 364 | ₱25,000.00 | ₱51,000.00 |
| 6 | 729 | 1,093 | ₱100,000.00 | ₱151,000.00 |
| 7 | 2,187 | 3,280 | ₱250,000.00 | ₱401,000.00 |
| 8 | 6,561 | 9,841 | ₱480,000.00 | ₱881,000.00 |
| 9 | 19,683 | 29,524 | ₱2,500,000.00 | ₱3,381,000.00 |
| 10 | 59,049 | 88,573 | ₱5,000,000.00 | **₱8,381,000.00** |

Three things an operator has to hold on to:

1. **`N` is the whole network's qualified count**, not the member's team. A block is filled by whoever
   took those queue positions, so a registration anywhere can complete a block for a member anywhere —
   including a complete stranger. This is intended, not a bug.
2. **A level pays all or nothing.** 26 of 27 positions pays ₱0.00 and the 27th pays the level's amount,
   once. There is no part-payment and no second payment.
3. **Every position moves the staircase right.** Node `q` needs `3^L·q` more than the apex at every
   level: position #2 reaches the full ladder at 147,622 qualified members, #3 at 206,671, #4 at 265,720.

The whole board's liability at queue size `N` is a closed form you can check without reading a row:
**₱2,000 at N = 4**, **₱176,000 at N = 121**, **₱190,208,000 at N = 88,573**.

> **Phase 21B shipped the configuration and the arithmetic; 21C adds the CLI arm that records the
> go-live baseline. Nothing can pay yet: there is no settlement until 21D.** This section is the card
> and the numbers it shows; the arm is a separate, CLI-only act (see *The one irreversible rule* below
> and the runbook), not a control on this page. The member's own view arrives with 21F.

### Where to edit it

**Admin → Settings → Commissions → General Harvest bonus**, below the Team Harvest block. The card is
one form with one save button:

| Control | Default | Meaning |
|---|---:|---|
| **Enable the General Harvest bonus** | **off** | Off writes no award. The queue is still recorded, so nothing is lost while it is off — and nothing pays until the board is **armed** (21C). |
| **Levels that pay** | 10 | Whole number 1–10. Levels above it still complete on the board and are marked *not paying at this depth*; their amounts are kept, and raising the depth pays them from the current queue. |
| **Level 1 … 10 amounts** | the reference ladder | Money per level, 0.00–10,000,000.00 each with two decimals. Blank and 0 are the same value. |

Below the inputs the card shows two **generated** tables, and neither is typed:

- the **ladder**: every level's slots, the apex threshold, the stored amount, the cumulative sum and
  whether the configured depth pays it;
- the **position table**: positions #1–#6 against levels 1–4 — the reference's own table
  (`4 13 40 121` / `7 22 67 202` / `10 31 94 283` / `13 40 121 364` / …) — computed from the block
  formula.

With JavaScript on, the cumulative column, the full-ladder total, the per-depth total and the
calculator re-read as you type; with JavaScript off the card still saves, still validates and still
shows the same stored numbers.

The card refuses, as field errors, not silent fixes:

- an amount that is not money, is negative, has more than two decimals or exceeds **₱10,000,000.00**;
- a ladder whose ten amounts total more than **₱25,000,000.00** (a typo guard: the reference ladder is
  ₱8,381,000.00 — five times smaller);
- **enabling** the stream, or **raising the depth**, while any level inside the depth is 0. The error
  names the level: *“Level 4 has no amount. …”* (decision D-17 — a zero level below the depth could
  never be paid, and the ledger's per-level frontier would no longer be a contiguous prefix);
- a depth that is 0, 11 or a decimal.

Saving is atomic: the switch, the depth and all ten rows commit together or not at all, and a refused
save changes nothing. Every moved ladder is audited as `general_harvest.ladder.update` with the
switch, the depth and the ten amounts before and after.

### The one irreversible rule: the amounts freeze at the arm

Once the board has been **armed** (21C — it records the go-live queue length), the ten amounts are
**frozen**: a save that changes one is refused with *“The board was armed on … so the ten amounts are
frozen …”*. The depth may still change, and the amounts it exposes are the frozen ones.

That is not an inconvenience, it is the only way the arithmetic stays true: the board's whole liability
is one closed form over one ladder, and a mid-stream amount change would make two nodes receive
different amounts for the same level and turn the reconciliation into a sample. The only way back is a
**business reset**, which empties the award ledger — safe before anything has been paid, unacceptable
after. While the board is unarmed, edit freely: nothing has been paid.

### The four-state strip

The card states the board's real condition in one of four ways, and never claims it is paying when it
is not:

| State | What it means | Next action it names |
|---|---|---|
| **Not armed** | no baseline has been recorded; nothing can pay | run `bin/general_harvest_arm.php` (21C) before enabling |
| **Armed and switched off** | positions are being recorded; nothing is paying | turn the switch on when the ladder is ready |
| **Armed, switch on — nothing waiting** | the board is settled to the queue | none |
| **Armed, switch on — positions waiting** | the queue has grown past `settled_n`; the positions are worth the closed-form difference | run `bin/general_harvest_catchup.php` (21D) |

A queue that is **not gapless** (a hole in the positions) is called out separately in red: the board's
arithmetic is defined over consecutive indices and the settlement will refuse to run until the hole is
repaired.

### The calculator is an illustration

The **If the queue reaches N** box answers "what would the whole board have paid at that queue size?"
— `1000` reads **₱1,601,000.00** across **493** awards. It is labelled an illustration for a reason:
it is the closed form over the stored ladder, not a liability, not a forecast and not a promise. No
award exists until the board is armed and settled.

### What this phase does not do

There is **no** button that arms the board and **no** button that settles it — both are deliberate,
separate acts (21C and 21D, CLI). Arming is not reachable from any page. The card writes no award, no
wallet movement, no queue row and no board state; it reads the queue and the ladder only.

**Go-live order**: the sequence is deploy → configure (this card) → arm (21C) → enable → catch up →
reconcile. Rollback is turning the switch off; the ladder rows and the two settings are inert and may
stay.

---

## 21. General Harvest money engine and ledger (Phase 21D)

The money engine pays every newly-complete block once, into the node holder's withdrawable wallet,
honouring the baseline, the depth cap and the once-ever rule. Nothing pays until the board is armed
and the stream is switched on; configuration states (off, unarmed, unpriced, beyond depth) never throw
and never block a registration.

### Settlement

`GeneralHarvestService::settle(int $maxNewPositions = 1)` is state-based: it compares the queue length
`N` with the board's `settled_n` and the ledger's per-level prefix. For each level `L` it pays every
complete node from the current frontier up to the highest complete node — writing `status = 'credited'`
plus one withdrawable wallet credit (`ref_type = 'general_harvest'`, `ref_id` = the award id) in the
same transaction. The inline registration hook settles exactly one position per append; the on-demand
`bin\general_harvest_catchup.php` settles a bounded backlog (default 500 positions per transaction).
Awards are idempotent: a second settlement writes nothing.

### Reconciliation

`wallet_reconcile.php` gains an eighth section (General Harvest) that proves `Σ credited =
boardTotal(settled_n) − boardTotal(baseline_n)` against the ledger and the wallet, and `Σ baseline =
boardTotal(baseline_n)` against the board row. `bin\general_harvest_catchup.php --doctor` reports the
clock, tables, settings, armed state, gaplessness, the queue count, settled, backlog, per-level
frontier, paid, baseline, open liability and the reconcile verdict. Exit codes: `0` clean, `1`
refused or a problem found, `2` database unreachable.

---

## 22. General Harvest admin visibility (Phase 21E)

Read-only admin surfaces over rows the money engine already writes. No page in this section writes an
award, a wallet movement or the board row.

### Commissions ledger tab — `?ledger=general_harvest`

The fifth tab on `/admin/commissions`. Stats tiles show credited, baseline, open liability and
unsettled positions; the always-ten-row level distribution lists levels 1–10 (amounts from
`general_harvest_levels`, with levels above `max_levels` marked *not paying at this depth*). Filters
(level, status, dates, member, search) fall back silently on unknown values. Rows link to the member
and to the wallet movement (`ref_id` = the award id). Pagination and the `general_harvest.export`
CSV audit row complete the tab. The four older tabs are byte-identical (`P21E-T1`).

### Members directory — `?board=paid|none` and the `GH` column

`/admin/members` gains a `GH` column (`N / 10` credited levels for node holders, `—` otherwise), the
`?board=paid` / `?board=none` filter, and a board card showing `N`, `settled_n`, the per-level
frontier and the open liability `boardTotal(N) − boardTotal(settled_n)`, labelled *not yet paid*.

### Member detail panel — `/admin/members/{id}`

For a node holder: position, node, qualified-at, settled state, the ten-cell grid (paid / before
go-live / in progress / not paying at this depth), the next level with its `N`, and the awards table.
For a member who has not qualified: the gate ("2 of 3 direct members") and no board panel. Baseline
holders see their baseline levels labelled *Before go-live* with amounts but no wallet link.

### Reports — `/admin/reports`

The General Harvest block (paid total, by-level split, bands, board summary) and the
`type=general_harvest` range CSV, which includes the award rows and the board summary block. All
figures call the same model methods as the ledger tab, so the two reconcile.

---

## 23. General Harvest member visibility (Phase 21F)

Read-only member surfaces, each scoped to the session member (`WHERE user_id = ?`; `?user_id=` is
ignored by page and export).

### Earnings tab — `/earnings?ledger=general_harvest`

The fifth tab: position, `N`, ten blocks with `#from–#to` ranges, filled-of-slots and amount, the
progress card, the paginated block roster (Direct / In your team / Outside your team per D-20),
award rows with wallet links, and the member CSV export (`general-harvest-statement_YYYY-MM-DD.csv`,
no audit row).

### Dashboard tile — `/dashboard`

The General Harvest tile shows the paid total, the position and the next block's progress. No action,
no form.

### Genealogy badge — `/genealogy`

The reader's own position and node badge, each queued downline member's position, and `—` for
unqualified members.

### Copy rules

The copy rules hold verbatim and singularly:

- The network sentence: *"A slot in your block is filled by a qualified member of the whole network —
  not necessarily anyone in your team. Your block fills in the order members qualified."* (once, on
  the block card)
- The position gap: every level row reads *"needs the queue to reach N"*, and the card header reads
  the current `N`. Never "soon", never "almost".
- If nothing can complete at the current queue size: *"No level can complete at the current queue
  size. Level 1 needs the queue to reach N."*
- No date or guarantee: *"level 3 completes when the queue reaches 67 and pays ₱8,000.00"*, never
  *"you will be paid by…"*
- The whole board is shown, filled and empty blocks alike.
- The footnote: *"This bonus does not depend on any purchase, on your rank, or on the monthly Active
  status."*
- Baseline rows are labelled *Before go-live* and excluded from every *Earned* figure; a footnote
  names the start date.
- No forbidden word renders: "spillover", "passive", "guaranteed", "unlimited", "matrix scheme",
  "binary", "cycle".

## 24. Product binary bonus: configuration only (Phase 22B)

The Product binary bonus pays a member from the points their own repeat purchases and their binary
team's purchases place on the two sides of a placement tree (22C), matching left against right into
pairs (22D), plus a Self Bonus on the buyer's own purchase.

**Terms.** *Product binary* is the payout program on paid orders; *Package binary* is the separate
stream on package activations; *Binary tree* is the placement view; *Binary placement* is the act of
placing a member on a side.

**Phase 22B only configures the stream.** The master switch is off, no product is flagged, no member
has a node yet, and **no money moves**. Placement, the money engine, the member pages and the tree
arrive in 22C–22D.

### Admin → Settings → Commissions → Product binary

One card, one form, one `settings.update` audit:

| Control | Default | Meaning |
|---|---:|---|
| **Product binary enabled** | **off** | Master switch. Off renders the offer nowhere and pays nothing (D-16). |
| **Self Bonus %** | `5.000` | % of the order's points paid to the buyer once per order; no Active gate (0 disables it). |
| **Points per pair** | `1000` | Points needed on each side for one pair (1–1,000,000; 0 refused). |
| **Pair value %** | `10.000` | % of the matched point value paid per pair, times the pair count (0 still accumulates). |
| **Pairs per day per member** | `20` | Daily cap per recipient (0 = unlimited). |
| **Release delay after delivery** | `7` | Days before a pending award becomes withdrawable (snapshotted at payment). |
| **Require the monthly Active rule** | **on** | Pair recipients must be Active for the month. |
| **Release Product binary awards** | **on** | The shared `--stream=binary` release job may credit them (22H). |
| **Reverse awards when a paid order is cancelled** | **on** | Credited awards are clawed back best effort on cancellation (22D). |

`binary_ledger_since` (the reconciliation baseline from migration 026) is never rendered.

### Products → edit → Product binary

The **Product binary** card sits after the *Unilevel repeat-purchase bonus* card:

- The switch flags the product for binary points.
- The readout shows `P.V. → N points per unit` (`ROUND(pv)`), and the self/pair sentence names the global preset.
- A switch-on product with `pv = 0` is refused: *"Give the product a P.V. or turn its binary switch off."*
- The list badge reads `Product binary N pts` (on) or nothing (off), and `?binary=on|off` splits the list.
- There is **no** per-product pair size, pair rate or self rate — those are global (D-09).

### Product binary readiness (read-only)

The same settings card gains a **Product binary readiness** panel with four counts:

1. **Product binary on, no points** — products flagged on with `pv = 0`. Links to `/admin/products?binary=on`.
2. **Product binary off** — products with the switch off. Links to `/admin/products?binary=off`.
3. **Global switch** — On / *The stream is off. Nothing is paid.*
4. **Preset check** — *1,000 points per pair pays ₱100.00 at 10%.* (recomputed when the pair values change).

None of the four is a button; each links to the filtered product list (Rule 12).

### The switch stays off

Paying an order after 22B writes no `binary_awards`, no `binary_volume` and no `binary` wallet movement —
that hook is 22D's work. Member pages (`/shop`, `/checkout`, `/orders`, `/earnings`, `/genealogy`) show no
binary content until 22F, and the genealogy tree arrives in 22G.

## 25. Product binary operations: health checks and the release job (Phase 22H)

Phase 22H adds no schema, no route and no page. It adds four read-only health checks to the admin
reports desk, a link from the readiness panel to the health panel, and the `--stream` flag on the shared
release job. Nothing here moves money.

### Admin → Reports — the health card

`/admin/reports` renders the health widget. Phase 22H appends four sections, each with a `pass`/`warn`
state and a one-line remedy:

| Check | What it proves | Remedy |
|---|---|---|
| **Product binary readiness** | master switch on, no flagged product earns 0 points, every member has a node | turn the switch on, set a P.V. or switch the product off, place unplaced members |
| **Product binary points** | every cached node balance equals the append-only ledger | `bin/binary_audit.php --fix` |
| **Product binary escrow** | pending award count, worth and oldest age | `bin/unilevel_release.php --stream=binary` after delivery + hold |
| **Product binary release job** | the last `unilevel_release` run ran `stream=binary` without error | `--doctor`, then `--dry-run`, then the real run |

Readiness counts link to `/admin/products?binary=on|off`; nothing on the health panel is a button that
changes a setting (Rule 10).

### Settings → Commissions → Product binary

The readiness panel now ends with **Open the health panel**, which opens `/admin/reports` (read-only).
The **Release Product binary awards** toggle gates whether the shared release job may credit binary
awards.

### The release job — one job, two streams

The release job already runs hourly at `:20` (cron.md §5). Phase 22H adds `--stream` so one job serves
both money engines; **no second cron schedule is introduced**:

| Flag | Effect |
|---|---|
| `--stream=unilevel` | credit due repeat-purchase awards only (the historical default) |
| `--stream=binary` | credit due Product binary awards only |
| `--stream=all` | credit both streams in one run (the cron default) |

```powershell
# preview due binary awards; credits nothing
php bin\unilevel_release.php --stream=binary --dry-run

# credit due binary awards
php bin\unilevel_release.php --stream=binary --limit=200

# environment, locks, log folder and both switches
php bin\unilevel_release.php --doctor
```

### The audit (read-only, never repairs on `--check`)

```powershell
# exit 0 when every cached node balance equals the ledger; names drifting nodes otherwise
php bin\binary_audit.php --check

# recompute every cached balance from the append-only ledger; awards, volume and wallets are untouched
php bin\binary_audit.php --fix
```

`--check` and `--fix` share exit codes: 0 = clean / repaired, 1 = drift found or refused.

### Verification after the upgrade

- [ ] `php tests\run.php` is green; `powershell -NoProfile -File tests\smoke.ps1` is green.
- [ ] `php bin\wallet_reconcile.php` exits 0 (this phase moves no money).
- [ ] `php bin\unilevel_release.php --doctor` is green.
- [ ] `php bin\binary_audit.php --check` exits 0.
- [ ] `/admin/reports` shows 12 health checks, all passing; the four binary sections render remedies.
- [ ] **Open the health panel** from the readiness panel opens `/admin/reports`.

### Rollback (22H)

Revert `system/app/Services/HealthService.php` (remove the four binary sections) and
`system/app/views/admin/settings/_commissions.php` (remove the health-panel link). The stream settings
stay inert; no money has moved and no schema existed to drop.

## 26. Package binary bonus: configuration only (Phase 23B)

The Package binary bonus pays an eligible upline when a member's package activation is approved:
the package's points are contributed once to the binary tree, every eligible binary upline settles
the pairs those points complete, and the money is credited to the withdrawable e-wallet immediately.

**Phase 23B only configures the stream.** The master switch is off, no package is flagged, and
**no money moves**. The money engine, the activation hook, the member pages and the reversal CLI
arrive in 23C–23F.

### Admin → Settings → Commissions → Package binary

One sub-block under the Product binary card, one form, one `settings.update` audit:

| Control | Default | Meaning |
|---|---:|---|
| **Package binary enabled** | **off** | Master switch. Off renders the offer nowhere and pays nothing (D-13). |
| **Require a binary package to earn package pairs** | **on** | On: a pair recipient must hold a package whose own binary switch is on. Off: every eligible member earns package pairs. The monthly Active rule never applies to package pairs (D-08). |

`package_binary_ledger_since` (the reconciliation baseline from migration 027) is never rendered.

There are **no** operator-editable fields here: no pair size, pair rate, cap or hold — those are the
shared Phase 22 values, and per-package points live on the package edit card, not here (D-06).

### Packages → edit → Package binary

The **Package binary** card sits after the referral ladder:

- The switch flags the package for binary points.
- The points field suggests `ROUND(product_value)` as a prefill but ships empty/0; the operator saves what they mean.
- A switch-on package with `binary_points = 0` is refused: *"Give the package points or turn its binary switch off."*
- Points are whole numbers, 0–1,000,000; a decimal is refused.
- The list badge reads `Package binary N pts` (on) or nothing (off), and `?binary=on|off` splits the list.
- There is **no** per-package pair size, pair rate, cap or hold input — those are global (D-06).

### Package binary readiness (read-only)

The same settings card gains a **Package binary readiness** panel with four counts:

1. **Packages flagged with 0 points** — packages with the switch on and `binary_points = 0`. Links to `/admin/packages?binary=on`.
2. **Packages with the switch off** — packages not contributing to the binary. Links to `/admin/packages?binary=off`.
3. **Global switch** — On / *The package stream is off. No activation places points.*
4. **Preset sentence** — *One pair is 1,000 points and pays ₱100.00 at 10%. A 1,000-point activation completes one pair.* (recomputed when the shared pair values change).

None of the four is a button; each links to the filtered package list (Rule 7).

### The switch stays off

Approving an activation of a binary-flagged package after 23B writes no `binary_awards`, no
`binary_volume` and no `binary` wallet movement — that hook is 23C's work. Member pages show no
package-binary content until 23E.

## 27. Package binary admin visibility (Phase 23D)

Phase 23D makes every package point and package award visible in the back office — read-only, no
money writes. Three surfaces gain the package stream: the binary ledger, the member panel, and the
reports desk.

### Admin → Commissions → Product binary (`?ledger=binary`)

The binary ledger tab gains a **Source** column between Side and Points. Every row reads
**Repeat purchase** (product orders) or **Package activation** (approved activations). A Source
filter above the table narrows to one source; an unknown value silently shows all.

The headline stats add two tiles beside the Phase 22 cards:

| Tile | What it shows |
|---|---|
| **Package pairs** | Pairs paid from package activations · money credited |
| **Package points** | Points placed by package activations |

The CSV export (`Export CSV`) gains the **Source** column and is audited as `binary.export`
with the filters and row count. Product-sourced rows are byte-identical to Phase 22 — the Source
column is additive, nothing else changes.

### Admin → Members → `{id}` → Product binary panel

The member's binary panel now splits by source:

| Split | What it means |
|---|---|
| **Product points / Package points** | Points placed on this member's node, from product orders vs package activations |
| **Product pairs / Package pairs** | Pairs paid to this member, split by the stream that produced them |
| **Product earned / Package earned** | Money credited to this member's wallet, split by source |

The **Recent volume** and **Recent awards** tables each gain a **Source** column reading *Product* or
*Package*. A **Package qualification** badge reads *Binary package — earns package pairs* when the
member holds a package with `binary_enabled = 1`, otherwise *No binary package — package
activations place no points here*.

### Admin → Activations

Each activation row in the queue now shows its binary outcome when processed:
`Package binary: N pts · ₱m.m0 · Mmm d` (points placed, money paid, processed date). Below the table,
every processed activation renders a **Package binary** card:

- Points placed and pairs paid
- Amount paid (₱)
- Volume rows and award rows
- Processed date and activation status
- Wallet links on each pair award row (`#<ledger_id>`)

A 0-point, disabled-package or stream-off activation shows *Nothing placed* with the reason
(stream off / package disabled / not placed / not approved).

### Admin → Reports

The reports desk gains two package-binary cards below the Product binary readiness card:

| Card | What it shows |
|---|---|
| **Package binary — by package** | Per package: activations, points placed, pairs paid, amount credited, recipients |
| **Package binary — by activation** | Per activation: member, package, points placed, pairs paid, amount credited, status, processed date |

Exporting **Package binary bonus** produces a CSV with a `Scope` column distinguishing `Package`
rows from `Activation` rows — both sources included, matching the ledger exactly.

### What did not change

- No new route, no new table, no new cron (D-14/D-15).
- The product stream's awards, volume and wallet rows are byte-identical to `p22i-release`.
- No member-facing change (23E covers that).
- Nothing here writes an award, volume or wallet row — it is all read-only over `binary_awards`
  and `binary_volume` (Rule 1).
