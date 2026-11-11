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
| **Payout register** | requests by status and method, the amount held now, completed value and fees |
| **Registration-code revenue** | codes issued/redeemed/expired per package and their face/realised value |
| **Finance health** | the seven invariants; a red row lists examples and links to the fixing screen |
| **Orders by status** | the lifecycle at a glance |
| **Top products** | best sellers in the window |

**Export CSV** offers one file per report for the selected window. Every download is audited.

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
