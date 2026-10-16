<p align="center">
  <img src="assets/social-preview.png" alt="SureOrder — Confirm COD orders with partial advance payments" width="100%" />
</p>

# 🛡️ SureOrder

> Reduce **COD RTO** (Return to Origin) by collecting a **partial advance payment** through one compatible WooCommerce payment gateway — without forcing customers to pay in full.

[![Version](https://img.shields.io/badge/version-2.0.4-blue.svg)](https://wordpress.org/plugins/cod-shield/)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)](https://wordpress.org/plugins/cod-shield/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588a.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)](LICENSE)
[![Code Style](https://img.shields.io/badge/code%20style-WordPress-0073aa.svg)](phpcs.xml.dist)

---

## Table of Contents

- [Why SureOrder?](#why-sureorder)
- [Features](#features)
- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Safety & Reliability](#safety--reliability)
- [Frequently Asked Questions](#frequently-asked-questions)
- [Development](#development)
- [Contributing](#contributing)
- [Support](#support)
- [License](#license)

---

## Why SureOrder?

Cash on Delivery is the dominant payment method in Indian e-commerce — but it carries high **RTO risk** from fake and unreliable orders. Every failed delivery costs you shipping, reverse-logistics and product handling fees.

SureOrder makes COD "cost something" upfront **without** forcing customers to pay the full amount online. By collecting a small, configurable advance through a compatible gateway you already use, you:

- ✅ **Filter out casual and fake orders** before they ship
- ✅ **Keep COD conversion high** — customers pay a fraction, not the full price
- ✅ **Match your storefront language** — customize the COD checkout title
- ✅ **Use a supported gateway** — choose the official Cashfree or Razorpay gateway, then run the compatibility smoke test for its installed version
- ✅ **Ship with confidence** — the remaining balance is still collected on delivery

---

## Features

### 💰 Partial Advance Payment

- Flat ₹ amount **or** percentage of order total
- Optional **min/max caps** and a **Minimum order value for COD**
- Collected through one compatible, already-enabled gateway (no new payment integration is bundled)
- Order is finalized as a standard COD order — remaining balance due on delivery

### 🚨 Global COD Kill Switch

- One click hides COD from checkout instantly
- Independent of plugin activation and WooCommerce's native COD settings
- No cache flushing, no hunting through gateway settings

### ✏️ Editable COD Title

- Customize the customer-facing COD payment name
- Applied consistently to classic checkout, Checkout Blocks and advance orders
- Leave blank to preserve WooCommerce's existing COD title

### 📉 Minimum Order Value for COD

- Optional **Minimum order value for COD** — orders **below** it cannot choose COD at all
- **Off by default**, so activation never changes your existing checkout behaviour
- Optional **maximum order value for COD** and delivery-pincode exclusions for higher-risk orders

### 🚫 High-Priority COD Exclusions

Exclude COD with surgical precision — any matching rule hides COD **and** the advance proxy with a clear, customer-facing reason shown in both classic checkout and Checkout Blocks:

- **Shipping zones** — zone IDs; the chosen shipping method's zone excludes COD
- **Shipping methods** — by rate ID (`local_pickup`) or a specific instance (`flat_rate:23`); multiple packages fail if any selected package matches
- **Products** — product or variation IDs; a variation matches its own ID *and* its parent product's data
- **Product categories** and **shipping classes** — term IDs applied to the parent product

The proxy also mirrors WooCommerce's own native COD availability: when native COD is removed by the COD gateway's *enable for methods* shipping restriction, virtual-cart rules, or is disabled outright, the advance proxy never resurrects it. Online payment methods are never affected by COD exclusions.

### 💬 Transparent Checkout Messaging

- Admin-editable messages explaining the advance — or that COD is risk-free
- Placeholder-driven: `{advance_amount}`, `{remaining_amount}`, `{percentage}`
- Clear **Pay now** and **Pay on delivery** rows explain that the advance is part of the order total
- Checkout Blocks refreshes the payment split when cart totals change
- Works on both classic and block checkout

### 🧾 Extra COD Fee

- Charge an additional fee on COD orders (flat ₹ or %)
- On/off toggle with **tax rate and tax-class support**
- The selected COD method adds the fee and configured tax to checkout totals before placement in both classic checkout and Checkout Blocks

### 👀 Full Admin Visibility

- Sortable **"COD Advance"** column on the orders list
- List filter: with advance / paid / pending / no advance
- Advance details **metabox** on the order edit screen
- Automatic **order notes** on advance initiation, success and failure (audit trail)

### 🔌 Compatibility

- **HPOS** (High-Performance Order Storage) — declared and supported
- **Cart/Checkout Blocks** and classic shortcode checkout
- **CartFlows** checkout funnels
- No hard dependency on a specific gateway plugin; compatibility must be verified for the selected gateway and checkout type

---

## How It Works

```
COD enabled and order meets the configured minimum?
            │
       No ──┴── Yes
       │         │
       ▼         ▼
 COD hidden   Advance required? ──── No ───► Normal COD order
                 │
                Yes
                 │
                 ▼
      Compatible gateway available? ─ No ──► COD hidden (fail closed)
                 │
                Yes
                 │
                 ▼
 Customer selects "Cash on delivery (advance)"
            │
            ▼
Order placed → redirected to your gateway → charged ONLY the advance
            │
            ▼
Payment success ───────────────► Payment failure
            │                            │
            ▼                            ▼
Order finalized as standard COD   Order stays pending (retryable)
Full total restored               or cancelled — configurable
Advance + transaction ID recorded
Remaining balance due on delivery
```

> ⚠️ **Important:** gateway compatibility is not automatic. The selected gateway must honour the temporary WooCommerce order total, accept the delegated payment flow, and charge only the advance. Test its current plugin version in both checkout types you use before enabling COD advance on a live store.

---

## Requirements

| Requirement | Minimum |
| ------------- | --------- |
| WordPress | 6.4+ |
| PHP | 8.1+ |
| WooCommerce | 8.0+ (HPOS supported and recommended) |

WooCommerce's **native COD gateway must remain enabled** — SureOrder extends it, it does not replace it.

---

## Installation

### From the WordPress Plugin Directory (recommended)

1. Go to **Plugins → Add New** in your WordPress admin
2. Search for **"SureOrder"**
3. Install and activate
4. Go to **WooCommerce → Settings → SureOrder** to configure

### Manual installation

1. Upload the `cod-shield` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen
3. Go to **WooCommerce → Settings → SureOrder** and configure

---

## Configuration

All settings live under **WooCommerce → Settings → SureOrder**.

### Minimal three-step setup

1. **Enable COD** on the Setup tab. WooCommerce's native COD gateway must also remain enabled.
2. **Choose Fixed Amount or Percentage** and enter the amount.
3. **Choose one supported gateway** that is enabled in WooCommerce, then run the release smoke test for its installed version and checkout type.

Fresh installations leave the optional minimum and maximum advance limits disabled, so the fixed amount or percentage is applied literally. Existing saved limits are preserved.

If an advance is required but no usable collection gateway is configured, SureOrder fails closed: it hides native COD instead of allowing the customer to place an unprotected COD order. Other payment methods remain available.

### Advanced settings

The remaining controls are optional:

| Section | What it controls |
| --------- | ------------------ |
| **Setup** | COD switch, advance mode/amount and one supported gateway |
| **Advanced Rules** | Advance caps, COD order-value limits and excluded delivery pincodes |
| **COD Fee** | Fee toggle, type, amount/percentage, tax toggle + tax class |
| **Messages** | COD title, advance/risk-free text and email summary |
| **Order Handling** | Behaviour on advance failure (keep pending / cancel), post-payment status and stale-attempt interval |

The extra COD fee is optional. Both checkout types show the fee and configured tax before placement; switching to another payment method removes the COD fee.

---

## Safety & Reliability

- **Server-side validation** — the advance amount is always calculated and re-validated server-side at order creation and payment time. Client-side values are display-only.
- **Overcharge guard** — if a gateway charges more than the advance, the order is flagged with a warning note and never double-charged (the amount still due is reduced to what the gateway actually left unpaid).
- **Fulfilment gating** — orders are only finalized when a real gateway transaction ID is recorded; an unpaid advance cannot be moved to Processing/Completed.
- **Advance-only retry** — customers with an unpaid advance can only retry the advance on the pay-for-order page, never skip it by paying the full amount.
- **Manual reconciliation** — stuck orders can be finalized from the order screen via a capability + nonce-protected "Mark advance as paid" action.
- **Idempotent finalization** — the money-moving lifecycle is protected against double-execution.

### Delayed payments and safe recovery

SureOrder never infers payment from an order's age, gateway order reference or status change. Automatic finalization requires a nonempty gateway transaction ID and, when an advance gateway was recorded on the order, a payment method matching that gateway. The explicit **Mark advance as paid (manual)** action is an administrator's assertion that the advance was collected; verify collection before using it.

If the gateway saves its transaction ID after the original payment/status event, an after-save observer queues a catch-up check. Action Scheduler runs it when available, with WP-Cron as fallback. The check reloads the order and uses the same guarded finalizer. It needs a working scheduler and cannot recover a transaction the gateway never writes to WooCommerce. Duplicate saves and callbacks do not repeat a successful finalization.

Check order notes and **WooCommerce → Status → Logs** (source `cod-shield`) for finalization diagnostics. Consecutive identical rejections are suppressed. These diagnostics contain operational order/gateway identifiers and status, excluding customer details, payment tokens and transaction IDs.

| Outcome code | Meaning and response |
| --- | --- |
| `missing_transaction` | Awaiting a gateway transaction ID; verify the payment in the gateway dashboard before fulfilment. |
| `gateway_mismatch` | The stored payment method differs from the recorded advance gateway (checked only when a gateway was recorded); reconcile the payment and gateway before fulfilment. |
| `invalid_total` | No valid order total can be restored; review totals and payment evidence. |
| `not_awaiting` / `ready` | Internal no-action / eligible-to-finalize outcomes; these do not generate rejection notes. `ready` still goes through the overcharge guard. |

The order's advance metabox also shows an **Attempt state**:

| State | Recovery policy |
| --- | --- |
| `active` | Waiting for gateway confirmation; retry remains blocked. Future timestamps remain active. |
| `stale` | The configured interval elapsed; verify the original attempt with the gateway before releasing it. |
| `legacy_unknown` | No valid attempt marker/timestamp; reconcile manually. Age never automatically releases a legacy attempt. |
| `captured` | Payment is recorded locally; retry release is blocked. This label does not independently verify capture with the gateway. |
| `review` | Reconciliation is required; retry release is blocked. |
| `inactive` | No active attempt is recorded. |

Set **Order Handling → Stale advance attempt interval (minutes)** as needed (default 60; allowed range 15–10,080 minutes). Elapsed time alone never releases an attempt. After confirming with the gateway that no money was collected, an authorized order editor can use **Release stale attempt for retry** on a pending/failed order with a pending/failed advance and valid saved totals. The action checks the order-specific permission and nonce, refuses transaction-bearing/review/legacy attempts, restores the full total and advance proxy, and records an audit note. The advance remains unpaid. Release does not cancel the original gateway attempt; account for delayed confirmation before permitting another charge.

---

## Frequently Asked Questions

<details>
<summary><strong>Does this replace WooCommerce's built-in COD gateway?</strong></summary>

No. It extends it. The order is finalized as a standard COD order; WooCommerce's native COD gateway must remain enabled.
</details>

<details>
<summary><strong>Which gateways can collect the advance?</strong></summary>

Choose one enabled WooCommerce gateway that has passed the compatibility smoke test with its current version and your checkout type. A gateway is not considered compatible merely because it appears in the selector. If the selected gateway is unavailable while an advance is required, COD is hidden rather than offered without the advance.
</details>

<details>
<summary><strong>What happens if the advance payment fails?</strong></summary>

The order stays pending so the customer can retry, or is cancelled ("not placed") — configurable under **Order Handling**.
</details>

<details>
<summary><strong>Is the advance amount calculated safely?</strong></summary>

Yes. The amount is always calculated and re-validated server-side at order creation and payment time. Client-side values are display-only.
</details>

<details>
<summary><strong>Does it work with CartFlows checkout funnels?</strong></summary>

Yes. CartFlows checkout steps use the native WooCommerce cart, so the advance is calculated from the same total CartFlows shows in the order review. On a CartFlows step the "Place order" button shows the advance (what is charged right now) instead of the full order total.
</details>

<details>
<summary><strong>Will activating the plugin change my existing checkout?</strong></summary>

No. **Minimum order value for COD** and advance rules are **off by default** — activation only adds the kill switch capability and the settings pages. Nothing about your checkout changes until you configure it.
</details>

---

## Development

### Setup

```bash
composer install            # dev tools (PHPUnit, PHPCS)
```

### Commands

| Command | Description |
| --------- | ------------- |
| `composer test` | PHPUnit unit tests + standalone lifecycle, checkout and breakdown harnesses (no database required) |
| `composer test:standalone` | Calculation engines via plain-PHP runner (no composer needed) |
| `composer test:lifecycle` | Standalone money-lifecycle harness: finalization, catch-up, recovery, overcharge guard and idempotency |
| `composer test:checkout-js` | Checkout Blocks payment-method JavaScript tests |
| `composer test:integration` | Real WordPress/WooCommerce lifecycle tests; requires the [integration environment](tests/integration/README.md), run for both legacy and HPOS storage |
| `composer lint` | PHPCS (WordPress coding standards) |
| `composer lint:fix` | Auto-fix PHPCS violations |
| `composer pot` | Regenerate `languages/cod-shield.pot` (requires wp-cli) |

### Architecture

The plugin is dependency-free at runtime (no `vendor/` required) and uses a lightweight PSR-4-style autoloader mapping `CodShield\Class_Name` → `includes/class-cod-shield-class-name.php`. One class per file.

```
cod-shield/
├── cod-shield.php               # Bootstrap + autoloader + HPOS/Blocks declarations
├── uninstall.php                   # Removes plugin options only (no data loss on deactivate)
├── readme.txt                      # WordPress.org readme
├── admin/
│   └── css/admin.css               # Admin-only assets
├── public/
│   └── js/
│       ├── checkout.js             # Classic checkout risk-free info box
│       └── blocks/payment-method.js # Checkout block registerPaymentMethod()
├── includes/                       # One class per file (autoloaded)
│   ├── class-cod-shield-core.php          # Options, engines, gateway lookup
│   ├── class-cod-shield-settings.php      # WC_Settings_Page tab
│   ├── class-cod-shield-checkout.php      # Kill switch, messaging, validation, meta
│   ├── class-cod-shield-gateway-bridge.php# Advance order finalization
│   ├── class-cod-shield-gateway-cod-advance.php # Hidden proxy gateway
│   ├── class-cod-shield-order.php         # Admin columns/filter/metabox
│   ├── class-cod-shield-ajax.php          # Live advance preview endpoint
│   ├── class-cod-shield-blocks.php        # Block payment method registration
│   └── class-cod-shield-blocks-payment-method-type.php
├── languages/                      # cod-shield.pot
├── docs/                           # prd.md, features.md
└── tests/
    ├── run-tests.php               # Standalone engine tests (no composer needed)
    ├── run-lifecycle.php           # Money-lifecycle harness (24 assertions)
    ├── bootstrap.php               # PHPUnit bootstrap (no WordPress required)
    ├── integration/                # WooCommerce integration tests (CI)
    └── unit/                       # PHPUnit test classes
```

### Testing Philosophy

The calculation engines (`Core::calculate_advance()`, `Core::calculate_fee()`) are pure functions unit-tested **without** WordPress or WooCommerce. The money-moving code (finalization, overcharge guard, reconciliation, idempotency) is covered by the standalone lifecycle harness — full tests run in seconds with zero database.

---

## Contributing

Contributions are welcome! Please follow the project conventions:

1. **Fork** the repository and create a feature branch
2. Write **tests** for any new behaviour (engines → `tests/unit/`, lifecycle → `tests/run-lifecycle.php`)
3. Run `composer lint` and keep the code WordPress-coding-standards clean
4. Open a pull request with a clear description of the change

Bug reports and feature requests: use the [issue templates](.github/ISSUE_TEMPLATE/) or the [GitHub Discussions](https://github.com/prathameshshirole/cod-shield/discussions).

---

## Support

- **GitHub Discussions:** [discussions](https://github.com/prathameshshirole/cod-shield/discussions) — ideas, Q&A, and community chat
- **WordPress.org forum:** [support/plugin/cod-shield](https://wordpress.org/support/plugin/cod-shield/)
- **Plugin page:** [wordpress.org/plugins/cod-shield](https://wordpress.org/plugins/cod-shield/)
- **Documentation:** [docs/](docs/) (`prd.md`, `features.md`)

---

## Changelog

See [readme.txt](readme.txt) for the full changelog. Notable recent releases:

- **2.1.3** — Reworked the COD advance title, payment explanation, CTA and notice styling around the exact amount due now
- **2.1.2** — Combined COD eligibility and payment-recovery hardening with responsive checkout messaging and content-versioned assets
- **2.1.1** — Improved the payment breakdown, advance-cap messaging, checkout button copy, and frontend cache invalidation
- **2.1.0** — Added high-priority COD exclusions and safe delayed-payment reconciliation
- **2.0.4** — Added explicit safeguards for official Razorpay automatic-capture checkout compatibility
- **2.0.3** — Fixed Cashfree webhook/return capture races and hardened advance payment lifecycle handling
- **2.0.2** — Restored official Cashfree hosted checkout support in the advance gateway selector
- **2.0.1** — Editable COD title, hardened manual reconciliation, and release quality gates
- **2.0.0** — Fresh start: GPL licensing, brand-new undistorted logo asset set with reusable generator, community setup (CONTRIBUTING/CODE_OF_CONDUCT/SECURITY)
- **1.1.2** — Fixed block checkout blocking all low-value orders when a threshold is set; added lifecycle test coverage
- **1.1.0** — Threshold now off by default; new installs default to Processing after advance; overcharge guard on manual reconciliation
- **1.0.8** — Overcharge guard, manual "mark advance as paid" reconciliation, advance-only pay-for-order retry

---

## License

[GNU General Public License v2 or later](LICENSE).

Copyright (C) 2026 Prathamesh Shirole. See [LICENSE](LICENSE) for the complete license terms.

---

<p align="center">
  Built with ❤️ by <a href="https://profiles.wordpress.org/prathaverse/">Prathamesh Shirole</a> · <a href="https://github.com/prathameshshirole/cod-shield">GitHub</a>
</p>
