# Minimal COD Advance Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make SureOrder a predictable, minimal fixed-or-percentage COD advance plugin while closing the duplicate-charge window and limiting production use to explicitly supported gateway integrations.

**Architecture:** Keep the existing proxy-gateway design and server-side calculation engine. Harden its state machine, make fresh-install calculations literal by default, and separate explicitly supported gateways from merely plausible gateway candidates. Preserve advanced controls for existing merchants, but keep the primary setup focused on enablement, amount mode/value, and one payment gateway.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, WooCommerce 8.0+, WooCommerce HPOS, Checkout Blocks, PHPUnit 10/11, WordPress Coding Standards, plain JavaScript.

**Spec:** `docs/prd.md`

## Global Constraints

- Preserve the legacy plugin slug, option names, text domain, gateway ID, and order meta keys.
- Do not add runtime Composer dependencies; the installed plugin must run without `vendor/`.
- Support classic checkout and Checkout Blocks.
- Use WooCommerce order CRUD APIs so both HPOS and legacy order storage remain supported.
- Calculate all payment amounts on the server; browser output is display-only.
- Fail closed for COD when an advance is required but cannot be collected safely.
- Preserve existing merchant option values; changed defaults apply only when an option does not already exist.
- Follow WordPress Coding Standards and translate every user-facing string with text domain `cod-shield`.
- Do not claim universal gateway compatibility or production readiness without completing `docs/release-smoke-test.md` for the exact gateway versions.

## Review Focus

- A prepared order and an already-initiated order both carry advance metadata; the first attempt must proceed and the second must not start another gateway transaction.
- Existing sites with saved ₹49/₹999 limits must retain them, while fresh installs receive uncapped literal fixed/percentage calculations.
- Unknown fieldless gateways must not become selectable merely because they implement `process_payment()` and support products.
- Cashfree and Razorpay special safety rules must remain effective after the supported-gateway policy is introduced.
- A gateway becoming disabled after configuration must hide COD without affecting card, UPI, or other non-COD methods.

---

## File Map

- `includes/class-cod-shield-gateway-cod-advance.php` — owns initiation and retry safety for the proxy gateway.
- `includes/class-cod-shield-core.php` — owns defaults, pure advance calculation, and gateway compatibility policy.
- `includes/class-cod-shield-settings.php` — renders the three-step setup and supported-gateway selector/status.
- `admin/js/settings.js` — shows only the amount input relevant to the selected mode.
- `tests/unit/CalculateAdvanceTest.php` and `tests/unit/DefaultsTest.php` — pin literal fresh-install calculation behavior.
- `tests/unit/GatewaySafetyTest.php` — pins the explicit gateway policy and gateway-specific exclusions.
- `tests/integration/LifecycleTest.php` — exercises first-attempt versus in-flight retry behavior with real WooCommerce orders.
- `composer.json`, `.github/workflows/release.yml`, and `tests/integration/README.md` — expose and run the correct verification layers.
- `README.md`, `readme.txt`, `docs/features.md`, and `docs/release-smoke-test.md` — state the minimal setup, supported gateways, migration behavior, and required live smoke tests.
- `languages/cod-shield.pot` — regenerated after string changes.

### Task 1: Close the in-flight duplicate-payment window

**Files:**
- Modify: `includes/class-cod-shield-gateway-cod-advance.php:131-149`
- Modify: `tests/integration/LifecycleTest.php`

**Interfaces:**
- Consumes: `Core::META_AWAITING`, `Core::GATEWAY_ID`, and `WC_Order::get_payment_method()`.
- Produces: `Gateway_COD_Advance::payment_attempt_is_in_flight( WC_Order $order ): bool`, used only by `process_payment()`.

- [ ] **Step 1: Add a failing integration test for an in-flight retry**

Add a test that creates an order with the recorded advance metadata, sets `META_AWAITING` to `yes`, stores the delegation gateway as the order payment method, leaves the transaction ID empty, calls `Gateway_COD_Advance::process_payment()`, and asserts:

```php
$this->assertSame( array( 'result' => 'failure' ), $result );
$this->assertSame( 0, $this->stub_gateway->process_count );
$this->assertSame( 'yes', $order->get_meta( Core::META_AWAITING ) );
$this->assertSame( 'cod_shield_stub', $order->get_payment_method() );
```

Extend `WC_Gateway_COD_Shield_Stub` with `public int $process_count = 0;` and increment it at the first line of its `process_payment()` method.

- [ ] **Step 2: Add a failing integration test proving the first attempt still works**

Create a prepared order whose payment method is `Core::GATEWAY_ID` and whose checkout hook has already set `META_AWAITING` to `yes`. Assert the stub gateway is called once and the result is successful. This prevents the fix from treating checkout preparation as an in-flight remote payment.

- [ ] **Step 3: Run the lifecycle integration tests and confirm the new retry test fails**

