# PRD: WooCommerce COD Advance Payment Plugin

**Plugin name:** SureOrder — COD Advance Payments for WooCommerce (display name; legacy slug/folder/text-domain remain `cod-shield` for compatibility)
**Version:** 1.0
**Author context:** Prathamesh Shirole — reduce RTO on COD orders by collecting a partial upfront payment through a compatible WooCommerce gateway.

---

## 1. Problem Statement

Cash on Delivery is the dominant payment method in Indian e-commerce but carries high RTO (Return to Origin) risk from fake or unreliable orders. Customers commit to nothing at checkout, so there's no cost to abandoning or refusing a COD parcel. Store owners need a way to make COD "cost something" upfront — without forcing customers to pay in full — while keeping the checkout experience trustworthy and transparent.

## 2. Goals

- Let the store collect a **partial advance payment** (fixed ₹ amount or % of order total) on COD orders through one enabled gateway that has been compatibility-tested with the store's checkout.
- Let the admin **toggle COD availability** at checkout instantly, without deactivating the plugin or touching WooCommerce's native COD gateway settings.
- Show clear, **admin-configurable messaging** at checkout explaining the advance fee (or that COD is risk-free, if no fee is set) to build trust and reduce checkout drop-off/confusion.
- Make COD-with-advance orders **fully visible and manageable** in the standard WooCommerce admin order screens (list + detail), including how much was paid upfront vs. due on delivery.
- Zero disruption to non-COD checkout flows or existing gateways.

## 3. Non-Goals

- Not a replacement for WooCommerce's core COD gateway — this plugin extends/wraps it.
- Not a full RTO analytics/reporting suite (may be a v2 consideration).
- Not building a new payment gateway — advance payment is delegated to one existing, already-configured WooCommerce gateway.
- Not promising universal compatibility with enabled gateways. Compatibility depends on the gateway plugin version and checkout type and must be tested.
- No multi-currency / international logic beyond what WooCommerce already provides.

## 4. Target User

Store admin running a WooCommerce store with COD as a primary payment method, wanting a fast, low-maintenance way to filter out low-intent COD orders.

---

## 5. Feature Requirements

### 5.1 Global COD Kill Switch

- Admin toggle: **Enable/Disable COD storewide**, independent of plugin activation state and independent of WooCommerce's native "Enable this payment method" checkbox.
- When disabled: COD gateway is hidden from checkout immediately (filtered via `woocommerce_available_payment_gateways`), with no cache/flush step needed.
- This is the emergency "something's wrong, stop taking COD" switch — one click, no settings hunting.

### 5.2 Advance Payment Configuration

- Admin chooses a **mode**: `Disabled` / `Fixed Amount` / `Percentage`.
- **Fixed Amount**: a flat ₹ value (e.g. ₹99) charged upfront on every COD order.
- **Percentage**: a % of cart/order total (e.g. 10%), with:
  - Optional **minimum advance amount** (floor, e.g. never less than ₹49).
  - Optional **maximum advance amount** (cap, e.g. never more than ₹999).
- Optional **Minimum order value for COD** (off by default): orders below it cannot choose COD at all; at/above it, advance collection applies.
- Advance amount is calculated server-side at checkout (never trust client-side JS) and re-validated on order creation.

### 5.3 Compatible Gateway Delegation for Advance Collection

- Admin selects **one existing enabled gateway** to collect the advance.
- Appearing in the selector is not proof of compatibility. The selected gateway version must pass the compatibility matrix in `release-smoke-test.md` for every checkout type the store uses.
- Checkout flow when COD + advance is active:
  1. Customer selects COD at checkout.
  2. If advance mode is active, checkout UI reveals: *"₹99 advance payment required to confirm this order via [gateway]. ₹[remaining] payable on delivery."*
  3. Customer is redirected/prompted through the selected gateway's normal payment flow for **only the advance amount**, not the full order.
  4. On successful gateway payment, the order is created/marked as COD with advance-paid metadata attached.
  5. On failed/abandoned gateway payment, order is not placed (or placed as `pending advance payment`, configurable) — customer can retry.
- Technically: this is implemented as a thin WooCommerce order-level split — the underlying gateway still runs its normal payment intent/transaction for the advance sub-amount, then WooCommerce order is finalized as `cod` payment method with custom meta.
- Fail-closed requirement: when an advance applies and the selected gateway is disabled, missing or otherwise unusable, hide native COD instead of allowing checkout without the required advance. Non-COD payment methods remain unaffected.

### 5.4 Checkout Messaging (COD Messaging)

