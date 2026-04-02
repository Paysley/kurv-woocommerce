=== Kurv Payments for WooCommerce ===
Contributors: kurv
Tags: woocommerce, payment, gateway, kurv
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments through Kurv directly in your WooCommerce store.

== Description ==
Kurv Payments for WooCommerce redirects customers to a secure, hosted Kurv
payment page to complete their purchase. Supports WooCommerce classic checkout
and the new Cart & Checkout blocks.

= Key Features =
* Secure hosted payment page — no card data touches your server
* Live and test (sandbox) mode with separate API keys
* Partial and full refunds from WooCommerce admin
* WooCommerce Blocks (Gutenberg checkout) support
* HPOS (High-Performance Order Storage) compatible
* Transaction logging for debugging

= Requirements =
* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 8.1 or higher
* A Kurv merchant account and API key

== Installation ==
1. Upload the `kurv-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to WooCommerce → Settings → Payments → Kurv Payments.
4. Enter your API key from the Kurv developer portal and save.

== Frequently Asked Questions ==

= Where do I get my API key? =
Log in to the Kurv developer portal and navigate to API Keys.

= Does this support WooCommerce Blocks checkout? =
Yes. The plugin is fully compatible with the WooCommerce Cart & Checkout blocks.

= Does this support refunds? =
Yes. Partial and full refunds are supported from the WooCommerce order screen.

== Changelog ==
See changelog.txt for full version history.

== Upgrade Notice ==
= 1.0.0 =
Initial release.
