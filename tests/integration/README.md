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

## What the integration suite adds

- The real `process_payment()` → gateway delegation → `payment_complete()` →
  `finalize_advance_order()` chain, including the late-recorded-transaction
  ordering (`woocommerce_order_status_changed` priorities 9 vs 10).
- The block-checkout Store API path (`filter_store_api_cart_errors` payment-method
  gating) with a real cart.
- HPOS (`custom_order_tables`) finalization vs legacy post storage.
- The manual "mark advance as paid" admin-post flow with nonce + capability.

## Setup (once per machine / CI image)

```bash
# 1. Install WordPress and its PHPUnit test framework.
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
composer install

# 2. Install WooCommerce under the test WordPress plugins directory, or point
#    WC_TEST_PLUGIN_FILE at an existing woocommerce.php file.
```

## Run

```bash
COD_SHIELD_ORDER_STORAGE=legacy composer test:integration
COD_SHIELD_ORDER_STORAGE=hpos composer test:integration
```

`composer test` remains the database-free local suite. `composer test:all` runs that suite followed by integration tests and therefore requires the WordPress, WooCommerce and MySQL setup above.

## Writing a new lifecycle integration test

1. Create the order with the WC test factory: `WC_Helper_Order::create_order()`.
2. Seed the plugin options via `update_option()` (see `Core::OPT_*`).
3. Register the stub gateway and configure `Core::OPT_GATEWAYS => ['cod_shield_stub']`.
4. Drive the flow: `$proxy->process_payment( $order_id )` (or the Store API
   checkout route), then assert on `$order->get_total()`, `get_payment_method()`,
   `get_meta(...)`, `get_status()`, and the order notes.

Use `tests/integration/Stub_Gateway.php` to control the delegation outcome
(`$should_succeed`, `$charge_override`, `$txn_id`).

## Automated coverage

- [x] `process_payment` success: total swapped to advance, meta attached,
      delegation invoked, order finalizes to COD with full total + processing.
- [x] `process_payment` failure: total + proxy method restored; `OPT_FAILURE`
      cancels or leaves pending.
- [x] Overcharge guard (gateway charges full total): order flagged, not
      converted to COD, remaining reduced.
- [x] Fulfilment guard: unpaid advance moved to processing → reverted to pending.
- [x] Manual mark: converts to COD after a failed attempt and is idempotent.
- [x] Block-checkout `filter_store_api_cart_errors`: errors added only when COD
      is the chosen method; non-COD orders never blocked.
- [x] Block-checkout COD fee: cart preview remains singular across repeated calculations and switching away from COD removes it.

GitHub Actions runs this suite under legacy and HPOS storage against the oldest
supported WordPress/PHP pair and the current test pair. Credentialed gateway
sandbox coverage remains the release smoke test in `docs/release-smoke-test.md`.
