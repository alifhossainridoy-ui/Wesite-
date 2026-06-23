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
- **Data durability.** `rzog_leads`, `rzog_blocklist`, and order data are explicitly meant for future business analysis/scaling, not disposable logs. Plugin deactivation must **never** delete these tables or their rows — `register_deactivation_hook` (if one is ever added) may only unregister hooks/cron, never touch data. Schema changes must be additive (new columns/tables), never a drop-and-recreate. Any feature that deletes rows (e.g. the 4.2 retention cleanup) must be opt-in, threshold-configurable via settings, and scoped to specific statuses — never a blanket wipe. If a genuinely destructive migration is ever unavoidable, it must ship with an export path (CSV dump of the affected table) run first.

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

Additional requirements (apply to the existing implementation, not deferred):
- **Rate-limiting by IP.** This is a public, unauthenticated, order-creating endpoint — it needs its own throttle independent of the fraud check (fraud check answers "is this phone trustworthy", not "is this client flooding us"). Cap requests per IP per time window; respond `429` once exceeded.
- **Idempotency via `session_id`.** A double-click submit or a client-side retry-on-timeout must not create two orders. Store the frontend-generated `session_id` (same one used in 4.2) as order meta on creation; if a request arrives with a `session_id` that already has an order, return that existing order's info instead of creating a duplicate. No-op (skip the check) when `session_id` is absent.

### 4.2 Lead Capture (NEW — build this)

Goal: capture partial form data the moment the customer types, no submit required, for the manual-call workflow in 4.4.

Reference: `reference/devpsoft-incomplete-orders.js` — the `#dp-order-now` marker pattern, debounced input/change listener, fbp/fbc capture logic, and the flexible field-selector fallbacks are all solid and should be adapted (not copied verbatim — it has its own AJAX wiring tied to a different plugin's options object; rebuild the JS using the same approach against this plugin's own localized config and `admin-ajax.php` action).

