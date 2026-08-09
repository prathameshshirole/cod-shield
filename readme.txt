=== SureOrder — COD Advance Payments for WooCommerce ===
Contributors: prathaverse
Tags: woocommerce, cod, cash on delivery, rto, advance payment
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.9
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reduce COD RTO by collecting a partial advance payment through one compatible WooCommerce gateway, with a global COD kill switch and clear checkout messaging.

== Description ==

Cash on Delivery is the dominant payment method in Indian e-commerce but carries high RTO (Return to Origin) risk from fake or unreliable orders. SureOrder makes COD "cost something" upfront without forcing customers to pay in full.

**Features**

* **Global COD kill switch** — one click to instantly hide COD from checkout, independent of plugin activation and WooCommerce's native COD settings.
* **Editable COD title** — customize the customer-facing Cash on Delivery name consistently across classic checkout, Checkout Blocks and advance orders.
* **Advance payment collection** — fixed ₹ amount or % of order total, collected through one compatible, enabled WooCommerce gateway.
* **Minimum order value for COD** — optional advanced rule; orders below it cannot choose Cash on Delivery at all (off by default, so activation never changes existing checkout behaviour).
* **India-focused eligibility rules** — optionally hide COD above a maximum order value or for exact/prefix delivery pincodes such as `110001` or `400*`.
* **Transparent checkout messaging** — admin-editable messages explaining the advance (or that COD is risk-free), with {advance_amount}, {remaining_amount} and {percentage} placeholders.
* **Full admin visibility** — sortable "COD Advance" column on the orders list, a filter (COD with advance / paid / pending / no advance), and an advance details metabox on the order edit screen.
* **Audit trail** — automatic order notes on advance payment initiation, success and failure.
* **Tested gateway compatibility** — no hard dependency on a specific gateway plugin; verify the selected gateway version with your checkout before going live.
* **Extra COD fee** — optional advanced fee (flat ₹ or %), with tax support. Checkout Blocks currently applies it during order processing and does not preview a separate fee line before placement.
* **HPOS + Blocks compatible** — the core advance flow works with High-Performance Order Storage and both the classic shortcode and Cart/Checkout block.

**How it works**

When an advance is required, the COD option at checkout is replaced by a "Cash on delivery (advance)" method. On placing the order the customer enters the selected gateway's normal payment flow and is charged *only* the advance amount; the order is then finalized as a normal COD order with the remaining balance due on delivery.

**Important:** gateway compatibility is not automatic. The selected gateway must honour the temporary WooCommerce order total, accept the delegated payment flow and charge only the advance. Test its current plugin version in each checkout type you use before enabling COD advance on a live store.

== Installation ==

1. Upload the `cod-shield` folder to `/wp-content/plugins/`, or install via Plugins → Add New → Upload.
2. Activate the plugin through the Plugins screen.

**Minimal three-step setup**

1. Go to **WooCommerce → Settings → SureOrder** and enable COD on the Setup tab. Keep WooCommerce's native COD gateway enabled too.
2. Choose **Fixed Amount** or **Percentage** and enter the amount.
3. Choose one enabled, compatible gateway that has passed the release smoke test for your checkout.

Minimum/maximum caps, COD order-value limits, excluded delivery pincodes, messaging, extra COD fees and order handling are advanced settings. If an advance is required but no usable collection gateway is configured, SureOrder hides COD instead of allowing an unprotected COD order. Other payment methods remain available.

== Frequently Asked Questions ==

= Does this replace WooCommerce's built-in COD gateway? =

No. It extends it. The order is finalized as a standard COD order; WooCommerce's native COD gateway must remain enabled.

= Which gateways can collect the advance? =

Choose one enabled WooCommerce gateway that has passed the compatibility smoke test with its current version and your checkout type. Appearing in the selector does not by itself prove compatibility. If that gateway is unavailable while an advance is required, COD is hidden rather than offered without the advance.

= What happens if the advance payment fails? =

The order stays pending so the customer can retry, or is cancelled ("not placed") — configurable under Order Handling.

= Is the advance amount calculated safely? =

Yes. The amount is always calculated and re-validated server-side at order creation and payment time. Client-side values are display-only.

= Does it work with CartFlows checkout funnels? =

Yes. CartFlows checkout steps use the native WooCommerce cart, so the advance is calculated from the same total CartFlows shows in the order review. On a CartFlows step the "Place order" button shows the advance (what is charged right now) instead of the full order total, even though CartFlows appends the full total to the button label when its "show price on button" setting is enabled.

== Changelog ==

