# RZ Order Guard — Blueprint

## 1. Context

Two WordPress sites (RupZone Beauty, RupLota Beauty), both COD skincare e-commerce in Bangladesh, WooCommerce + Facebook Ads as the acquisition channel. The business is moving to a landing-page-style funnel: a single Elementor "Single Product" template applied to all products, with the order form near the top (no cart/checkout redirect), benefit content below, and cross-sell to other products before the footer.

Two commercial plugins currently handle pieces of this: a "fake order blocker" (courier delivery-history fraud check) and a Facebook Pixel/CAPI plugin. Both have real, working logic worth keeping, both have real bloat/bugs worth dropping. This project consolidates the useful parts into one owned, readable, lean plugin — `rz-order-guard`.

**Current use**: single site (personal). **Architecture goal**: clean enough that turning this into a distributable commercial plugin later is a refactor, not a rewrite — see CLAUDE.md hard rules for what that means in practice. Do not build the commercial-distribution parts (multi-tenant data isolation, billing, an update/distribution server) now.

## 2. Current State

See CLAUDE.md "What's already built." In short: fraud-check core, courier integration (3 couriers, outbound + inbound), license system, settings page. All present and namespaced under `RZOG\`.

## 3. Architecture Principles

- **One plugin, one settings page.** Not a settings page per feature.
- **No vendor dependency for the plugin's own gatekeeping.** License validation is local (Ed25519). The *Fraud API* is an explicit, swappable external signal source, not infrastructure the plugin depends on to function at all (local fraud-check works without it).
- **Action hooks fire correctly, always.** See CLAUDE.md hard rule #4. This was the single biggest class of bug found in the source plugins — order-status-dependent features silently doing nothing because the order was created/transitioned through a path that never fired the expected hook.
- **Settings-driven, not hardcoded.** No product names, no specific thresholds, no specific domain baked into core logic. Domain binding only exists in the license check (by design — that's its job).
- **Readable over clever.** This will be read and maintained by a non-professional-developer business owner. Prefer explicit code over abstraction layers that require tracing through multiple files to understand a single operation.

## 4. Feature Spec

### 4.1 Order Intake (NEW — build this)

A REST endpoint (e.g. `POST /wp-json/rzog/v1/order`) that the landing-page order form submits to directly — no classic `/checkout/` page involved.

Required behavior, in order:
1. Validate required fields present (name, phone, address, product/variant, quantity). Reject with a clear error list if not — the frontend needs field-level errors, not a generic failure.
2. Normalize phone (`Fraud_Check::normalize_phone`), reject non-BD-format numbers.
3. Check manual blocklist (`rzog_blocklist` table — IP and phone).
4. Run `Fraud_Check::should_block($phone)`.
   - If blocked: do **not** create a WC_Order. Instead, upsert into `rzog_leads` with `status = 'blocked'` (reuse the same upsert logic as the lead-capture feature in 4.2 — a blocked checkout attempt IS a lead, just one that needs the manual-call review described in 4.4) and return a response telling the frontend to show the contact modal (WhatsApp/Messenger/Call links — already configured in settings via `rzog_contact_*` options). No OTP, no SMS — confirmed not needed.
   - If allowed: continue.
5. Fire `do_action('woocommerce_checkout_process')` and check `wc_notice_count('error')` — any other plugin hooked there (now or later) gets a chance to block too. Abort with its notice text if it added one.
6. Create the `WC_Order`: set billing fields, shipping = billing (no separate shipping address in this funnel), add the line item(s), set payment method to COD, set total.
7. Fire `do_action('woocommerce_checkout_create_order', $order, $data)` — lets meta-capture (4.3's fbp/fbc capture) and the lead-matching logic (4.2) run.
8. `$order->save()`, then `$order->update_status('processing', 'COD order via landing funnel')` — this is the transition that should fire the CAPI Purchase event (4.3) via the existing `woocommerce_order_status_processing` pattern.
9. Reconcile: if a `rzog_leads` row exists for this session/phone, mark it `status = 'converted'` with the new `order_id`.
10. Return the order ID/thank-you info to the frontend (for any client-side browser-pixel Purchase fire, dedup'd against the server event via the shared `event_id` from 4.3).

### 4.2 Lead Capture (NEW — build this)

Goal: capture partial form data the moment the customer types, no submit required, for the manual-call workflow in 4.4.

Reference: `reference/devpsoft-incomplete-orders.js` — the `#dp-order-now` marker pattern, debounced input/change listener, fbp/fbc capture logic, and the flexible field-selector fallbacks are all solid and should be adapted (not copied verbatim — it has its own AJAX wiring tied to a different plugin's options object; rebuild the JS using the same approach against this plugin's own localized config and `admin-ajax.php` action).

