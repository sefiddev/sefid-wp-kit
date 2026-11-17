=== Sefid WP Kit ===
Contributors: sefiddev
Tags: chatbot, ai assistant, woocommerce, live chat, product search
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the Sefid AI assistant to your site: an on-page chat widget, product sync for in-chat search, and order tracking for WooCommerce stores.

== Description ==

Sefid WP Kit connects your WordPress or WooCommerce site to [Sefid](https://sefid.dev), a hosted AI assistant service. Once connected, a chat widget appears on your site and can answer questions about your business, search your products, and — for WooCommerce stores — tell a signed-in customer where their order is.

**What it does**

* **On-page chat widget.** One small asynchronous script; nothing blocks page rendering.
* **Product sync.** Your catalogue is sent to Sefid so the assistant can find and suggest real products instead of guessing. Field mapping is automatic. Changes go out the moment you save, and anything that fails to send is retried on its own until it lands.
* **Order tracking (optional, experimental).** Mirrors order status into Sefid so a signed-in customer can ask about their own order in the chat.
* **Customer notices (optional).** Your store can push a message into the on-page chat for a signed-in customer, including WooCommerce "note to customer" entries.

**How it is built**

* Every request to Sefid is signed with HMAC. Your secret key stays on your WordPress server and never reaches the browser.
* All outbound calls are non-blocking, with a circuit breaker: if Sefid is slow or unreachable, your site does not slow down.
* Small database footprint — one `sefid_kit` option row and a few expiring transients. No custom tables, no product meta, nothing written to your posts. Uninstalling removes all of it.

== External services ==

This plugin connects to **Sefid**, a third-party service operated by sefid.dev, which provides the AI assistant. The plugin is a client for that service and does not work without it.

**When data is sent**

* When you save your connection keys — to verify the connection.
* When a product is created, updated or deleted, if *Product sync* is enabled — so the assistant can search your catalogue.
* When an order changes status, if *Order tracking* is enabled.
* When your store sends a customer notice, if *Customer notices* is enabled.
* When a visitor opens the chat widget, their browser loads the widget script from sefid.dev and their messages are sent there to be answered.

**What is sent**

* Site URL and your public key on every request.
* With product sync: product name, description, price, stock, categories, images and permalink.
* With order tracking: order number, status and the customer identifier (mobile number or email) used to match the order to the person asking.
* With customer notices: the customer identifier and the message text your store provided.
* From the widget: the visitor's chat messages, and any contact details they choose to give.

Service terms: https://sefid.dev/terms
Privacy policy: https://sefid.dev/privacy

You need a Sefid account to obtain the connection keys. Every feature that sends data is a setting you turn on yourself; with all of them off, the plugin sends nothing beyond the connection check.

== Installation ==

1. Install and activate the plugin.
2. Go to **Sefid** in the WordPress admin menu.
3. Paste the public key and secret key from your Sefid panel and save.
4. The status line at the top of the settings page turns green once the connection is live. The first full catalogue sync then runs in the background.

If you downloaded the plugin from your own Sefid panel, the keys are already filled in and you can skip steps 3 and 4.

== Frequently Asked Questions ==

= Do I need a Sefid account? =

Yes. The plugin is a client for the Sefid service and needs connection keys from a Sefid account.

= Does it work without WooCommerce? =

Yes. The chat widget works on any WordPress site. Product sync and order tracking need WooCommerce.

= Will it slow my site down? =

No. The widget is a single asynchronous script, and every call to Sefid is fire-and-forget with a circuit breaker, so an outage on our side cannot hold up your pages.

= What happens when I uninstall it? =

Every option, transient and scheduled task the plugin created is removed. Nothing is left behind in your database.

== Changelog ==

= 3.3.0 =
* The shop's abilities for AI assistants (WordPress 6.9+ Abilities API): product search and details for signed-in users; a customer's own orders, cart, addresses and checkout for that customer only. On by default; `define('SEFID_ABILITIES', false);` in wp-config.php turns them off.

= 3.2.1 =
* The plugin talks to Sefid on its own address (api.sefid.dev); product sync stays open while a site's address is checked.

= 3.2.0 =
* Your store can answer the chat live (stock, prices) instead of waiting for the next product sync, and push notices into the chat.

= 3.1.0 =
* Signing out of your shop now signs the customer out of the chat at once: the chat asks your shop who is signed in before every message (the proof lasts 10 minutes; it lasted 30 days).
* The chat reads a signed-in customer's own orders from your shop, read only (number, date, status, items, shipping, tracking fields), never your private notes.

= 3.0.2 =
* Local pickup: when your store offers "Local pickup" (or pickup locations), a customer who chooses it in the chat is no longer asked for a street address.
* Shipping prices in the chat are quoted for your store's own country (they were always quoted as if the store were in Iran).

= 3.0.1 =
* The first catalogue sync no longer "finishes" while Sefid is still refusing it (for example before the Sefid team has switched on your store's connection): the page is retried every five minutes and the sync continues by itself once the connection is on. Products waiting in the retry queue are kept the same way.
* Order tracking: an order in a custom status of your store (for example "sent") is reported as paid when WooCommerce counts it as paid, so the chat no longer says it awaits payment.

= 3.0.0 =
* Redesigned settings screen: plain language, one decision per line, readable at any age.
* Fully translatable, with a Persian translation included. Persian shows only when WordPress itself is in Persian; every other language sees English.
* Smarter product sync: a push that fails is queued and retried with a growing delay instead of being lost, and a failed catalogue page is repeated rather than skipped.
* Database footprint reduced to a single option row; the three older rows are migrated and removed on upgrade.
* Uninstall now clears legacy rows as well.

= 2.8.0 =
* Plugin slug changed to `sefid-kit`; the display name stays "Sefid WP Kit".
* Full external-service disclosure added to this readme.
