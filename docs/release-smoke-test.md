# Release smoke test

Run this checklist on a staging store before tagging a release. Use sandbox/test credentials and products that do not require fulfilment.

## Compatibility contract

A gateway is compatible only for the exact plugin version, WooCommerce version, storage mode and checkout type that passed this checklist. An enabled gateway appearing in SureOrder's selector is not evidence of compatibility.

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
6. Verify customer/admin emails and the order screen show matching advance and remaining amounts.

## Advanced settings

- Set **Minimum order value for COD** and confirm COD is hidden below it without blocking non-COD payments.
- Set a maximum COD order value and confirm COD is hidden only above it.
- Exclude one exact delivery pincode and one prefix such as `400*`; confirm both rules update as the checkout address changes and never block online payment methods.
- Test optional minimum/maximum advance caps at their boundaries.
- Enable the extra COD fee in classic checkout and confirm it appears once, with the configured tax, before the order is placed.
- In Checkout Blocks, confirm the current limitation: no separate COD-fee line is previewed before placement. After submitting the order, confirm the fee and configured tax are added exactly once to the stored order.
- Exercise both configured payment-failure behaviours and the selected post-payment order status.

Attach screenshots or recordings and the relevant order IDs to the release notes. A matrix with blank evidence is incomplete.