= 2.0.2 =
* **Changed: renamed to SureOrder.** The new public brand avoids conflicts with other COD Shield products while all legacy technical identifiers remain stable for update, settings and order compatibility.
* **Changed: relicensed under GNU GPL v2 or later.** Plugin metadata, package metadata, documentation, contribution terms, translation template and the bundled license text now consistently declare the WordPress-recommended GPL license.
* **Fixed: Cashfree now appears in the advance gateway selector.** Cashfree's hosted redirect gateway marks itself as having checkout fields only to display its description; SureOrder now recognizes the official Cashfree gateway without weakening field-safety checks for other payment methods.

= 2.0.1 =
* **Added: editable COD checkout title.** Set a custom customer-facing Cash on Delivery name under WooCommerce → Settings → SureOrder → General. The title is shared by native COD, the advance proxy, classic checkout, Checkout Blocks and finalized order records; leaving it blank preserves WooCommerce's existing COD title.
* **Hardened manual advance reconciliation.** The admin action now uses POST with an order-scoped capability check and nonce verification.
* **Expanded release safeguards.** Real WooCommerce lifecycle tests now cover legacy and HPOS storage, and releases cannot publish until quality checks pass.

= 2.0.0 =
* **Added: highlighted advance notice at checkout.** The advance line under the COD payment method ("An advance of ₹99 secures this order…") now renders bold inside a warm amber callout on both the classic and the block checkout, so the amount due right now is scanned in a glance. This also fixes the risk-free info box, whose styles previously lived in admin-only CSS and never loaded on the storefront.
* **Fixed: raw HTML shown on the General settings tab instead of the section banner.** The banner `<img>` was placed in the section `title`, which WooCommerce renders through `esc_html()` — so the markup printed as literal text. The logo now renders correctly in the section description (served from `admin/img/`, which ships with the plugin, instead of the `.wordpress-org/` listing-artwork folder that is absent on real installs).
* **Licensing standardized.** The plugin is distributed under GNU GPL v2 or later, the license recommended for WordPress plugins. See the LICENSE file.
* **Renamed slug to `cod-shield`.** The slug, folder and text-domain are now `cod-shield` (previously `wc-cod-shield`) so the plugin satisfies the WordPress.org naming guideline — "wc" is a restricted term that cannot appear in a plugin slug. No settings or stored data are affected.
* **Brand-new logo asset set.** All banners, icons and the social preview are regenerated from a single high-resolution master logo with zero distortion — the WordPress.org banner artwork is no longer stretched or off-centre. A reusable generator (`tools/generate-assets.py`) rebuilds every asset from the one master file.
* **Open-source community setup.** Added CONTRIBUTING.md, CODE_OF_CONDUCT.md and SECURITY.md plus issue and pull-request templates, ready for outside contributors.
* **Professional GitHub presence.** Complete README, GPL license, repo description, topics and a 100% community profile.

