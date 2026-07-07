# WooCommerce Agentic Commerce

## Project structure

Monorepo with three WordPress plugin packages:
- `packages/core` — UCP protocol layer (fd-woocommerce-ucp)
- `packages/prism-payment` — Prism stablecoin payment handler (fd-woocommerce-prism)
- `packages/dummy-payment` — Always-succeeds test handler (fd-woocommerce-dummy-payment)

## Security rules

This is a payment system. Every code change touching money flow must be reviewed adversarially.

- **Validate before settle** — any credential, signature, or payment input MUST be validated against stored requirements BEFORE calling external settlement APIs. Validation code that exists but is not wired into the execution path is a critical bug.
- **Check the attacker's view** — after implementing any payment flow, ask: "what happens if the buyer tampers with the amount, recipient, network, or credential?" If the answer isn't "it's rejected before settlement," the code is broken.
- **No silent pass-through** — never forward user-supplied payment data to a settlement API without checking it against server-stored expectations (amounts, recipients, networks). The stored `accepts[]` from `prepare_checkout_payment` is the source of truth, not the credential the buyer sends.
- **Dead code is a bug** — if a validator, guard, or check exists but nothing calls it, treat it as a critical finding. Tests passing on isolated validator code does not mean the validator is active.
- **After completing any payment feature, run `/code-review`** before committing.

## Testing

- Unit tests: `cd packages/core && vendor/bin/phpunit` / `cd packages/prism-payment && vendor/bin/phpunit`
- Integration tests require a running Docker store (see fd-woocommerce-demo repo)
- Plugins are copied into the container via `docker cp`, not volume-mounted

## Conventions

- Payment handler interface: `FD_Payment_Handler` with registry pattern via `fd_ucp_register_payment_handlers` action hook
- Amounts are in minor units (cents) internally, converted to major units only at API boundaries
- Address input accepts multiple formats — `FD_UCP_Address::normalize()` canonicalizes to schema.org keys
- WooCommerce orders are created at checkout session time (pending status) for order number availability
- Remove technical jargon (x402, ERC-3009) from merchant-facing UI — keep it in code and developer docs only
