# Release smoke test

Run this checklist on a staging store before tagging a release. Use sandbox/test
credentials and products that do not require fulfilment.

## Coverage

- Test the oldest supported and latest supported WordPress/WooCommerce versions.
- Repeat the order flow with HPOS enabled and disabled.
- Use one redirect gateway and one inline/modal gateway.
- Run each gateway through classic checkout and Checkout Blocks.

## Advance lifecycle

1. Place a COD-advance order and confirm the gateway requests only the advance.
2. Complete payment and confirm the full order total is restored, the payment
   method becomes native COD, the transaction ID is recorded, and only the
   remaining balance is shown as payable on delivery.
3. Abandon and decline payments; confirm the full total and retryable proxy are
   restored, or the order is cancelled when blocking is configured.
4. Retry from the pay-for-order page and confirm no full-payment gateway is offered.
5. Replay the success webhook and confirm no duplicate notes, fees, or status changes.
6. Enable the COD fee and confirm it appears once, including its configured tax.
7. Verify customer/admin emails and the order screen show matching advance and
   remaining amounts.

Record the gateway plugin versions, storage mode, checkout type, order IDs, and
screenshots in the release notes.
