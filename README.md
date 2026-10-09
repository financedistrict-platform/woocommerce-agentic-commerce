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
| [`packages/dummy-payment`](packages/dummy-payment) | `fd-woocommerce-dummy-payment` | Test handler that always succeeds. Useful for developing against the checkout flow without a wallet or testnet funds. |

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

## Configuration

### Prism Payment Handler

1. Go to **WooCommerce > Settings > Payments**
2. Find **Prism Stablecoin** > **Manage**
3. Enter your **Prism Gateway URL** and **API Key**
4. Save

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

### Session token

`POST /carts` and `POST /checkout-sessions` return a random `UCP-Session-Token` response header once. The store keeps only its SHA-256 hash. Send it back as a `UCP-Session-Token` request header on every later call for that cart, checkout session, its buyer, promotions, order and returns. A checkout session made from a cart keeps the cart's token.

| Request without the right token | Answer |
|---|---|
| Cart, checkout session, buyer, promotions | `403` |
| `GET /orders/{id}`, `/orders/{id}/returns` | `404 order_not_found` |
| `GET /orders` | `401 session_token_required` |
| `POST /checkout-sessions` with a used `Idempotency-Key` | `409 idempotency_key_conflict` |

`POST /orders/{id}/returns` records a return request (`202`, status `requested`) and an order note. It never creates a refund. The merchant reviews it and refunds from the order screen.

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

### Checkout

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
# Optional: UCP_VER=<version> UCP_AGENT_PROFILE=<profile url, with trailing slash>
bash packages/core/tests/curl/30-ucp-integration-test.sh [product_id]

# Prism payment tests (15 tests)
bash packages/prism-payment/tests/curl/30-prism-integration-test.sh [product_id]

# Dummy payment handler tests (17 tests)
bash packages/dummy-payment/tests/curl/30-dummy-integration-test.sh [product_id]
```

### Test Summary

| Package | Unit | Integration | Total |
|---------|------|-------------|-------|
| Core UCP | 150 | 19 | 169 |
| Prism Payment | 40 | 15 | 55 |
| Dummy Payment | — | 17 | 17 |
| **Total** | **190** | **51** | **241** |

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
