# Project Handover: PMP Store Credits Integration

> *Published as delivered.* This is the technical handover written during the original deployment. Sections 6 and 8 describe the intended repository layout and next steps, not the state of this snapshot. See [README.md](README.md) for what this repository actually ships and for the pre-publication review.

## 1. Project Overview

This document outlines the architecture, environment constraints, conflict resolution history, and repository setup guidelines for the **PMP Store Credits Integration** custom WordPress plugin.

* **Core Purpose:** Automatically allocate monthly store credits to active Paid Memberships Pro (PMPro) members, handling rollover balances, scheduled expirations, and WooCommerce store credit redemption.
* **Target Ecosystem:** WordPress + Paid Memberships Pro (PMPro) + WooCommerce + Breakdance Builder.

## 2. Motivations & Problem Statement

### Motivations
* **Rewarding Recurring Memberships Automatically:** Drive subscriber retention and deliver immediate, tangible value to paying members by automatically granting monthly store credits upon subscription renewal without manual admin intervention.
* **Controlled Credit Rollover & Expiration:** Allow unused credits to roll over for a defined grace period before expiring, encouraging steady customer engagement and repeat purchases while preventing indefinite liability on the store's balance sheet.
* **Lightweight Native Integration:** Bridge **Paid Memberships Pro (PMPro)** and **WooCommerce** cleanly without bloating the site with heavy, expensive third-party loyalty/points plugins that clash with the site's architecture.

### Core Issues Solved
1. **Missing Native Credit Lifecycle in PMPro & WooCommerce:** Neither Paid Memberships Pro nor standard WooCommerce store credit tools natively supported issuing recurring monthly credits tied to membership billing cycles with custom rollover caps and time-based expiration rules.
2. **Checkout & Theme Compatibility (Breakdance):** Off-the-shelf credit plugins either failed to render properly inside **Breakdance Builder's** custom WooCommerce checkout templates or caused layout and script conflicts with the Breakdance Zero Theme.
3. **Environment Instability & Plugin Conflicts:**
   * Running modern WooCommerce (`11.x`) alongside outdated WooCommerce add-ons created fatal PHP function mismatches and database schema conflicts.
   * Unbatched background cron tasks and aggressive caching/minification from **SpeedyCache Pro** triggered LiteSpeed `Request Timeout` errors under strict PHP `max_execution_time` and `memory_limit` settings.

## 3. Core Plugin Features & Architecture

When structuring the codebase for GitHub, organize the plugin around these core functional modules:

* **PMPro Monthly Allocation Engine:** Hooks into Paid Memberships Pro membership level changes and recurring payment/renewal hooks (e.g., `pmpro_after_change_membership_level`, `pmpro_subscription_payment_completed`) to grant monthly store credit allotments.
* **Rollover Logic:** Evaluates remaining member credit balances at the end of a billing cycle and carries over eligible unused credits into the new cycle based on membership tier rules.
* **Expiration Handler:** Tracks credit grant timestamps and automatically expires unused credits once their validity window closes.
* **User Ledger / Metadata:** Stores credit balances, transaction logs, and expiration dates cleanly in user meta or a custom database table (avoiding heavy, unindexed `wp_options` bloat).
* **WooCommerce & Account Integration:** Displays current credit balances and expiration dates within the PMPro/WooCommerce "My Account" dashboard and applies store credit balances during WooCommerce checkout.

## 4. Environment & Stack Constraints

Any developer cloning or deploying this repository must account for the target production environment profile:

* **Page Builder / Theme:** Breakdance Builder with **Breakdance Zero Theme**.
  * *Constraint:* The theme requires the Breakdance plugin to remain active at all times; disabling all plugins globally will render a blank "Breakdance not enabled" notice on the frontend.
* **Caching Layer:** SpeedyCache Pro.
  * *Constraint:* Aggressive JS/CSS minification and page preloading can interfere with dynamic credit balance displays and AJAX checkout updates. Exclude dynamic membership/account/checkout endpoints from caching.
