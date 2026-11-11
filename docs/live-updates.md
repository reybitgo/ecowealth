# Live page updates

The admin product list, the admin dashboard, the order desk (list and one
order), the wallet monitor (all three tabs and one member's wallet), the
member directory (list and one member), the activation queue, the
registration-code list, the commission ledger, the admin package list and
the member package catalogue, the admin settings page, the member
dashboard (the header status pill, the KPI row, the E-wallet, membership,
recent-orders and earnings cards), the member's own membership page, the
member's own e-wallet statement and earnings page, the member's genealogy
and profile pages, the cart page, the checkout page (saved addresses,
order summary and payment/wallet card), the member's order history and
one order page, the storefront catalogue/product pages and the S-Login
console's active and recent session cards update themselves without a full page
reload. The same pattern is intended for the other list, detail and dashboard
pages.

This is **not** a client-side renderer. The server still owns every query,
permission check and HTML fragment; the browser only decides which existing
region to replace. That keeps `money()`, `status_badge()`, escaping and guards
in one place.

## How it works

- Every live page declares one `[data-live-surface]` container.
- The page exposes `data-live-etag`, `data-live-revision`, `data-live-interval`
  and `data-live-enabled`.
- Inside the container, each replaceable block has `data-live-region="…"`.
- The same URL doubles as the JSON feed. A request with `X-Live: 1` and
  `Accept: application/json` returns fragments instead of the layout.
- The client sends the previous ETag. An unchanged surface returns `304`
  before the page reads its list or aggregate queries.
- A successful mutation broadcasts its surface key on `BroadcastChannel`.
  Other tabs revalidate immediately. A visible tab also polls every 30s as a
  fallback for changes made in another browser or by a CLI job.
- A surface can depend on a *shared* revision it mixes into its ETag by
  declaring `data-live-config="config"` (comma-separated for more than one).
  A broadcast of that key revalidates it even though its own surface key did
  not change.
- A hidden tab marks itself dirty and waits for `visibilitychange`; it makes no
  polling requests.

## Feed contract

```http
GET /ecowealth/system/admin/products?q=&status=&page=1
X-Live: 1
Accept: application/json
If-None-Match: "admin.products:12:d37770f2f249"
```

```json
{
  "ok": true,
  "surface": "admin.products",
  "revision": 12,
  "etag": "\"admin.products:12:d37770f2f249\"",
  "fragments": {
    "product-stats": "<div data-live-region=\"product-stats\">…</div>",
    "product-rows": "<tbody data-live-region=\"product-rows\">…</tbody>",
    "product-pagination": "<div data-live-region=\"product-pagination\">…</div>"
  },
  "meta": {
    "page": 1,
    "pages": 4,
    "total": 37,
    "per_page": 10,
    "canonical": "/ecowealth/system/admin/products"
  }
}
```

The same URL is deliberate: `Paginator::url()` and the per-page form keep
pointing at `/admin/products`, not a feed path. The JSON branch is checked at
the top of `AdminProductController::index()`; it never renders the layout.

Response headers: `ETag`, `Cache-Control: private, no-store`,
`Vary: Accept, Cookie`, `X-Content-Type-Options: nosniff`.

## Server helpers

`app/Services/Live.php` owns the protocol:

- `revision($surface)` / `touch($surfaces)` — the `live_revisions` table
- `meta($surface, $scope)` — the page's `data-live-*` metadata, with updates
  switched off (not a failed screen) when the table is missing
- `scope($query, $userId)` / `etag(...)` — request-specific ETag
- `matchesIfNoneMatch(...)` / `notModified(...)` — conditional GET
- `json(...)` / `jsonError(...)` — feed responses
- `publish(...)` / `takePublished()` — native POST → redirect → broadcast
- `orderSurface($id)` / `touchOrder($id, $userId = null)` — the `admin.orders`
  list, one `admin.orders:{id}` detail page, one `member.order:{id}` page and
  (when the buyer id is passed) that buyer's `member.orders:{userId}` history
- `memberOrdersSurface($id)` / `memberOrderSurface($id)` — the member's own
  order history and one order detail page
- `walletSurface($id)` / `touchWallet($id)` — the `admin.wallets` monitor plus
  one `admin.wallets:{id}` detail page
- `memberSurface($id)` / `touchMember($id)` — the `admin.members` directory
  plus one `admin.members:{id}` detail page
- `memberDashboardSurface($id)` / `touchMemberDashboard($id)` — the member's
  own `/dashboard`: the header status pill, the KPI row, the E-wallet card,
  the membership card, the recent-orders card and the earnings card
- `memberCartSurface($id)` / `touchCart($id)` — the member's own `/cart`; the
  same call bumps the checkout picker, because both read the same cart
- `memberWalletSurface($id)` / `touchMemberWallet($id)` — the member's own
  `/wallet` statement (buckets and ledger)
- `earningsSurface($id)` / `touchEarnings($id)` — the member's own
  `/earnings` statement (commission awards)
- `genealogySurface($id)` / `touchGenealogy()` — the member's own
  `/genealogy` page; the page key is per user and every page also mixes the
  shared `GENEALOGY_TEAM` revision into its ETag
- `profileSurface($id)` / `touchProfile($id)` — the member's own `/profile`
  page (name, payout accounts and address book)
- `checkoutSurface($id)` / `touchCheckout($id)` — the checkout page's saved
  addresses, order summary and payment/wallet card
- `touchAddressBook($id)` — bumps the profile and checkout surfaces together
  after an address-book write
- `touchPackages()` — bumps the `admin.packages` list, the `shop.packages`
  catalogue and the shared `CONFIG` revision
- `touchSettings()` — bumps the `admin.settings` page and the shared `CONFIG`
  revision
- `CONFIG` / `revisionOrZero($surface)` — the shared configuration revision
  mixed into the membership and order ETags, and a safe read used while a
  scope is being built
- `PayoutService::settingsFingerprint()` — a short hash of the effective
  withdrawal rules (`min_payout`, the four fees, `usdt_rate`, both gas fees and
  the four channel switches), mixed into the `/payouts` and `/admin/payouts`
  ETags; a settings write, a migration or a direct SQL edit all move it
- `membershipSurface($id)` / `touchMembership($id)` — the member's own
  `/membership` page (status, package, activation paths and history)
- `transferSurface($id)` / `touchTransfer($id)` — the member's own
  `/wallet/transfer` page (limit meters and the recent sent/received list).
  A completed transfer bumps both participants; a refused one bumps nothing.
  The page's ETag also mixes the member's `member.wallet:{id}` revision, so a
  wallet movement that is not a transfer (a commission, a purchase) still
  refreshes the balance card
- `shopProductSurface($id)` / `touchShop($ids)` — the `shop.catalog` grid
  plus one or more `shop.product:{id}` detail pages
- `SLOGIN` / `touchSLogin()` — the S-Login console (`admin.slogin`), bumped by
  `Impersonation::record()` when a session opens, by `Impersonation::mark()`
  when one ends or expires, and by `Impersonation::expireOverdue()` when its
  sweep actually moves a row

`Product::save()`, `Product::setStatus()` and `Product::delete()` touch
`admin.products` after a successful change. A surface must be touched by
**every** write that can change its regions: for products that includes order
and cart reservations, because the list shows `available`, not just `stock`.

An order has a list surface and one detail surface per order. `touchOrder()`
bumps both, and it is called by every write that changes an order: placement,
proof upload, verify/reject/cancel (admin and member), packing, shipping,
delivery, member confirmation, payment expiry and auto-complete. The detail
surface is `admin.orders:{id}`, so a background refresh on one order page never
touches another.

The member side has two surfaces: `member.orders:{userId}` for the history and
`member.order:{orderId}` for one order. Every order write calls
`touchOrder($orderId, $buyerId)`, so the admin desk, the member detail page and
the buyer's history all revalidate; a caller that only knows the order id still
bumps the detail page. The history renders two regions (rows and pagination)
and keeps its filter form outside them. The detail renders six regions:
header, status card, payment attempts, delivery, payment summary and history.
The status card owns the proof / cancel / confirm forms, so it carries
`data-live-hold="dirty"`; its ETag scope also includes the hour because the
payment and correction window labels read the clock. The item snapshot is
immutable and stays static, and the "payment attempts" wrapper is always
present (hidden while the list is empty) so a first proof can appear live.

A wallet has a monitor surface and one detail surface. `WalletService::apply()`
calls `touchWallet()` after every successful balance movement — top-ups,
commissions, purchases, refunds and payouts — and skips a replayed movement
because no money moved. The same movement also calls `touchMemberWallet()`,
`touchMemberDashboard()` and `touchMembership()`, so the member's own `/wallet`
statement, `/dashboard` wallet widgets and `/membership` balance refresh, and
`touchMember()` so the member directory's wallet column and that member's page
show the new buckets. Member-facing writes that change what the monitor shows
(registration, activation, a status change, a profile name) touch the admin
surfaces only. The detail surface is `admin.wallets:{id}`, so one wallet's
movement does not refresh every other open wallet page.

The member's own `/wallet` page is `member.wallet:{id}`. It renders two
regions: the three bucket cards and the statement card. The statement card owns
the ledger filter form, so it carries `data-live-hold="dirty"` and the page
shows the stale banner; the heading and the explanatory alert stay static. The
ETag scope is the query string plus the signed-in member — the page has no
clock-relative label, so it does not widen the scope with the day or hour.

The member's own `/earnings` page is `member.earnings:{id}`.
`CommissionService` calls `touchEarnings()` after every credited and every
skipped award; a replayed award writes no row and does not bump. The page
renders six regions: the four summary cards, the level breakdown (levels 1–10,
counts and amounts), the skipped-award alert, the card heading with its row
count, the award rows and the pagination. The skipped alert is always present
— an empty hidden wrapper while the member has none — so the first skipped
award can appear live, and the level breakdown is always present so the first
indirect award can move a bar without a full reload. The filter form stays
outside the regions, so a background refresh never discards an unsubmitted
filter. The ETag scope includes the hour because the "last award" label is
relative and "this month" counts from `CURDATE()`.

The member's own `/genealogy` page is `member.genealogy:{id}`. It renders six
regions: the heading's team badges, the upline card, the direct-recruit count
badge, its rows, its pagination and the team-by-level card. The referral block
stays static so a refresh never disturbs the copy control. Unlike the other
per-user pages, every genealogy page mixes the shared `member.genealogy`
revision into its ETag: a new registration grows every ancestor's counters and
lists, while an activation, a status change or a profile-name change also
rewrites the upline row on every descendant's page. One shared bump is
therefore more accurate than walking the tree in only one direction. It is
bumped by `RegistrationService`, `ActivationService::complete()`,
`Member::setStatus()` and the profile save; the recruits table paginates on
`page`/`per_page`, and the canonical URL clamps only the page number.

The member's own `/profile` page is `member.profile:{id}`. It renders four
regions: the full-name form, the payout-account form, the address-book card
and the add/edit address form. The three forms carry
`data-live-hold="dirty"` — a background refresh never replaces a typed name,
payout account or address — while the address-book list has no text input and
is replaced freely. The canonical URL drops an `edit` id whose address was
deleted, so the form falls back to "Add an address" on the next poll. The ETag
scope is the query string plus the member; the page has no clock-relative
label. Address writes call `touchAddressBook()`, which bumps this surface and
the checkout picker; a full-name or payout save calls `touchProfile()`.

The member's own `/cart` page is `member.cart:{id}`. It renders one
`cart-content` region: the shop-closed notice, the stock warnings, the
line table and the order summary are all inside it, so an emptied cart
swaps to the "Your cart is empty" card (and back) with the same wrapper
the client already knows. The region owns the quantity and remove forms,
so it carries `data-live-hold="dirty"`; the page shows the stale banner
while a typed quantity is kept. The heading and the Continue-shopping
links stay static. `CartService` calls `touchCart()` after every
successful add, quantity change, step, removal, clear and the conversion
that follows a placed order; a refused write (out of stock, someone
else's line) bumps nothing. The ETag scope adds the shop-open setting and
the shared `shop.catalog` revision, so a price, status or stock change
re-renders the lines without touching every member's cart revision. The
JSON answer to a cart mutation carries the affected surface keys, and
`app-cart.js` hands them to `EcoLive.invalidate()`, so the member's other
tabs revalidate immediately instead of waiting for the 30s poll.

The shared `checkoutSurface:{id}` belongs to `/checkout`. It renders three
regions: the saved-address picker, the order summary and the payment/wallet
card. The summary has no input and is replaced freely; the payment card owns
the radio choice, so it carries `data-live-hold="dirty"` and the page shows
the stale banner. The delivery form, the terms card and the summary footer
(the Place order button) stay static, so `app-checkout.js` keeps its binding.
The address wrapper is always present and `hidden` while the book is empty, so
the first saved address can appear live; the `address` query selects the
highlighted card, and a stale id falls back to the default and is removed from
the canonical URL. The ETag scope includes the `shop.catalog` revision, so a
price or stock change re-renders the summary without touching every member's
cart; `CartService` and `WalletService` bump the per-member checkout surface
after a cart or balance movement.

The member directory and one member page are two surfaces: `admin.members`
and `admin.members:{id}`. `touchMember()` bumps both. It is called by
registration (plus the sponsor and every ancestor's detail surface, because a
new downline row moves their team counters), activation approval, a status
change, a profile-name change, a wallet movement, an order placement or
payment decision and every commission award. A member page renders seven
regions — heading, account, membership (including team counters), wallet and
activity totals, activation history with its own pagination, upline and recent
audit — so a background refresh fails gracefully when a member has no
activations, upline or audit rows.

`admin.activations`, `admin.codes` and `admin.commissions` are single-surface
list pages with no detail page. The activation queue is touched by a manual
request, an approval and a rejection (approval also touches the member's list
and detail surfaces); the code list by a batch issue, a redemption and an
expiry sweep; the commission ledger by every credited or skipped award. The
activation queue's approve and reject buttons open a confirmation modal; the
rejection reason is typed there, not in the table, so the rows are not a held
region and a background refresh never races an unsaved reason.

The admin package list is `admin.packages`. The counters, keyed rows and
pagination are regions; the search/status form stays outside them, and the
status toggle is a normal POST whose CSRF field is primed before the feed
closes the session. Every `Package::save()` and a real `Package::setStatus()`
bump it and the member catalogue `shop.packages` through `touchPackages()`,
which also moves the shared `CONFIG` revision because the membership
activation options read the active packages. The member package page renders
one `package-cards` region (the grid or the empty state); the heading and the
explanatory alert stay static.

The admin settings page is `admin.settings`. It renders six regions: the
placeholder-accounts notice and the five card bodies (shop, receiving
accounts, payout methods, payment windows, fulfilment & invoices). Each body is
held while the admin has typed into that card, and the card headers, footers and
Save buttons stay static. A settings save calls `touchSettings()`, which bumps
this surface and the shared `CONFIG` revision; the membership and order pages
include that revision in their ETags because their activation channels and
payment instructions read the receiving accounts. A `shop_enabled` change also
keeps the existing `touchShop()` call. The **Payout methods** card writes the
withdrawal rules (`min_payout`, `service_fee_<method>`, `usdt_rate`,
`usdt_*_gas_fee`, `payout_*_enabled`); the two payout pages below read them.

The member's `/payouts` page is `member.payouts:{id}` and the admin queue is
`admin.payouts`. The member page renders four regions — the withdrawable card
(both buckets, the minimum, what an open request holds and the ₱/USDT rate),
the request card (the form, or the open request; held while dirty), the history
and its pagination. The queue renders the stat cards with their facts line, the
tab counts, the rows and the pagination. Approve, reject and complete each open
a confirmation modal; a rejection requires its reason in that modal, so no
reason input lives in the rows. Three things move the member page
besides a payout action, and its ETag mixes all of them: the wallet revision
(a commission or purchase refreshes the withdrawable card), the profile
revision (the form prefills the saved payout accounts) and
`PayoutService::settingsFingerprint()` — a hash of the effective withdrawal
rules. The queue mixes the hour and the same fingerprint. The fingerprint is
used instead of a revision row because those settings can also be written by a
migration, a CLI job or a direct SQL edit, which never call `Live::touch()`;
the card still revalidates on its next poll. Both pages declare
`data-live-config="config"`, so the settings save's `config` broadcast
revalidates an open tab at once instead of waiting for the poll.


The member's own `/membership` page is `member.membership:{id}`. It renders
four regions: the heading with the status badge, the current-membership card,
the activation-path block and the activation history with its own pagination.
The path block changes shape with the membership state — three activation
forms (registration code, e-wallet, manual proof) while the member can
activate, an "activation in progress" notice while a request is open, and the
suspended / standard / active-associate notice otherwise — so the feed always
returns the same four region wrappers. The path block is a held region: a
typed code, package, reference or selected proof file is never discarded by a
background refresh. `touchMembership()` is called by an activation request,
approval or rejection, a status change, a profile-name change and every wallet
movement (the e-wallet path shows the balance).

The storefront catalogue is `shop.catalog` and one product is
`shop.product:{id}`. `touchShop()` bumps the catalogue and every listed
product page; `Product::save()`, `Product::setStatus()` and
`Product::delete()` call it, and so does every reservation change — order
placement, member/admin cancellation, payment rejection, the expiry sweep and
a re-upload that reserves stock again. Shipping does not call it: the stock
decrement and the reservation release cancel out, so `available` is
unchanged. The catalogue renders the heading's result count, the result block
(closed shop, empty state, grid and pagination) and a quick-view JSON region;
the search form stays outside them. One product page renders the breadcrumb,
the shop-closed notice and the product card, with the card held while the
quantity input is dirty. If a product is deleted or deactivated while its page
is open, the feed answers 200 with an "unavailable" card instead of a JSON
404 the client would retry forever; reactivating the product swaps the card
back. Both surfaces include the shop-open setting in their ETag scope, so
closing or opening the shop reaches an open tab on its next poll without a
product write.

`/dashboard` is role-dependent: an administrator is delegated to the admin
dashboard and keeps the `admin.dashboard` surface, while a member gets
`member.dashboard:{id}`. The member surface renders six regions: the header
status pill, the KPI row (membership, E-wallet, commissions and team), the
E-wallet card, the membership card, the five newest orders with their
counters, and the earnings card (totals, team figures and recent
activations). The greeting, the referral card and the static copy stay
outside them, so a refresh never disturbs the referral copy control.

Every wallet movement bumps the surface through `WalletService::apply()`;
`Live::touchOrder()` bumps it for the buyer on every order write; an
activation request, approval or rejection and a member status change call
`touchMemberDashboard()`. Team counters are shared, so the ETag scope mixes
the shared `GENEALOGY_TEAM` revision (a registration moves every ancestor)
instead of walking the tree, and the shared `CONFIG` revision covers a
package rename. The day is in the scope because "this month" counts from
`CURDATE()`.

The S-Login console is one surface, `admin.slogin`. It renders two regions —
the **Active sessions** card and the **Recent sessions** card — each carrying
its own count badge, so a badge can never disagree with the rows it labels.
The member picker, the security explainer and the page heading stay outside
them, so a poll never discards a typed username. Every write to
`impersonation_log` bumps the surface: `Impersonation::record()` when a
session opens, `Impersonation::mark()` when it is logged out or expired, and
`Impersonation::expireOverdue()` when a sweep actually moves a row (a
superseded `mark()` — a row already terminal — moves nothing and never
bumps). The console and the audit page both sweep on load, and the feed
sweeps before the revision check, so a poll that expires a session answers
`200` with the new lists instead of a `304` for the list the tab already had.
The ETag scope adds the hour because the **Started** column is a relative
label. `POST /slogin` also queues the surface with `Live::publish()` inside
the member session it creates, so the dashboard's first render broadcasts it
and another open console tab revalidates at once instead of waiting for its
poll. The audit list (`/admin/impersonations`) remains an ordinary page.

## Client behaviour

`public/assets/js/app-live.js` fills `[data-live-surface]` containers. It:

- sends `X-Live: 1`, `Accept: application/json` and the stored `If-None-Match`;
- applies only the fragments that changed;
- patches `tbody` rows by `data-live-key`, so unchanged rows keep their DOM
  nodes, focus and hover state;
- restores focus to the same `data-live-focus` action after a row replacement;
- defers swaps while a SweetAlert/Bootstrap modal is open, a live mutation is
  in flight, or a form is posting (`data-live-busy="1"` in the DOM). The cart's
  AJAX quantity/remove forms set that attribute for the duration of their
  request, so a poll cannot replace a quantity that is still being saved. A
  region that had focus is re-fetched when focus leaves it; the stored ETag is
  dropped first, so the retry is a real `200` and not a `304` for a payload the
  page never applied;
- never replaces a region marked `data-live-hold="dirty"` while the user has
  unsaved input in it. It applies the other regions, keeps the form, and reports
  the held keys in `detail.held` so the page can show a stale banner; once every
  held field is clean again it forces one refresh and applies the newer form.
  A `<tbody data-live-hold="dirty">` is held the same way when a table row owns
  an editable field.
- updates `history.replaceState()` when a page is clamped to the last page;
- broadcasts after a successful live mutation and forces one local refresh;
- uses a `localStorage`/`storage` fallback when `BroadcastChannel` is missing;
- dispatches `ecowealth:live-applied` after a payload is applied, with
  `detail.surface`, `detail.payload`, `detail.applied`, `detail.deferred` (focus
  was inside) and `detail.held` (unsaved input was kept);
- revalidates a surface whose container declares `data-live-config="config"`
  when a `config` broadcast arrives, so a settings save reaches an open page
  whose own surface key did not change (the two payout pages use this for the
  withdrawal rules).

`app-live.js` also exposes `window.EcoLive.invalidate(keys)`, which broadcasts a
surface key and revalidates the matching local surfaces. `public/assets/js/app-cart.js`
uses it after a successful AJAX cart mutation: the JSON answer lists
`member.cart:{id}` and `member.checkout:{id}`, so every other open tab of that member
revalidates at once instead of waiting for its 30s poll. After a cart or checkout payload
is applied, the same script re-reads `/cart/count` and patches the global drawer and the
navbar/sidebar badge — those sit outside the page's `[data-live-surface]` container, so
they are refreshed by the shell script rather than by a region. A server-confirmed
quantity also becomes the input's default value, so it is clean input on the next
payload instead of being mistaken for unsaved typing.

### Regions that appear later

The client only replaces a region that already exists in the page: a fragment
key with no matching `[data-live-region]` is ignored. A card that appears only
after a write (an order's shipment card, its cancel card) must therefore render
an empty `[data-live-region]` wrapper with `hidden` on the initial page. The
feed then replaces that wrapper with the visible card, and the reverse when the
card goes away. Never rely on the client inserting a region it does not know.

### Widgets that need re-initialisation

A swapped region may own a chart, map or other widget that was initialised against the
old DOM. Listen for the event and re-render only the regions that actually applied:

```js
document.addEventListener('ecowealth:live-applied', function (event) {
  var detail = event.detail || {};

  if (detail.surface === 'admin.dashboard'
      && (detail.applied || []).indexOf('dashboard-catalogue') !== -1) {
    renderCatalogue(detail.payload.data.catalogue);
  }
});
```

The admin dashboard uses this hook to destroy and recreate its ApexCharts instances after
the catalogue or stock card is replaced. A deferred region (focus was inside it) is not
listed in `detail.applied`, so its chart is not re-rendered until the actual swap.

Three page behaviours keep swapped forms working:

- `public/assets/js/app-ship.js` re-runs the courier/“Other courier” toggle after the
  fulfilment card is replaced, because the ship form can be swapped in.
- `public/assets/js/app-confirm.js` listens on `document` (delegated submit) instead of
  binding each form at load, so a verify/reject/ship/cancel form that a swap inserted
  still asks for confirmation before it posts. A form with `data-confirm-prompt` asks
  for its required reason in the modal and writes it into a hidden field only after
  the administrator confirms; `requestSubmit()` lets a live status form perform its
  AJAX mutation once the answer is in.
- `public/assets/js/app-payout.js` re-binds its fee/USDT preview and channel toggle
  through `init()` when the `payout-request` region is applied, because a settings
  change (a new fee, rate or minimum) can replace the whole request form with fresh
  `data-*` rules. A `data-payout-bound` marker keeps the current form from being
  bound twice.
- The catalogue's quick view is delegated too: the click handler reads the
  `shop-catalog-quickview` JSON region afresh on every open, and the modal element is
  static, so replacing the whole product grid never leaves a card's button without a
  working quick view. The add-to-cart forms are delegated by `app-cart.js`.

`public/assets/js/admin-live.js` is shared by every live surface with a held
form region — the admin order desk, member directory, settings page and wallet
monitor, the member's `/membership` page, the member's own `/wallet` statement,
the member's `/profile` page, the member's `/payouts` page, the checkout page,
the member order page and the product page: it consumes `detail.held` and reveals the
page's `[data-live-stale]` banner (“changed in another tab, your unsaved input
was kept — reload to see the latest”). The banner is only shown when a form
region was held back; a payload that applied cleanly hides it again. A
`[data-live-reload]` link in the banner is pointed at the current URL, filters
and page included.

The dashboard's ETag scope also includes the current date: its 30/60-day account windows
change at midnight even when no row is written. The order desk does the same because its
“today” counters use `CURDATE()`. The member directory and the commission ledger also
include the day: `new_week` counts from `CURDATE()` and the ledger's “this month” total
moves at the month boundary. The wallet monitor adds the hour as well, because its
directory shows a relative “last movement” label; a tab left open therefore revalidates
on its next poll without a write. The member earnings page adds the hour too, because its
“last award” label is relative and “this month” counts from `CURDATE()`. The member order
page adds the hour too, because its payment/correction window labels read the clock. Every
genealogy page adds the shared team revision instead of a clock: an activation, a status
change or a profile-name change rewrites the upline row on a descendant's page, so the
per-page key alone would leave half the tree stale. The membership and member order pages
add the shared `CONFIG` revision because their activation channels and payment
instructions read the receiving accounts. The checkout page adds the `shop.catalog`
revision so a price or stock change re-renders its summary. The storefront catalogue and
product pages include the `shop_enabled` setting for the same reason: closing the shop must
reach an open tab without a product write. The navbar notification dropdown sits outside
the dashboard surface and updates on the next full navigation; it is a candidate for a
future shell surface.

### Tabbed pages

The wallet monitor is one surface with three tabs. The feed returns the shared regions
(metric badges, stat cards, tab counts) plus only the active tab's regions; a fragment
key with no matching region is ignored by the client. Because the tab is part of the URL,
the existing `window.location.href` feed URL already selects the right feed, and the
canonical URL keeps `tab=` and the filters while clamping only the page number.

## Adding another surface

1. Pick one surface key for the page (`admin.orders`, `admin.dashboard`,
   `member.wallet:42`, …). One feed per page; combine multiple widgets into one
   composite revision and one JSON payload. A list and its detail pages are
   separate surfaces: `admin.orders` for the desk and `admin.orders:{id}` for
   one order.
2. Extract each region into a partial that returns its own wrapper. Use
   `View::capture('admin/orders/_rows', …)`; `View::partial()` only resolves
   `app/views/partials/`.
3. Add the `X-Live` branch to the controller:
   scope → revision → ETag → 304, **then** build the page data. Build the page
   data in one method used by both the page and the feed, so they cannot drift.
4. Add `Live::touch(...)` to every write path. Test both the write and the
   no-op/failed path.
5. Add `data-live-surface`, `data-live-region`, `data-live-key` and
   `data-live-action` attributes. Add `data-live-focus` to row actions. Put a
   `data-live-status` placeholder in a static part of the surface.
6. Put `data-live-hold="dirty"` on any region that owns a form. Keep the form
   region's actions inside it; a region that appears later needs a `hidden`
   empty wrapper on the initial page.
7. Add tests for the revision bump, the 200/304 contract, fragment escaping and
   the pagination/action URLs.
8. Verify two tabs, a hidden tab, focus, a modal, unsaved input in a form (the
   banner must appear and the field must keep its value) and the no-JS form
   fallback.

## Rules

- **No auto-refresh for forms with unsaved input.** A form region marked
  `data-live-hold="dirty"` is never replaced while any field differs from its
  server-rendered value; the page shows a "changed in another tab" banner and
  the user reloads when ready. A field that was merely pre-filled is clean.
- **One timer per surface, one request at a time.** Keep the single-flight,
  jitter, hidden-tab pause and exponential backoff.
- **A shared revision travels in the scope.** A page that reads a setting another
  page writes must mix that setting into its ETag (a revision, the
  `settingsFingerprint()` hash, or the value itself) and declare it with
  `data-live-config`, so the writer's broadcast reaches it.
- **No polling on hidden tabs.** They receive the broadcast, mark dirty, and
  revalidate once when they become visible.
- **304 before data.** The revision check must run before the list queries.
- **Close the session before feed work.** `session_write_close()` prevents two
  tabs from serializing on PHP's default file session lock. Prime
  `csrf_token()` before the close when a fragment renders a form; reading
  `old()`/`errors_all()` after the close is safe because the session is no
  longer writable.
- **The server is the source of truth.** Fragments are server-rendered; do not
  duplicate money/status/escaping rules in JavaScript.

## Current status

| Surface | Page | Regions |
|---|---|---|
| `admin.products` | `/admin/products` | stats, rows, pagination |
| `admin.dashboard` | `/admin` | KPIs, commerce roll-up, catalogue chart, stock health, orders by status, top products, recent accounts, packages |
| `admin.reports` | `/admin/reports` | health widget, KPI cards, sales chart + method table, top products, commissions by level, payout register, codes, orders by status |
| `admin.orders` | `/admin/orders` | header/counter, stats, rows, pagination |
| `admin.orders:{id}` | `/admin/orders/{id}` | status, payment review, fulfilment, items, history, member, delivery, shipment, payment summary, cancel |
| `admin.wallets` | `/admin/wallets` | metrics, stats, tab counts, members tab, top-ups tab, ledger tab |
| `admin.wallets:{id}` | `/admin/wallets/{id}` | heading, badges, buckets, statement, top-up form, top-up history |
| `admin.members` | `/admin/members` | header, stats, filters, rows, pagination |
| `admin.members:{id}` | `/admin/members/{id}` | heading, account, membership, wallet, activations, upline, audit |
| `admin.activations` | `/admin/activations` | header, stats, tabs, rows, pagination |
| `admin.codes` | `/admin/codes` | stats, rows, pagination |
| `admin.commissions` | `/admin/commissions` | header, stats, rows, pagination |
| `admin.packages` | `/admin/packages` | stats, rows, pagination |
| `admin.payouts` | `/admin/payouts` | stats + facts, tab counts, rows, pagination (also follows the payout-settings fingerprint) |
| `admin.settings` | `/admin/settings` | notice, general, shop, receiving accounts, payout methods, payment windows, fulfilment & invoices, commissions, transfers |
| `admin.slogin` | `/slogin` | active sessions card, recent sessions card |
| `member.dashboard:{id}` | `/dashboard` | header pill, KPI row, E-wallet card, membership, recent orders, earnings |
| `member.membership:{id}` | `/membership` | header, current membership, activation paths, history |
| `member.wallet:{id}` | `/wallet` | buckets, send-funds card, withdraw card, statement |
| `member.payouts:{id}` | `/payouts` | withdrawable card, request card (form / open request, held while dirty), history, pagination (also follows the payout-settings fingerprint) |
| `member.earnings:{id}` | `/earnings` | stats, level breakdown, skipped notice, header, rows, pagination |
| `member.genealogy:{id}` | `/genealogy` | team badges, upline, recruits count/rows/pagination, team by level |
| `member.profile:{id}` | `/profile` | name form, payout form, address list, address form |
| `member.cart:{id}` | `/cart` | cart content (notice, stock warnings, lines, summary, empty state) |
| `member.checkout:{id}` | `/checkout` | saved addresses, order summary, payment/wallet card |
| `member.orders:{id}` | `/orders` | rows, pagination |
| `member.order:{id}` | `/orders/{id}` | header, status, payment attempts, delivery, payment summary, history |
| `shop.catalog` | `/shop` | heading, result states, quick-view JSON |
| `shop.product:{id}` | `/shop/{id}` | heading, shop-closed notice, product card |
| `shop.packages` | `/packages` | package cards, empty state |

Planned next: the shared shell chrome (the navbar cart badge/drawer and the
notification dropdown) as one optional surface. The cart page already refreshes the
drawer and badge after its own payloads, but a change made while a page without a
cart surface is open still waits for the next full navigation.

### Phase 15: two data-heavy surfaces

`admin.reports` and the extended `admin.dashboard` read orders, commissions, wallets,
payouts and settings. Rather than touching their surfaces from every one of those
services, both ETag scopes mix the revisions of the surfaces the writes already bump:

```
scope = Live::scope($_GET, $user)
      + '|day:'  . date('Y-m-d')
      + '|orders:'      . Live::revisionOrZero('admin.orders')
      + '|commissions:' . Live::revisionOrZero('admin.commissions')
      + '|wallets:'     . Live::revisionOrZero('admin.wallets')
      + '|payouts:'     . Live::revisionOrZero(Live::ADMIN_PAYOUTS)
      + '|config:'      . Live::revisionOrZero(Live::CONFIG)
```

An order transition, a commission award, a balance movement, a payout decision or a
settings save therefore makes the next poll answer **200** with fresh figures; an
idle tab still gets **304** and pays only the revision lookups. The reports page also
runs `HealthService` in its feed — the seven reconciliation checks — after the
revision check, so a 304 costs no reconciliation. The sales chart is re-rendered from
the payload's `data.sales` when `report-sales` was applied (see
`assets/js/admin-reports.js`); the admin dashboard's ApexCharts keep their existing
`ecowealth:live-applied` handling.

**Not live by design:** `/admin/audit`. An audit trail is read like a document; a
background refresh that reordered its rows while an operator was reading would be
worse than a manual reload. The impersonation console and its audit list follow the
same rule for the same reason.

## Deployment

Apply `database/migrations/013_live_revisions.sql` to an existing database.
Fresh installs already get the table from `database/schema.sql`. Without the
table the page still renders, but live updates switch themselves off and the
feed returns a JSON 500.

Phase 15 adds no new live table: `admin.reports` reuses `live_revisions` and the
revisions the earlier phases already write. Apply `019_audit_and_reporting.sql` for
the report indexes on the same deployment.

For the full shared-hosting walkthrough (including Hostinger), see
[deploy.md](deploy.md).
