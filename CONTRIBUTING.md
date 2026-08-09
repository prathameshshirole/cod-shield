# Contributing to COD Shield for WooCommerce

First off, thanks for taking the time to contribute! ❤️

All types of contributions are encouraged and valued. See the [Table of Contents](#table-of-contents) for different ways to help and details about how this project handles them. Please make sure to read the relevant section before making your contribution — it will make it a lot easier for us maintainers and smooth out the experience for all involved. The community looks forward to your contributions. 🎉

> And if you like the project, but just don't have time to contribute, that's fine. There are other easy ways to support the project and show your appreciation, which we would also be very happy about:
>
> - ⭐ Star the project
> - 🐦 Tweet about it
> - 📣 Mention the project at local meetups and tell your friends/colleagues
> - 💬 Join the [GitHub Discussions](https://github.com/prathameshshirole/cod-shield/discussions)
> - 💬 Help others in the [WordPress.org support forum](https://wordpress.org/support/plugin/cod-shield/)

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [I Have a Question](#i-have-a-question)
- [I Want To Contribute](#i-want-to-contribute)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Enhancements](#suggesting-enhancements)
  - [Your First Code Contribution](#your-first-code-contribution)
  - [Improving The Documentation](#improving-the-documentation)
- [Styleguides](#styleguides)
  - [Coding Standards](#coding-standards)
  - [Testing](#testing)
  - [Commit Messages](#commit-messages)
- [Join The Project Team](#join-the-project-team)

## Code of Conduct

This project and everyone participating in it is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code. Please report unacceptable behavior to the [GitHub repository](https://github.com/prathameshshirole/cod-shield/issues).

## I Have a Question

> If you want to ask a question, we assume that you have read the available [Documentation](README.md) — the [FAQ](README.md#frequently-asked-questions) in particular.

Before you ask a question, it is best to search for existing [Issues](https://github.com/prathameshshirole/cod-shield/issues) that might help you.

**For usage and configuration help, please use the [WordPress.org support forum](https://wordpress.org/support/plugin/cod-shield/)** — questions are answered faster there, and the answers help other store owners too.

If you still need clarification, open an [Issue](https://github.com/prathameshshirole/cod-shield/issues/new) and provide:

- As much context as you can about what you're running into
- WordPress, WooCommerce, and PHP versions
- Checkout type (classic shortcode or block checkout) — this plugin behaves differently on each

## I Want To Contribute

> ### Legal Notice
>
> When contributing to this project, you must agree that you have authored 100% of the content, that you have the necessary rights to the content, and that the content you contribute may be provided under the project license ([GNU GPL v2 or later](LICENSE)).

### Reporting Bugs

#### Before Submitting a Bug Report

A good bug report shouldn't leave others needing to chase you up for more information. Please complete the following steps in advance:

- **Make sure you are using the latest version** of the plugin.
- **Check the [FAQ](README.md#frequently-asked-questions)** — your issue may already be answered.
- **Check the [changelog](readme.txt)** — the issue may already be fixed in a newer version.
- **Search the [issue tracker](https://github.com/prathameshshirole/cod-shield/issues)** to see if someone has already reported the same problem.
- Determine if your bug is really a bug and not an error on your side (e.g. a gateway not honouring the order total — see the [gateway note](README.md#how-it-works)).

#### How Do I Submit a Good Bug Report?

> ⚠️ **You must never report security-related issues, vulnerabilities, or bugs including sensitive information to the issue tracker or anywhere public.** Sensitive bugs must be reported **privately** — see [SECURITY.md](SECURITY.md) (GitHub private vulnerability reporting).

We use GitHub issues to track bugs and errors. If you run into an issue with the plugin, please use the [bug report template](.github/ISSUE_TEMPLATE/bug_report.md) and include:

- **A clear, descriptive title**
- **Steps to reproduce** — the more specific, the better
- **Expected vs. actual behavior**
- **Environment**: WordPress version, WooCommerce version, PHP version, plugin version, checkout type
- **Order details if money is involved**: order status and any order notes on the affected order — this plugin moves real payments, and that information is usually the fastest way to diagnose

Once it's filed, a maintainer will try to reproduce the issue with your steps. If there are no reproduction steps, we'll ask for them and mark the issue as `needs-repro`.

### Suggesting Enhancements

This section guides you through submitting an enhancement suggestion for COD Shield for WooCommerce, **including completely new features and minor improvements to existing functionality**.

#### Before Submitting an Enhancement

- Make sure you are using the latest version.
- Read the [documentation](README.md) carefully and find out if the functionality is already covered.
- Perform a [search](https://github.com/prathameshshirole/cod-shield/issues) to see if the enhancement has already been suggested. If it has, add a comment to the existing issue instead of opening a new one.
- Find out whether your idea fits with the scope and aims of the project. We want features that will be useful to the majority of users — if you're targeting a narrow use case, consider writing a small add-on instead.

#### How Do I Submit a Good Enhancement Suggestion?

Enhancement suggestions are tracked as [GitHub issues](https://github.com/prathameshshirole/cod-shield/issues), using the [feature request template](.github/ISSUE_TEMPLATE/feature_request.md):

- Use a **clear and descriptive title**
- Provide a **step-by-step description** of the suggested enhancement
- **Describe the current behavior** and **explain which behavior you expected to see instead**, and why
- **Explain why this enhancement would be useful** and who benefits from it
- Screenshots or animated GIFs help demonstrate what you mean

### Your First Code Contribution

Unsure where to start? Good entry points:

- Issues labelled [`good first issue`](https://github.com/prathameshshirole/cod-shield/labels/good%20first%20issue)
- Issues labelled [`help wanted`](https://github.com/prathameshshirole/cod-shield/labels/help%20wanted)
- The pure calculation engines in `includes/class-cod-shield-core.php` (`calculate_advance()`, `calculate_fee()`) — self-contained, heavily unit-tested, and a great introduction to the plugin's core logic

**Development setup** (no WordPress installation needed — tests run standalone):

```bash
composer install
```

See [Development](README.md#development) in the README for the full workflow, and the [Pull Request Process](#pull-request-process) below.

#### Pull Request Process

1. **Fork** the repository and create a feature branch:

   ```bash
   git checkout -b feature/my-awesome-feature
   ```

2. **Make your changes**, following the [coding standards](#coding-standards) and [testing](#testing) rules above.
3. **Run the full suite** before pushing:

   ```bash
   composer lint
   composer test
   ```

4. **Update documentation** if user-facing behaviour changed: `README.md`, `readme.txt`, and `languages/cod-shield.pot` (via `composer pot`).
5. **Open a pull request** using the [pull request template](.github/PULL_REQUEST_TEMPLATE.md) and complete its checklist — maintainers will not merge PRs with failing lint or tests.

Small, focused PRs are reviewed much faster than large ones.

### Improving The Documentation

Documentation lives in three places — keep them in sync when user-facing behaviour changes:

1. **[README.md](README.md)** — the GitHub-facing readme (features, install, config, FAQ)
2. **[readme.txt](readme.txt)** — the WordPress.org readme (feature list, FAQ, changelog, upgrade notices)
3. **[docs/](docs/)** — `prd.md` (product requirements) and `features.md`

Translations live in `languages/` (`text domain: cod-shield`). If you add or edit translatable strings, regenerate the template with `composer pot`, and ideally contribute a `.po`/`.mo` file for your locale.

## Styleguides

### Coding Standards

This project follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/), enforced with PHPCS:

```bash
composer lint        # check for violations
composer lint:fix    # auto-fix where possible
```

Key conventions:

- One class per file, named `class-cod-shield-{name}.php` — the autoloader maps `CodShield\Class_Name` to this convention automatically
- No runtime dependencies — the plugin must work without `vendor/` (dev tools are composer-only)
- All text strings wrapped for translation (`__()`, `esc_html__()`, etc.) with text domain `cod-shield`

All code must pass `composer lint` before a pull request is merged.

### Testing

Tests run **without** WordPress or WooCommerce — no database, no fixtures:

```bash
composer test              # full suite: PHPUnit unit tests + lifecycle harness
composer test:standalone   # calculation engines via plain-PHP runner (no composer)
composer test:lifecycle    # money-lifecycle harness (finalization, overcharge guard, idempotency)
```

**Rules:**

- Any new behaviour **must** come with tests:
  - Pure logic → `tests/unit/` (PHPUnit)
  - Money-moving code → extend `tests/run-lifecycle.php`
- Bug fixes must include a **regression test** that fails on the old code and passes on the fix
- Run the full suite before pushing: `composer test`

### Commit Messages

- Use clear, descriptive commit messages in the imperative mood:
  - ✅ `Fix fatal error on HPOS orders list when filtering by advance status`
  - ❌ `fix stuff`
- Reference related issues: `Fix #12 — advance not recalculated when coupon applied`
- No strict format is required, but one logical change per commit is appreciated

## Join The Project Team

Want to get more involved? We're always happy to welcome maintainers. The best ways to start:

- Help triage and answer questions in the [GitHub Discussions](https://github.com/prathameshshirole/cod-shield/discussions) and the [WordPress.org support forum](https://wordpress.org/support/plugin/cod-shield/)
- Review open [pull requests](https://github.com/prathameshshirole/cod-shield/pulls)
- Take ownership of a feature area and drive it forward

Reach out via the GitHub repository if you'd like to become a regular contributor.

## Attribution

This guide is based on the [contributing.md](https://contributing.md/) template.
