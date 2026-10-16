# Release smoke test

Run this checklist on a staging store before tagging a release. Use sandbox/test credentials and products that do not require fulfilment.

## Compatibility contract

A gateway is compatible only for the exact plugin version, WooCommerce version, storage mode and checkout type that passed this checklist. SureOrder limits its selector to explicitly supported gateway classes, but appearing there is still not evidence that the installed gateway version passed this matrix.

Use these result values in the matrix: `Not run`, `Pass`, `Fail` or `Blocked`. Add one row for every tested combination; do not publish a compatibility claim for a `Not run`, `Fail` or `Blocked` row.

| Gateway plugin + version | WP / WC / PHP | Flow (redirect/inline) | Checkout (classic/blocks) | Storage (legacy/HPOS) | Advance lifecycle | Failure + retry | Webhook replay | Result | Evidence/order IDs |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `<name + version>` | `<versions>` | `<flow>` | `<checkout>` | `<storage>` | Not run | Not run | Not run | Not run | `<links/IDs>` |

## Coverage

- Test the oldest supported and latest supported WordPress/WooCommerce versions.
- Repeat each supported gateway flow with HPOS enabled and disabled.
- Test classic checkout and Checkout Blocks separately when both are supported.
- Include both redirect and inline/modal gateways only when the release intends to label both flow types compatible.
- Record the gateway plugin version, WordPress/WooCommerce versions, PHP version, storage mode and checkout type for every result.

## Minimal setup and fail-closed checks

1. Enable SureOrder while keeping WooCommerce's native COD gateway enabled.
2. Choose **Fixed Amount** or **Percentage**, enter the amount and choose exactly one candidate gateway.
3. Place a test order to prove that the chosen gateway requests only the advance.
4. Disable or make the selected gateway unavailable while the advance still applies. Confirm native COD is hidden, the advance cannot be bypassed and unrelated payment methods remain available.
5. Restore the gateway and confirm COD advance becomes available again.

## Advance lifecycle

1. Place a COD-advance order and confirm the gateway requests only the advance.
2. Complete payment and confirm the full order total is restored, the payment method becomes native COD, the transaction ID is recorded, and only the remaining balance is shown as payable on delivery.
3. Abandon and decline payments; confirm the full total and retryable proxy are restored, or the order is cancelled when blocking is configured.
4. Retry from the pay-for-order page and confirm no full-payment gateway is offered.
5. Replay the success webhook and confirm no duplicate notes, fees or status changes.
6. Retry while the first attempt is awaiting gateway confirmation but has no transaction ID yet; confirm no second gateway transaction is initiated.
7. Verify customer/admin emails and the order screen show matching advance and remaining amounts.
8. With PDF Invoices & Packing Slips for WooCommerce installed, generate the invoice PDF and confirm the totals show the advance paid online and the balance due on delivery; a packing slip generated for the same order is unaffected.

## Cancellation protection

1. Place an advance order and let it age past `woocommerce_hold_stock_minutes` with the "cancel unpaid orders" setting enabled; confirm the order is **not** auto-cancelled while it is awaiting its advance.
2. Cancel an awaiting-advance order that has a recorded transaction id (simulate a captured charge whose finalization never ran); confirm the order carries the "ADVANCE RECONCILIATION REQUIRED" note and the advance shows as review/pending, not silently dropped.
3. On a cancelled/review order, open pay-for-order and confirm retrying the advance is refused with the "already being processed" notice and no second charge is initiated.

## Payment diagnostics, delayed confirmation and recovery

Run these cases for each checkout/storage combination in the compatibility matrix. Use a controlled staging gateway or lifecycle fixture to delay persistence and replay callbacks; record order IDs, notes, scheduler results and `cod-shield` log entries. Never edit production orders to simulate these cases.

