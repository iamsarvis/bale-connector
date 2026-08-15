# Bale Connector — Build Roadmap

This is the phase-by-phase plan referenced by `AGENTS.md`. Feed one phase at a
time to Hermes as its own branch; merge only after the Definition of Done in
`AGENTS.md` §9 passes. Do not skip ahead — later phases assume earlier ones exist.

## Business model (decided)

Two repositories, one hard boundary:

| Repo | Visibility | Distribution | Contains |
|---|---|---|---|
| `bale-connector` | Public | WordPress.org | Free core: bot connection, recipients, logs, CF7 integration |
| `bale-connector-pro` | Private | zhaket.com | Every paid add-on, sold **individually** as separate small plugins |

**Why this split:** WordPress.org requires the free version to be genuinely
complete, not a locked demo — so CF7 integration ships free and unlimited, which
also drives installs/reviews that the whole project depends on. WooCommerce order
notification is the single highest-value feature in this market (every existing
competitor — Balino, Baleyar, Baleban, Woo-Bale — charges specifically for it), so
it anchors the Pro lineup rather than the free core. Each Pro capability is sold as
its **own small add-on plugin** on zhaket (not one bundled "Pro" plugin), matching
how Iranian buyers on that marketplace prefer to purchase — pay only for what you
use. All Pro add-ons declare `Requires Plugins: bale-connector` (WP 6.5+ native
plugin-dependency header) and attach through the extension points documented in
`AGENTS.md` §7.

## Database schema (finalized)

```sql
-- Recipients: the "specific person / specific group" registry (spec item #3)
CREATE TABLE wp_bale_connector_recipients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(255) NOT NULL,
  chat_id VARCHAR(255) NOT NULL,
  type ENUM('user','group') NOT NULL,
  last_tested_at DATETIME NULL,
  last_test_status ENUM('success','failed') NULL,
  created_at DATETIME NOT NULL
);

-- Logs: shared by every trigger (CF7 in core; order/OTP/etc. in Pro)
CREATE TABLE wp_bale_connector_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_type VARCHAR(50) NOT NULL,        -- 'cf7', 'woocommerce_order', 'otp', ...
  source_ref VARCHAR(255) NULL,            -- form ID, order ID, etc.
  recipient_chat_id VARCHAR(255) NOT NULL,
  payload TEXT NOT NULL,                   -- JSON sent
  response TEXT NULL,                      -- JSON received
  status ENUM('success','failed') NOT NULL,
  created_at DATETIME NOT NULL
);

-- Per-form settings (CF7 today; reused by other form builders in Pro)
CREATE TABLE wp_bale_connector_form_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  form_type VARCHAR(50) NOT NULL,          -- 'cf7', 'elementor', 'wpforms', 'gravity'
  form_id VARCHAR(255) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  recipient_ids TEXT NOT NULL,             -- JSON array of recipient IDs
  message_template TEXT NOT NULL,          -- JSON, tag-based
  UNIQUE KEY form_lookup (form_type, form_id)
);
```

Global settings (bot token — libsodium-encrypted, log retention size, etc.) live
in `wp_options` via the Settings API, not a custom table.

---

## PART A — Free core (`bale-connector` repo)

### Phase 1 — `feature/core-infrastructure`
**Goal:** Plugin skeleton that activates cleanly and does nothing visible yet.
- Bootstrap file, header, text domain, activation/deactivation hooks
- `class-bale-installer.php`: dbDelta for the 3 tables above, `uninstall.php`
  respecting a "keep data on uninstall" checkbox
- Global settings storage: bot token field (libsodium encryption at rest)
- Admin menu skeleton (empty screens, correct capability checks)
- **Depends on:** nothing. **Acceptance:** activate → tables exist → deactivate →
  reactivate is idempotent → uninstall respects the cleanup checkbox.

### Phase 2 — `feature/bale-api-client`
**Goal:** A single, reusable, testable class wrapping the Bale Bot API.
- `class-bale-api-client.php`: `getMe()`, `getChat( $chat_id )`,
  `sendMessage()`, `sendPhoto()`, `sendDocument()`
- Centralized error handling: non-`ok` JSON, network failure, timeout — all
  normalized into one exception/error shape the rest of the plugin can log
- Character-limit guards (4096 / 1024) enforced before the HTTP call
- **Depends on:** Phase 1. **Acceptance:** a WP-CLI or admin-only debug call to
  `getMe()` with a real token returns bot info; invalid token returns a clean
  error, not a fatal.

### Phase 3 — `feature/recipient-management`
**Goal:** Spec item #3 — define people/groups once, reuse everywhere.
- Admin UI: add/edit/delete recipients (label, chat_id, type)
- AJAX "Test Connection" button calling `getChat()`, showing bot-membership /
  start-status inline, per spec
- Nonce + capability checks on every AJAX action
- **Depends on:** Phase 2. **Acceptance:** adding a real group/user chat_id and
  clicking Test Connection reflects true success/failure without a page reload.

### Phase 4 — `feature/cf7-integration`
**Goal:** Spec item #1 — Contact Form 7 → Bale, fully free, unlimited forms.
- Hook: `wpcf7_mail_sent` (not `wpcf7_before_send_mail` — see `AGENTS.md` §10)
- Per-form enable/disable toggle in the CF7 form editor or a dedicated screen
- Message template editor: tag-based field mapping (`[your-name]` → template),
  live preview respecting Bale's 3 formatting rules only
