# PMP Store Credits Integration

A WordPress plugin that issues recurring store credit to **Paid Memberships Pro** members and lets them redeem it at **WooCommerce** checkout.

Built for a live membership store where neither PMPro nor WooCommerce offered a native "monthly credit tied to the billing cycle" primitive, and where off-the-shelf loyalty plugins broke inside a Breakdance checkout template.

> **Status: reference implementation / handover snapshot.** This is the single-file plugin as it ran in production, published for documentation and as a starting point for the refactor described in [HANDOVER.md](HANDOVER.md). It is *not* a polished public plugin: the [known issues](#known-issues) below are real and include one high-severity bug. Read them before deploying.

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

The plugin registers its hooks only when the corresponding dependency is present, but it does *not* currently abort bootstrap when both are missing — see [known issues](#known-issues) #7.

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

## Known issues

Findings from the pre-publication review of the shipped code. **#1 and #2 change behaviour, not just style.**

| # | Severity | Issue |
| --- | --- | --- |
| 1 | **High** | **Redemption is never booked.** `add_fee()` names the fee *Store Credit*, but `deduct_credits_on_purchase()` looks for a fee named *Store Credit Applied*. The match never happens, so the ledger stays `active` and a member can spend the same balance on repeated orders until it expires. `deduct_credits()` also ignores its `$amount` argument and marks every active row used, so partial redemption is not modelled. |
| 2 | **High** | **No rollover.** `add_credits()` calls `remove_all_credits()` first, cancelling any unused balance. The handover's rollover requirement is unfulfilled; members are reset to the fixed amount each cycle. |
| 3 | Medium | `assets/credits.js` is enqueued on every front-end page but the file is not in the repository, so each page load requests a missing script. The real handler is inline in `display_credit_form()`. |
| 4 | Medium | `pmpro_after_change_membership_level` is registered twice with overlapping logic, and one handler echoes `<script>` from inside the hook instead of using `wc_enqueue_js()`. |
| 5 | Medium | `check_and_create_table()` runs `SHOW TABLES LIKE` on every `init`. Replace with a `dbDelta` version-option guard. |
| 6 | Medium | `?test_auto_credit=1` runs on `init` for *any* logged-in user and writes to `error_log`. Debug tooling should be administrator-gated or removed. |
| 7 | Medium | Bootstrap does not `return` when PMPro and WooCommerce are both absent, contrary to the guard rule in the handover. Hook registration is conditional, but class methods still call PMPro functions. |
| 8 | Low | Unprepared SQL in `SHOW TABLES LIKE '...'` and interpolated level IDs. Values are internal constants today, so not user-controllable — still not the pattern to keep. |
| 9 | Low | Escaping gaps on the admin page (`$membership->name`, raw `$_POST`-derived echoes are cast but not escaped). Administrator-only surface. |
| 10 | Low | Bulk grant prints "Credits awarded to 1 existing members" — the method returns `true`, not a count. |
| 11 | Low | Currency symbol `£`, English strings and the 30-day window are hardcoded in markup and strings; not currency-agnostic or fully i18n-ready. |
| 12 | Low | `wp_ajax_nopriv_apply_store_credit` is registered although the handler requires a logged-in user. |

## Roadmap

Aligned with the handover plan:

1. Split the monolith into `includes/class-pmp-credit-{allocator,rollover,expirer}.php` and `includes/class-wc-credit-integration.php`, with a guarded bootstrap file.
2. **Book redemptions correctly** — store a fee/order-meta identifier instead of matching a translated display name, and support partial use.
3. **Implement real rollover** — carry unused credit per tier up to a cap, instead of cancel-and-reissue.
4. Move expiry and re-grant to batched Action Scheduler jobs (50 users per tick) instead of one WP-Cron query.
5. Replace hardcoded properties with constants/filters and add a settings screen.
6. Add PHPUnit / WP_Mock coverage for expiry windows, rollover caps and redemption math.
7. Escape/i18n pass, remove debug endpoints, delete or ship `assets/credits.js`.

## Security review

Checked before this repository was published:

* No database credentials, WordPress salts, payment-gateway keys, SMTP or third-party API keys.
* No production/staging URLs, no absolute server paths, no IP addresses.
* No member emails, names, order data or exported logs.
* No `.env`, `.sql` dumps or site archives.

The scan covered both the plugin source and the handover document for key/token/password patterns, URLs, email addresses, IP addresses and filesystem paths; the only matches were the literal checklist words `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `Stripe`, `PayPal` inside the handover's pre-commit checklist, and the CSS class `striped`.

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
