<p align="center">
  <img src="assets/social-preview.png" alt="COD Shield for WooCommerce — Reduce COD RTO with partial advance payments" width="100%" />
</p>

# 🛡️ COD Shield for WooCommerce

> Reduce **COD RTO** (Return to Origin) by collecting a **partial advance payment** through your existing payment gateways — Razorpay, Cashfree, Stripe and more — without forcing customers to pay in full.

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://wordpress.org/plugins/cod-shield/)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)](https://wordpress.org/plugins/cod-shield/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588a.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Code Style](https://img.shields.io/badge/code%20style-WordPress-0073aa.svg)](phpcs.xml.dist)

---

## Table of Contents

- [Why COD Shield?](#why-cod-shield)
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

## Why COD Shield?

Cash on Delivery is the dominant payment method in Indian e-commerce — but it carries high **RTO risk** from fake and unreliable orders. Every failed delivery costs you shipping, reverse-logistics and product handling fees.

COD Shield makes COD "cost something" upfront **without** forcing customers to pay the full amount online. By collecting a small, configurable advance through gateways you already use, you:

- ✅ **Filter out casual and fake orders** before they ship
- ✅ **Keep COD conversion high** — customers pay a fraction, not the full price
- ✅ **Stay gateway-agnostic** — works with Razorpay, Cashfree, Stripe, and any gateway that respects the order total set by WooCommerce
- ✅ **Ship with confidence** — the remaining balance is still collected on delivery

---

## Features

### 💰 Partial Advance Payment

- Flat ₹ amount **or** percentage of order total
- Optional **min/max caps** and an **order-value threshold**
- Collected through any of your **already-enabled gateways** (no new payment integration required)
- Order is finalized as a standard COD order — remaining balance due on delivery

### 🚨 Global COD Kill Switch

- One click hides COD from checkout instantly
- Independent of plugin activation and WooCommerce's native COD settings
- No cache flushing, no hunting through gateway settings

### 📉 Minimum Order Value for COD

- Optional order-value threshold — orders **below** it cannot choose COD at all
- **Off by default**, so activation never changes your existing checkout behaviour

### 💬 Transparent Checkout Messaging

- Admin-editable messages explaining the advance — or that COD is risk-free
- Placeholder-driven: `{advance_amount}`, `{remaining_amount}`, `{percentage}`
- Works on both classic and block checkout

### 🧾 Extra COD Fee

- Charge an additional fee on COD orders (flat ₹ or %)
- On/off toggle with **tax rate and tax-class support**

### 👀 Full Admin Visibility

- Sortable **"COD Advance"** column on the orders list
- List filter: with advance / paid / pending / no advance
- Advance details **metabox** on the order edit screen
- Automatic **order notes** on advance initiation, success and failure (audit trail)

### 🔌 Compatibility

- **HPOS** (High-Performance Order Storage) — declared and supported
- **Cart/Checkout Blocks** and classic shortcode checkout
- **CartFlows** checkout funnels
- Gateway-agnostic — no hard dependency on any gateway plugin

---

## How It Works

```
Customer selects COD at checkout
            │
            ▼
Advance required? ──── No ───► Normal COD order (risk-free messaging shown)
            │
           Yes
            │
            ▼
COD replaced by "Cash on delivery (advance)" method
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

> ⚠️ **Important:** the gateway selected for advance collection must honour the order total set by WooCommerce and must not recalculate order totals inside its own `process_payment()`. All major gateways (Razorpay, Cashfree, Stripe) do this correctly.

---

## Requirements

| Requirement | Minimum |
| ------------- | --------- |
| WordPress | 6.4+ |
| PHP | 8.1+ |
| WooCommerce | 8.0+ (HPOS supported and recommended) |

WooCommerce's **native COD gateway must remain enabled** — COD Shield extends it, it does not replace it.

---

## Installation

### From the WordPress Plugin Directory (recommended)

1. Go to **Plugins → Add New** in your WordPress admin
2. Search for **"COD Shield for WooCommerce"**
3. Install and activate
4. Go to **WooCommerce → Settings → COD Shield** to configure

### Manual installation

1. Upload the `cod-shield` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen
3. Go to **WooCommerce → Settings → COD Shield** and configure

---

## Configuration

All settings live under **WooCommerce → Settings → COD Shield**:

| Section | What it controls |
| --------- | ------------------ |
| **General** | Global COD enable/disable (the kill switch) |
| **Advance Payment** | Mode (disabled / fixed / percentage), amount, min/max caps, order-value threshold (also the COD minimum), allowed gateways |
| **Extra COD Fee** | Fee toggle, type, amount/percentage, tax toggle + tax class |
| **Messaging** | Advance + risk-free message text and toggles (placeholders supported) |
| **Order Handling** | Behaviour on advance failure (keep pending / cancel) and post-payment status |

**Tip:** at least one of your enabled gateways must be selected to collect advances — otherwise the advance flow won't be offered.

---

## Safety & Reliability

- **Server-side validation** — the advance amount is always calculated and re-validated server-side at order creation and payment time. Client-side values are display-only.
- **Overcharge guard** — if a gateway charges more than the advance, the order is flagged with a warning note and never double-charged (the amount still due is reduced to what the gateway actually left unpaid).
- **Fulfilment gating** — orders are only finalized when a real gateway transaction ID is recorded; an unpaid advance cannot be moved to Processing/Completed.
- **Advance-only retry** — customers with an unpaid advance can only retry the advance on the pay-for-order page, never skip it by paying the full amount.
- **Manual reconciliation** — stuck orders can be finalized from the order screen via a capability + nonce-protected "Mark advance as paid" action.
- **Idempotent finalization** — the money-moving lifecycle is protected against double-execution.

---

## Frequently Asked Questions

<details>
<summary><strong>Does this replace WooCommerce's built-in COD gateway?</strong></summary>

No. It extends it. The order is finalized as a standard COD order; WooCommerce's native COD gateway must remain enabled.
</details>

<details>
<summary><strong>Which gateways can collect the advance?</strong></summary>

Any of your currently enabled WooCommerce gateways (Razorpay, Cashfree, Stripe, etc.). Select them in the COD Shield settings. At least one must be enabled for the advance flow to work.
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

No. The order-value threshold and advance rules are **off by default** — activation only adds the kill switch capability and the settings pages. Nothing about your checkout changes until you configure it.
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
| `composer test` | PHPUnit unit tests + lifecycle integration harness (no database required) |
| `composer test:standalone` | Calculation engines via plain-PHP runner (no composer needed) |
| `composer test:lifecycle` | Money-lifecycle harness: finalization, overcharge guard, idempotency (24 assertions) |
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

- **2.0.0** — Fresh start: MIT license, brand-new undistorted logo asset set with reusable generator, community setup (CONTRIBUTING/CODE_OF_CONDUCT/SECURITY)
- **1.1.2** — Fixed block checkout blocking all low-value orders when a threshold is set; added lifecycle test coverage
- **1.1.0** — Threshold now off by default; new installs default to Processing after advance; overcharge guard on manual reconciliation
- **1.0.8** — Overcharge guard, manual "mark advance as paid" reconciliation, advance-only pay-for-order retry

---

## License

[MIT](LICENSE) — permissive open-source license.

Copyright (c) 2026 Prathamesh Shirole

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

<p align="center">
  Built with ❤️ by <a href="https://profiles.wordpress.org/prathaverse/">Prathamesh Shirole</a> · <a href="https://github.com/prathameshshirole/cod-shield">GitHub</a>
</p>
