=== Kurv Payments for WooCommerce ===
Contributors: kurv
Tags: woocommerce, payment, gateway, kurv
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.3
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
* Optional pre-authorisation with manual capture from the order screen
* Optional Apple Pay and Google Pay on the hosted payment page
* WooCommerce Blocks (Gutenberg checkout) support
* HPOS (High-Performance Order Storage) compatible
* Server-to-server payment confirmation, so orders complete even if the
  customer closes their browser on the payment page
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

= What happens if a customer closes their browser after paying? =
The order still completes. Kurv confirms every payment to the plugin with a
server-to-server callback, independently of the customer's browser. As a further
safety net the plugin re-checks any order that is still awaiting payment against
the Kurv API after 10 minutes, 1 hour and 6 hours.

= Do I need to configure a webhook or callback URL? =
No. The plugin sends its own callback URL with every payment request. The URL is
shown at the top of the Kurv settings screen for reference.

= Can I change the API host or timeout? =
Yes, with two filters:

`kurv_api_base_url` — receives the base URL and whether sandbox mode is active.
Use it to point a store at a different Kurv host without editing the plugin.

`kurv_api_timeout` — receives the timeout in seconds (20 by default) and the
endpoint being called. Raise it only if a server genuinely needs longer; these
calls can run while a customer waits at checkout.

= Which data is sent to Kurv? =
Kurv is the payment processor for your store, so completing a purchase sends the
order total and currency, the billing name, email address, phone number and
address, and the line items in the order. See the Kurv privacy policy and terms
at https://kurv.com for how that data is handled.

== Changelog ==
See changelog.txt for full version history.

== Upgrade Notice ==
= 1.0.3 =
Recommended. Cuts the API timeout so a slow response cannot hold checkout open
for minutes, stops customer contact details being written to the debug log, and
hardens handling of unexpected API responses.

= 1.0.2 =
Required for live payments. The live API hostname was wrong, so checkout failed
for every store using a live API key. Test/sandbox mode was unaffected.

= 1.0.1 =
Recommended for anyone running 1.0.0. Fixes payment results not being recorded
when a customer closes their browser on the payment page, and adds a scheduled
check that confirms unresolved orders against the Kurv API.

= 1.0.0 =
Initial release.
