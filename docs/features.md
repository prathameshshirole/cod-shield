# SureOrder feature set

## Minimal setup

1. Enable SureOrder while keeping WooCommerce's native COD gateway enabled.
2. Choose a fixed advance amount or a percentage of the order total.
3. Choose one enabled gateway that has passed the compatibility smoke test for the store's checkout.

For new installations, minimum and maximum advance limits default to `0` (off), so the configured fixed amount or percentage is applied literally. Existing saved limits are preserved.

If the configured advance is required but the selected collection gateway is not usable, COD is hidden. The plugin never silently falls back to COD without the required advance.

## Core features

- Collect a partial COD advance as a fixed amount or percentage.
- Show the advance paid and remaining COD balance on the order.
- Support classic checkout and Checkout Blocks for the core advance flow.
- Show separate Pay now and Pay on delivery rows, with live cart updates in Checkout Blocks.
- Keep COD orders and advance transactions visible in WooCommerce admin.

## Advanced settings

- Set optional minimum and maximum advance amounts.
- Set an optional **Minimum order value for COD**; orders below it cannot select COD.
- Set an optional maximum order value for COD.
- Exclude exact delivery pincodes or prefixes such as `110001` and `400*`.
- Exclude shipping zones, shipping methods (optionally a specific instance like `flat_rate:23`), products, categories, and shipping classes. Any matching cart item or selected shipping method hides COD and the advance proxy with the same customer-facing reason in classic checkout and Checkout Blocks; variations match their own ID and their parent product's data.
- Mirror WooCommerce's native COD availability: the advance proxy never appears when native COD itself was removed by the COD gateway's "enable for methods" shipping restriction, virtual-cart rules, or when the gateway is disabled.
- Customize the COD title and customer messages.
- Choose what happens after payment succeeds or fails.
- Add an optional flat or percentage COD fee with tax settings. Both checkout types show the fee and configured tax before placement and remove it when switching away from COD.
- Use the global switch to hide COD storewide.

Core currently recognizes the official Cashfree (`WC_Cashfree_Payments`) and Razorpay (`WC_Razorpay`) gateway classes. Gateway compatibility is still version- and checkout-specific; record evidence in `release-smoke-test.md` before calling an installed version compatible. Unsupported or unavailable configured gateways make advance COD fail closed.
