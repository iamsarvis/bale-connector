# Bale Connector — Agent Instructions (Free Core Repo)

Repository: `bale-connector` (public, hosted on WordPress.org)
API reference: https://docs.bale.ai/

## 1. What this repo is

Bale Connector bridges WordPress/WooCommerce sites to the **Bale messenger** Bot API.
This repository is the **free core plugin**, distributed through the WordPress.org
Plugin Directory. All paid features live in a **separate, private** repository,
`bale-connector-pro` (see `bale-connector-roadmap.md` for the full multi-repo plan).

**Read this file in full before touching any code.** For what to build in which
order, see `bale-connector-roadmap.md` in this same repo — it contains the phased,
branch-by-branch build plan for both this repo and the Pro repo.

## 2. Business boundary — read this before writing a single line

WordPress.org's Detailed Plugin Guidelines prohibit **trialware**: a free plugin
whose real functionality is disabled and only unlocked by payment is grounds for
rejection or removal. Because of this, the split between this repo and
`bale-connector-pro` is a **hard architectural boundary**, not a UI toggle:

- This repo must be 100% free, complete, and fully functional on its own — forever.
  No license checks, no "upgrade to unlock" buttons, no crippled code paths.
- **In free core:** bot token setup + connection testing, recipient (person/group)
  management, send-log, and Contact Form 7 → Bale integration (unlimited forms).
- **In Pro (separate repo, not here):** WooCommerce order notifications, OTP login,
  Elementor/WPForms/Gravity Forms support, two-way AI chat + inbound webhook,
  in-Bale order management, abandoned-cart reminders.
- This repo exposes documented hooks/filters (§7) so Pro add-ons attach externally.
  Never add Pro-only branches, feature flags, or dead code for Pro features here.

If a task description asks you to add anything from the Pro list to this repo,
stop and flag it — it belongs in `bale-connector-pro` instead.

## 3. Compatibility targets

- PHP 7.4 – 8.4 (avoid PHP 8-only syntax such as enums/readonly unless guarded)
- WordPress 6.x – 7.x
- No Composer, no SOAP, no heavy third-party libraries. If a small library is ever
  unavoidable, vendor it with Strauss (namespace-scoped), matching the convention
  used in Sobhan's other plugins (FileChi).
- Any delayed/bulk sending must use **Action Scheduler**, not raw `wp-cron` — this
  is an established project convention (used in the Pulse SMS plugin).

## 4. Architecture

OOP, one class per responsibility, no procedural spaghetti:

```
bale-connector/
├── bale-connector.php              ← bootstrap, hooks, version const
├── includes/
│   ├── class-bale-api-client.php   ← thin wrapper over tapi.bale.ai
│   ├── class-bale-admin.php
│   ├── class-bale-recipients.php   ← person/group CRUD + test-connection
│   ├── class-bale-cf7-integration.php
│   ├── class-bale-logger.php
│   ├── class-bale-security.php     ← token libsodium encryption/masking
│   └── class-bale-installer.php    ← dbDelta schema, uninstall cleanup
├── admin/{views,css,js}/
├── languages/
│   ├── bale-connector.pot
│   └── bale-connector-fa_IR.po
└── uninstall.php
```

## 5. Bale Bot API — verified essentials

- Base URL: `https://tapi.bale.ai/bot<TOKEN>/<METHOD>`
- `getMe` — validates the token, no params. Use for the "Save & Verify Token" action.
- `getChat` — validates a `chat_id` is reachable (bot is a group member, or the user
  has started the bot). This is exactly the "Test Connection" button from the spec.
- `sendMessage` text is capped at **4096 characters**; `caption` on media methods
  (`sendPhoto`, `sendDocument`, etc.) is capped at **1024 characters**. Enforce both
  limits client-side (character counter in the template editor) and server-side
  before the API call — don't rely on Bale to reject it gracefully.
- Text formatting is limited to exactly three constructs: `*bold*`, `_italic_`, and
  `[text](url)`. Do not build a rich Markdown editor — only expose these three.
- `setWebhook` takes only a `url` parameter. Unlike some bot platforms, Bale does
  not document a built-in secret-token header. **Any inbound webhook (Pro repo,
  not this one) must embed a random secret in the URL path itself** and
  de-duplicate deliveries using `update_id`.
- No documented rate limits on this API version — code defensively anyway: handle
  non-`ok` JSON responses, back off and retry on network failures, and route all
  outbound sends through Action Scheduler so a slow/failed Bale API call never
  blocks a page load (form submission, order save, etc.).

## 6. Security — non-negotiable on every PR

- Nonce verification on every admin POST/AJAX request.
- `current_user_can( 'manage_options' )` gate on every settings screen and AJAX
  handler that touches plugin config.
- Sanitize all input (`sanitize_text_field`, `absint`, `sanitize_textarea_field`,
  etc.); escape all output (`esc_html`, `esc_attr`, `esc_url`).
- Bot token is encrypted at rest with **libsodium** (same pattern as FileChi) —
  never written to logs in plaintext, and shown in the UI only as a masked value
  (last 4 characters visible).
- Validate `chat_id` format before every outbound call.

## 7. Extension points for `bale-connector-pro`

Every hook/filter the Pro repo depends on must be documented here as it's added.
Minimum viable set to ship in Phase 1–4:

- `bale_connector_get_client()` → returns the configured, ready-to-use API client.
- `bale_connector_recipients()` → returns the saved recipients array.
- `bale_connector_register_trigger( string $slug, array $args )` → lets a Pro
  add-on register a new trigger type (order status, OTP, etc.) into the shared
  admin UI and the shared log table, without modifying this repo's code.
- `bale_connector_log( array $entry )` → single write path into the logs table,
  so Pro triggers reuse the exact same log/report UI as CF7 does.

## 8. Coding & git conventions

- English-only code, comments, and commit messages (required for wp.org and for
  cross-agent/cross-developer clarity).
- Conventional commits: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`.
- One feature per branch, named `feature/<slug>` (or `chore/<slug>` for non-feature
  work). Never commit directly to `main`. Merge only after the Definition of Done
  below passes, then delete the branch.
- Bump the plugin version header on every release (wp.org guideline requirement).

## 9. Definition of Done (every branch, before merge)

- [ ] Manually verified on PHP 7.4 and PHP 8.4
- [ ] No nonce / capability / sanitize / escape gaps (see §6)
- [ ] No Pro-only or license-gated code introduced (see §2)
- [ ] User-facing strings wrapped for i18n; `.pot` regenerated
- [ ] Fresh install → activate → configure → uninstall leaves no orphaned data
      (respecting the "keep data on uninstall" setting)
- [ ] Commit messages and PR description reference the phase number from
      `bale-connector-roadmap.md`

## 10. What NOT to do

- Don't introduce a framework (React, Vue, `@wordpress/components`) for what is a
  handful of settings screens — plain, well-structured PHP + vanilla JS/AJAX is
  the right footprint for this plugin.
- Don't reach for `wpcf7_before_send_mail` for the CF7 hook — use
  `wpcf7_mail_sent`, which only fires after CF7's own validation/spam checks pass,
  so Bale notifications aren't sent for failed or spam submissions.
- Don't invent a new settings-storage pattern — use `wp_options` (via the
  Settings API) for global config and the two custom tables
  (`wp_bale_connector_recipients`, `wp_bale_connector_logs`) for structured,
  queryable data. See `bale-connector-roadmap.md` §"Database" for the finalized
  schema.
