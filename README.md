# Eco-wealth frontend — continued design draft

## Included
- `index.html` — responsive landing page with the Eco-wealth identity, animated botanical hero, product-led participation section, growth-system overview, journey, sandbox preview and expanded FAQs.
- `assets/logo.png` — supplied Eco-wealth logo.
- `simulator.html` — original browser-local Harvest simulator provided as the prototype reference.

## Preview locally
Keep the folder structure intact. Open `index.html` in a modern browser, or run:

```bash
python -m http.server 8000
```

Then visit `http://localhost:8000`.

## Caching while editing
`.htaccess` makes the browser revalidate every front-end file (`index.html`, CSS, JS, images and fonts) on each load, so one normal reload shows your changes — no Ctrl+F5 needed. Unchanged files still answer `304 Not Modified`, so nothing gets slower, and the `system/` application keeps its normal caching. The rule only works when the site is served by Apache (e.g. `http://ecowealth.test`); when you open `index.html` directly from disk the browser decides on its own.

## Important
This is a static frontend prototype. The simulator is not connected to a production account, database, payment gateway or live payout service. Product information and final compensation terms are not invented here; they should be added from verified official material when available.