Run:

```bash
COD_SHIELD_ORDER_STORAGE=legacy composer test:integration -- --filter 'first_attempt|in_flight_retry'
```

Expected: the in-flight retry test fails because the stub gateway is called; the first-attempt test passes.

- [ ] **Step 4: Implement the state-aware guard**

Add this private method and call it from the existing guard in `process_payment()`:

```php
private function payment_attempt_is_in_flight( $order ) {
	return 'yes' === $order->get_meta( Core::META_AWAITING )
		&& Core::GATEWAY_ID !== $order->get_payment_method();
}
```

The rejection condition becomes true when a transaction ID exists, reconciliation is required, or `payment_attempt_is_in_flight()` is true. Do not clear or rewrite any order fields in the rejection path.

- [ ] **Step 5: Run the focused integration tests in both storage modes**

Run:

```bash
COD_SHIELD_ORDER_STORAGE=legacy composer test:integration -- --filter 'first_attempt|in_flight_retry'
COD_SHIELD_ORDER_STORAGE=hpos composer test:integration -- --filter 'first_attempt|in_flight_retry'
```

Expected: both tests pass in both modes and the stub process count is exactly zero for the retry.

- [ ] **Step 6: Commit the payment-safety change**

```bash
git add includes/class-cod-shield-gateway-cod-advance.php tests/integration/LifecycleTest.php tests/integration/Stub_Gateway.php
git commit -m "Prevent duplicate COD advance attempts"
```

### Task 2: Make fixed and percentage values literal on fresh installs

**Files:**
- Modify: `includes/class-cod-shield-core.php:86-120`
- Modify: `includes/class-cod-shield-settings.php:228-257`
- Modify: `tests/unit/DefaultsTest.php`
- Modify: `tests/unit/CalculateAdvanceTest.php`

**Interfaces:**
- Consumes: existing `Core::OPT_MIN`, `Core::OPT_MAX`, and `Core::calculate_advance()`.
- Produces: fresh-install defaults `OPT_MIN => 0` and `OPT_MAX => 0`; no option migration and no calculation signature change.

- [ ] **Step 1: Write failing default tests**

Add assertions:

```php
$defaults = Core::get_defaults();
$this->assertSame( 0, $defaults[ Core::OPT_MIN ] );
$this->assertSame( 0, $defaults[ Core::OPT_MAX ] );
```

- [ ] **Step 2: Write calculation tests using the real fresh-install defaults**

Add tests showing that fixed ₹25 returns `25.0`, 10% of ₹300 returns `30.0`, and 10% of ₹20,000 returns `2000.0` when min/max are zero. Keep the existing explicit clamp tests so optional limits remain supported.

- [ ] **Step 3: Run the focused unit tests and verify failure**

Run:

```bash
vendor/bin/phpunit tests/unit/DefaultsTest.php tests/unit/CalculateAdvanceTest.php
```

Expected: default-limit assertions fail against ₹49/₹999.

- [ ] **Step 4: Change only the fallback and field defaults**

Set the two values in `Core::get_defaults()` and the matching settings field definitions to integer `0`. Do not update existing database options during activation or upgrade.

- [ ] **Step 5: Run focused and full dependency-free tests**

Run:

```bash
vendor/bin/phpunit tests/unit/DefaultsTest.php tests/unit/CalculateAdvanceTest.php
composer test
```

Expected: all tests pass; explicit min/max tests still demonstrate opt-in clamping.

- [ ] **Step 6: Commit the predictable-default change**

```bash
git add includes/class-cod-shield-core.php includes/class-cod-shield-settings.php tests/unit/DefaultsTest.php tests/unit/CalculateAdvanceTest.php
git commit -m "Use uncapped COD advance defaults"
```

### Task 3: Require explicit gateway support

**Files:**
- Modify: `includes/class-cod-shield-core.php:364-446`
- Modify: `includes/class-cod-shield-core.php:502-554`
- Modify: `includes/class-cod-shield-settings.php:698-792`
- Modify: `tests/unit/GatewaySafetyTest.php`
- Modify: `tests/unit/SettingsSetupUxTest.php`

**Interfaces:**
- Consumes: the existing gateway characteristics array passed to `Core::assess_gateway_compatibility()`.
- Produces: `Core::supported_gateway_classes(): array<string,array<int,string>>` and compatibility result code `gateway_not_explicitly_supported`.

- [ ] **Step 1: Add failing tests for the support policy**

Test this policy:

```php
$this->assertSame(
	array(
		'cashfree' => array( 'WC_Cashfree_Payments' ),
		'razorpay' => array( 'WC_Razorpay' ),
	),
	Core::supported_gateway_classes()
);
```