- Send via the Phase 2 client to selected Phase 3 recipients; write to
  `wp_bale_connector_logs` with `source_type = 'cf7'`
- **Depends on:** Phases 2–3. **Acceptance:** submitting a real CF7 form on a
  test site delivers a correctly formatted message to the configured recipient
  and appears in the log.

### Phase 5 — `feature/logging-admin-ui-i18n`
**Goal:** Polish the free core into something submission-ready.
- `WP_List_Table` log viewer (filter by source_type, status, date)
- Log auto-cleanup once storage exceeds the size the user sets in Settings
- Settings screen final pass: masked token display, log level toggle
- Generate `.pot`, provide `fa_IR.po` (Sobhan is the primary translator)
- **Depends on:** Phase 4. **Acceptance:** logs page usable with 500+ rows
  (pagination works); switching site locale to fa_IR shows translated strings.

### Phase 6 — `chore/wp-org-submission-prep`
**Goal:** Ship v1.0.0.
- Full manual QA pass across PHP 7.4 and 8.4
- Security self-review against `AGENTS.md` §6 and §9 checklist, item by item
- `readme.txt` per wp.org format: description, FAQ, screenshots placeholders,
  changelog, stable tag, and an honest note that WooCommerce/AI/multi-form
  features are separate paid add-ons (upselling is allowed under guideline 11 as
  long as it doesn't hijack the admin UI)
- Tag `v1.0.0`, submit to the WordPress.org plugin review queue
- **Depends on:** Phase 5.

---

## PART B — Pro add-ons (`bale-connector-pro` repo, new private repo)

Bootstrap this repo only after Phase 6 ships. Structure as a monorepo of
independent add-on plugins so each can be zipped and sold separately on zhaket,
while sharing a small internal helper library.

```
bale-connector-pro/
├── woocommerce-notify/
├── otp-login/
├── multi-form-builders/
├── order-management-bale/
├── ai-two-way-chat/
├── abandoned-cart/
└── shared/            ← internal helpers only, not a standalone plugin
```

Each add-on folder is its own installable plugin with
`Requires Plugins: bale-connector` in its header, and attaches to the core
purely through the hooks in `AGENTS.md` §7 — never by editing core files.

### Phase 7 — `chore/pro-repo-bootstrap`
Repo setup, shared helper library (thin wrapper around the free core's
extension points), per-add-on plugin header templates, shared build/zip script.

### Phase 8 — `feature/pro-woocommerce-notify` (highest priority — anchors Pro)
Spec item #2. `woocommerce_order_status_changed` hook, per-status enable toggle
configured by the site owner, order-detail template with variables (order meta,
customer note, discount %, product variables — matching the sophistication of
the strongest competitor in this space), async dispatch via Action Scheduler,
full HPOS compatibility via `FeaturesUtil::declare_compatibility` (same pattern
as the Pulse SMS plugin).

### Phase 9 — `feature/pro-otp-login`
Bale-delivered OTP codes for WP login and/or WooCommerce checkout. Generate +
send via `sendMessage`, short expiry window, rate-limited requests per user/IP
to prevent abuse, clear fallback if the user hasn't started the bot yet.

### Phase 10 — `feature/pro-multi-form-builders` (user priority #1)
Elementor Forms, WPForms, and Gravity Forms adapters, reusing the exact
template/field-mapping engine built in Phase 4 — each adapter is a thin
translation layer from that builder's submission hook into the shared
send/log pipeline.

### Phase 11 — `feature/pro-order-management-bale` (user priority #2)
Two-way order handling from inside Bale (in the spirit of the "Baleban"-style
competitor): inline keyboard buttons on order-notification messages (e.g. "Mark
Completed", "View Details"), handled via inbound webhook `callback_query`
updates. Requires the webhook secret-path pattern from `AGENTS.md` §5, plus
`update_id` de-duplication.

### Phase 12 — `feature/pro-ai-two-way-chat` (user priority #3)
Inbound webhook for regular messages, a conversation panel inside wp-admin,
and a pluggable LLM client abstraction (OpenAI / DeepSeek / OpenRouter / GapGPT
— one interface, swappable provider), configurable system prompt, per-
conversation opt-in for privacy. Reuses the webhook infrastructure from Phase 11.

### Phase 13 — `feature/pro-abandoned-cart` (user priority #4)
Time-based abandoned-cart detection via Action Scheduler, Bale reminder message
with a recovery link, configurable delay and message template reusing the
Phase 4/10 template engine.

---

## Notes for whoever prompts Hermes each session

- Paste only the relevant phase section above, plus a reminder to read
  `AGENTS.md` first — don't paste the whole roadmap into every session; Hermes
  loads `AGENTS.md` automatically at session start, but this roadmap file should
  be read on demand per phase to keep context lean.
- Before merging any phase, run a dedicated review pass against `AGENTS.md` §9
  regardless of which underlying model wrote the code — this keeps security
  quality consistent even when different models handle different phases.
- If a phase reveals the DB schema above needs to change, update this file's
  schema section in the same PR — don't let the two drift apart.
