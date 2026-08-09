# COD Shield feature set

## Minimal setup

1. Enable COD Shield while keeping WooCommerce's native COD gateway enabled.
2. Choose a fixed advance amount or a percentage of the order total.
3. Choose one enabled gateway that has passed the compatibility smoke test for the store's checkout.

If the configured advance is required but the selected collection gateway is not usable, COD is hidden. The plugin never silently falls back to COD without the required advance.

## Core features

- Collect a partial COD advance as a fixed amount or percentage.
- Show the advance paid and remaining COD balance on the order.
- Support classic checkout and Checkout Blocks for the core advance flow.
- Keep COD orders and advance transactions visible in WooCommerce admin.

## Advanced settings

- Set optional minimum and maximum advance amounts.
- Set an optional **Minimum order value for COD**; orders below it cannot select COD.
- Set an optional maximum order value for COD.
- Exclude exact delivery pincodes or prefixes such as `110001` and `400*`.
- Customize the COD title and customer messages.
- Choose what happens after payment succeeds or fails.
- Add an optional flat or percentage COD fee with tax settings. Classic checkout can show this fee before placement; Checkout Blocks currently applies it during order processing and does not preview a separate fee line before placement.
- Use the global switch to hide COD storewide.

Gateway compatibility is version- and checkout-specific. Listing an enabled gateway does not mean it has been verified; record evidence in `release-smoke-test.md` before calling a gateway compatible.
