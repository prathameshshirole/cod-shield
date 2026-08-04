# Integration tests (WordPress + WooCommerce test framework)

These tests exercise the **full** COD-advance money lifecycle end-to-end against a
**real** `WC_Order` — `process_payment()` delegation, webhook-driven finalization,
the Store API block-checkout path, and HPOS order storage.

They are separate from the pure-engine suite (`tests/unit/`) and the standalone
lifecycle harness (`tests/run-lifecycle.php`) because they require a running
WordPress + MySQL + WooCommerce test environment, which is not available in the
default (dependency-free) dev setup.

## What runs without a database (now)

| Runner | Covers | Env |
| --- | --- | --- |
| `composer test` → PHPUnit (`tests/unit/`) | Pure calculation engines, meta parsing, button-label regex, settings resolver (68 tests) | Plain PHP |
| `composer test` → `tests/run-lifecycle.php` | Money-lifecycle **logic**: `do_finalize` (txn-gating, normal, overcharge guard), fulfilment revert guard, manual mark, idempotency (24 tests) | Plain PHP + fake order |
| `php tests/run-tests.php` | Advance/fee engines (no composer) (25 tests) | Plain PHP |

## What these integration tests add (CI)

- The real `process_payment()` → gateway delegation → `payment_complete()` →
  `finalize_advance_order()` chain, including the late-recorded-transaction
  ordering (`woocommerce_order_status_changed` priorities 9 vs 10).
- The block-checkout Store API path (`filter_store_api_cart_errors` payment-method
  gating) with a real cart.
- HPOS (`custom_order_tables`) finalization vs legacy post storage.
- The manual "mark advance as paid" admin-post flow with nonce + capability.

## Setup (once per machine / CI image)

```bash
# 1. Install the WordPress + WooCommerce test frameworks.
bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer install          # brings in phpunit, woocommerce/woocommerce dev

# 2. Register the stub delegation gateway + load the plugin.
#    The integration bootstrap (tests/integration/bootstrap.php) requires the
#    plugin main file, registers WC_Gateway_COD_Shield_Stub, and points
#    Core::OPT_GATEWAYS at it.
```

## Run

```bash
composer test:integration   # phpunit -c phpunit-integration.xml.dist
```

## Writing a new lifecycle integration test

1. Create the order with the WC test factory: `WC_Helper_Order::create_order()`.
2. Seed the plugin options via `update_option()` (see `Core::OPT_*`).
3. Register the stub gateway and configure `Core::OPT_GATEWAYS => ['cod_shield_stub']`.
4. Drive the flow: `$proxy->process_payment( $order_id )` (or the Store API
   checkout route), then assert on `$order->get_total()`, `get_payment_method()`,
   `get_meta(...)`, `get_status()`, and the order notes.

Use `tests/integration/Stub_Gateway.php` to control the delegation outcome
(`$should_succeed`, `$charge_override`, `$txn_id`).

## Coverage targets (mirrors REVIEW-1.1.1 §2 Major 2)

- [ ] `process_payment` success: total swapped to advance, meta attached,
      delegation invoked, order finalizes to COD with full total + processing.
- [ ] `process_payment` failure: total + proxy method restored; `OPT_FAILURE`
      cancels or leaves pending.
- [ ] Overcharge guard (gateway charges full total): order flagged, not
      converted to COD, remaining reduced.
- [ ] Fulfilment guard: unpaid advance moved to processing → reverted to pending.
- [ ] Manual mark: converts to COD after a failed attempt.
- [ ] Block-checkout `filter_store_api_cart_errors`: errors added only when COD
      is the chosen method; non-COD orders never blocked.
