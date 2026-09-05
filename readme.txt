=== Bale Connector ===
Contributors: sobhan
Tags: bale, messenger, contact form 7, cf7, notification, iran, forms
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send a Bale messenger notification to any person, group, or channel the moment a Contact Form 7 form is submitted — with unlimited forms, full delivery logs, and encrypted bot token storage.

== Description ==

Bale Connector bridges your WordPress site to the Bale messenger Bot API. Configure your bot token once, add the recipients who should be notified (a person, a group, or a channel), and every Contact Form 7 submission is delivered straight to Bale — reliably, in the background, and with a complete delivery log.

What's included in this plugin (free, unlimited):

* Contact Form 7 integration — connect unlimited CF7 forms to Bale. Each form gets its own recipients and its own message template.
* Bot token setup & verification — save your bot token, and the plugin verifies it against the Bale Bot API (getMe) before you rely on it.
* Recipient management — add unlimited recipients: private users, groups, and channels. A built-in connection test (getChat plus a bot-membership check for groups/channels) tells you whether the bot can actually reach each target.
* Message templates — pull any form field into the message with [tag] placeholders, optional per-tag length limits like [your-name:100], and Bale's three formatting constructs (bold, italic, links).
* Delivery log — every send attempt is logged with status (success/failed), payload, and the API response. Filter by source, status, and date; delete single entries, in bulk, or all at once.
* Background sending — all notifications are queued through Action Scheduler, so a slow or failing Bale API call never blocks the page load for your visitors. Failed sends are retried automatically with exponential backoff (honoring Bale's retry_after when present).
* Security first — your bot token is encrypted at rest with libsodium (never stored or shown in plaintext), every admin action is nonce-protected and capability-gated, and visitor-submitted field values are neutralized so a form submitter can never inject links or formatting into your notifications.

Not included — available as separate paid add-ons:

The following features are not part of this plugin. They are developed and sold as separate add-ons at zhaket.com:

* WooCommerce order notifications
* OTP login (one-time password over Bale)
* Multi-form-builder support (Elementor forms, WPForms, Gravity Forms)
* Two-way AI chat and inbound message webhook
* Abandoned-cart reminders

This free plugin is complete on its own — nothing in it is locked, crippled, or time-limited.

Privacy note: raw Contact Form 7 field data temporarily persists in Action Scheduler's own database tables (as queued job arguments, subject to Action Scheduler's default retention period) in addition to this plugin's delivery log.

== Installation ==

1. Install the plugin through the WordPress plugins screen (Plugins > Add New > Upload Plugin) or unzip it into /wp-content/plugins/bale-connector.
2. Activate the plugin through the 'Plugins' screen.
3. Create your bot in Bale (via @BotFather) and copy the bot token.
4. Go to Bale Connector > Settings, paste the token, and save. The token is verified against the Bale API and stored encrypted.
5. Go to Bale Connector > Recipients and add the person, group, or channel that should receive notifications. Use the Test button to confirm the bot can reach each target (for groups and channels, the bot must be a member first).
6. Open any Contact Form 7 form in its editor, switch to the Bale Notification tab, enable it, pick recipients, and customize the message template.
7. Submit a test entry and check Bale Connector > Logs for the delivery status.

== Frequently Asked Questions ==

= Where do I get a bot token? =

Create a bot with @BotFather inside Bale and use the /newbot command (or ask it for your existing bot's token). Paste the token into Bale Connector > Settings.

= Why does my group or channel test fail? =

The most common reason: the bot has not been added to that group or channel. Bale Connector verifies bot membership explicitly (getChatMember) for group and channel recipients — add the bot as a member (for channels, as an admin that can post), then test again.

= Can I use it with more than one form? =

Yes. Every Contact Form 7 form can have its own recipients and message template, with no limit on the number of forms. Configure each form in its editor under the Bale Notification tab.

= Can a visitor inject links or formatting into my notification? =

No. Visitor-submitted field values are always rendered as plain text — the six characters that trigger Bale's formatting parser are neutralized at render time. Only your admin-authored template can use bold, italic, or links.

= Where do WooCommerce order notifications, OTP login, or other features come from? =

Those are separate paid add-ons developed and sold at zhaket.com — they are not part of this plugin. This plugin fully covers Contact Form 7 to Bale notifications, and nothing in it requires a purchase to work.

= Is my bot token stored safely? =

Yes. The token is encrypted at rest with libsodium using a dedicated 32-byte encryption key (OpenSSL AES-256-CBC fallback), never written to logs, and displayed in the admin only as a masked value with the last four characters visible.

= What happens if the Bale API is temporarily down? =

Failed sends are retried automatically with exponential backoff (up to 3 retries), and Bale's retry_after rate-limit hint is honored. Every attempt appears in the delivery log, so nothing is silently lost.

== Screenshots ==

1. Settings screen — bot token (masked) with save-and-verify.
2. Recipients screen — add and test person/group/channel recipients.
3. Contact Form 7 editor — the Bale Notification panel with template editor and character counter.
4. Delivery logs — filterable list of every send attempt.
5. A sample notification arriving in Bale.

== Changelog ==

= 1.0.0 =
* Initial release.
* Contact Form 7 to Bale notifications with unlimited forms.
* Recipient management (person, group, channel) with connection testing.
* Encrypted bot token storage and delivery logging with retention control.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
