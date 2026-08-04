# Security Policy

Thank you for helping keep COD Shield for WooCommerce and the stores that use it safe. This plugin handles **real payments** — we take security reports seriously and will respond promptly.

## Reporting a Vulnerability

**Please do not open a public issue for security vulnerabilities.**

Instead, report privately using one of these channels:

1. **GitHub private vulnerability reporting** (preferred) — use the **"Report a vulnerability"** button on the repository's [Security tab](https://github.com/prathameshshirole/cod-shield/security/advisories).
2. **Email** — send details to the [maintainer's WordPress.org profile](https://profiles.wordpress.org/prathaverse/).

Please include as much of the following as possible:

- The plugin version(s) affected
- WordPress, WooCommerce, and PHP versions
- A detailed description of the vulnerability
- Steps to reproduce (proof of concept)
- Impact assessment (what an attacker could do)

## What Happens Next

- You will receive an **acknowledgement within 48 hours**.
- We will assess the report and provide a timeline for the fix.
- The vulnerability is kept **confidential** until a fix is released, giving stores time to update.
- When a fix ships, you will be credited (if you wish) and the issue is disclosed responsibly.

## Supported Versions

Security fixes are backported to the **latest stable release** only.

| Version | Supported |
| ------- | --------- |
| Latest (1.x) | ✅ Security fixes and bug fixes |
| Older 1.x | ❌ Please update to the latest release |

## Reporting Scope

We care about vulnerabilities in the **plugin's own code** — for example:

- Server-side validation bypasses (advance amount tampering, overcharge)
- Unauthorized order state changes (status escalation, manual reconciliation abuse)
- Missing capability/nonce checks on admin actions
- Unescaped/unsanitized output leading to XSS
- Insecure data handling or information disclosure

**Out of scope:**

- Vulnerabilities in WordPress or WooCommerce core, or in third-party gateway plugins
- Social engineering or phishing
- Denial-of-service attacks that do not involve a code defect

## Security Best Practices for Store Owners

- Keep WordPress, WooCommerce, and all plugins updated
- Only enable gateways you trust to handle advance collection (see the gateway note in the [README](README.md#how-it-works))
- Use strong admin credentials and limit admin access
- Take regular backups of your database and files

## Disclosure Policy

We follow a **coordinated disclosure** process:

1. Reporter submits privately → acknowledged within 48h
2. Maintainer triages and prepares a fix
3. Fix is released (patch release) and disclosed
4. Vulnerability details are published after users have had a reasonable window to update