Add a test that a generic fieldless `redirect_gateway` is rejected with `gateway_not_explicitly_supported`. Retain tests proving official Cashfree and automatic-capture/non-Route Razorpay pass, while impostor classes, Razorpay authorization, and Razorpay Route fail.

- [ ] **Step 2: Run the gateway unit tests and verify the generic gateway test fails**

Run:

```bash
vendor/bin/phpunit tests/unit/GatewaySafetyTest.php
```

Expected: the generic fieldless gateway is currently reported compatible.

- [ ] **Step 3: Implement the pure support map and policy check**

Add:

```php
public static function supported_gateway_classes() {
	return array(
		'cashfree' => array( 'WC_Cashfree_Payments' ),
		'razorpay' => array( 'WC_Razorpay' ),
	);
}
```

In `assess_gateway_compatibility()`, normalize the class name, require the gateway ID to exist in this map, and require an exact class match before applying gateway-specific checks. Return `gateway_not_explicitly_supported` for every other gateway.

- [ ] **Step 4: Add a narrowly scoped extension filter at the runtime boundary**

In `delegation_gateway_status()` and `Settings::get_delegation_gateway_options()`, pass the built-in support map through:

```php
apply_filters( 'cod_shield_supported_gateway_classes', Core::supported_gateway_classes() )
```

Add the resolved boolean as `explicitly_supported` to the characteristics array. Update the pure compatibility method to accept this boolean so filtered additions can pass without calling WordPress from pure unit tests. Validate that filtered IDs and class names are non-empty scalar strings before comparing them.

- [ ] **Step 5: Preserve saved but unsupported configurations as warnings**

Ensure `get_configuration_status()` reports `gateway_not_explicitly_supported` as a warning and never labels the setup ready. The storefront must hide COD when advance mode is enabled and this state is present. Do not silently replace the saved gateway with Cashfree or Razorpay.

- [ ] **Step 6: Run gateway, settings, and full tests**

Run:

```bash
vendor/bin/phpunit tests/unit/GatewaySafetyTest.php tests/unit/SettingsSetupUxTest.php
composer test
composer lint
```

Expected: supported gateway tests pass, unknown gateways fail closed, and PHPCS reports no violations.

- [ ] **Step 7: Commit the explicit gateway policy**

```bash
git add includes/class-cod-shield-core.php includes/class-cod-shield-settings.php tests/unit/GatewaySafetyTest.php tests/unit/SettingsSetupUxTest.php
git commit -m "Restrict COD advances to supported gateways"
```

### Task 4: Tighten the three-step merchant setup

**Files:**
- Modify: `includes/class-cod-shield-settings.php:145-220`
- Modify: `admin/js/settings.js`
- Modify: `admin/css/admin.css`
- Modify: `tests/unit/SettingsSetupUxTest.php`

**Interfaces:**
- Consumes: `Core::OPT_MODE`, `Core::OPT_FIXED`, `Core::OPT_PERCENTAGE`, and the supported gateway options from Task 3.
- Produces: one visible amount input for the active mode while retaining the existing stored option keys.

- [ ] **Step 1: Add source-level UI regression assertions**

Assert that the setup still contains exactly these numbered decisions:

```text
1. Enable Cash on Delivery
2. Choose the advance
3. Select an advance gateway
```

Assert that fixed and percentage fields retain distinct row classes and that `admin/js/settings.js` listens for changes to `#cod_shield_advance_mode`.

- [ ] **Step 2: Run the settings UI unit test**

Run:

```bash
vendor/bin/phpunit tests/unit/SettingsSetupUxTest.php
```

Expected: any new row-class or script assertions fail before implementation.

- [ ] **Step 3: Implement mode-specific field visibility**

Give the fixed row class `cod-shield-fixed-field` and the percentage row class `cod-shield-percentage-field`. In `settings.js`, create one `syncAdvanceFields()` function that:

```javascript
const mode = $("#cod_shield_advance_mode").val();
$(".cod-shield-fixed-field").toggle(mode === "fixed");
$(".cod-shield-percentage-field").toggle(mode === "percentage");
```

Call it on page load and on the mode field's `change` event. Do not delete hidden values; switching modes must preserve what the merchant previously entered.

- [ ] **Step 4: Clarify supported-gateway copy**

Change the selector placeholder to “Select a supported gateway…” and its description to state that only explicitly supported gateway plugins appear and the installed version still requires the release smoke test.

- [ ] **Step 5: Verify settings behavior and style**

Run:

```bash
vendor/bin/phpunit tests/unit/SettingsSetupUxTest.php
composer lint
```

Then manually load WooCommerce → Settings → SureOrder and verify Disabled shows neither amount field, Fixed shows only fixed amount, Percentage shows only percentage, and saved values survive switching modes.

- [ ] **Step 6: Commit the setup simplification**

```bash
git add includes/class-cod-shield-settings.php admin/js/settings.js admin/css/admin.css tests/unit/SettingsSetupUxTest.php
git commit -m "Simplify COD advance setup fields"
```

