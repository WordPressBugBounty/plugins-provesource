=== ProveSource Social Proof ===
Contributors: provesource
Tags: social proof, sales popup, fomo, reviews, woocommerce
Requires PHP: 7.4
Requires at least: 4.7
Tested up to: 7.1
Stable tag: 5.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Show recent WooCommerce sales, product reviews and live visitor counts as social proof notifications to build trust and increase conversions.

== Description ==

ProveSource is a [social proof tool](https://provesrc.com/) and [FOMO app](https://provesrc.com/fomo-app/) that boosts conversions on your WordPress and WooCommerce website.

Ever booked a hotel because it was selling fast and you did not want to miss out?
Ever gone to a restaurant because a friend recommended it?

That is social proof.

* Consumers trust what other people do more than advertising when they make buying decisions.
* Consumers who experience FOMO (fear of missing out) are more likely to buy sooner.
* Showing sales notifications and positive reviews has been shown to increase conversions by up to 17%.

ProveSource connects to your WordPress website and WooCommerce store and imports your recent orders, so sales notifications start showing right away.
It shows recent orders, product reviews, page visits and live visitor counts, and each sales notification links to the purchased product for upsell and cross-sell opportunities.

= Feature highlights =

1. Sales notifications that show recent orders to create urgency.
2. Review notifications that show your real 5 star WooCommerce product reviews.
3. Only real orders and reviews are shown, which builds trust.
4. Customize the position, timing, colors, images and the pages each notification shows on.
5. Optimized for mobile, shown at the top or bottom of the screen.
6. Clickable notifications that take visitors to the advertised product.
7. Translated into more than 22 languages.
8. A subtle nudge style that fits your brand instead of an obtrusive popup.

= Integrations =

ProveSource integrates with 100+ services including WooCommerce, Zapier, Mailchimp, Elementor, Google Reviews, Capterra and Judge.me.
See the [full list of integrations](https://provesrc.com/integrations).

ProveSource also builds and maintains [Shapo](https://shapo.io), a platform to collect, manage and display reviews and testimonials with forms, widgets, a wall of love and email campaigns.

= Support =

Our support team is available by live chat and email.

https://www.youtube.com/watch?v=dySZaQnWZa8

= Pricing =

ProveSource is a subscription service with a free plan. Manage your subscription in the ProveSource dashboard (click the logo on the plugin settings page).

== Installation ==

1. Install the plugin from the WordPress plugins screen, or unzip the plugin archive to `/wp-content/plugins`.
2. Activate the plugin on the WordPress "Plugins" page.
3. Select "ProveSource" in your admin sidebar.
4. Click "Connect to ProveSource", log in or sign up, and approve your site. You can also paste the API key and webhook secret from the ProveSource dashboard settings page, accept the Terms of Service and click "Save".
5. In the ProveSource dashboard open "Quick create" and launch the recent WooCommerce orders notification, or click "New Notification" to build one step by step.

The plugin adds the ProveSource script to your website and sends WooCommerce orders and reviews to ProveSource. See "External services" below for exactly what is sent and when.

== Frequently Asked Questions ==

= Which WooCommerce order events should I select? =

Keep the recommended events. Each order is sent once, on the first selected event that fires, so selecting more events never creates duplicates. The recommended set (Checkout Order Processed, Order Status Processing, Order Status Completed and Payment Complete) also catches orders from one-page and funnel checkouts, express payment buttons, subscription renewals and orders created in the admin. Failed, cancelled and refunded orders are never sent.

Orders are sent in the background by WooCommerce's Action Scheduler, which runs on WP-Cron. If WP-Cron is disabled on your site, make sure a server cron job runs it.

== Screenshots ==

1. Social proof notification types and options
2. Social proof in action
3. Dashboard website analytics and stats
4. Dashboard list of notifications

== External services ==

This plugin relies on ProveSource, a service operated by Configo LTD. The plugin sends nothing until you click "Connect to ProveSource" or save an API key.
[Terms of service](https://provesrc.com/terms/), [privacy policy](https://provesrc.com/privacy/).

* **Notification script.** The plugin adds a script to every page of your site that loads `https://cdn.provesrc.com/provesrc.js`. The script shows the notifications, keeps a visitor identifier in a cookie and in local storage, records page visits and, for forms you choose to track in the ProveSource dashboard, the submitted form fields (for example a name and email). It sends this data to `api.provesrc.com`.
* **Connect.** The "Connect to ProveSource" button opens `console.provesrc.com` with your site URL. After you approve the site, the plugin trades a one-time code with `api.provesrc.com` for your API key and webhook secret.
* **Setup.** When you connect or save the settings, the plugin sends your site name, description and URL and the selected order events. When you connect to an account for the first time or switch to another one, and when you click "Re-import Last 30 Orders", it also sends your last 30 orders (the order data listed below), skipping unpaid, failed, cancelled and refunded orders.
* **Orders.** For each new WooCommerce order the plugin sends, in the background: the order ID, date, total and currency, the customer's first name, last name and email (the email is used for the customer's Gravatar image), the billing or shipping city, state and country, and the products (ID, name, price, quantity, link and image). The customer IP address is sent only when an order has no city or country, so the location can be looked up. Street addresses, phone numbers and postcodes are never sent. Failed, cancelled and refunded orders are not sent.
* **Reviews.** Your latest 100 approved WooCommerce product reviews are sent when you connect, when you save the settings and when you click "Import Reviews", and a review is sent again whenever it is approved, edited, unapproved, marked as spam or deleted. A review is sent as the author's display name, the rating, text and date, whether the author is a verified owner, and the product ID, name, link and image. Reviewer emails and IP addresses are never sent.
* **Error reports.** When the plugin hits an error it sends the error message, a short stack trace, your site URL, the plugin, WordPress, WooCommerce and PHP versions and the order ID. No order or customer data is included.
* **Uninstall.** When the plugin is deleted it sends your site URL so the site is removed from your ProveSource account.

== Changelog ==

= 5.0.0 =
* Connect to ProveSource with one click: approve the site in the ProveSource dashboard instead of copying the API key and webhook secret
* Show WooCommerce product reviews as ProveSource review notifications, sent by the plugin with no extra keys
* Support the WooCommerce block checkout, "Checkout Order Processed" now covers classic and block checkout
* Send orders in the background with Action Scheduler, each order is sent once even when several events are selected
* New default order events (Checkout Order Processed, Order Status Processing, Order Status Completed, Payment Complete) catch orders from custom checkouts, express payments, subscription renewals and admin orders. Sites that kept the old default are moved to the new one
* Never send failed, cancelled or refunded orders, and skip unpaid orders when importing recent orders
* Warn on the settings page when background jobs are not running
* Send less personal data: no street addresses, phone numbers or IP lists, prices keep their cents
* Keep the debug log in the database instead of a public file, without order or customer data
* Error reports contain only the message, a short trace, versions and the order id
* Remove the unused analytics consent option, "Checkout Order Created" is replaced by "Checkout Order Processed"
* Clean up settings and notify ProveSource when the plugin is deleted
* Fix product image URLs on https sites, load admin styles and scripts only where needed, support network activated WooCommerce

= 4.0.x =
* Enhance security with webhook secret field (required)

= 3.1.x =
* Updated admin options for WP plugin guideline compatibility
* Update screenshots

= 3.0.x =
* Add woocommerce event selector (multi select)
* Add option to import last 30 orders manually
* Add debug.log downloader for easier debugging

= 2.3.x =
* Update WooCommerce HPOS compatibility

= 2.2.x =
* Fix woocommerce checkout order action handler to support virtual products
* Fix woocommerce address inaccuracies
* Fix woocommerce fatal error on refunded order import
* Add more order capture options (thank-you, order complete, payment complete)
* Add debug toggle for log messages
* Add better order location check

= 2.1.x =
* Fix initial setup to be called on first install (import past orders)

= 2.0.x =
* Add WooCommerce past orders auto import (up to 30 recent orders)
* Fix WooCommerce product name to not include variant text
* Add more IPs lookup for location accuracy
* Add warnings about common 3rd party plugin incompatibility

= 1.4.x =
* Update snippet to latest version
* Fix errors stopping orders from completion
* Fix api key collision with other plugins
* Tested with WordPress 5.3+ and WooCommerce 3.9+

= 1.3.x =
* Added user first name and last name from registration
* Added notice about installation of plugin
* Added WooCommerce image URL https check and replace (mixed content warning)

= 1.2.x =
* Added support for WooCommerce user register

= 1.1.x =
* Added support for new user signup / register event for social proof

= 1.0.x =
* Seamless, simple integration with all notification types supported, exclusive WooCommerce notification type.

== Upgrade Notice ==

= 5.0.0 =
One-click connect, WooCommerce review notifications and more reliable order capture. Settings are kept.
