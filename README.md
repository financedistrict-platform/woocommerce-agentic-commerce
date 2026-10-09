<p align="center">
  <h1 align="center">WooCommerce Agentic Commerce</h1>
  <p align="center">
    Make any WooCommerce store discoverable and purchasable by AI agents.
    <br />
    <a href="https://ucp.dev"><strong>UCP Protocol Spec</strong></a> &middot;
    <a href="https://developers.fd.xyz"><strong>Prism Docs</strong></a>
  </p>
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License: MIT"></a>
  <a href="#"><img src="https://img.shields.io/badge/PHP-8.1+-8892BF.svg" alt="PHP 8.1+"></a>
  <a href="#"><img src="https://img.shields.io/badge/WooCommerce-8.0+-96588A.svg" alt="WooCommerce 8.0+"></a>
  <a href="https://ucp.dev"><img src="https://img.shields.io/badge/UCP-2026--04--08%20%7C%202026--08--25%20%7C%202026--01--23-green.svg" alt="UCP 2026-04-08, 2026-08-25, 2026-01-23"></a>
</p>

---

A WordPress plugin ecosystem that implements the [Universal Commerce Protocol (UCP)](https://ucp.dev) for WooCommerce. AI agents discover your store via `/.well-known/ucp`, browse your catalog, and complete purchases — with on-chain stablecoin settlement through [Prism](https://developers.fd.xyz) and the [x402 protocol](https://www.x402.org/).

```
AI Agent                        Your WooCommerce Store                 Prism Gateway
   |                                    |                                    |
   |  GET /.well-known/ucp              |                                    |
   |───────────────────────────────────>|                                    |
   |  <- capabilities, payment config   |                                    |
   |                                    |                                    |
   |  POST /catalog/search              |                                    |
   |───────────────────────────────────>|                                    |
   |  <- products                       |                                    |
   |                                    |                                    |
   |  POST /checkout-sessions           |  POST /payment-requirements        |
   |───────────────────────────────────>|───────────────────────────────────>|
   |  <- session + payment accepts[]    |  <- network, asset, amount, payTo  |
   |                                    |                                    |
   |  POST /complete (ERC-3009 sig)     |  POST /payment/settle              |
   |───────────────────────────────────>|───────────────────────────────────>|
   |  <- completed order                |  <- tx_hash (on-chain)             |
```

## Table of Contents

- [Quick Start](#quick-start)
- [Packages](#packages)
- [Installation](#installation)
- [Configuration](#configuration)
- [API Reference](#api-reference)
- [Custom Payment Handlers](#custom-payment-handlers)
- [Testing](#testing)
- [Development](#development)
- [License](#license)

## Quick Start

Install the plugins into an existing WooCommerce store, point the Prism handler at your gateway, and confirm the store is agent-discoverable.

1. **Install from source.** Copy or symlink each package into `wp-content/plugins/`, then activate **UCP Core** first, followed by the payment handlers:

   ```bash
   packages/core            → wp-content/plugins/fd-woocommerce-ucp
   packages/prism-payment   → wp-content/plugins/fd-woocommerce-prism
   ```

   See [Installation](#installation) for full details.

2. **Set the Prism API key.** In **WooCommerce → Settings → Payments → Prism Stablecoin → Manage**, enter your **Prism Gateway URL** and **API Key**.

3. **Verify discovery.** Confirm your store advertises the UCP profile (replace the host with your store's URL):

   ```bash
   curl https://your-store.example.com/.well-known/ucp | jq .
   ```

## Packages

Three packages in a monorepo. Core works standalone; payment handlers are optional plugins that register via a hook.

| Package | Plugin | Description |
|---------|--------|-------------|
| [`packages/core`](packages/core) | `fd-woocommerce-ucp` | UCP protocol layer — discovery, catalog, cart, checkout sessions, orders. Provides the payment handler interface that any provider can implement. |
| [`packages/prism-payment`](packages/prism-payment) | `fd-woocommerce-prism` | [Prism](https://developers.fd.xyz) payment handler — on-chain stablecoin settlement. Includes WooCommerce admin meta box with block explorer links and Prism reference tracking. |
| [`packages/dummy-payment`](packages/dummy-payment) | `fd-woocommerce-dummy-payment` | Test handler that always succeeds. Useful for developing against the checkout flow without a wallet or testnet funds. Runs only on a `local` or `development` site that opts in, and is never listed in discovery. |

## Installation

### Requirements

- WordPress 6.4+
- WooCommerce 8.0+
- PHP 8.1+
- A [Prism](https://developers.fd.xyz) account (for real payments)

### As WordPress Plugins

Copy or symlink each package into your `wp-content/plugins/` directory:

```bash
packages/core            → wp-content/plugins/fd-woocommerce-ucp
packages/prism-payment   → wp-content/plugins/fd-woocommerce-prism
packages/dummy-payment   → wp-content/plugins/fd-woocommerce-dummy-payment  # optional
```

Activate **UCP Core** first, then any payment handlers. Handlers hook into UCP at `plugins_loaded` priority 25.

The dummy handler stays off unless `wp-config.php` sets the environment to `local` or `development` and opts in:

```php
define( 'WP_ENVIRONMENT_TYPE', 'development' );
define( 'FD_DUMMY_PAYMENT_ENABLED', true );
```

It is not advertised in `/.well-known/ucp`; agents pick it from the checkout session's `payment_handlers`. Never install it on a production store.

## Configuration

### Prism Payment Handler

1. Go to **WooCommerce > Settings > Payments**
2. Find **Prism Stablecoin** > **Manage**
3. Enter your **Prism Gateway URL** and **API Key**
4. Save

Prism Stablecoin is paid only by AI agents through the UCP checkout. It is never offered at the regular store checkout, even when enabled.

### UCP versions

**WooCommerce > Settings > Advanced > UCP versions** sets which Universal Commerce Protocol (UCP) versions agents can use.

| Option | Default | Meaning |
|--------|---------|---------|
| `fd_ucp_version` | latest (currently `2026-08-25`) | Version served when the agent does not ask for another one. New installs and upgrades both start here |
| `fd_ucp_supported_versions` | every other version (currently `2026-04-08`, `2026-01-23`) | Extra versions, listed in `ucp.supported_versions` and served at `/.well-known/ucp/<version>` |
| `fd_ucp_version_negotiation` | `lenient` | `lenient` or `strict` (see below) |

The store reads the agent profile URL from the `UCP-Agent` header (`profile="https://..."`) and uses the `ucp.version` it declares. A checkout session or cart keeps the version it was created with; a later request that declares a different version gets `422 version_unsupported`.

The profile fetch only goes to public HTTPS hosts, has a 3 second timeout and a 128 KiB limit, and is cached for 10 minutes in the WordPress object cache (only within one request when the site has no persistent object cache). A checkout session or cart is pinned only when the agent profile declared a version the store serves; sessions created on a fallback keep following the agent.

**Lenient mode deviates from the UCP spec on purpose.** The spec says a business MUST reject a request whose agent profile cannot be read or declares no version. Lenient mode serves the current version only when the profile cannot be read or declares no version, and logs a `ucp_profile_resolution` warning (WooCommerce > Status > Logs, source `fd-ucp`). A declared version the store does not know, or one the owner disabled, is always rejected with `422 version_unsupported`. Use `strict` to follow the spec exactly (`424 profile_unreachable` or `422 profile_malformed`).

An unknown stored value makes every `/wp-json/fd-ucp/v1` route answer `500 configuration_invalid` and shows an admin notice. The rest of the shop keeps working.

### Authentication

Every route except `POST /catalog/search`, `POST /catalog/lookup` and `/.well-known/ucp` needs a verified calling platform. Send the platform profile URL in `UCP-Agent` (`profile="https://..."`, HTTPS only) and prove it one of two ways:

- **Sign the request.** Add `Signature-Input` and `Signature` (RFC 9421). The store finds the key by `keyid` in the `keys[]` JWK set of the platform profile (ES256 or Ed25519), checks `Content-Digest` against the body and accepts signatures created within the last 5 minutes. The signature must cover `@method`, `@authority`, `@path`, `ucp-agent`, plus `@query` when there is a query string, `content-digest` and `content-type` when there is a body, and `idempotency-key` when that header is sent. `@authority` is the host of the store URL.
- **Present a registered key.** Ask the merchant for a key and send it as `X-API-Key`. The merchant creates it under **WooCommerce > Settings > Advanced > UCP versions > Platform access** by entering the platform profile URL; the key is shown once and only its SHA-256 hash is stored. A key works only together with the `UCP-Agent` profile it was issued for, and the merchant can disable or delete it at any time. A key grants the identity of the profile URL it is registered for, so register only profile URLs you have confirmed belong to the agent you are onboarding.

The verified profile URL (lower-case host, no fragment, no trailing slash) is the platform id. A cart, checkout session or order belongs to the platform that created it. Any other platform, and any record created before the upgrade to schema `1.6.0`, gets `404` as if the record did not exist. Open carts and checkout sessions created before that upgrade are unreachable afterwards.

| Problem | Answer |
|---|---|
| `UCP-Agent` missing, not HTTPS or longer than 191 characters | `400 invalid_profile_url` |
| No `Signature-Input` and no `X-API-Key` | `401 signature_missing` |
| Signature malformed, not covering the required parts, outside the 5 minute window or not matching the key | `401 signature_invalid` |
| `keyid` not in the platform profile, or `X-API-Key` unknown or disabled | `401 key_not_found` |
| `Content-Digest` does not match the body | `400 digest_mismatch` |
| Key is not ES256 (P-256) or Ed25519 | `400 algorithm_unsupported` |
| Platform profile cannot be fetched while verifying a signature | `424 profile_unreachable` |
| `X-API-Key` is registered for another platform profile | `403 profile_not_trusted` |
| Cart, checkout session, buyer, promotions, order or returns of another platform | `404` (`cart_not_found`, `checkout_not_found`, `session_not_found`, `order_not_found`) |
| `POST /checkout-sessions` repeating an `Idempotency-Key` with a different body | `409 idempotency_key_conflict` |
| `Idempotency-Key` longer than 128 characters | `400 invalid_idempotency_key` |
| Cart or checkout session could not be stored | `503 storage_unavailable` |

An `Idempotency-Key` is scoped to the calling platform: repeating it with the same body returns the stored session (`200`), another platform using the same value gets its own session.
`POST /orders/{id}/returns` (with the opaque order id) records a return request (`202`, status `requested`) and an order note. It never creates a refund. The merchant reviews it and refunds from the order screen. `GET /orders/{id}/returns` lists the merchant's refunds and the buyer's return requests.

### Prism Console Setup

1. Log in to the [Prism Console](https://apps.fd.xyz)
2. Navigate to **Configs > Network**
3. Set a **receiving wallet address** for each network you want to accept payments on
4. Ensure the address is not the zero address — settlement will fail silently otherwise

## API Reference

### Discovery

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/.well-known/ucp` | UCP discovery profile — capabilities, payment handlers, store metadata |

### Catalog

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/wp-json/fd-ucp/v1/catalog/search` | Full-text product search |
| `POST` | `/wp-json/fd-ucp/v1/catalog/lookup` | Product lookup by ID |

### Cart

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/wp-json/fd-ucp/v1/carts` | Create cart |
| `GET` | `/wp-json/fd-ucp/v1/carts/{id}` | Get cart |
| `PUT` | `/wp-json/fd-ucp/v1/carts/{id}` | Update cart |
| `DELETE` | `/wp-json/fd-ucp/v1/carts/{id}` | Delete cart |
| `POST` | `/wp-json/fd-ucp/v1/carts/{id}/checkout` | Convert cart to checkout session |

Carts live for 6 hours, then answer `404 cart_not_found`. Every cart read, update and checkout prices the items at the current catalog price, and a product that is no longer purchasable answers `422 invalid_product`.

### Checkout

Items are priced at the current catalog price on create, on every update and again on complete. A payment quote is valid for 15 minutes. After that, `complete` answers `409 quote_expired`; update the session to get a new quote.

Discount totals are negative minor-unit amounts, and `total = subtotal + fulfillment + tax + discount`. Version `2026-01-23` serves discount amounts as positive numbers because its total schema has a minimum of 0.

When a settlement is received but held for merchant review (amount mismatch, reused transaction, or a handler-reported inconsistency), `complete` answers `200` with the checkout in `status: requires_escalation`, a `continue_url` (the https order-received page of the held order) and an error message `{ "type": "error", "code": "payment_on_hold", "severity": "requires_buyer_review" }`. `GET` on the held session returns the same shape to the owning platform. The order stays `on-hold` and a repeated `complete` answers `409 session_requires_escalation`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/wp-json/fd-ucp/v1/checkout-sessions` | Create checkout session |
| `GET` | `/wp-json/fd-ucp/v1/checkout-sessions/{id}` | Get session status |
| `PUT` | `/wp-json/fd-ucp/v1/checkout-sessions/{id}` | Update session (buyer, fulfillment, items) |
| `POST` | `/wp-json/fd-ucp/v1/checkout-sessions/{id}/complete` | Complete with payment credential |
| `POST` | `/wp-json/fd-ucp/v1/checkout-sessions/{id}/cancel` | Cancel session |

### Orders

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/wp-json/fd-ucp/v1/orders/{id}` | Get order details |

`{id}` is the opaque order id returned by `complete` (the WooCommerce order key, `wc_order_...`), never the sequential order number. Orders cannot be listed: there is no `GET /orders`. An unknown id and an order created by another platform both answer `404 order_not_found`. The curl suites under `packages/*/tests/curl` need the demo store to be seeded with a platform key first.

### UCP Capabilities

Each UCP version advertises its own capability set at `/.well-known/ucp/<version>`, per the [UCP spec](https://ucp.dev):

| Capability | 2026-08-25 | 2026-04-08 | 2026-01-23 |
|------------|:---:|:---:|:---:|
| `dev.ucp.shopping.cart` | yes | yes | |
| `dev.ucp.shopping.catalog.search` | yes | yes | |
| `dev.ucp.shopping.catalog.lookup` | yes | yes | |
| `dev.ucp.shopping.checkout` | yes | yes | yes |
| `dev.ucp.shopping.fulfillment` | yes | yes | yes |
| `dev.ucp.shopping.order` | yes | | yes |
| `dev.ucp.shopping.orders` | | yes | |
| `dev.ucp.shopping.buyer_identity` | | yes | |
| `dev.ucp.shopping.promotions` | | yes | |
| `dev.ucp.shopping.returns` | | yes | |

Discount codes are a WooCommerce-specific REST route (`/promotions/validate`, `/checkout-sessions/{id}/promotions`). Only 2026-04-08 advertises them as a capability.

Applying a code adds the coupon to the WooCommerce order. The code is checked against the coupon's limits, minimum spend, product, individual-use and buyer-email rules, and its use is counted when the order is paid. The checkout total and the payment quote are rebuilt from the discounted order. A coupon that stops applying after a later change is dropped from the totals.

Line items must be purchasable and in stock. A variable product's parent is refused: send the ID of a concrete variation.

## Custom Payment Handlers

The core plugin defines a payment handler interface. Any payment provider can integrate without modifying core — just register via the action hook:

```php
add_action( 'fd_ucp_register_payment_handlers', function( FD_Payment_Registry $registry ) {
    $registry->register( new My_Payment_Handler() );
});
```

Your handler implements `FD_Payment_Handler`:

```php
interface FD_Payment_Handler {
    public function id(): string;                                          // e.g. "com.example.stripe"
    public function name(): string;                                        // e.g. "Stripe"
    public function get_ucp_discovery_handlers(): array;                   // advertised in /.well-known/ucp
    public function prepare_checkout_payment( array $input ): ?array;      // called when session is created
    public function validate_instrument( array $instrument ): ?string;    // error message rejects complete before any order
    public function settle_payment( array $input ): array;                 // called on complete with credential
    public function get_ucp_checkout_handlers( ?array $metadata = null ): array;  // shapes checkout response
}
```

The `$input` array passed to `prepare_checkout_payment` includes:

| Key | Type | Description |
|-----|------|-------------|
| `checkout_id` | `string` | Session UUID |
| `total` | `int` | Order total in minor units (cents) |
| `currency` | `string` | ISO 4217 currency code |
| `checkout_base_url` | `string` | Base URL for the checkout endpoints |
| `store_name` | `string` | Store name from WordPress settings |
| `order_label` | `string` | WooCommerce order reference (e.g. `"Order #48"`) |
| `checkout_meta` | `?array` | Previously stored handler metadata (for idempotency) |

See [`packages/dummy-payment`](packages/dummy-payment) for a minimal working example.

## Testing

### Unit Tests (PHPUnit)

```bash
cd packages/core && composer install && vendor/bin/phpunit
cd packages/prism-payment && composer install && vendor/bin/phpunit
```

### Integration Tests

Integration tests run against a live store using curl. Point them at your running WooCommerce store (with the plugins installed and activated).

```bash
# UCP protocol tests (up to 19 checks, depending on the version's capabilities)
# Needs a registered key: UCP_API_KEY=<key> and optionally UCP_PROFILE=<profile url> (default https://fd.xyz/.well-known/ucp)
# Optional: UCP_VER=<version> UCP_OTHER_PROFILE=<profile url> UCP_OTHER_API_KEY=<key of a second platform>
bash packages/core/tests/curl/30-ucp-integration-test.sh [product_id]

# Prism payment tests (15 tests)
bash packages/prism-payment/tests/curl/30-prism-integration-test.sh [product_id]

# Dummy payment handler tests (13 tests)
bash packages/dummy-payment/tests/curl/30-dummy-integration-test.sh [product_id]
```

### Test Summary

| Package | Unit | Integration | Total |
|---------|------|-------------|-------|
| Core UCP | 150 | 19 | 169 |
| Prism Payment | 40 | 15 | 55 |
| Dummy Payment | — | 13 | 13 |
| **Total** | **190** | **47** | **237** |

## Development

### Project Structure

```
packages/
├── core/                  # UCP protocol layer
│   ├── includes/
│   │   ├── ucp/           # Discovery, catalog, checkout, orders, cart
│   │   └── payment/       # Payment handler interface + registry
│   └── tests/
│
├── prism-payment/         # Prism x402 payment handler
│   ├── includes/
│   │   ├── prism/         # Client, handler, gateway, validator
│   │   └── admin/         # WooCommerce order meta box
│   └── tests/
│
└── dummy-payment/         # Always-succeeds test handler
    ├── includes/
    └── tests/
```

### Key Design Decisions

- **Early order creation** — WooCommerce orders are created at checkout session time with `pending` status, so the order number is available in payment descriptions before settlement occurs.
- **Handler fan-out** — Multiple payment handlers can coexist. The registry calls `prepare_checkout_payment` on all handlers during session creation; the agent selects which handler to pay with at complete time.
- **One payment, one checkout** — Before an order is marked paid, the core records the payment in a unique-key table (`fd_ucp_payment_claims`). A transaction reference can complete only one checkout session, and the Prism handler also records each signed authorization (network, token, payer, nonce) before asking Prism to settle. A payment that was already used by another session is rejected, or the order is put on hold when the settlement has already happened. Retrying the same session is allowed.
- **Settlement responses are checked** — The Prism handler accepts a settlement only when Prism answers `success: true`. A response whose transaction hash, network, payer or amount is missing or differs from the stored payment requirements and the signed payment is not trusted: the order is put on hold for merchant review instead of being marked paid. Handlers can request this with the `hold_reason` key of the settle result. The order meta box shows the settled amount using the decimals of the settled token.
- **No WC cart dependency** — Checkout sessions use a transient WC cart for price calculation only. Sessions are stored in a dedicated database table, not in WC sessions.

### Staging Environment

| Resource | URL |
|----------|-----|
| Prism Gateway | `https://prism-gw.test.1stdigital.tech` |
| Prism Console | [apps.test.1stdigital.tech](https://apps.test.1stdigital.tech) |
| Network | Base Sepolia (`eip155:84532`) |
| USDC Contract | `0x036cbd53842c5426634e7929541ec2318f3dcf7e` |
| Testnet USDC | [Circle faucet](https://faucet.circle.com/) (Base Sepolia) |

## License

[MIT](LICENSE)