- The **advance notice** renders **under the COD payment method radio, inside the payment box** (payment method description): e.g. *"Pay ₹99 now, ₹943 on delivery (COD)."* It is deliberately **not** repeated in a box above the payment section.
- The **risk-free message** (advance disabled while COD remains eligible) renders in a small info box directly **above the payment methods section**: e.g. *"Cash on Delivery available — no advance payment required."* Orders below **Minimum order value for COD** do not see this message because COD is unavailable.
- Both messages are **admin-editable text** (with `{advance_amount}`, `{remaining_amount}`, `{percentage}` placeholders) and each has its **own on/off toggle** — admin can disable either message independently without disabling the underlying logic.
- Messaging is written for Indian ecommerce shoppers: the COD label reads "Cash on delivery (COD)" and the advance notice states simply *"Pay X now, Y on delivery (COD)."*
- Messaging renders via the payment method description hook / a lightweight checkout script — no page builder dependency.

### 5.5 Admin Order Visibility

- **Orders list table**: new sortable column "COD Advance" showing amount paid upfront (or "—" if not applicable) for at-a-glance triage.
- **Order detail page**: a metabox/panel showing:
  - Advance amount collected, gateway used, transaction/reference ID, payment status (paid/failed/pending).
  - Remaining COD-due amount (auto-calculated: order total − advance).
- Order notes: automatic note logged on advance payment success/failure for audit trail.
- Filter: admin can filter orders list by "COD with advance" vs. "COD no advance" vs. "Standard COD".

### 5.6 Settings Page

Settings live under **WooCommerce → Settings → SureOrder**. The primary setup is limited to three decisions:

1. **Enable** SureOrder's COD availability; WooCommerce's native COD gateway must remain enabled.
2. Choose **Fixed Amount** or **Percentage** and enter the advance amount.
3. Choose **one compatible gateway** that is enabled and tested for the active checkout.

Clearly label these optional controls as **Advanced settings**:

- Minimum/maximum advance caps and **Minimum order value for COD**.
- Optional maximum COD order value and exact/prefix delivery-pincode exclusions.
- Custom COD title and message text/toggles.
- Extra COD fee and tax controls.
- Advance-failure behaviour and post-payment order status.

The settings page must warn that an unusable gateway configuration hides COD whenever an advance is required.

---

## 6. User Flows

**Flow A — Standard COD, no advance (advance disabled):**
Checkout → select COD → risk-free message shows above the payment box → place order → standard WooCommerce COD order created.

**Flow B — COD with advance required:**
Checkout → select COD → advance notice shows under the COD option ("Pay ₹99 now, ₹943 on delivery (COD)") → place order → redirected to the selected compatible gateway for advance amount only → payment success → order created as COD with advance-paid meta, order note logged → thank-you page confirms both amounts.

**Flow C — Advance payment fails:**
Gateway declines/customer abandons → order not placed (default) OR order placed with status `on-hold`/custom `advance-pending` status (configurable) → admin sees it flagged in order list → customer can retry from "Pay" link if `on-hold`.

**Flow D — Admin disables COD mid-day:**
Admin flips global toggle off → COD instantly disappears from checkout for all customers, no cache clear, no plugin deactivation, no code touch.

---

## 7. Technical Architecture

### 7.1 Plugin Structure

```
cod-shield/
├── cod-shield.php              # Main plugin file, header, activation hooks
├── includes/
│   ├── class-cod-shield-core.php       # Bootstrap, hook registration
│   ├── class-cod-shield-settings.php   # WC Settings API integration
│   ├── class-cod-shield-checkout.php   # Checkout UI, messaging, fee calc
│   ├── class-cod-shield-gateway-bridge.php  # Delegates advance charge to selected gateway
│   ├── class-cod-shield-order.php      # Order meta, admin columns, metabox, notes
│   └── class-cod-shield-ajax.php       # Any AJAX needed for live fee preview
├── assets/
│   ├── js/checkout.js              # Live update of advance amount as cart changes
│   └── css/admin.css
├── languages/
└── readme.txt
```

### 7.2 Key WooCommerce Hooks

- `woocommerce_available_payment_gateways` — enforce global COD kill switch.
- `woocommerce_payment_gateways` — register gateway wrapper if needed, or extend `WC_Gateway_COD`.
- `woocommerce_review_order_before_payment` / `woocommerce_thankyou` — render info messaging.
- `woocommerce_checkout_create_order` — attach advance amount, gateway, threshold snapshot as order meta.
- `woocommerce_checkout_process` — server-side recalculation and validation of advance amount (never trust posted values).
- `manage_edit-shop_order_columns` / `manage_shop_order_posts_custom_column` — admin list column (with HPOS-compatible equivalents: `woocommerce_shop_order_list_table_columns` / `woocommerce_shop_order_list_table_column_content`).
- `add_meta_boxes` (order edit screen) — advance payment details panel.
- `woocommerce_admin_order_data_after_billing_address` — inline summary alternative.

