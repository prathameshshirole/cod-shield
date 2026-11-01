# Internal partial COD implementation

The setup screen has four controls: enable partial COD, fixed/percentage, advance value, and one existing compatible gateway. Disabling partial COD leaves native WooCommerce COD available under its own restrictions. Existing orders retain their recorded advance obligation and gateway for retries.

Old minimum/maximum advance, order minimum, optional COD fees, custom messages, cancellation policy and additional eligibility controls no longer affect new checkout. Their saved options are retained for rollback; the old pure calculation utilities are retained for compatibility. Native WooCommerce shipping/virtual/subscription restrictions remain in force. Existing RIRCOD records remain readable.

## Where the advance appears

- Checkout: live **Pay now** and **Pay on delivery** breakdown. Blocks uses an amount-free method/button label so an initial page-load amount cannot become stale after coupons or shipping changes.
- Admin order edit: **Partial COD payment** sidebar panel, plus inline totals and the orders-list advance column.
- Thank-you and My Account order-details pages: shared payment summary.
- Customer/admin order emails, including plain text, and supported PDF invoices: required advance, received amount and outstanding/delivery balance. Pending payments explicitly show zero received and a separately labelled expected delivery balance.

## Order record and corrections

The order stores required advance, rule and configured value, original total, gateway, received amount, remaining balance, status, transaction ID, payment source, payment timestamp, manual reference and the last correction. WooCommerce order CRUD is used for both legacy and HPOS storage.

**Edit payment details** is inside the order panel. An authorized editor can correct the required advance before collection or record/correct the total actually received, with a required reason and optional manual reference. Balance is calculated. Gateway transaction evidence remains unchanged. Each correction writes an order note identifying the old/new amount, editor, time and reason. Saving is bookkeeping; actual charges/refunds remain in the gateway plugin.

An active or uncertain gateway attempt is locked against editing or another charge. Stale attempts have the existing explicit reconciliation/release action. A page revision prevents overwriting a record changed since the editor opened it; this is not a transactional database lock. Changed order totals below the required/received amount are held for review. If a delayed confirmation arrives while an uncertain payment order is already on hold, payment is recorded and the hold remains for admin review.

## Payment lifecycle fixes

- A transaction ID from the wrong gateway no longer bypasses the unpaid fulfilment guard.
- Cancelled ordinary prepaid orders are not marked for partial-COD reconciliation.
- Payment amount discrepancies, including underpayments and minor-unit differences, record the observed amount and hold the order for review instead of silently approving fulfilment.
- A thrown error after gateway delegation cannot clear an uncertain charge and enable a duplicate retry. Failure handling reloads the order and preserves an already-confirmed synchronous callback.
- Saved amount/gateway agreements survive changes to global settings.
- Pending PDF invoices no longer describe the advance as paid.
- Orders-list “no advance” filtering is restricted to COD orders.

## Official documentation consulted (1 November 2026)

- [WooCommerce Payment Gateway API](https://developer.woocommerce.com/docs/features/payments/payment-gateway-api/): gateway processing and WooCommerce payment completion. Checkout Blocks requires its own integration.
- [WooCommerce HPOS extension recipe book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/): order CRUD and compatible order metaboxes.
- [Razorpay WooCommerce integration](https://razorpay.com/docs/payments/payment-gateway/ecommerce-plugins/woocommerce/integration-steps/): official plugin setup, capture configuration, webhook events and test-mode verification. Use capture, not authorization-only, for a collected advance.
- [Cashfree official WooCommerce plugin documentation](https://github.com/cashfree/cashfree-woocommerce): vendor plugin configuration and domain whitelisting.
- [Cashfree payment webhook overview](https://www.cashfree.com/docs/api-reference/payments/latest/webhooks/overview): verify signatures before trusting webhook payloads. The existing vendor plugin owns this boundary; SureOrder does not introduce another webhook endpoint or credential store.

The partial-order delegation adapter is our implementation; these documents do not certify compatibility with every vendor-plugin version. No Cashfree-specific API, SDK, credential or webhook code was added in this change.

## Verification and remaining release checks

Database-free PHPUnit, lifecycle, checkout, breakdown, editor, customer-display and proxy-delegation tests cover the changed behavior. PHPCS and Blocks JavaScript checks are run locally; the translation template is regenerated.

The real WordPress integration suite cannot start in this workspace because the WordPress test library is missing. Legacy/HPOS integration, responsive admin/browser checks, and real Razorpay/Cashfree sandbox charges and callbacks remain unverified. Before releasing, test fixed/percentage success, failure, cancellation, coupon/shipping updates, delayed/duplicate callbacks, retry after configuration changes, and corrections in both checkout types. Verify the courier/export uses the remaining COD amount rather than the full WooCommerce order total. No deployment or live payment was performed.