* **Server Environment (LiteSpeed / PHP):**
  * Ensure `max_execution_time` is set to at least `300` and `memory_limit` to `512M+` when running batch cron jobs for credit rollovers/expirations.
  * Background cron tasks must be batched/paginated to prevent LiteSpeed `Request Timeout` errors.

## 5. Known Conflicts & Troubleshooting History

During initial deployment and stabilization, several ecosystem conflicts were identified and resolved. Keep these guardrails in mind during future development:

1. **WooCommerce & Extension Version Mismatches:**
   * *Issue:* Running modern WooCommerce (`11.x+`) alongside outdated WooCommerce add-ons causes fatal PHP errors due to deprecated hooks and database schema mismatches.
   * *Rule:* Never downgrade/roll back WooCommerce core while dependent extensions remain active. Always declare explicit `WC requires at least` and `WC tested up to` headers in the main plugin file.
2. **Orphaned Dependency Crashes:**
   * *Issue:* If either **Paid Memberships Pro** or **WooCommerce** is temporarily deactivated or missing, dependent code will throw fatal errors unless wrapped in `defined( 'PMPRO_VERSION' )` and `class_exists( 'WooCommerce' )` checks.
   * *Rule:* **PMP Store Credits Integration** must gracefully abort initialization (`return;`) if PMPro or WooCommerce is not loaded.
3. **Browser Session & Cookie Loops:**
   * *Issue:* Following a PHP fatal error or timeout, stale admin session cookies can cause main browser tabs to hang while Incognito sessions load normally.
   * *Rule:* Clear domain cookies/site data and purge server-side object/page caches after resolving fatal errors.

## 6. Recommended GitHub Repository Structure

Use the following directory structure when initializing your Git repository:

```
pmp-store-credits-integration/
├── .gitignore                        # Ignore vendor/, node_modules/, .env, and OS files
├── README.md                         # Setup instructions, PMPro hooks, and shortcode docs
├── HANDOVER.md                       # This technical handover file
├── pmp-store-credits-integration.php # Main plugin bootstrap file (with PMPro & WC checks)
├── includes/
│   ├── class-pmp-credit-allocator.php   # PMPro membership & renewal grant hooks
│   ├── class-pmp-credit-rollover.php    # Rollover calculation & batch cron logic
│   ├── class-pmp-credit-expirer.php     # Expiration checks & cleanup routines
│   └── class-wc-credit-integration.php  # WooCommerce checkout & My Account UI
├── assets/
│   ├── css/
│   └── js/
└── tests/                            # PHPUnit / WP_Mock tests for rollover math
```

## 7. Security & Pre-Commit Checklist

Before pushing code to a public or private GitHub repository, verify that **none** of the following are present in the commit history:

* [ ] Database credentials (`DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`)
* [ ] WordPress Salts / Security Keys (`AUTH_KEY`, `SECURE_AUTH_KEY`, etc.)
* [ ] Hardcoded production URLs, staging domains, or server root paths (e.g., `/home/username/public_html/`)
* [ ] Payment gateway (Stripe/PayPal), SMTP, or third-party API keys
* [ ] Raw `error_log` or `debug.log` dumps containing member emails or order data
* [ ] `.zip` backups of the site or database `.sql` exports

## 8. Next Development Steps

1. **Initialize Repo:** Create a new repository named `pmp-store-credits-integration` on GitHub and add a standard WordPress `.gitignore` file.
2. **Add Dependency Guards:** Ensure `pmp-store-credits-integration.php` verifies both `PMPRO_VERSION` and `WooCommerce` exist before executing any hooks.
3. **Batch Cron Processing:** Refactor rollover and expiration routines to process members in small batches (e.g., 50 users per tick) using Action Scheduler (built into WooCommerce) rather than standard `WP-Cron` to avoid LiteSpeed timeouts.
4. **Staging Workflow:** Test all plugin and WooCommerce/PMPro updates on a staging clone before deploying to production.