= 1.1.4 =
* **Fixed: fatal error on the WooCommerce → Orders admin screen (HPOS).** Two callbacks on the `woocommerce_order_list_table_prepare_items_query_args` hook declared a required second `$order_type` parameter, but WooCommerce has applied this filter with a single argument (the query-args array) since WC 6.9.0. Every HPOS orders-list render threw `ArgumentCountError` and rendered a blank page. The callbacks now declare exactly one parameter, and a structural regression test pins both the registration and the signatures.
* **The "Place order" button now shows the price on CartFlows checkouts even when the step's "show price on button" option is off.** Previously, when a CartFlows checkout step had the price-display option disabled (or the step used a custom button text without a price), the plugin left the button label untouched — no price appeared at all. The amount charged right now (the advance, or the full total for plain COD) is now appended automatically, using the same `&nbsp;&nbsp;` separator CartFlows itself uses, mirroring its visual output. Plain checkouts and non-COD methods are never touched — only the COD/advance methods on CartFlows checkouts get the price.
* **Advance notice shown only under the COD radio.** The classic checkout no longer renders the advance notice in the box above the payment methods section; it now appears only under the Cash on Delivery radio (the box above the payment section shows just the risk-free message). Unused `advanceMessage`/`percentage` localization data was removed from the checkout script payload.
* **Simplified checkout messaging.** The COD method label now reads "Cash on delivery (COD)", and the default advance notice is "Pay {advance_amount} now, {remaining_amount} on delivery (COD)." across the classic gateway description, the block checkout, and the no-message fallbacks. Stores keep any saved custom text.
* **"Place order" button shows the advance on CartFlows funnels.** CartFlows appends the full cart total to the button label (or replaces it with the step's custom text) after the plugin's advance replacement, so the button could read "Place Order ₹1,042" while only the advance was charged. The plugin now re-applies the advance label at priority 1000 and strips every trailing price before appending, so a CartFlows-appended total can never double up with the advance.

= 1.1.2 =
* **Fixed: block checkout no longer blocks all low-value orders when a threshold is set.** The Store API cart-errors check added the COD minimum-order-value and "advance unavailable" errors without checking the selected payment method, and the Store API throws an HTTP 409 for *any* cart error — so a customer paying by card/UPI below the threshold was blocked from checking out entirely, even though the message said "choose another payment method." These errors are now added only when COD (or the advance proxy) is the chosen method; non-COD checkouts are never affected. Gateway-availability filtering already hides COD in these cases, so this filter is now the fail-closed safety net for the "customer still has COD selected" race, not a blanket checkout block. (Classic shortcode checkout was already correct.)
* **Added: automated coverage for the money lifecycle.** The money-moving code (`do_finalize`, the fulfilment revert guard, manual reconciliation, idempotency) previously had zero automated coverage — only the pure calculation engines were tested. New standalone harness `tests/run-lifecycle.php` (24 assertions) covers transaction-gated finalization, normal total restore + COD conversion, the overcharge guard, the unpaid-advance revert, the manual-mark path, and idempotency; it runs in `composer test` with no database. A documented WooCommerce integration-test layer (`tests/integration/`, with a controllable stub delegation gateway) is included for full end-to-end coverage in CI.

= 1.1.1 =
* **"Place order" button now shows the advance, not the full order total, when the COD advance flow is active.** Themes that append the order total to the button ("Place Order ₹1,042") no longer mislead the customer: the button shows exactly what will be charged right now — the advance ("Place Order ₹104") — with the remainder payable on delivery. The amount updates live when the COD advance method is selected and matches the theme's existing price formatting. Pure COD (no advance) keeps the full total as before.

= 1.1.0 =
* **The order-value threshold is now off by default** (was ₹500). Previously, activating the plugin immediately hid every COD order below ₹500 — even for stores that only wanted the kill switch or the advance feature. Fresh installs now ship with no minimum (threshold 0); set one under Advance Payment if you want it. Existing installs keep their current threshold.
* **New installs default to Processing after the advance is paid** (was On hold). With On hold, the customer received the "payment received / processing" email while the order actually landed On hold (no On-hold email follows because the transition is processing → on-hold, which WooCommerce emails don't listen to). Processing matches the actual state — the advance was already collected online. Existing installs keep their current setting.
* **Manual "Mark advance as paid" now applies the overcharge guard.** If the delegation gateway is still the order's payment method and the order total at finalization exceeds the recorded advance (the gateway recalculated totals and charged the full amount), the order is no longer silently converted to COD with the remainder still "due on delivery" — it is flagged with a WARNING order note and the amount still due is reduced to what the gateway actually left unpaid. (When the failure path already restored the proxy method and full COD total, the comparison is skipped — the admin is asserting collection elsewhere.)
* **Pay-for-order page no longer hides COD for non-advance orders when a threshold is set.** The minimum-order-value check keyed off the cart total, which is 0 on the order-pay page, so native COD was removed from every pay-for-order retry whenever a threshold was configured. The storefront minimum and advance policies are checkout-time gates and no longer apply on that page (the global kill switch still does).
* **Section slugs `cod_fee` / `order_handling` (underscores).** The settings tabs now use underscore slugs so `get_settings_for_{section}_section` resolves natively, even if WooCommerce ever drops the deprecated `get_settings()` path the hyphen-mapping override rides on. URLs change from `section=cod-fee` to `section=cod_fee`.
* **The order total is no longer reduced outside the delegation try/catch.** The advance-total swap and payment-method adoption now happen inside the guarded block, so an uncatchable error in a third-party plugin hooked into the gateway's flow can never leave the order stranded at the advance total with a foreign payment method.
* New regression tests: fresh-install defaults (threshold 0, status processing) + updated settings-section resolver test.

= 1.0.9 =
* **Fixed empty settings tabs.** The "Extra COD Fee" and "Order Handling" tabs rendered blank because WooCommerce resolves settings by method name (`get_settings_for_{section}_section`), and a PHP method name cannot contain a hyphen — the `cod-fee` / `order-handling` slugs could never resolve. Settings retrieval now maps hyphenated section slugs to their underscore-form methods, so both tabs display and save correctly. Includes a unit-test regression guard.
* **Renamed to "COD Shield for WooCommerce"** (display name) to satisfy the WordPress.org naming guideline — trademarks/abbreviations belong after a connector like "for". The slug, folder, and text-domain were still `wc-cod-shield` at this point and were renamed to `cod-shield` in 2.0.0.

= 1.0.8 =
* **Overcharge guard.** If the order total at payment completion exceeds the recorded advance, the gateway likely charged more than the advance (some gateways recalculate order totals). The order is then **not** converted to COD with the remainder still "due on delivery" (which would double-charge the customer); it is flagged with a warning order note, the advance marked paid, and the amount still due reduced to what the gateway actually left unpaid.
* **Admin reconciliation.** Orders stuck awaiting an advance (gateway never recorded a transaction id, or the customer paid outside the gateway — bank transfer, cash) can now be finalized from the order screen with a new "Mark advance as paid (manual)" action (capability + nonce protected, logged as an order note).
* **Late transaction id handling.** Finalization now also runs on the processing/completed status change itself, so gateways that record the transaction id in a second step/request are honoured instead of the order being rolled back to pending.
* **Advance-only retry on the pay-for-order page.** While an advance is unpaid, the only payment method offered on the order-pay page is the advance proxy — the customer can no longer settle the full order online and skip the COD commitment. (If the delegation gateway is unavailable, no gateway is offered on that page until it is configured again.)
* **Block checkout fixes.** The advance message on the block checkout now uses the fee-inclusive total (matching the charged amount when the extra COD fee is enabled), and the no-message fallback uses plain-text amounts instead of raw HTML.
* **Admin styling on order screens.** The admin stylesheet now also loads on the orders list and order edit screens (HPOS + legacy), so the COD Advance column badges and the advance metabox render styled.

= 1.0.7 =
* The **Order value threshold** now also acts as the **minimum order value for COD**: orders below it (default ₹500) cannot choose Cash on Delivery at all — COD is hidden entirely on both classic and block checkout, with fail-closed server-side validation. Orders at or above the threshold follow the normal advance rules.

= 1.0.6 =
* Fulfilment is now gated on the advance actually being paid: an order is only finalized (marked "advance paid", converted to plain COD, configured status applied) when a real gateway transaction id is recorded.
* New guard: an order with an unpaid advance can no longer be moved to Processing or Completed (including manual admin overrides) — the status is rolled back to Pending with an explanatory order note until the advance is paid.

= 1.0.5 =
* Advance can no longer be bypassed after a failed payment: native COD stays hidden on the pay-for-order page whenever the order still carries an unpaid advance, and the advance proxy is offered there so the customer can retry the advance.
* Uninstall now removes the two email-message options (`cod_shield_msg_email_enabled`, `cod_shield_msg_email_text`) that were previously left behind.
* Translations now actually load: the missing `load_plugin_textdomain()` call has been added.
* Manually completing/processing an awaiting-advance order no longer bounces the status back; the configured COD status is only applied when a gateway transaction is recorded.
* The `{percentage}` placeholder now resolves in the classic-checkout live advance message.
* Checkout and block message templates are kses-sanitised server-side before being sent to the client.

= 1.0.4 =
* Fixed pay-for-order dead end: the order-pay page no longer renders an empty receipt without the payment gateway popup. The proxy now adopts the delegation gateway's payment method on the order before redirecting, so gateways like Razorpay can render their checkout modal on the order-pay page (amount charged is exactly the advance).
* Native COD can no longer be selected on the pay-for-order form for an order that is still awaiting its advance.
* Delegation failures (including exceptions) now restore the order to its original state so it can never be charged the full amount by the wrong gateway.

= 1.0.3 =
* Added an advance-payment summary to WooCommerce order emails (customer and admin), shown only after the advance is collected.
* Added a compact inline advance summary under the order total on the admin order screen.

= 1.0.1 =
* Hotfix: resolved a fatal error (`Class "CodShield\Order_UI" not found`) that caused a white screen on every uncached frontend request. The `Order_UI` class was in a misnamed file that the autoloader could not locate.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.2 =
* Renames the plugin to SureOrder, relicenses it under GNU GPL v2 or later and restores the official Cashfree gateway in the advance gateway selector.

= 2.0.1 =
* Adds an editable COD checkout title and strengthens payment reconciliation and release validation.

= 2.0.0 =
* Fresh start with a new logo/banner set and open-source community files. The slug, folder and text-domain are now `cod-shield` (was `wc-cod-shield`) for WordPress.org naming compliance. No functional or settings changes — safe to update.

= 1.1.0 =
* Fresh installs no longer hide COD below ₹500 by default, and new installs apply Processing (not On hold) after the advance is paid. Existing installs are unaffected. The manual mark-paid action now warns if the gateway may have charged the full order. Review the changelog before updating.

= 1.0.9 =
* Fixes the blank "Extra COD Fee" and "Order Handling" settings tabs. Update to configure or edit the extra COD fee and order-handling options.

= 1.0.8 =
* Adds an overcharge guard (a gateway that charges more than the advance is flagged, never double-charged), a manual "mark advance as paid" action for stuck orders, and makes the pay-for-order retry advance-only. Review the 1.0.8 changelog before updating.

= 1.0.4 =
* Fixes stuck orders on the order-pay page where the Razorpay/gateway popup never opened. Update before processing any pending advance orders.

= 1.0.3 =
* Recognizes and displays legacy RIRCOD order data (advance / COD due / total) across the admin and emails.

= 1.0.2 =
* Adds order-email summaries and an inline admin advance summary.

= 1.0.1 =
* Critical hotfix for all 1.0.0 installs. Update immediately.

= 1.0.0 =
* Initial release.