### 7.3 Data Storage (HPOS-compatible)

Use `WC_Order::update_meta_data()` / `get_meta()` against Order objects (not direct postmeta queries), so the plugin works with both legacy post-based orders and High-Performance Order Storage.

Order meta keys:

| Key | Description |
| --- | --- |
| `_cod_shield_advance_amount` | Amount charged upfront |
| `_cod_shield_advance_mode` | `fixed` / `percentage` snapshot at time of order |
| `_cod_shield_gateway_used` | Gateway ID used for advance |
| `_cod_shield_gateway_txn_id` | Transaction/reference ID from gateway |
| `_cod_shield_advance_status` | `paid` / `failed` / `pending` |
| `_cod_shield_remaining_due` | Order total − advance |

Plugin settings stored via WooCommerce Settings API (`woocommerce_cod_shield_settings` option array) — no custom DB table needed for v1.

### 7.4 Fee Calculation (server-side, single source of truth)

```
function calculate_advance( $order_total ) {
    if ( mode === 'disabled' || $order_total < threshold ) return 0;
    $amount = mode === 'fixed' ? fixed_value : $order_total * ( percentage / 100 );
    $amount = clamp( $amount, min_amount, max_amount );
    return round( $amount, 2 );
}
```

This single function is called both to render the checkout preview (via AJAX/JS for live updates as cart changes) and to authoritatively set the charged amount server-side — the two must never diverge.

### 7.5 Compatibility

- WooCommerce HPOS (order tables) — required, not optional, for 2026-era stores.
- Works alongside WooCommerce Blocks checkout (`checkout-block`) AND legacy shortcode checkout — messaging must be registered via both the classic hook system and a Blocks-compatible integration (`IntegrationInterface` for the Checkout block).
- Does not hard-depend on a specific gateway plugin, but this is not a universal-compatibility claim. The selected gateway must preserve the temporary advance total, accept the delegated payment flow and record a transaction ID.
- A gateway is considered compatible only for the tested combination of gateway plugin version, WooCommerce version, storage mode and checkout type recorded in `release-smoke-test.md`.
- The core advance flow supports classic checkout and Checkout Blocks. The optional extra COD fee is currently applied during Store API order processing and is not previewed as a separate fee line in Checkout Blocks before placement.
- PHP 8.1+, WooCommerce 8.0+.

---

## 8. Edge Cases

- Cart total changes after advance amount is calculated but before payment completes (e.g. coupon applied mid-flow) → recalculate on order creation, not just at initial render.
- Customer changes payment method away from COD after seeing advance box → messaging/fee logic must not leak into non-COD orders.
- Refunds: if a COD order is cancelled/refunded, admin must be able to see the advance amount clearly to know how much (if any) needs manual refund via the gateway's own refund flow (v1: informational only, not automated refund).
- Guest checkout — advance flow must work without an account.
- Currency/rounding — always round to gateway-supported decimal precision.
- Free shipping / zero-total orders (rare edge case, e.g. full discount) — advance should not exceed order total.

## 9. Non-Functional Requirements

- **Security**: nonce-verified AJAX, capability checks (`manage_woocommerce`) on all admin actions, server-side re-validation of all amounts, no client-trusted pricing.
- **Performance**: no blocking calls on checkout page load; gateway calls only fire on actual submit.
- **i18n**: all strings translatable, `.pot` file included.
- **Fail-safe**: messaging may degrade without blocking unrelated payment methods, but payment logic must fail closed. When an advance is required and no usable collection gateway is available, hide COD rather than silently skipping the advance.

## 10. Success Metrics

- Reduction in COD RTO % post-launch (primary).
- Checkout completion rate on COD orders (ensure advance requirement isn't killing conversion).
- % of COD orders with advance successfully collected vs. abandoned at gateway step.

## 11. Out of Scope (v1) / Future Considerations

- Automated partial refund via gateway API on order cancellation.
- Per-product or per-category advance rules (v1 is store-wide only).
- Analytics dashboard for RTO trend over time.
- SMS/WhatsApp advance-payment reminder for abandoned advance flows.

---

## 12. Build Order (suggested implementation sequence)

1. Plugin scaffold + activation/settings page skeleton (WC Settings API tab).
2. Global COD kill switch (`woocommerce_available_payment_gateways` filter).
3. Fee calculation engine + unit tests for fixed/percentage/min/max/threshold logic.
4. Checkout messaging (classic checkout first, then Blocks integration).
5. Gateway delegation flow for advance collection + order meta writing.
6. Admin order list column + order detail metabox (HPOS-compatible).
7. Edge case hardening (coupon recalculation, refund visibility, failure handling).
8. QA the intended gateway plugin versions across classic + block checkout and HPOS on/off; record results without treating untested gateways as compatible.
