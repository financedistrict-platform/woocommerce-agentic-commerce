=== Finance District UCP for WooCommerce ===
Contributors: financedistrict
Tags: woocommerce, ai, agents, commerce, stablecoin, payments, ucp
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.3.10
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
* Authenticates every calling AI platform by request signature or a key you issue under WooCommerce > Settings > Advanced > UCP versions; carts, checkouts and orders are visible only to the platform that created them
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
* **Dummy Payment (fd-woocommerce-dummy-payment)** — Test payment handler for local development only. Available from a git checkout, not shipped in release packages, and never active on a production site

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

= 0.3.10 =
* Prism handler discovery calls the public GET /ucp/<ucp-version>/handlers route. Needs Prism with that route.

= 0.3.9 =
* Prism checkout uses Prism's protocol-agnostic payment-requirements endpoint. The checkout handler id and version now always match the store's UCP discovery for the same UCP version. Needs Prism with POST /api/v2/merchant/payment-requirements.

= 0.3.8 =
* Prism order details now show the buyer's wallet as the payer (it showed the store's collector address). Orders paid before 0.3.8 keep the old value. The empty "Prism Reference" row is removed: Prism's settle reply has no payment id; the transaction hash links the order to the payment.

= 0.3.7 =
* Maintenance release: leaner Prism client. No behaviour change for stores.

= 0.3.6 =
* Prism calls use the UCP version in the path. Settle no longer sends a UCP version. Needs Prism with versioned routes.

= 0.3.5 =
* Agent profile fetch: the 128 KiB size cap is now enforced (an oversized profile is rejected instead of being truncated and parsed), and the same-origin redirect hop reuses the already validated address instead of resolving DNS a second time.

= 0.3.4 =
* Prism payment: remove the unused `verify` client call, which sent the raw authorization instead of the payment payload and requirements.

= 0.3.3 =
* An agent profile URL that redirects to another URL on the same origin (for example a trailing slash) is followed once. Any other redirect is rejected with `424 profile_redirected` in both modes, naming the Location, instead of silently serving the latest version.

= 0.3.2 =
* Prism payment: every Prism request now carries the UCP version so Prism serves the matching handler contract, and the `ucp_version` query is no longer sent.
* The 2026-01-23 profile links `services/shopping/openapi.json` and lists only checkout, fulfillment and order.
* The 2026-08-25 profile no longer declares buyer identity.
* Strict mode errors use `profile_unreachable` (424) and `profile_malformed` (422).
* A declared version the store does not know is rejected with `422 version_unsupported` in both modes.
* A session bound to one version now says so when the agent profile declares another.
* The agent profile size limit is 128 KiB.

= 0.3.1 =
* Upgraded stores without a stored UCP version now start on the latest version (2026-08-25), like new installs. Agents that declare 2026-04-08 or 2026-01-23 are still served in their version. A version already saved in settings is kept.

= 0.3.0 =
* Serves the latest UCP version (2026-08-25) by default on new installs; stores upgraded from a release before 0.3.0 keep 2026-04-08, byte-for-byte the same answers as 0.1.0, until changed in settings
* Also serves the other UCP versions (2026-04-08, 2026-01-23 on new installs), advertised in `supported_versions` with a profile per version at `/.well-known/ucp/<version>`
* Picks the version from the agent profile in the `UCP-Agent` header; a checkout or cart keeps the version it was created with
* New settings under WooCommerce > Settings > Advanced > UCP versions
* Accepts original instruments again (`tokenized`, `default`, missing type, `x402` handler id, string credentials); orders record `xyz.fd.prism_payment`
* Prism payment: identifies itself to Prism, asks for the matching UCP version, and accepts both Prism handler entry shapes
* UCP 2026-08-25 answers now follow its schema: error severity `unrecoverable` instead of `fatal`, empty `payment_handlers` as an object, and cart totals with a `total` line

= 0.1.0 =
* Initial release
* UCP discovery, catalog, checkout, and order endpoints
* Prism stablecoin payment handler
* WooCommerce order integration with on-chain payment details
* Address format normalization for multiple input formats
* Credential validation before settlement

== Upgrade Notices ==

= 0.3.2 =
Update the UCP and Prism plugins together.

= 0.3.1 =
Upgraded stores serve the latest UCP version (2026-08-25) by default. Set an older version under WooCommerce > Settings > Advanced > UCP versions to keep it.

= 0.3.0 =
New installs default to the latest UCP version (2026-08-25); upgraded stores keep 2026-04-08 until changed in settings. Update the UCP and Prism plugins together.

= 0.1.0 =
Initial release.
