=== Finance District UCP for WooCommerce ===
Contributors: financedistrict
Tags: woocommerce, ai, agents, commerce, stablecoin, payments, ucp
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WooCommerce store discoverable and purchasable by AI agents via the Universal Commerce Protocol (UCP).

== Description ==

Finance District UCP turns any WooCommerce store into an AI-agent-ready storefront. AI agents can discover your products, create checkout sessions, and complete purchases using stablecoin payments — all through a standard REST API.

**What this plugin does:**

* Adds a `/.well-known/ucp` discovery endpoint so AI agents can find your store
* Exposes a full catalog search and product lookup API
* Provides a structured checkout flow: create, update, complete, and cancel sessions
* Supports order tracking, returns, promotions, and buyer identity
* Integrates with Finance District Prism for stablecoin settlement (USDC, FDUSD)

**How it works:**

1. An AI agent discovers your store via `/.well-known/ucp`
2. The agent searches your catalog and adds items to a checkout session
3. The agent provides buyer and shipping information
4. The agent completes payment using a stablecoin wallet
5. You receive a WooCommerce order with full payment details

**Three packages:**

* **Core (fd-woocommerce-ucp)** — UCP protocol endpoints, checkout flow, catalog API
* **Prism Payment (fd-woocommerce-prism)** — Stablecoin payment handler via Finance District Prism
* **Dummy Payment (fd-woocommerce-dummy-payment)** — Test payment handler for development

== Installation ==

1. Upload the `fd-woocommerce-ucp` folder to `/wp-content/plugins/`
2. Upload the `fd-woocommerce-prism` folder to `/wp-content/plugins/`
3. Activate both plugins through the Plugins menu in WordPress
4. Go to WooCommerce > Settings > Payments > Prism Stablecoin
5. Enter your Prism Gateway URL and API Key
6. Visit `yoursite.com/.well-known/ucp` to verify the discovery endpoint

== Frequently Asked Questions ==

= Do I need a Prism account? =

Yes. Sign up at [Finance District](https://fd.xyz) to get your Prism Gateway API credentials.

= What stablecoins are supported? =

USDC and FDUSD on Ethereum, Base, Arbitrum, Polygon, and BNB Chain. The supported networks depend on your Prism configuration.

= Does this replace normal WooCommerce checkout? =

No. This adds a separate API for AI agents. Your existing checkout for human customers is unaffected.

= What is the Universal Commerce Protocol (UCP)? =

UCP is an open protocol that lets AI agents interact with online stores in a standardized way — discovering products, managing carts, and completing purchases programmatically.

== Screenshots ==

1. Prism Payment details on a WooCommerce order
2. Prism gateway settings in WooCommerce

== Changelog ==

= 0.1.0 =
* Initial release
* UCP discovery, catalog, checkout, and order endpoints
* Prism stablecoin payment handler
* WooCommerce order integration with on-chain payment details
* Address format normalization for multiple input formats
* Credential validation before settlement

== Upgrade Notices ==

= 0.1.0 =
Initial release.
