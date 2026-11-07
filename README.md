# Eco-wealth frontend — continued design draft

## Included
- `index.html` — responsive landing page markup with the Eco-wealth identity, animated botanical hero, product-led participation section, growth-system overview, journey, sandbox preview and expanded FAQs.
- `assets/css/style.css` — all landing page styles, including the responsive breakpoints and animations.
- `assets/js/script.js` — page behaviour: navigation, scroll effects, reveals and the rotating product showcases.
- `assets/img/` — logo, favicon, product packs (`assets/img/products/`) and the supporting photography.
- `simulator.html` — original browser-local Harvest simulator provided as the prototype reference.

## Preview locally
Keep the folder structure intact. Open `index.html` in a modern browser, or run:

```bash
python -m http.server 8000
```

Then visit `http://localhost:8000`.

## Caching while editing
`.htaccess` makes the browser revalidate every front-end file (`index.html`, CSS, JS, images and fonts) on each load, so one normal reload shows your changes — no Ctrl+F5 needed. Unchanged files still answer `304 Not Modified`, so nothing gets slower, and the `system/` application keeps its normal caching. The rule only works when the site is served by Apache (e.g. `http://ecowealth.test`); when you open `index.html` directly from disk the browser decides on its own.

## Backend application (`system/`)

`system/` is the EcoWealth application — plain PHP 8 + MySQL, served at
`http://localhost/ecowealth/system/`. It is being built in phases
(see `dmp/plan-ecowealth-cart-system.md`); **Phase 1** shipped the router, layouts, role
guards, settings and test suite, **Phase 2** added the product catalogue and storefront,
**Phase 3** added associate packages (price, direct bonus, the 2–10 unilevel ladder,
contents and the member packages page), **Phase 4** the cart, **Phase 5** the dual-bucket
e-wallet and ledger, **Phase 6** checkout and orders, **Phase 7** manual payments and the
order lifecycle, **Phase 8** fulfilment (pack, ship with tracking, deliver, confirm) and
printable invoices, **Phase 9** registration (standard / code / e-wallet / pending),
registration codes, membership activation with the commission hook, the member dashboard,
genealogy and the member/codes/activation back office, and **Phase 10** direct referral
commissions (the package's direct bonus paid to the sponsor exactly once, into the
withdrawable e-wallet, with the member earnings statement and the admin commission ledger).
Indirect referral commissions (levels 2–10), transfers, payouts and super-login follow in
later phases.

The demo tree from Phase 9 (`juan`, `maria`, `peter`, `sarah`, `david`) and its
wallets/codes are development data: every demo member signs in with `Demo@1234`, and the
seed is removed or replaced before go-live (see the phase log).

- Application notes, setup and tests: [`system/README.md`](system/README.md)
- Running log of decisions, migrations and test results: [`docs/phase-log.md`](docs/phase-log.md)

## Important
The landing page is still a static frontend prototype. The application behind it is real
(login, roles, catalogue, cart, checkout, payments, fulfilment, e-wallet, membership,
registration codes and direct referral commissions as of Phase 10) but the simulator is not
connected to it, and indirect commissions, wallet transfers, payouts and super-login are
delivered in later phases.
Product information and final compensation terms are not invented here; they should be
added from verified official material when available.
