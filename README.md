# PMP Store Credits Integration

A WordPress plugin that issues recurring store credit to **Paid Memberships Pro** members and lets them redeem it at **WooCommerce** checkout.

> **Status: reference implementation / handover snapshot.** This is the single-file plugin as it ran in production, published for documentation and as a starting point for the refactor described in [HANDOVER.md](HANDOVER.md). It is *not* a polished public plugin: the [known issues](#known-issues) below are real and include one high-severity bug. Read them before deploying.

---

## Why this exists

From the original handover — full text in [HANDOVER.md](HANDOVER.md) §2:

* **Reward recurring membership automatically.** A paying member should get immediate, tangible value on every renewal, without an administrator touching the account.
* **Control rollover and expiry.** Unused credit may carry over for a defined grace period and then expire — enough to keep members engaged and buying, not so much that the store carries indefinite liability on its balance sheet.
* **Stay lightweight.** Bridge PMPro and WooCommerce natively rather than install a heavy, expensive loyalty/points plugin that fights the site's architecture.

It was written because nothing off the shelf did this: neither PMPro nor WooCommerce could issue recurring credit tied to a billing cycle with custom rollover caps and time-based expiry; commercial credit plugins broke inside Breakdance's custom checkout templates or collided with its theme; and running WooCommerce `11.x` alongside stale add-ons produced fatal function mismatches and schema conflicts.

The rollover half of that intent is **not** in the shipped code — it resets the balance each cycle instead of carrying unused credit forward. See [known issues](#known-issues) #3.

---

## What it does

| Capability | How |
| --- | --- |
| Grant credit on joining the paid level | `pmpro_after_checkout` — compares the order's `membership_id` with the configured target level |
| Grant credit on each renewal | `pmpro_subscription_payment_completed` |
| Revoke credit when the member leaves the level | `pmpro_after_change_membership_level` + `pmpro_membership_post_membership_expiry` |
| Catch level changes made by an admin | `admin_init` sweep of `pmpro_memberships_users` rows modified in the last 5 minutes |
| Apply credit to the cart | Negative WooCommerce fee, capped at the cart subtotal, non-taxable, auto-applied once a balance exists |
| Show the balance | My Account dashboard, cart, checkout, and two shortcodes |
| Expire stale credit | Daily WP-Cron event `pmp_expire_credits` |
| Admin tooling | Manual grant to one user, bulk grant to existing members, last 50 ledger rows, plugin status panel |

## Requirements

* WordPress 5.x or newer (uses `dbDelta`, WP-Cron, shortcodes, `wp_doing_ajax`).
* PHP 7.0+ — the code uses the null-coalescing operator. 7.4 / 8.x recommended; it was not written for 8.3 deprecation-clean output.
* **Paid Memberships Pro** — needs `pmpro_getMembershipLevelForUser`, `pmpro_hasMembershipLevel`, `pmpro_after_checkout`, `pmpro_subscription_payment_completed`.
* **WooCommerce** — needs `WC()->cart->add_fee()` and HPOS-era order CRUD (`wc_get_order`).

The plugin registers its hooks only when the corresponding dependency is present, but it does *not* currently abort bootstrap when both are missing — see [known issues](#known-issues) #8.

## Install

1. Copy this folder to `wp-content/plugins/pmp-store-credits-integration/`.
2. Activate **PMP Store Credits Integration** in *Plugins*.
3. The ledger table `{prefix}pmp_store_credits` is created on the next `init` (and again on activation).
4. Grant the level-1 cohort an opening balance from **Store Credits → Award Credits to Existing Members**.

### Configuration

Three private properties at the top of `PMP_Store_Credits` are the entire configuration surface:

```php
private $credit_amount          = 5;   // amount granted per cycle, in store currency (£)
private $target_membership_level = 1;  // PMPro level ID that earns credit
private $expiration_days        = 30;  // validity window of each grant
```

Change them in the file; there is no settings screen. Moving them to constants or filters is on the roadmap.

### Hosting notes

* **Breakdance / Breakdance Zero Theme** — the theme requires the Breakdance plugin to stay active; never disable all plugins globally to debug, the front end renders a blank "Breakdance not enabled" notice.
* **SpeedyCache Pro / LiteSpeed** — exclude My Account, cart, checkout and `admin-ajax.php` from page cache and JS/CSS minification, otherwise balances render stale or the apply-credit AJAX call breaks.
* **Cron** — expiry runs as a single unbounded query plus a per-member re-grant loop. On a large list, batch it (see roadmap) or raise `max_execution_time` to 300 and `memory_limit` to 512M.

## How it works

**Allocation.** A grant is `remove_all_credits()` followed by one insert, so a member always holds exactly one active row. `add_credits()` also cancels the previous balance, which means the "rollover" goal from the handover is *not* implemented — the current model is "reset to £5 at the start of each cycle", not "carry unused credit forward".

**Redemption.** `woocommerce_cart_calculate_fees` adds a fee named *Store Credit* with a negative amount, capped at the cart subtotal. The flag `use_store_credits` is stored in the WC session and set automatically the first time a balance is seen.

**Expiry.** The daily job expires every `active` row past `expiration_date`, then re-grants credit to members who still hold the target level. Credit therefore renews through cron as well as through PMPro payment hooks.

### Hook map

| Hook | Callback | Purpose |
| --- | --- | --- |
| `plugins_loaded` (priority 20) | `new PMP_Store_Credits()` | Bootstrap after other plugins |
| `pmpro_after_checkout` | `award_credits_after_checkout` | Grant on joining |
| `pmpro_subscription_payment_completed` | `award_credits_on_payment` | Grant on renewal |
| `pmpro_after_change_membership_level` | *two separate closures* | Revoke on leave/downgrade |
| `pmpro_membership_post_membership_expiry` | `remove_credits_on_cancellation` | Revoke on expiry |
| `admin_init` | `check_pending_level_assignments` | Reconcile admin-side level edits |
| `woocommerce_cart_calculate_fees` | `apply_credit_discount` | Apply balance as a fee |
| `woocommerce_checkout_order_processed` | `deduct_credits_on_purchase` | Mark credit used (does not fire — see #1) |
| `woocommerce_account_dashboard`, `woocommerce_before_cart_table`, `woocommerce_before_checkout_form` | display callbacks | Balance UI |
| `wp` | `schedule_credit_expiration` | Register the daily event |
| `pmp_expire_credits` | `expire_old_credits` | Expire + re-grant |
| `init` | `check_and_create_table` | Table existence check |

### Database schema

`{prefix}pmp_store_credits`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `mediumint(9)` auto | Primary key |
| `user_id` | `bigint(20)` | Indexed |
| `credit_amount` | `decimal(10,2)` | In store currency |
| `earned_date` | `datetime` | Defaults to `CURRENT_TIMESTAMP` |
| `expiration_date` | `datetime` | Indexed |
| `status` | `varchar(20)` | `active`, `used`, `cancelled`, `expired` (indexed) |
| `source` | `varchar(50)` | `checkout`, `renewal`, `level_change`, `signup`, `admin`, `admin_change`, `auto_renewal` |

Balances are always read as `SUM(credit_amount) WHERE status='active' AND expiration_date > NOW()`, gated on `pmpro_hasMembershipLevel()` for the target level.

## Shortcodes and admin UI

| Shortcode | Output |
| --- | --- |
| `[store_credits_balance]` | Balance card for the logged-in member |
| `[store_credits_form]` | Balance + "Apply Discount" button; renders nothing at zero balance |
| `[credit_status]` | Debug line (user ID, balance, membership state) — administrators only |

**Store Credits** appears twice in wp-admin: as a submenu under *Memberships* (or *Tools* when PMPro is inactive) and as a top-level menu, both pointing at the same page. The page grants credit to a user ID, bulk-grants to existing level members, lists the last 50 ledger rows, and shows a status panel for PMPro / WooCommerce / the table.

## Verified production incident: credit expired, nothing re-granted

The log below is from a live install running this plugin (absolute paths trimmed). It is the reason issue #1 exists, and it is the highest-priority defect in the codebase.

```
[11-Aug-2025 20:21:05 UTC] [PMP CREDITS] Starting credit expiration process
[11-Aug-2025 20:21:05 UTC] [PMP CREDITS] Found 5 active members with expiring credits
[11-Aug-2025 20:21:05 UTC] [PMP CREDITS] Expired 6 credit records
[11-Aug-2025 20:21:05 UTC] Attempting to add credits - User: 2, Amount: 5, Source: auto_renewal
[11-Aug-2025 20:21:05 UTC] PHP Fatal error:  Uncaught TypeError: method_exists(): Argument #1
    ($object_or_class) must be of type object|string, null given in
    .../plugins/pmp-store-credit-auto-award.php:380
```

**What happens.** `expire_old_credits()` expires every past-due row first, then loops the affected members calling `add_credits()`. That method starts with `remove_all_credits()`, whose final block is:

```php
if (class_exists('WooCommerce') && function_exists('WC') && !is_admin()) {
    if (isset(WC()->session)) {
        WC()->session->__unset('use_store_credits');
    }
    if (method_exists(WC()->cart, 'calculate_totals')) {   // WC()->cart is null under wp-cron.php
        WC()->cart->calculate_totals();
    }
}
```

`WC()->cart` is not initialised during `wp-cron.php`, so `method_exists(null, …)` throws. The `UPDATE … SET status='expired'` has already been committed, so the member loses the credit and gets nothing back — and the fatal aborts the rest of the loop, so no later member is processed either.

**Impact.** Credit expires and is not renewed: the opposite of the intended perk, and it fails silently because PHP only logs the fatal. 159 of 160 cron runs in the sampled log found nothing to expire, so the fault stays invisible until a cycle with real expirations.

**The same block also fatals in admin context** when `WC()->session` is null — five occurrences on 12 Jul 2025, including one second before a manual grant that then never completed:

```
[12-Jul-2025 17:25:26 UTC] Attempting to add credits - User: 1, Amount: 5, Source: admin
[12-Jul-2025 17:25:26 UTC] PHP Fatal error:  Uncaught Error: Call to a member function
    __unset() on null in .../plugins/pmp-store-credit-auto-award.php:377
```

**Shape of the fix** — guard both objects before touching them, and remove the `!is_admin()` condition that hides the session clear from admin grants:

```php
if (class_exists('WooCommerce') && function_exists('WC')) {
    if (WC()->session) {
        WC()->session->__unset('use_store_credits');
    }
    if (WC()->cart && method_exists(WC()->cart, 'calculate_totals')) {
        WC()->cart->calculate_totals();
    }
}
```

**Reading line numbers.** The deployed copy is named `pmp-store-credit-auto-award.php`; this repository ships it as `pmp-store-credits-integration.php`. The live build is three lines shorter at this point, so `:377`/`:380` correspond to `:380`/`:383` here. The file has also been through several earlier builds, so older log lines can cite methods that no longer exist.

## Known issues

Findings from the pre-publication review of the shipped code. **#1–#3 change behaviour, not just style.**

| # | Severity | Issue |
| --- | --- | --- |
| 1 | **High** | **The expiry cron fatals before it can re-grant.** `remove_all_credits()` ends with `method_exists(WC()->cart, 'calculate_totals')`, and `WC()->cart` is null under `wp-cron.php`, so `expire_old_credits()` kills the request after committing the expiry UPDATE. Members lose credit and get nothing back. Verified in production — see the incident section above. |
| 2 | **High** | **Redemption is never booked (in this snapshot).** `add_fee()` names the fee *Store Credit*, but `deduct_credits_on_purchase()` looks for a fee named *Store Credit Applied*, so the ledger stays `active` and a member can spend the same balance on repeated orders until it expires. `deduct_credits()` also ignores its `$amount` argument and marks every active row used, so partial redemption is not modelled. The live site did not reproduce this in the available log — but that log has no deduction logging at all, and the live file differs by a few lines, so confirm against the `status` column before treating it as settled. |
| 3 | **High** | **No rollover, contrary to the handover.** `add_credits()` calls `remove_all_credits()` first (commented `non-stackable`), cancelling any unused balance: members are reset to the fixed amount each cycle instead of carrying credit forward with a cap, which is what HANDOVER §3 describes. Whether that is a bug or a deliberate "use it or lose it" rule is a product decision, not a code one. |
| 4 | Medium | `assets/credits.js` is enqueued on every front-end page but the file is not in the repository, so each page load requests a missing script. The real handler is inline in `display_credit_form()`. |
| 5 | Medium | `pmpro_after_change_membership_level` is registered twice with overlapping logic, and one handler echoes `<script>` from inside the hook instead of using `wc_enqueue_js()`. |
| 6 | Medium | `check_and_create_table()` runs `SHOW TABLES LIKE` on every `init`. Replace with a `dbDelta` version-option guard. |
| 7 | Medium | `?test_auto_credit=1` runs on `init` for *any* logged-in user and writes to `error_log`. Debug tooling should be administrator-gated or removed. |
| 8 | Medium | Bootstrap does not `return` when PMPro and WooCommerce are both absent, contrary to the guard rule in the handover. Hook registration is conditional, but class methods still call PMPro functions. |
| 9 | Low | Unprepared SQL in `SHOW TABLES LIKE '...'` and interpolated level IDs. Values are internal constants today, so not user-controllable — still not the pattern to keep. |
| 10 | Low | Escaping gaps on the admin page (`$membership->name`, raw `$_POST`-derived echoes are cast but not escaped). Administrator-only surface. |
| 11 | Low | Bulk grant prints "Credits awarded to 1 existing members" — the method returns `true`, not a count. |
| 12 | Low | Currency symbol `£`, English strings and the 30-day window are hardcoded in markup and strings; not currency-agnostic or fully i18n-ready. |
| 13 | Low | `wp_ajax_nopriv_apply_store_credit` is registered although the handler requires a logged-in user. |

## Roadmap

Aligned with the handover plan:

1. **Stop the fatal first** — guard `WC()->cart` and `WC()->session` in `remove_all_credits()` so the expiry cron and admin grants can complete. Nothing else matters while a monthly job can silently delete credit.
2. **Book redemptions correctly** — store a fee/order-meta identifier instead of matching a translated display name, and support partial use.
3. **Decide the rollover rule** — either carry unused credit per tier up to a cap, or document "£5 per cycle, use it or lose it" and drop the rollover language from the handover.
4. Move expiry and re-grant to batched Action Scheduler jobs (50 users per tick) instead of one WP-Cron query, so a failure affects one batch rather than the whole run.
5. Split the monolith into `includes/class-pmp-credit-{allocator,rollover,expirer}.php` and `includes/class-wc-credit-integration.php`, with a guarded bootstrap file.
6. Replace hardcoded properties with constants/filters and add a settings screen.
7. Add PHPUnit / WP_Mock coverage for expiry windows, rollover caps and redemption math — the cron fatals above would have been caught by a single test that runs `expire_old_credits()` with `WC()->cart` unset.
8. Escape/i18n pass, remove debug endpoints, delete or ship `assets/credits.js`.

## Security review

Checked before this repository was published:

* No database credentials, WordPress salts, payment-gateway keys, SMTP or third-party API keys.
* No production/staging URLs, no absolute server paths, no IP addresses.
* No member emails, names, order data or exported logs.
* No `.env`, `.sql` dumps or site archives.

The scan covered both the plugin source and the handover document for key/token/password patterns, URLs, email addresses, IP addresses and filesystem paths; the only matches were the literal checklist words `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `Stripe`, `PayPal` inside the handover's pre-commit checklist, and the CSS class `striped`.

Deliberately **not** in this repository: the production `debug.log` the incident section was reconstructed from. It contains absolute server paths, the site's domain and a hosting licence key. Treat any site log the same way — it fails every item in HANDOVER §7.

Runtime caveats remain in the code itself (debug endpoint, `error_log` chatter, admin-only escaping gaps) — see [known issues](#known-issues). Report anything sensitive you spot by opening an issue rather than a pull request that quotes the value.

## Repository layout

```
pmp-store-credits-integration/
├── README.md                            # this file
├── HANDOVER.md                          # architecture, constraints, conflict history
├── .gitignore                           # WordPress plugin ignores
└── pmp-store-credits-integration.php    # the complete plugin (single file)
```

The `includes/`, `assets/` and `tests/` structure in HANDOVER.md §6 is the target for the refactor, not the current state.

## Licence

No licence is declared yet — add one before redistributing or reusing this code.

Author of the plugin header: **Nulla Labs**.
