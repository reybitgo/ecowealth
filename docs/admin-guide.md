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
| **Wallet float** | total member money and the withdrawable portion |
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
  instead.
- Stock is the physical count. The shop shows `stock − reserved`; reserved units belong to unpaid
  orders. Shipping deducts the physical stock.

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
| a table of L1–L10 rows with a total | the award this order paid to the sponsor chain |

Each row shows the level, recipient, product, base, rate, amount and status, and the headline equals
the order's repeat-purchase total. The buyer is never a recipient. A skipped level keeps its number
(no compression); a voided row shows how much was recovered and, in red, the shortfall.

---

## 7. E-wallet, transfers and payouts

**Admin → E-wallet monitor** shows every member's two buckets, the float, the top-up history, the
transfers tab and the full ledger. **Admin → Wallets → a member** is where an **admin top-up** is
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
| **Shop** | The shop switch; closing it blocks new cart/checkout but not pending payments |
| **Receiving accounts** | GCash / Maya / bank details shown at checkout and on the order page |
| **Payout methods** | Minimum withdrawal, USDT rate, each channel's fee and switch, network fees |
| **Payment windows** | Payment deadline, correction window, proof attempts per order, expiry kill switch |
| **Fulfilment & invoices** | Auto-complete days and switch, seller name/TIN/phone/address on invoices |
| **Commissions** | Direct switch, unilevel switch and depth, active-sponsor rule, free registration |
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
| **Repeat-purchase bonus** | the product ladder's credited, skipped and voided money, with the unrecovered shortfall |
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
(`/admin/commissions?ledger=repeat`). Its filters are recipient/buyer/product/order (`q`), level 1–10,
status (credited, skipped, voided), product and date. A **skipped** row is an audit note, never money:
it shows what was due and why it was not paid. **Unrecovered** is the part of a voided award the
wallet could not return. Every row links to its order and to the recipient's wallet statement filtered
to that order. The **Closed months** card is a history only — closing a month is an operations job,
not a button here. The package commission ledger is the other tab and is unchanged.

---

## 10. Audit log

**Admin → Audit log** is the append-only record of admin and money actions: settings changes with
their before/after, wallet top-ups, exports, member status changes, activation decisions, payout
decisions, transfers, code generation/redemption and S-Login starts, refusals and exits.

- Filter by action, subject, actor/action text and date; paginate like any table.
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
| Weekly | Back up and copy the dump off the machine; review the audit log for unexpected settings changes; check the cron logs |
| Monthly | Restore drill into a scratch schema; rotate admin passwords; review stock and best sellers |
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
6. When in doubt, take a backup first and read the audit trail before changing anything.

---

## 14. What members see (repeat-purchase bonus)

The member-facing half of the repeat-purchase stream is read-only. Nothing a member can click pays,
changes or claims a bonus; every number comes from the same engine and ledger the admin screens read
(Phase 17E).

| Surface | What it shows | Where |
|---|---|---|
| **Product page panel** | all ten levels with the per-unit rate and amount on the product's bonus base, the "up to" total, and the sentence that the buyer does not earn on their own purchase | any product whose switch is on |
| **Catalogue badge** | *Up to 29% repeat bonus* on a card whose ladder is on | Shop |
| **Checkout preview** | "Up to ₱145.00 goes to your sponsor chain when this order is paid" — the whole cart's ladder, one line | Checkout |
| **Order page card** | the order's total that went to the sponsor chain; never which upline received which amount | `/orders/{id}` |
| **Earnings → Repeat-purchase bonus** | the member's own awards as an upline, the 1–10 breakdown and a two-clock Active panel | `/earnings?ledger=repeat` |
| **Dashboard tile** | what the member has earned as an upline plus their current Active status | `/dashboard` |
| **Membership paragraph** | the same status, in one sentence | `/membership` |

The tiles, the panel and the preview are **absent** while the global repeat-purchase master is off or
the product's ladder is off/empty. The earnings tab and its export stay reachable either way, because a
disabled stream can still have history.

### The two clocks a member asks about

The flag that decides **this month** comes from **last month's** purchases; this month's purchases
decide next month. The threshold is **Settings → Commissions → Active threshold** (₱500 by default).

- A member who buys ₱2,000 on the 2nd does **not** become Active that month — they become Active for
  the *next* month.
- A member with **no flag yet** (a fresh account, or a month that has not been closed) counts as
  **Active**. After the first monthly close every account gets an explicit row.
- A level that falls on someone who is **not Active** is *skipped*: no money, no compression, the next
  upline keeps its own level number.

The member's own purchases never pay them a bonus; they only decide whether a bonus from their team
reaches them. This is the sentence to use in support replies.

### Support checklist: "my repeat-purchase bonus is zero"

1. **Global switch** — `unilevel_enabled` on **Settings → Commissions**.
2. **Product switch and rates** — the product's ladder card; a zero rate at a level means that level
   pays nothing.
3. **Was the order paid?** — a `pending` order pays nothing; the money is credited when the order is
   paid, never when it is placed.
4. **Chain depth** — the buyer's sponsor chain may be shorter than the level in question.
5. **Active flag** — the monthly rule above; the admin ledger's **Skipped** rows show the reason.
6. **Membership** — an inactive/suspended recipient is skipped, exactly like a not-Active one.

The admin ledger is **Finance → Commissions → Repeat-purchase bonus** (§9). Copy rules for support:
say **repeat-purchase bonus**, say **up to**, say **paid when the order is paid**, and never call it a
rebate or cashback — the buyer is not a recipient.