Required:
- Frontend JS enqueued site-wide, only binds if `#dp-order-now` (or equivalent marker the order form will use) is present in the DOM.
- AJAX action (`wp_ajax_rzog_save_lead` / `wp_ajax_nopriv_rzog_save_lead`) writing to `rzog_leads` (insert or update by `session_id`, same as the reference's upsert pattern).
- Minimum-meaningful-data gate before sending (phone ≥6 digits, or valid email, or name ≥3 chars, or address ≥3 chars) — don't write empty/garbage rows.
- Standard field names in the actual order form HTML should be WooCommerce-convention (`billing_first_name`, `billing_phone`, `billing_address_1`, etc.) for maximum compatibility with this and any future hook-based plugin.
- **Nonce verification** on the AJAX action via `check_ajax_referer()`, matching the reference plugin's pattern — this is a state-changing write endpoint, not a read.
- **Server-side rate-limiting**, independent of the client-side debounce (the reference JS debounces 900ms before sending, but that's bypassable by calling `admin-ajax.php` directly — the throttle has to live on the server, not just in the JS).
- **Scheduled cleanup via WP-Cron** (daily): mark `new` leads older than N minutes as `abandoned`; optionally delete `abandoned`/`rejected` leads older than a configurable retention period. Both the "mark abandoned after" minutes and the "delete after" retention period must be settings fields (not hardcoded) — and per the data-durability principle in section 3, deletion must default to off / a long retention unless the business owner explicitly configures it shorter.

### 4.3 Pixel / CAPI (NEW — build this)

Reference: `reference/devpsoft-server-capi-reference.php` for the Purchase event payload shape and hashing approach — **extract only that, do not import its structure wholesale or its SDK usage.** Reference: `reference/devpsoft-woocommerce-events-reference.php` for which WooCommerce hooks to use (`woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_thankyou` — fire on all three for resilience, dedup by a stored event_id so it only actually sends once per order).

Required:
- Settings fields: Pixel ID, CAPI access token (System User token from Meta Business Manager), per-pixel (RupZone and RupLota will need different pixel IDs eventually — make this a list keyed by domain or by a settings field per site, not a single global constant).
- On order creation (4.1 step 7): capture `_fbp`/`_fbc` cookies and a generated `event_id` into order meta — same data old plugin captured via `woocommerce_checkout_create_order`, just without the SDK.
- On order status → `processing`/`completed`, and on `woocommerce_thankyou`: send one Purchase event via plain `wp_remote_post` to `https://graph.facebook.com/v{API_VERSION}/{pixel_id}/events`, with hashed `em`/`ph` (SHA-256, lowercased/trimmed per Meta's spec), `client_ip_address`, `client_user_agent`, `fbp`, `fbc`, `event_id` (for browser-pixel dedup), `value`, `currency`, `content_ids`. Guard with a per-order "already sent" meta flag so the three trigger points don't send three times.
- A simple way to verify: log the request/response when `WP_DEBUG` is on, and tell the user to check Meta Events Manager's Test Events tab for live verification — do not claim this "works" without that live check (CLAUDE.md verification expectations).
- **Phone hashing for CAPI needs the `880...` country-code format**, which is DIFFERENT from `Fraud_Check::normalize_phone()`'s local `01...` format used for courier/fraud matching. Add a separate normalization function for CAPI specifically (e.g. `to_capi_phone_format()`) — do not reuse `normalize_phone()` for both purposes, they have opposite target formats.
- **Log failed CAPI sends visibly** — order meta (e.g. `_rzog_capi_last_error`) plus an admin notice or a simple failures list. Do not swallow `wp_remote_post` errors or non-200 responses silently.
- **Store the CAPI access token via `Encryption::encrypt`**, same pattern as the courier credentials in `class-admin-settings.php` (`maybe_encrypt` sanitize callback on save, `Encryption::read_option` / `display_value` on render).

#### 4.3 Addendum — Live Audit Findings (locked, not yet implemented)

Sourced from a live audit of `devpsoft-fb-pixel-capi` (RupZone pixel `1937621713840918`) and `wc-fake-order-blocker-free`. These supersede/extend the base 4.3 spec above and are locked decisions, not suggestions. **Not yet implemented against the already-merged `class-capi.php` — recorded here for scope; implementation requires explicit go-ahead since it reopens approved/merged work.**

- **Event scope**: send `PageView`, `ViewContent`, `InitiateCheckout`, `Purchase`, and `Lead`. Explicitly **do not** send `AddToCart` — the audit found the legacy plugin firing `AddToCart` on a CartFlows-style auto-add-to-cart flow where the cart was populated programmatically before any real user action, producing false signal. Do not reintroduce that.
- **Purchase dedup architecture**: keep the three trigger points (`woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_thankyou`) but formalize the guard: a persistent per-order meta flag (already-sent) checked before any send, one shared `event_id` reused across browser pixel and CAPI for the same order (for Meta-side dedup), and a **pre-send validity guard** that must pass before a Purchase event is sent at all: order status is `processing` or `completed`, `total > 0`, and `item_count >= 1`. Skip silently (with a log entry) if the guard fails rather than sending malformed events.
- **Order Status Integrity — blocking pre-check for this phase, not optional**: before building any of the above, confirm the 4.1 order-intake endpoint actually calls real WooCommerce status-transition methods (`$order->update_status()` / `set_status()` + `save()`), not just `update_meta_data()`. The audit found `wc-fake-order-blocker-free`'s courier webhooks updated order meta directly without ever calling a status-transition method, so WooCommerce's own status-change hooks (and therefore CAPI's trigger points above) never fired for those orders. Any courier webhook code added in this project (see `class-status-bridge.php`) must keep calling `update_status()` directly — this is the same requirement as CLAUDE.md hard rule 4, restated here because it's a hard dependency for 4.3's Purchase triggers to fire at all.
- **Phone normalization — locked naming**: two distinctly-named functions, `normalize_phone_for_capi(string $raw): string` (target format `880...`, for hashing into `ph`) and `normalize_phone_for_courier(string $raw): string` (target format `01...`, i.e. the existing `Fraud_Check::normalize_phone()` logic, possibly renamed/aliased to match this convention). Do not pass a single shared "normalized phone" variable between CAPI and courier code paths — they have opposite target formats and silently swapping one for the other was the actual bug shape found in the legacy plugin's phone handling.
- **CAPI failure logging — locked decision**: each failed `wp_remote_post` (network error or non-2xx/Meta error response) must produce a structured log entry containing: event name, order ID, error message, HTTP status / Meta error code, timestamp, and attempt count. Failures must feed a retry-queue (not just a single best-effort send) with backoff between attempts and a max-retry cap, after which the order is left flagged as failed for manual follow-up rather than retried forever. This replaces the simpler "order meta + admin notice" approach described as sufficient earlier in this section — the structured/queued version is now the requirement.
- **Cutover sequence (operational, not code)**: when this sender goes live, disable the legacy `devpsoft-fb-pixel-capi` plugin's pixel and CAPI toggles in the *same* maintenance window — don't run both senders concurrently (double-counted events). Immediately after cutover, verify in Meta Events Manager that pixel `1937621713840918`'s dataset quality/event match rate hasn't regressed compared to the legacy plugin's baseline.

### 4.4 Manual-Review Admin UI (NEW — build this)

This is a real operational requirement: blocked/incomplete orders and captured leads are not just logs — the business owner manually calls a subset of them, finds occasional real sales among the "fake" bucket, and needs to track who's been called.

Required, on a simple admin list page (could be a tab on the existing settings page or its own menu item):
- Table of `rzog_leads` rows: name, phone (as a `tel:` link), address, product, value, status, created_at.
- Status field editable inline or via quick-action buttons: `new` → `called` → `confirmed` / `rejected`. (Add `called`/`confirmed`/`rejected` as valid status values alongside the existing `new`/`converted`/`abandoned`/`blocked`.)
- Sortable/filterable by status at minimum (so "show me everything still `new`" is one click).
- No SMS, no automated outreach — confirmed manual-only.
- **Gate the page behind `current_user_can('manage_options')`**, same as the settings page (CLAUDE.md rule 7 — this applies to the *capability check*, not the license gate; the license gate still only applies to functional/external hooks, not admin screens).

### 4.5 Frontend Funnel Template (NEW — build this, separate plugin)

A second, small plugin — `rz-funnel-template` — separate from `rz-order-guard` (different responsibility: display, not fraud/courier/pixel logic). It consumes `rz-order-guard`'s existing contracts rather than duplicating them:
- Order form posts to `/wp-json/rzog/v1/order` (4.1).
- Order form wrapper carries `id="dp-order-now"` so the existing lead-capture JS (4.2) binds to it automatically — no new JS needed for that part.
- Form fields use standard WooCommerce field names (`billing_first_name`, `billing_last_name`, `billing_phone`, `billing_address_1`) for consistency with both plugins' expectations.

**What it does**: overrides WooCommerce's single-product template for every product (not per-product Elementor assignment) via `woocommerce_locate_template` or a `single-product.php` override registered from the plugin, so it applies automatically to all 10 products with zero per-product setup.

**Why a custom template instead of Elementor** (decided, don't revisit): Elementor adds page weight that works against the "low load" requirement already established for this project, and the order form's REST/JS integration needs raw markup regardless of which is used — Elementor's no-code advantage doesn't apply to the most important part of the page anyway.

**Page structure, top to bottom**:
1. Hero — product gallery/images, title, price (pull from the real `WC_Product` object, not hardcoded — content stays editable via the normal product admin screen).
2. Order form, wrapped in `id="dp-order-now"` — name, phone, address, quantity, COD-only, submit posts to the 4.1 endpoint via `fetch`.
3. Repeated anchor CTA buttons through the page body (`<a href="#dp-order-now">...</a>`), scrolling back up to the form — exact copy is business content, not specified here.
4. Benefit/ingredient/image sections — pull from the product's description/short description fields, so editing content never requires a code change.
5. Cross-sell grid before the footer — WooCommerce related-products query, styled consistently, each card linking to that product's own page (which renders through this same template).

**Design tokens** (best-effort reconstruction of an earlier landing page direction — Cream white + Deep Teal + Gold + Playfair Display — exact hex values were not recoverable from this session; treat as a strong starting point, adjust if the original file turns up):
- Cream background: `#F8F4EC`
- Deep teal (primary/headers/CTA): `#1C4A45`
- Gold accent (sparingly, CTA highlights/dividers): `#C9A24B`
- Warm near-black body text: `#211D17`

**Typography — important constraint, do not default to Playfair Display for Bangla copy**: the actual product copy is Bengali (Bangla script). Playfair Display is Latin-only and will silently fall back to a default font for any Bangla character, breaking the intended look. Use:
- Display/headline (Bangla): **Noto Serif Bengali** — closest elegant-serif match to the Playfair Display direction that actually renders Bangla.
- Body (Bangla): **Hind Siliguri** or **Noto Sans Bengali** — clean, highly legible at small sizes on mobile.
- Playfair Display itself is fine ONLY for any Latin-script brand wordmark, English logo text, or numerals — not for Bangla headline/body copy.

**Performance**: lazy-load every image below the hero fold (`loading="lazy"`), no Elementor/page-builder runtime, minimal custom CSS/JS — this page will be hit by paid traffic on BD mobile networks, page weight is a conversion-rate variable, not a nice-to-have.

**Structural reference**: an existing live page (tuketaky.online, old domain, same product) has a genuinely useful structure to borrow from — order form directly below the hero, repeated CTA buttons scrolling to the form, an FAQ accordion near the end, footer trust badges (COD/bKash/Nagad) + policy page links + WhatsApp contact. Use this as a structural reference only.

**Explicitly do NOT carry over from that reference page** — these content patterns contradict the policy-safety decisions already established for this project, and one was already self-identified as a problem in the business owner's own past planning and never fixed:
- Before/after photos
- Guaranteed-result language ("7-day result guarantee" etc.)
- Biological mechanism claims ("increases collagen/elastin production")
- Unverified specific percentage statistics presented as fact
- Fake scarcity counters ("only 27 left in stock") — flagged as fake in the business's own action plan previously, still live on the reference page, do not repeat it here

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
- [ ] `rz-funnel-template` applies to all products automatically, no per-product setup
- [ ] Order form on the template has `id="dp-order-now"` and standard WooCommerce field names, confirmed working with both the 4.1 endpoint and the 4.2 lead-capture JS on a real page (not just in isolation)
- [ ] No Bangla text rendered in Playfair Display (or any other Latin-only font) — confirmed visually, not just by reading the CSS
- [ ] Every new/changed file passes `php -l`
- [ ] Nothing in the new code reintroduces obfuscation, bundled SDKs, or a missed `do_action()` for order creation/status changes
