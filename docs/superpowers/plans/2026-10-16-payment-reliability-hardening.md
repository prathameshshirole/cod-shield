# Payment Reliability Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make delayed transaction persistence observable and recoverable without ever inferring that money was collected.

**Architecture:** Keep `Gateway_Bridge` as the idempotent finalization authority. Add structured rejection diagnostics, an after-save catch-up action, timestamped stale-attempt classification, and explicit admin release/reconciliation controls.

**Tech Stack:** PHP 8.1+, WordPress/WooCommerce hooks and CRUD APIs, Action Scheduler when available with WP-Cron fallback, PHPUnit and standalone lifecycle harnesses.

**Spec:** `docs/superpowers/specs/2026-10-16-payment-reliability-and-eligibility-design.md`

## Global Constraints

- Preserve every pre-existing uncommitted user change.
- No runtime dependency on `vendor/`.
- Use WooCommerce order CRUD APIs and support HPOS with posts synchronization disabled.
- Never mark an advance paid without a nonempty gateway transaction ID or the existing explicit manual-paid action.
- Never log customer identity, credentials, webhook secrets, signatures, or request bodies.
- Classic checkout and Checkout Blocks must remain supported.
- Production RideIndiaRide remains read-only during implementation.

## Review Focus

- A transaction ID written after the original status event schedules exactly one idempotent finalization attempt.
- Saving during finalization does not recurse or enqueue an infinite action loop.
- Gateway mismatch remains fail-closed and produces a deduplicated diagnostic.
- A stale attempt without a transaction ID can be released, while captured/review orders cannot.
- Legacy orders without an attempt timestamp remain visible but are never automatically made retryable.

---

### Task 1: Structured finalization outcomes and diagnostics

**Files:**
- Modify: `includes/class-cod-shield-gateway-bridge.php`
- Modify: `includes/class-cod-shield-core.php`
- Test: `tests/run-lifecycle.php`
- Test: `tests/unit/GatewaySafetyTest.php`

**Interfaces:**
- Produces: stable finalization outcome codes and `Core::should_log_finalization_diagnostic( string $previous, string $current ): bool`.
- Consumes: existing SureOrder order metadata and gateway identity rules.

- [ ] Write failing tests for `not_awaiting`, `missing_transaction`, `gateway_mismatch`, successful finalization, and duplicate diagnostic suppression.
- [ ] Run the focused unit and lifecycle tests and verify the new assertions fail.
- [ ] Refactor finalization preconditions into a side-effect-free outcome evaluator; preserve the public hook callback signature.
- [ ] Add privacy-safe WooCommerce logging and one deduplicated order note for actionable rejection states.
- [ ] Run focused tests and verify they pass.
- [ ] Commit the task as `Add advance finalization diagnostics` without including unrelated user changes.

### Task 2: Late transaction catch-up

**Files:**
- Modify: `includes/class-cod-shield-gateway-bridge.php`
- Modify: `includes/class-cod-shield-core.php`
- Test: `tests/integration/LifecycleTest.php`
- Test: `tests/run-lifecycle.php`

**Interfaces:**
- Consumes: Task 1 finalization outcome evaluator.
- Produces: after-save observer, one-order scheduled reconciliation callback, and recursion/deduplication guards.

- [ ] Add failing tests where status changes before the transaction ID is saved and no second status transition occurs.
- [ ] Add failing tests for duplicate saves/actions and finalizer-triggered recursive saves.
- [ ] Run the focused integration/standalone tests and verify failure.
- [ ] Register an after-order-save observer that queues reconciliation only for awaiting orders whose transaction ID is present.
- [ ] Implement Action Scheduler dispatch with a WP-Cron fallback and a direct callback that reloads the order before finalization.
- [ ] Run focused tests and verify one finalization with no recursion.
- [ ] Commit as `Reconcile late advance transaction IDs` without including unrelated user changes.

### Task 3: Stale attempt classification and safe release

**Files:**
- Modify: `includes/class-cod-shield-core.php`
- Modify: `includes/class-cod-shield-gateway-cod-advance.php`
- Modify: `includes/class-cod-shield-gateway-bridge.php`
- Modify: `includes/class-cod-shield-settings.php`
- Modify: `includes/class-cod-shield-order-ui.php`
- Test: `tests/unit/GatewaySafetyTest.php`
- Test: `tests/integration/LifecycleTest.php`

**Interfaces:**
- Produces: `META_ATTEMPT_STARTED_AT`, stale-attempt option, classifier states `active`, `stale`, `legacy_unknown`, `captured`, and `review`, plus nonce/capability-protected release action.
- Consumes: existing `META_ATTEMPT_STARTED`, `META_AWAITING`, transaction and review state.

- [ ] Write failing classifier tests at the interval boundary, including clock-skew, missing timestamp, transaction present, and review state.
- [ ] Write failing action tests proving only stale/no-transaction attempts can be released.
- [ ] Persist the UTC attempt timestamp when gateway delegation begins and add a bounded merchant setting with a safe default.
- [ ] Display active/stale/legacy/review state in the order metabox and implement the explicit release action with an audit note.
- [ ] Run focused tests and verify pass.
- [ ] Commit as `Add safe stale advance recovery` without including unrelated user changes.

### Task 4: Payment reliability documentation and verification

**Files:**
- Modify: `README.md`
- Modify: `readme.txt`
- Modify: `docs/release-smoke-test.md`
- Modify: `languages/cod-shield.pot`

**Interfaces:**
- Consumes: Tasks 1–3 behavior.
- Produces: merchant-facing recovery documentation and release checks.

- [ ] Document diagnostic states, late catch-up, stale release, and the rule that payment is never inferred.
- [ ] Add smoke tests for delayed transaction persistence, duplicated callbacks, legacy attempts, stale release, and gateway mismatch.
- [ ] Regenerate the POT file.
- [ ] Run `composer lint`, `composer test`, `composer test:checkout-js`, and available integration tests; record any environment-only limitation.
- [ ] Commit as `Document advance reconciliation safeguards` without including unrelated user changes.

