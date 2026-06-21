# CLAUDE.md — RZ Order Guard

This file is project-level context for Claude Code working in this repo. Read `BLUEPRINT.md` first for the full spec — this file is the short operating rules.

## What this project is

A single WordPress plugin (`rz-order-guard/`) consolidating fraud-check, courier integration, lead capture, and Facebook Pixel/CAPI into one codebase for a Bangladesh COD skincare e-commerce business (WooCommerce). It replaces two commercial plugins whose source is in `reference/` for API/payload details only — **do not copy their structure, licensing code, or bloat, only the factual API details** (field names, endpoints, status vocab).

Currently for single-site personal use, architected so it *could* later become a distributable commercial plugin without a rewrite. Do not build multi-tenant infrastructure, billing, an update server, or a marketplace listing now — that is explicitly out of scope. "Architected for it" means: no hardcoded business-specific values in core classes, everything site-specific lives in `wp_options` via the settings page, classes have single responsibilities.

## Hard rules

1. **Never obfuscate code.** No `goto`-based control flow, no randomized variable names, nothing that makes the code harder to read than necessary. One of the two source plugins had obfuscated files (`FraudAPIClient.php`, `CredentialEncryption.php`) specifically to hide vendor logic — that is the opposite of what this project wants. Every file should be readable by the project owner without help.
2. **No bundled SDKs for single API calls.** The original Pixel plugin shipped the entire Facebook Business PHP SDK (hundreds of files) just to call `wp_remote_post` once. Use WordPress's own HTTP API (`wp_remote_post`/`wp_remote_get`) directly. Don't add Composer dependencies without a real reason.
3. **No license-server phone-home for the plugin's own functioning.** The license system (`includes/class-license.php`) is Ed25519 signature verification done entirely locally — no network call to validate it. Keep it that way. The *Fraud API* (iguazudigital) is a deliberate, user-chosen external dependency for fraud-check cross-merchant signal — that one's fine, it's not the plugin's own gatekeeping.
4. **Action hooks must actually fire.** Any code path that creates or transitions a `WC_Order` outside the classic checkout flow (e.g. the order-intake REST endpoint this project still needs) must manually fire `do_action('woocommerce_checkout_process')` before creating the order (so any future fraud gate runs) and `do_action('woocommerce_checkout_create_order', $order, $data)` after (so meta-capture hooks run), and must use `$order->update_status()` for any status change — never just `update_meta_data()` when the actual order state should change. This was a real, confirmed bug in the original courier webhooks (fixed in `class-status-bridge.php`) — don't reintroduce the pattern elsewhere.
5. **BD phone format**: `^01[3-9][0-9]{8}$`, 11 digits. Normalize `8801...` to `01...` before storing/comparing (see `Fraud_Check::normalize_phone`).
6. **Don't average percentages from different-sized samples.** When combining fraud signals from multiple sources, sum raw counts first, then compute one ratio (see `Fraud_Check::combined_check` for why).
7. **Settings page must stay usable even with an invalid license** (so the license key itself can be entered/fixed) — only gate the *functional* hooks (webhooks, order intake, etc.) behind `License::is_valid()`, never the settings screen itself.

## What's already built (do not redo, extend instead)

- `includes/class-db.php` — DB schema (blocklist, leads, fraud_cache tables)
- `includes/class-encryption.php` — AES-256-GCM helper for storing credentials
- `includes/class-fraud-check.php` — combined local (own order history) + external (iguazudigital) fraud check
- `includes/class-status-bridge.php` — courier raw-status → WC order status mapping (Pathao/RedX mappings are **unverified guesses**, marked as such in the file — confirm against real webhook payloads and fix if wrong)
- `includes/class-license.php` + `rzog-offline-tools/` (NOT part of the plugin, kept separate — never bundle these into the plugin zip) — Ed25519 license signing/verification
- `includes/CourierIntegration/` — Manager + Pathao/Steadfast/RedX clients (outbound booking + status refresh), adapted from a clean (non-obfuscated) source
- `includes/Webhooks/` — inbound delivery-status webhooks for all three couriers, with the status-transition fix applied
- `includes/class-admin-settings.php` — single settings page (license, fraud API, courier credentials, contact info for blocked-order modal)

## What's still pending — see BLUEPRINT.md section 4 for full spec

1. Order intake REST endpoint (the actual entry point from the landing-page COD form)
2. Lead capture AJAX endpoint + frontend JS (`#dp-order-now` pattern — reference: `reference/devpsoft-incomplete-orders.js`)
3. Server-side CAPI sender (reference: `reference/devpsoft-server-capi-reference.php` for payload structure — do NOT copy its SDK usage or its 1469 lines of bloat, extract only the Purchase-event payload shape and the hashing approach for em/ph)
4. A manual-review admin UI for the `leads`/blocklist tables (phone/address/product visible, one-click `tel:` call link, status buttons: called → confirmed/rejected) — this is a real operational requirement, not a nice-to-have, see BLUEPRINT.md section 4.4

## Verification expectations

There is no local WordPress/PHP environment in this sandbox to run the plugin in. At minimum: `php -l` every file before considering it done. Beyond that, call out clearly to the user what needs live verification on the actual WordPress site (Meta Events Manager Test Events tab for CAPI, a real webhook payload for Pathao/RedX status mapping, an actual test order through the intake endpoint) — don't claim something "works" that's only been syntax-checked.
