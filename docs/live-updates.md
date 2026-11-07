# Live page updates

The admin product list and the admin dashboard update themselves without a
full page reload. The same pattern is intended for the other list, detail and
dashboard pages.

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
- `scope($query, $userId)` / `etag(...)` — request-specific ETag
- `matchesIfNoneMatch(...)` / `notModified(...)` — conditional GET
- `json(...)` / `jsonError(...)` — feed responses
- `publish(...)` / `takePublished()` — native POST → redirect → broadcast

`Product::save()`, `Product::setStatus()` and `Product::delete()` touch
`admin.products` after a successful change. A surface must be touched by
**every** write that can change its regions: for products that includes order
and cart reservations, because the list shows `available`, not just `stock`.

## Client behaviour

`public/assets/js/app-live.js` fills `[data-live-surface]` containers. It:

- sends `X-Live: 1`, `Accept: application/json` and the stored `If-None-Match`;
- applies only the fragments that changed;
- patches `tbody` rows by `data-live-key`, so unchanged rows keep their DOM
  nodes, focus and hover state;
- restores focus to the same `data-live-focus` action after a row replacement;
- defers swaps while a SweetAlert/Bootstrap modal is open or a live mutation is
  in flight;
- updates `history.replaceState()` when a page is clamped to the last page;
- broadcasts after a status/delete success and forces one local refresh;
- uses a `localStorage`/`storage` fallback when `BroadcastChannel` is missing;
- dispatches `ecowealth:live-applied` after a payload is applied, with
  `detail.surface`, `detail.payload`, `detail.applied` and `detail.deferred`.

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

The dashboard's ETag scope also includes the current date: its 30/60-day account windows
change at midnight even when no row is written. A tab left open across midnight therefore
revalidates on its next poll. The navbar notification dropdown sits outside the dashboard
surface and updates on the next full navigation; it is a candidate for a future shell
surface.

## Adding another surface

1. Pick one surface key for the page (`admin.orders`, `admin.dashboard`,
   `member.wallet:42`, …). One feed per page; combine multiple widgets into one
   composite revision and one JSON payload.
2. Extract each region into a partial that returns its own wrapper. Use
   `View::capture('admin/orders/_rows', …)`; `View::partial()` only resolves
   `app/views/partials/`.
3. Add the `X-Live` branch to the controller:
   scope → revision → ETag → 304, **then** build the page data.
4. Add `Live::touch(...)` to every write path. Test both the write and the
   no-op/failed path.
5. Add `data-live-surface`, `data-live-region`, `data-live-key` and
   `data-live-action` attributes. Add `data-live-focus` to row actions.
6. Add tests for the revision bump, the 200/304 contract, fragment escaping
   and the pagination/action URLs.
7. Verify two tabs, a hidden tab, focus, a modal and the no-JS form fallback.

## Rules

- **No auto-refresh for forms with unsaved input.** Form pages can watch the
  surface and show a "changed in another tab" banner later, but they must not
  overwrite the user's fields.
- **One timer per surface, one request at a time.** Keep the single-flight,
  jitter, hidden-tab pause and exponential backoff.
- **No polling on hidden tabs.** They receive the broadcast, mark dirty, and
  revalidate once when they become visible.
- **304 before data.** The revision check must run before the list queries.
- **Close the session before feed work.** `session_write_close()` prevents two
  tabs from serializing on PHP's default file session lock.
- **The server is the source of truth.** Fragments are server-rendered; do not
  duplicate money/status/escaping rules in JavaScript.

## Current status

| Surface | Page | Regions |
|---|---|---|
| `admin.products` | `/admin/products` | stats, rows, pagination |
| `admin.dashboard` | `/admin` | KPIs, catalogue chart, stock health, recent accounts, packages |

Planned next: `admin.orders` (+ detail), `admin.wallets` (+ detail), `admin.members`,
`admin.activations`, `admin.codes`, `admin.commissions`, `shop.catalog`,
`shop.product:{id}`, `member.orders:{userId}`, `member.wallet:{userId}`,
`member.earnings:{userId}`.

## Deployment

Apply `database/migrations/013_live_revisions.sql` to an existing database.
Fresh installs already get the table from `database/schema.sql`. Without the
table the page still renders, but live updates switch themselves off and the
feed returns a JSON 500.

For the full shared-hosting walkthrough (including Hostinger), see
[deploy.md](deploy.md).
