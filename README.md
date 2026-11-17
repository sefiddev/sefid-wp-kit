# Sefid WP Kit

The WordPress and WooCommerce plugin for [Sefid](https://sefid.dev), the AI chat assistant: an on-page chat widget, product sync for in-chat search, order tracking and the live store for WooCommerce shops.

افزونه‌ی وردپرس و ووکامرس سفید.

## Install

1. Download this repository as a ZIP (or the latest release) and upload it in WordPress under Plugins › Add New › Upload.
2. Activate «Sefid WP Kit».
3. Paste the public key and secret key from your Sefid panel and save.

Full description, requirements and changelog: [`readme.txt`](readme.txt).

## Security

Every request to Sefid is signed with HMAC-SHA256. The secret key stays on your WordPress server and never reaches the browser. All outbound calls are non-blocking, with a circuit breaker.

Not on WordPress? Connect with the API instead: [sefid-api](https://github.com/sefiddev/sefid-api).

## License

GPLv2 or later, like WordPress itself.
