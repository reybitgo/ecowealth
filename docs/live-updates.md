# Live page updates

The admin product list, the admin dashboard, the order desk (list and one
order), the wallet monitor (all three tabs and one member's wallet), the
member directory (list and one member), the activation queue, the
registration-code list, the commission ledger, the member dashboard's
E-wallet widgets, the member's own membership page, the member's order
history and one order page, and the storefront catalogue/product pages
update themselves without a full page reload. The same pattern is intended
for the other list, detail and dashboard pages.

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
  own `/dashboard`, where the E-wallet KPI and card are live regions
- `membershipSurface($id)` / `touchMembership($id)` — the member's own
  `/membership` page (status, package, activation paths and history)
- `shopProductSurface($id)` / `touchShop($ids)` — the `shop.catalog` grid
  plus one or more `shop.product:{id}` detail pages

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
because no money moved. The same movement also calls `touchMemberDashboard()`
so the member's own `/dashboard` E-wallet KPI and card refresh, and
`touchMember()` so the member directory's wallet column and that member's page
show the new buckets. Member-facing writes that change what the monitor shows
(registration, activation, a status change, a profile name) touch the admin
surfaces only. The detail surface is `admin.wallets:{id}`, so one wallet's
movement does not refresh every other open wallet page.

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
activation queue's `<tbody>` is a held region: the rejection reason is typed
directly into the table, so the client keeps the rows while that input is
dirty and the page shows its stale banner.

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
`member.dashboard:{id}` with only those two wallet regions live.

## Client behaviour

`public/assets/js/app-live.js` fills `[data-live-surface]` containers. It:

- sends `X-Live: 1`, `Accept: application/json` and the stored `If-None-Match`;
- applies only the fragments that changed;
- patches `tbody` rows by `data-live-key`, so unchanged rows keep their DOM
  nodes, focus and hover state;
- restores focus to the same `data-live-focus` action after a row replacement;
- defers swaps while a SweetAlert/Bootstrap modal is open or a live mutation is
  in flight;
- never replaces a region marked `data-live-hold="dirty"` while the user has
  unsaved input in it. It applies the other regions, keeps the form, and reports
  the held keys in `detail.held` so the page can show a stale banner; once every
  held field is clean again it forces one refresh and applies the newer form.
  A `<tbody data-live-hold="dirty">` is held the same way: the activation
  queue's rejection-reason field lives inside its rows.
- updates `history.replaceState()` when a page is clamped to the last page;
- broadcasts after a successful live mutation and forces one local refresh;
- uses a `localStorage`/`storage` fallback when `BroadcastChannel` is missing;
- dispatches `ecowealth:live-applied` after a payload is applied, with
  `detail.surface`, `detail.payload`, `detail.applied`, `detail.deferred` (focus
  was inside) and `detail.held` (unsaved input was kept).

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
  still asks for confirmation before it posts.
- The catalogue's quick view is delegated too: the click handler reads the
  `shop-catalog-quickview` JSON region afresh on every open, and the modal element is
  static, so replacing the whole product grid never leaves a card's button without a
  working quick view. The add-to-cart forms are delegated by `app-cart.js`.

`public/assets/js/admin-live.js` is shared by every live surface with a form
region — the admin pages, the member's `/membership` page, the member order
page and the product page: it consumes `detail.held` and reveals the page's
`[data-live-stale]` banner (“changed in another tab, your unsaved input was
kept — reload to see the latest”). The banner is only shown when a form region
was held back; a payload that applied cleanly hides it again. A
`[data-live-reload]` link in the banner is pointed at the current URL, filters
and page included.

The dashboard's ETag scope also includes the current date: its 30/60-day account windows
change at midnight even when no row is written. The order desk does the same because its
“today” counters use `CURDATE()`. The member directory and the commission ledger also
include the day: `new_week` counts from `CURDATE()` and the ledger's “this month” total
moves at the month boundary. The wallet monitor adds the hour as well, because its
directory shows a relative “last movement” label; a tab left open therefore revalidates
on its next poll without a write. The member order page adds the hour too, because its
payment/correction window labels read the clock. The storefront catalogue and product
pages include the `shop_enabled` setting for the same reason: closing the shop must
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
| `admin.dashboard` | `/admin` | KPIs, catalogue chart, stock health, recent accounts, packages |
| `admin.orders` | `/admin/orders` | header/counter, stats, rows, pagination |
| `admin.orders:{id}` | `/admin/orders/{id}` | status, payment review, fulfilment, items, history, member, delivery, shipment, payment summary, cancel |
| `admin.wallets` | `/admin/wallets` | metrics, stats, tab counts, members tab, top-ups tab, ledger tab |
| `admin.wallets:{id}` | `/admin/wallets/{id}` | heading, badges, buckets, statement, top-up form, top-up history |
| `admin.members` | `/admin/members` | header, stats, filters, rows, pagination |
| `admin.members:{id}` | `/admin/members/{id}` | heading, account, membership, wallet, activations, upline, audit |
| `admin.activations` | `/admin/activations` | header, stats, tabs, rows, pagination |
| `admin.codes` | `/admin/codes` | stats, rows, pagination |
| `admin.commissions` | `/admin/commissions` | header, stats, rows, pagination |
| `member.dashboard:{id}` | `/dashboard` | E-wallet KPI, E-wallet card |
| `member.membership:{id}` | `/membership` | header, current membership, activation paths, history |
| `member.orders:{id}` | `/orders` | rows, pagination |
| `member.order:{id}` | `/orders/{id}` | header, status, payment attempts, delivery, payment summary, history |
| `shop.catalog` | `/shop` | heading, result states, quick-view JSON |
| `shop.product:{id}` | `/shop/{id}` | heading, shop-closed notice, product card |

Planned next: `member.wallet:{userId}`, `member.earnings:{userId}`.

## Deployment

Apply `database/migrations/013_live_revisions.sql` to an existing database.
Fresh installs already get the table from `database/schema.sql`. Without the
table the page still renders, but live updates switch themselves off and the
feed returns a JSON 500.

For the full shared-hosting walkthrough (including Hostinger), see
[deploy.md](deploy.md).