1. **Missing transaction:** trigger the gateway's success/status event before it saves a transaction ID. Confirm the advance stays unpaid, fulfilment is blocked, and the `missing_transaction` diagnostic appears once. Repeat the event with unchanged evidence; confirm the note/log is not duplicated. No payment may be inferred from status, age or a gateway order reference.
2. **Delayed persistence without another status event:** save a valid transaction ID later, leaving the status unchanged. Confirm one `cod_shield_reconcile_advance_order` action is queued for the order in Action Scheduler (group `cod-shield`). Run the queue and reload the order; confirm the full total, native COD, recorded advance and remaining balance match a normal success. Repeat using WP-Cron with Action Scheduler unavailable in a controlled environment; confirm the fallback event performs the same catch-up. Verify scheduler health if either path is delayed.
3. **Duplicate saves/callbacks:** save the same awaiting order repeatedly before queue execution, then replay both the scheduled action and success callback. Confirm one pending reconciliation per order, one successful finalization, no duplicate success notes or monetary changes, and no recursive action loop from finalizer/diagnostic saves.
4. **Gateway mismatch:** persist a transaction ID while the payment method differs from the recorded advance gateway. Run the catch-up action. Confirm `gateway_mismatch`, unchanged unpaid advance/totals/method, no conversion to COD, and no automatic requeue loop. Repeat the rejected callback and confirm the same diagnostic is suppressed. Restore matching gateway evidence only in the fixture, then confirm a valid finalization can run.
5. **Invalid total:** use a fixture with no positive restorable original, advance-plus-remaining or current total. Confirm `invalid_total`, no paid mark and one deduplicated actionable note. Confirm diagnostic logs contain only operational identifiers/status, with no customer details, credentials, signatures, request bodies, tokens or transaction IDs.
6. **Active and stale boundaries:** set the interval to 15 minutes and seed a timestamp just before the boundary; retry release must be absent/refused. At the boundary confirm `stale` and the release control appears only for an eligible pending/failed order and advance. A future timestamp must remain `active`. Confirm the setting defaults to 60 and server-side normalization bounds values to 15–10,080 minutes.
7. **Explicit stale release:** verify in the sandbox gateway that the original attempt collected no money, then use "Release stale attempt for retry" with an authorized order editor. Confirm the full total and advance proxy are restored, the advance remains unpaid, the attempt timestamp is removed, and the audit note records the editor. A subsequent pay-for-order attempt must charge only the advance and create a fresh timestamp. Confirm the original attempt was not cancelled by the release action.
8. **Release rejection:** submit with an invalid/missing nonce or insufficient order permission, then with a transaction ID, saved advance transaction, review flag, paid advance, cancelled order or invalid saved totals. Confirm each request leaves the order unchanged and cannot start another charge. Simulate a transaction arriving between rendering the button and submitting; the server must refuse release.
9. **Legacy attempts:** seed an awaiting attempt without a marker or valid timestamp, including an order older than the stale interval. Confirm `legacy_unknown`, no release control, and no automatic retry/release from age or a crafted release request. Verify collection manually in the gateway before using the separate manual-paid action; never invent a timestamp to make a legacy payment retryable.
10. **Recorded/review states:** confirm transaction-bearing attempts display "Payment recorded" and flagged orders display "Reconciliation review"; neither permits release. Treat these as local recovery states and verify capture/refund status with the gateway before any manual action.

## Automated release checks

Run from the project root and retain complete results with the release evidence:

```bash
composer pot
composer lint
composer test
composer test:standalone
composer test:checkout-js
COD_SHIELD_ORDER_STORAGE=legacy composer test:integration
COD_SHIELD_ORDER_STORAGE=hpos composer test:integration
```

`composer test` includes the database-free unit, lifecycle, checkout and breakdown harnesses. Integration tests require the [WordPress/WooCommerce/MySQL test environment](../tests/integration/README.md). A missing environment is **Blocked**, never a pass. Automated results do not replace credentialed sandbox smoke tests or prove a gateway version compatible.

## Advanced settings

- Set **Minimum order value for COD** and confirm COD is hidden below it without blocking non-COD payments.
- Set a maximum COD order value and confirm COD is hidden only above it.
- Exclude one exact delivery pincode and one prefix such as `400*`; confirm both rules update as the checkout address changes and never block online payment methods.
- Exclude a shipping method by rate ID and by instance (`flat_rate:23`), and a shipping zone. Change the shipping address so each rule matches in turn; confirm COD and the advance proxy disappear with the shipping-method reason in both classic checkout and Checkout Blocks, that a multi-package cart fails when any package matches, and that online methods stay selectable.
- Exclude a product, a product category, and a shipping class. Confirm a simple product and a variation of an excluded product both hide COD with the item reason, a variation of an excluded variation ID hides COD, deleted products never fatal, and the reason updates live after cart changes.
- Configure the native COD gateway's **enable for methods** restriction so WooCommerce itself removes COD for a shipping method. Confirm the advance proxy never appears for that selection (native COD parity) while it still replaces native COD in the normal flow.
- On Checkout Blocks, confirm the ineligibility reason appears as a notice while a COD method is selected and disappears when switching to an online method (no interference).
- Test optional minimum/maximum advance caps at their boundaries.
- Enable the extra COD fee in classic checkout and confirm it appears once, with the configured tax, before the order is placed.
- In Checkout Blocks, select COD and confirm the fee and configured tax appear before placement. Switch to an online method and confirm the COD fee disappears; switch back and confirm it appears exactly once.
- Apply/remove coupons and change shipping while COD advance is selected. Confirm Pay now plus Pay on delivery equals the current fee-inclusive total, including taxable fees.
- Change methods rapidly and simulate a failed cart update. Confirm checkout cannot submit stale amounts and gives an actionable retry message.
- At 320px width and browser zoom, verify payment amounts wrap without clipping. Verify payment updates are announced to screen readers.
- Start below the COD minimum, then raise the cart total above it without reloading. Confirm the COD advance method becomes available and its amounts are current.
- Exercise both configured payment-failure behaviours and the selected post-payment order status.

Attach screenshots or recordings and the relevant order IDs to the release notes. A matrix with blank evidence is incomplete.