### Task 5: Make verification layers explicit and release-safe

**Files:**
- Modify: `composer.json:34-41`
- Modify: `.github/workflows/release.yml`
- Modify: `tests/integration/README.md`
- Modify: `docs/release-smoke-test.md`

**Interfaces:**
- Consumes: existing `test`, `test:integration`, and lint scripts.
- Produces: `composer test:all` for environments with WordPress/MySQL and a release workflow gate that runs available automated checks before packaging.

- [ ] **Step 1: Add an aggregate Composer script**

Add:

```json
"test:all": "@test && @test:integration"
```

Keep `composer test` dependency-free so local contributors without MySQL retain the documented fast path.

- [ ] **Step 2: Update integration documentation with exact coverage**

Document that `composer test` is not a WordPress integration test, `composer test:all` requires the database-backed setup, and both legacy and HPOS modes must be run before a release.

- [ ] **Step 3: Add release checks before archive creation**

In `.github/workflows/release.yml`, ensure the validation job runs:

```bash
composer install --no-interaction --prefer-dist
composer lint
composer test
```

If the workflow provisions MySQL and WordPress, add separate legacy and HPOS integration steps. If it does not, make completion of both commands a checked manual item in `docs/release-smoke-test.md`; do not add a CI command that cannot boot its dependencies.

- [ ] **Step 4: Expand the smoke-test matrix**

For Cashfree and Razorpay, require rows for gateway plugin version, WooCommerce version, PHP version, classic checkout, Checkout Blocks, HPOS on/off, successful advance, declined advance, abandoned redirect, delayed webhook, browser return after webhook, and retry while awaiting confirmation.

- [ ] **Step 5: Run the locally available verification**

Run:

```bash
composer validate --strict
composer lint
composer test
```

Expected: all commands exit zero. Run `composer test:all` only in the documented WordPress/MySQL environment.

- [ ] **Step 6: Commit the verification changes**

```bash
git add composer.json .github/workflows/release.yml tests/integration/README.md docs/release-smoke-test.md
git commit -m "Clarify COD advance release verification"
```

### Task 6: Align product documentation and translations

**Files:**
- Modify: `README.md`
- Modify: `readme.txt`
- Modify: `docs/features.md`
- Modify: `docs/prd.md`
- Modify: `languages/cod-shield.pot`

**Interfaces:**
- Consumes: behavior delivered by Tasks 1-5.
- Produces: merchant-facing documentation that matches the implementation and release policy.

- [ ] **Step 1: Update the minimal setup documentation**

Describe the setup as enable COD, select Fixed or Percentage and enter its literal value, then select one supported gateway. State that min/max are optional and default off for new installations.

- [ ] **Step 2: Document backward compatibility**

State that existing stored min/max values remain unchanged. Explain that a previously selected unverified gateway becomes unavailable for advance collection until explicitly supported, causing COD to fail closed when an advance is required.

- [ ] **Step 3: Document supported gateways without a universal claim**

List the exact Cashfree and Razorpay gateway classes recognized by the release and state that each installed version must pass `docs/release-smoke-test.md` on the store's checkout types.

- [ ] **Step 4: Regenerate translations**

Run:

```bash
composer pot
```

Expected: `languages/cod-shield.pot` contains the revised selector and warning strings.

- [ ] **Step 5: Run final verification**

Run:

```bash
composer validate --strict
composer lint
composer test
git diff --check
```

In a configured integration environment also run:

```bash
COD_SHIELD_ORDER_STORAGE=legacy composer test:integration
COD_SHIELD_ORDER_STORAGE=hpos composer test:integration
```

Expected: every available command exits zero, no whitespace errors are reported, and the release smoke-test document clearly identifies any manual gateway checks still pending.

- [ ] **Step 6: Commit the documentation alignment**

```bash
git add README.md readme.txt docs/features.md docs/prd.md languages/cod-shield.pot
git commit -m "Document minimal COD advance setup"
```

## Final Acceptance Criteria

- A first COD advance attempt can start exactly once, even though checkout preparation already wrote advance metadata.
- An in-flight order cannot call the delegation gateway again until the existing attempt reaches a recorded failure/reconciliation path.
- Fresh installations calculate the merchant's fixed value or percentage literally unless the merchant opts into min/max limits.
- Existing min/max settings are not overwritten.
- Only explicitly supported gateway ID/class pairs appear in setup or operate at runtime.
- Missing, disabled, changed-class, or unsupported configured gateways hide COD when an advance is required without affecting non-COD gateways.
- Setup presents only the relevant amount field and one gateway choice.
- Unit, lifecycle, lint, and—where infrastructure is available—legacy and HPOS integration suites pass.
- Documentation states the remaining gateway-version smoke tests and does not claim universal or production-ready compatibility.
