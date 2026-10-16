# Payment Reliability and Eligibility Design

## Intent

Ship a safer SureOrder release that makes delayed Razorpay confirmations observable and recoverable, prevents stale legacy attempts from remaining blocked forever, preserves native WooCommerce COD restrictions, and adds the highest-value merchant eligibility rules.

The implementation is built in the existing SureOrder working folder. Existing uncommitted work belongs to the user and must be preserved. Production RideIndiaRide remains read-only during development. The final deliverable is a verified installable ZIP; production deployment and historical-order mutation are separate, explicitly reviewed operations.

## Evidence motivating the work

- RideIndiaRide runs SureOrder 2.1.1, WooCommerce 11.1.2, Razorpay 4.8.8, and HPOS without posts synchronization.
- Seventeen orders are awaiting a Razorpay advance with no WooCommerce transaction ID.
- Razorpay automatic webhook setup is rejected because the production hostname resolves locally to `127.0.0.1` through `/etc/hosts`.
- SureOrder has no catch-up path when a transaction ID is saved after the final eligible status event.
- Legacy attempts without the newer attempt marker can be treated as in-flight indefinitely.
- The advance proxy can be reintroduced after native COD was removed by its shipping-method restrictions.

## Goals

1. Record a bounded, privacy-safe diagnostic reason whenever an awaiting order cannot finalize.
2. Finalize an awaiting order when a valid transaction ID is persisted after the original payment/status event.
3. Surface stale pending attempts for reconciliation without marking money paid from local assumptions.
4. Allow retry only after a configurable stale interval and only when there is no transaction ID or reconciliation flag.
5. Never let the proxy bypass native COD availability or shipping-method restrictions.
6. Add exclusion rules for shipping zones/method instances, products, product categories, and shipping classes.
7. Return a stable eligibility reason code and localized reason-specific message across classic and block checkout.
8. Produce an installable ZIP only after lint, unit, standalone lifecycle, checkout, JavaScript, and available integration checks pass.

## Non-goals

- SureOrder will not implement or impersonate Razorpay's webhook endpoint.
- It will not mark a payment captured solely because an order is old, has a Razorpay order ID, or changed status.
- It will not call Razorpay APIs from checkout requests.
- It will not mutate RideIndiaRide orders or server networking during development.
- Country, state, city, customer-role, fuzzy-address, CSV import/export, and remote fraud-scoring rules are deferred.

## Payment reliability design

`Gateway_Bridge` remains the single authority for finalization. A new observer reacts after an order is saved and schedules a one-order reconciliation action only when SureOrder metadata says an advance is awaiting and a transaction ID is now present. The action reloads the order and calls the existing idempotent finalizer. A recursion guard prevents save-triggered loops.

Finalization rejection becomes a structured result with stable codes such as `not_awaiting`, `missing_transaction`, and `gateway_mismatch`. Operational logging contains order ID, code, gateway identifiers, and status only—never customer details, secrets, request payloads, or full payment tokens. Repeated identical diagnostics for the same order are deduplicated.

A stale-attempt policy uses the existing attempt-started metadata plus a new attempt timestamp. Old orders without a timestamp stay protected and appear as `legacy_unknown`; they are not automatically released. New attempts become retryable after the configured interval only when no transaction ID exists and the order is not in reconciliation review. Admin UI shows stale/review state and an explicit safe retry-release action protected by capability and nonce. Payment status is never inferred.

## Eligibility design

`Eligibility` becomes the single evaluator and returns `{ eligible, code, message }`. Rules are exclusions and use deterministic fail-closed semantics: a matching shipping zone/method or any matching physical cart item excludes COD. Variations match both their own product ID and parent product/category/shipping-class data. Multiple shipping packages are ineligible when any selected package matches an exclusion.

The bridge must receive evidence that native COD was available before it inserts the advance proxy. Order-pay retains its existing recorded-obligation behavior and is not re-evaluated against current cart rules.

Classic checkout, Store API validation, Blocks data, and live notices consume the same evaluation result. Online payment methods remain unaffected.

## Operational follow-up

After the ZIP is verified, present a separate RideIndiaRide runbook covering backup, correcting local DNS/host resolution, re-enabling and testing the Razorpay webhook in the dashboard, observing signed delivery, and reconciling the 17 historical orders against authenticated Razorpay evidence. No step is executed without explicit production approval.