Required:
- Frontend JS enqueued site-wide, only binds if `#dp-order-now` (or equivalent marker the order form will use) is present in the DOM.
- AJAX action (`wp_ajax_rzog_save_lead` / `wp_ajax_nopriv_rzog_save_lead`) writing to `rzog_leads` (insert or update by `session_id`, same as the reference's upsert pattern).
- Minimum-meaningful-data gate before sending (phone ≥6 digits, or valid email, or name ≥3 chars, or address ≥3 chars) — don't write empty/garbage rows.
- Standard field names in the actual order form HTML should be WooCommerce-convention (`billing_first_name`, `billing_phone`, `billing_address_1`, etc.) for maximum compatibility with this and any future hook-based plugin.

### 4.3 Pixel / CAPI (NEW — build this)

Reference: `reference/devpsoft-server-capi-reference.php` for the Purchase event payload shape and hashing approach — **extract only that, do not import its structure wholesale or its SDK usage.** Reference: `reference/devpsoft-woocommerce-events-reference.php` for which WooCommerce hooks to use (`woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_thankyou` — fire on all three for resilience, dedup by a stored event_id so it only actually sends once per order).

Required:
- Settings fields: Pixel ID, CAPI access token (System User token from Meta Business Manager), per-pixel (RupZone and RupLota will need different pixel IDs eventually — make this a list keyed by domain or by a settings field per site, not a single global constant).
- On order creation (4.1 step 7): capture `_fbp`/`_fbc` cookies and a generated `event_id` into order meta — same data old plugin captured via `woocommerce_checkout_create_order`, just without the SDK.
- On order status → `processing`/`completed`, and on `woocommerce_thankyou`: send one Purchase event via plain `wp_remote_post` to `https://graph.facebook.com/v{API_VERSION}/{pixel_id}/events`, with hashed `em`/`ph` (SHA-256, lowercased/trimmed per Meta's spec), `client_ip_address`, `client_user_agent`, `fbp`, `fbc`, `event_id` (for browser-pixel dedup), `value`, `currency`, `content_ids`. Guard with a per-order "already sent" meta flag so the three trigger points don't send three times.
- A simple way to verify: log the request/response when `WP_DEBUG` is on, and tell the user to check Meta Events Manager's Test Events tab for live verification — do not claim this "works" without that live check (CLAUDE.md verification expectations).

### 4.4 Manual-Review Admin UI (NEW — build this)

This is a real operational requirement: blocked/incomplete orders and captured leads are not just logs — the business owner manually calls a subset of them, finds occasional real sales among the "fake" bucket, and needs to track who's been called.

Required, on a simple admin list page (could be a tab on the existing settings page or its own menu item):
- Table of `rzog_leads` rows: name, phone (as a `tel:` link), address, product, value, status, created_at.
- Status field editable inline or via quick-action buttons: `new` → `called` → `confirmed` / `rejected`. (Add `called`/`confirmed`/`rejected` as valid status values alongside the existing `new`/`converted`/`abandoned`/`blocked`.)
- Sortable/filterable by status at minimum (so "show me everything still `new`" is one click).
- No SMS, no automated outreach — confirmed manual-only.

## 5. Non-functional Requirements

- **Performance**: cache external fraud-API results (already done, `rzog_fraud_cache` table, configurable hours). Avoid N+1 queries in the admin list (4.4) — paginate.
- **Security**: nonce-verify all AJAX/REST writes, sanitize all input, prepared statements for all direct `$wpdb` queries (already the pattern in `class-fraud-check.php` and the courier files — keep it consistent in new code).
- **No PHP fatal on missing optional config** — e.g. if a courier isn't enabled or Pixel ID isn't set, skip that feature silently (with an admin notice, not a crash).
- **Lint every file** (`php -l`) before considering a task done — no environment exists in this sandbox to do deeper testing.

## 6. Explicitly Out of Scope (for this phase)

- Multi-tenant data isolation / per-customer billing / license-issuing server
- A plugin update/distribution mechanism (WordPress.org-style or otherwise)
- Marketplace listing, documentation site, customer support tooling
- OTP verification, SMS-based abandoned-lead recovery (explicitly dropped by the business owner)
- Device fingerprinting (already disabled in the source plugin, not being revived)

## 7. Definition of Done (for the pending items in section 4)

- [ ] Order intake endpoint creates a real WC_Order, fires the correct hooks in the correct order (CLAUDE.md rule #4), and blocks via `Fraud_Check` before creating anything
- [ ] A blocked attempt produces a `rzog_leads` row, not a silently dropped request
- [ ] Lead capture JS fires on input without requiring submit, on a page containing `#dp-order-now`
- [ ] CAPI Purchase event sends exactly once per order (verify via order meta flag + Meta Test Events tab)
- [ ] Manual-review list page shows leads with working `tel:` links and status transitions
- [ ] Every new/changed file passes `php -l`
- [ ] Nothing in the new code reintroduces obfuscation, bundled SDKs, or a missed `do_action()` for order creation/status changes
