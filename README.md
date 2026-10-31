# Zero_StockAlert

"Tell me when it's back" for sold-out products, for any visitor (no account
needed), and one email when it is.

- **Product page** (Magento's `alert.urls` place, by the price): a sold-out
  simple product asks for an email; a configurable product with sold-out
  variants asks which one ("M / Blue": every variant, Magento's own lists
  leave sold-out ones out) and an email. Posted with HTMX, the answer in
  the form's place; an address already waiting is told so. A honeypot field
  turns bots away; one address can wait for 50 products at most.
- **The email** (cron every 15 minutes, `zero_stock_alert_send`; now:
  `bin/magento zero:stock-alert:send`): once the product waited for is in
  stock, each address gets one email in the store view it asked in: the
  product (the configurable one for a variant, the variant named), photo,
  price, a link to it, and "Didn't ask for it?" (cancels). Template "Back in
  Stock" (Marketing > Email Templates to customize), sender per store.
  The cron reads only what can be bought: the products and store views waited
  for (active store views with the module on), their stock asked once per
  store view for up to 500 products (enabled there, on its website, salable
  in its stock with reservations counted), then the alerts of the products
  that are back, at most 1000 emails a run (the rest next run). Old alerts
  for a product still sold out never hold back one that is back.
- **Reports > Products > Stock Alerts**: products by how many wait for them,
  notified so far, waiting since, in stock now: what to restock first.

Settings: Stores > Configuration > Zero > Stock Alerts (on/off, sender,
template). Table `zero_stock_alert` (waiting / sent / cancelled).

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-stock-alert
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Tests

`dev/tests/zero-e2e/tests/stock-alert.spec.ts` (on the sold-out products in
`ZERO_SOLD_OUT_PRODUCTS`), `stock-alert-admin.spec.ts` (the report).

## Disabling or removing

Its data lives in its own tables (`zero_stock_alert`). With the module's code still in
place, `bin/magento module:disable Zero_StockAlert` followed by `setup:upgrade`
**drops those tables** (Magento's declarative schema), and enabling the module
again brings them back empty. To keep the data, switch the feature off in
Stores > Configuration where it has a setting, or back up first:
`bin/magento setup:upgrade --safe-mode=1` dumps the data before anything is
dropped (`--data-restore=1` puts it back). Deleting the module's code instead
leaves the tables in place.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
