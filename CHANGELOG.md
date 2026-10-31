# Changelog: zerofront/module-zero-stock-alert

## 1.3.6

- The cron asks the stock once per product and store view (500 products at a time) instead of once per alert, and only for the products people are waiting for.
- Alerts for products that stay sold out no longer hold back the alerts of products that are back.
- Array-valued form fields no longer cause a 500.

Earlier releases (1.3.5 and before): see the Git tags.
