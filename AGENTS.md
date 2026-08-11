# Repository Guidelines

Contributor guide for **COD Shield for WooCommerce** (`cod-shield`), a WordPress plugin that reduces COD RTO by collecting a partial advance payment through existing WooCommerce gateways. Requires PHP 8.1+. See `CONTRIBUTING.md` for the full contribution workflow.

## Project Structure

- `cod-shield.php` — main plugin entry point
- `includes/` — all PHP source classes (`class-cod-shield-{name}.php`)
- `admin/` / `public/` — admin and frontend assets (CSS, JS, block checkout scripts)
- `languages/` — translations (text domain `cod-shield`)
- `tests/` — `tests/unit/` (PHPUnit) plus standalone and lifecycle harnesses
- `docs/` — product requirements (`prd.md`) and features
- `.github/` — release workflow, PR and issue templates
- `vendor/` — composer dev tools only; **never a runtime dependency**

## Build, Test & Development Commands

```bash
composer install        # install dev tooling (no runtime deps)
composer test           # full suite: PHPUnit + lifecycle harness
composer test:standalone  # plain-PHP runner for calculation engines (no composer)
composer test:lifecycle   # money-lifecycle harness (finalization, overcharge guard, idempotency)
composer lint           # PHPCS check (WordPress Coding Standards)
composer lint:fix       # auto-fix violations
composer pot            # regenerate languages/cod-shield.pot after string changes
```

## Coding Style & Naming Conventions

- Follow the **WordPress Coding Standards**, enforced via `phpcs.xml.dist`; tabs for indentation
- One class per file: `includes/class-cod-shield-{name}.php`, autoloaded as `CodShield\Class_Name`
- No runtime dependencies — the plugin must work without `vendor/`
- Wrap every user-facing string in `__()`, `esc_html__()`, etc., using text domain `cod-shield`
- Checkout logic must support both classic shortcode and block checkout

## Testing Guidelines

- **Pure logic** (advance/fee calculations) → PHPUnit tests in `tests/unit/`, named `testXxx()` per behaviour
- **Money-moving code** → extend `tests/run-lifecycle.php`
- Bug fixes must include a **regression test** that fails on the old code
- Run `composer test` in full before pushing

## Commit & Pull Request Guidelines

- Commit messages in the **imperative mood**, descriptive: `Fix fatal error on HPOS orders list when filtering by advance status` — not `fix stuff`
- Reference related issues: `Fix #12 — advance not recalculated when coupon applied`
- One logical change per commit; no strict format required
- Open PRs with the template in `.github/PULL_REQUEST_TEMPLATE.md`, and complete its checklist: passing `composer lint` and `composer test`, tests for new functionality, regenerated `.pot` file, and screenshots for visual changes
- Small, focused PRs are reviewed fastest; maintainers will not merge PRs with failing lint or tests
