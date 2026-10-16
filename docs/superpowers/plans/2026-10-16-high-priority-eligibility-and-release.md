# High-Priority Eligibility and Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent native COD restriction bypass, add high-value exclusion rules with clear messages, and build a verified installable ZIP.

**Architecture:** Centralize checkout decisions in a structured `Eligibility` result shared by gateway filtering, classic validation, Store API validation, and Blocks display. Preserve order-pay behavior and package the repository only after all available verification passes.

**Tech Stack:** PHP 8.1+, WooCommerce classic checkout and Store API/Blocks, WordPress Settings API, JavaScript Blocks extension, PHPUnit and standalone harnesses.

**Spec:** `docs/superpowers/specs/2026-10-16-payment-reliability-and-eligibility-design.md`

## Global Constraints

- Preserve every pre-existing uncommitted user change.
- No runtime dependency on `vendor/`.
- Support HPOS, classic checkout, Checkout Blocks, and order-pay retries.
- Use exclusion-only rule semantics; any matching physical item/package makes COD ineligible.
- Variations match their own ID plus parent product/category/shipping-class data.
- Online gateways remain available when COD is excluded.
- Every user-facing string uses text domain `cod-shield`.

## Review Focus

- The advance proxy never appears when native COD was removed by shipping-method configuration.
- Multiple packages fail COD eligibility when any selected zone/method is excluded.
- A variation matches both variation and parent restrictions without null-product fatals.
- Blocks and classic checkout show the same reason after cart/address/shipping changes.
- Order-pay for an existing advance obligation is not blocked by new storefront eligibility rules.

---

### Task 1: Native COD parity and structured evaluator

**Files:**
- Modify: `includes/class-cod-shield-eligibility.php`
- Modify: `includes/class-cod-shield-gateway-bridge.php`
- Modify: `includes/class-cod-shield-checkout.php`
- Test: `tests/unit/EligibilityTest.php`
- Test: `tests/run-checkout.php`

**Interfaces:**
- Produces: `Eligibility::evaluate_current_cart(): array{eligible:bool,code:string,message:string}`.
- Consumes: native COD presence before SureOrder proxy insertion and existing amount/pincode rules.

- [ ] Write failing tests for structured maximum/pincode results, eligible result, order-pay bypass, and native-COD-absent proxy suppression.
- [ ] Run focused tests and verify failure.
- [ ] Implement the structured evaluator while preserving `get_current_ineligibility_reason()` as a compatibility wrapper.
- [ ] Pass native COD availability through the bridge so proxy insertion cannot resurrect it.
- [ ] Run focused tests and verify pass.
- [ ] Commit as `Preserve native COD eligibility` without including unrelated user changes.

### Task 2: Shipping and catalog exclusion rules

**Files:**
- Modify: `includes/class-cod-shield-eligibility.php`
- Modify: `includes/class-cod-shield-settings.php`
- Modify: `includes/class-cod-shield-core.php`
- Test: `tests/unit/EligibilityTest.php`
- Test: `tests/unit/DefaultsTest.php`

**Interfaces:**
- Consumes: Task 1 structured evaluator.
- Produces: sanitized options for excluded zone IDs, rate/instance IDs, product IDs, category IDs, and shipping-class IDs.

- [ ] Write failing pure-helper tests for option normalization, multiple packages, method instances, mixed carts, variation/parent matching, deleted products, categories, and shipping classes.
- [ ] Run focused tests and verify failure.
- [ ] Add Settings API fields and strict sanitizers using newline/comma-separated IDs where a scalable Woo selector is unavailable.
- [ ] Evaluate shipping exclusions before catalog exclusions and return stable reason codes.
- [ ] Memoize resolved products and the result for the current request fingerprint.
- [ ] Run focused tests and verify pass.
- [ ] Commit as `Add high priority COD exclusions` without including unrelated user changes.

### Task 3: Reason-specific classic and Blocks messages

**Files:**
- Modify: `includes/class-cod-shield-blocks.php`
- Modify: `includes/class-cod-shield-ajax.php`
- Modify: `includes/class-cod-shield-settings.php`
- Modify: `public/js/blocks/payment-method.js`
- Modify: `public/js/checkout.js`
- Test: `tests/js/blocks-payment-method.test.js`
- Test: `tests/run-checkout.php`

**Interfaces:**
- Consumes: Tasks 1–2 result codes/messages.
- Produces: Store API/AJAX fields `cod_eligible`, `cod_ineligibility_code`, and `cod_ineligibility_message`.

- [ ] Write failing JS and PHP tests for reason propagation, escaping, live updates, and online-payment noninterference.
- [ ] Run focused tests and verify failure.
- [ ] Expose the structured result through existing Store API and AJAX contracts without accepting client-calculated eligibility.
- [ ] Render sanitized reason messages consistently in classic and Blocks checkout.
- [ ] Run focused tests and verify pass.
- [ ] Commit as `Show COD eligibility reasons at checkout` without including unrelated user changes.

### Task 4: Release verification and installable ZIP

**Files:**
- Modify: `README.md`
- Modify: `readme.txt`
- Modify: `docs/features.md`
- Modify: `docs/release-smoke-test.md`
- Modify: `languages/cod-shield.pot`
- Modify: version-bearing files only if all verification passes.

**Interfaces:**
- Consumes: completed payment-hardening and eligibility plans.
- Produces: a clean `cod-shield` installation directory ZIP excluding development-only files and dependencies.

- [ ] Document new rules, semantics, compatibility limits, and the RideIndiaRide webhook prerequisite.
- [ ] Regenerate translations and run `composer lint`, `composer test`, `composer test:checkout-js`, and `composer test:integration` when its environment is available.
- [ ] Inspect the release archive inventory to ensure no secrets, tests, Git metadata, plans, or `vendor/` are included.
- [ ] Install or load the built ZIP in a disposable WordPress environment when available and run the release smoke checks.
- [ ] Update the version consistently only after verification, then rebuild and hash the final ZIP.
- [ ] Commit as `Prepare verified SureOrder release` without including unrelated user changes.

