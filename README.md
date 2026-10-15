# Mailhouse

One WordPress plugin for the whole mail path — replaces **Noptin** and **WP Mail
SMTP** together.

Those two plugins solve halves of the same problem. The newsletter plugin
writes the email; the SMTP plugin gets it off the server. Split across two
vendors, the seam is where sites break: the newsletter is fine, the transport
was reconfigured, and nobody finds out until a customer says they never got the
password reset.

---

## What it does

```
mailhouse/
├── mailhouse.php              Bootstrap: constants, autoloader, activation
├── includes/
│   ├── mail/                  Delivery: PHPMailer substitution, transports, log
│   ├── list/                  Subscribers, tags, forms, opt-in pages
│   ├── campaigns/             Emails, queue, automations, merge tags, tracking
│   └── admin/                 Screens, settings, list tables, tools
├── assets/                    One stylesheet and one script per side
├── templates/                 Overridable email and page templates
└── docs/                      Admin, developer and migration guides
```

### Delivery — replacing WP Mail SMTP

- **Fifteen transports.** PHP mail, SMTP (with quick setup for the common
  mailbox providers), SendGrid, Mailgun, Brevo, Postmark, Resend, Amazon SES,
  Mailjet, SparkPost, SMTP2GO, Elastic Email, MailerSend, Zoho ZeptoMail and
  Mailchimp Transactional. A backup transport, and a separate one for
  newsletters if you want it.
- **One design for every email.** Logo header, branded footer, social links,
  dark-mode aware -- applied to WordPress's own mail as well as campaigns.
- **New post notifications.** One email per post or a daily/weekly digest,
  sent automatically or held for review.
- **Sender control.** Force the From address and name, set a default Reply-To,
  send bounces back to a mailbox somebody reads.
- **Email log.** Every message, its result, and the transport's own error text
  when it fails. Searchable, with retention, and resend for anything whose body
  was kept.
- **Failure alerts.** One email an hour, at most, when delivery is failing.
- **Encrypted credentials**, or define them as constants in `wp-config.php` and
  keep them out of the database entirely.
- **Test send** that reports what the transport actually said.

### Newsletter — replacing Noptin

- **Self-hosted list** with double opt-in, tags, custom fields, CSV import and
  export.
- **Forms** as a shortcode or a block, working without JavaScript, with a
  honeypot, a speed trap and a per-IP rate limit.
- **Broadcasts** written in the post editor, sent from a queue in batches so a
  big list cannot time out mid-send.
- **Automations**: confirmation, welcome, and new post notification.
- **One-click unsubscribe** (RFC 8058) and a preferences page.
- **Optional** open and click tracking — off by default.
- **Merge tags** with fallbacks: `{{first_name|there}}`.

## Getting started

1. Upload the plugin and activate it.
2. **Mailhouse → Settings → Delivery**: pick a transport and fill it in.
3. **Tools**: send a test email.
4. Paste `[mailhouse_form]` into a page, or add the *Newsletter form* block.
5. **Mailhouse → Emails**: write one, then Send.

Moving in from Noptin or WP Mail SMTP? See
[`docs/MIGRATING.md`](docs/MIGRATING.md) — the Tools screen finds both and
imports them.

| Guide | For |
|---|---|
| [ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md) | Running it day to day — no coding |
| [DEVELOPER-GUIDE.md](docs/DEVELOPER-GUIDE.md) | Structure, hooks, templates |
| [MIGRATING.md](docs/MIGRATING.md) | Coming from Noptin or WP Mail SMTP |

## Technical summary

- WordPress 6.0+, PHP 7.4+ (tested on 8.3), MySQL 5.7+ / MariaDB 10.3+
- **No build step.** No npm, no Composer, no bundler — what is in `assets/` is
  what the browser gets, and the block is plain `createElement`.
- **No external requests** except to the mail provider you configured.
- Five tables, one option. Nothing in `wp_postmeta` except the emails' own
  settings.
- Delivery works by substituting a `PHPMailer` subclass, so everything
  WordPress and other plugins do to a message still happens — `phpmailer_init`,
  `wp_mail_succeeded` and `wp_mail_failed` all fire as normal.

## How it was verified

Against a real WordPress install (WordPress 7.1, PHP 8.3.33, MariaDB 11.8), not
mocks.

| Check | Result |
|---|---|
| PHP lint, 35 files | pass |
| `tests/test-core.php` — 78 assertions | pass |
| `tests/test-admin.php` — 29 assertions | pass |
| `tests/test-import.php` — 40 assertions | pass |
| Every admin screen and settings tab rendered | no notices or warnings |
| Activation from cold: tables, defaults, rewrites | correct |
| Double opt-in: confirm, replay, unsubscribe | 200 / 410 / 200 |
| One-click unsubscribe (`POST`, RFC 8058) | 200, subscriber removed |
| Form: honeypot, speed trap, forged signature, missing consent | all rejected; identical response for honeypot and success |
| Full form POST through `admin-post.php` and via `fetch` | redirect and JSON both correct |
| Campaign queue: enqueue, double-enqueue, send, stats | no duplicates, stats correct |
| Unsubscribing mid-campaign | queued message skipped, not sent |
| Tracking: signed open pixel, signed click redirect, forged signature | 200 gif / 302 / refused |
| Credentials in the database | encrypted, marked, round-trip |
| API transport routing through the PHPMailer subclass | from, reply-to, recipients, body all correct |
| Delivery failure | logged with the transport's own message |
| CSV import: aliased headers, mixed case, bad rows, re-run | 3 imported, 1 skipped, no duplicates |
| Noptin import: `active`, `confirmed`, names, dates | statuses mapped correctly, nobody emailed |
| WP Mail SMTP import: sender, mailer, SMTP, API keys | imported, password encrypted |
| Capabilities: subscriber and editor against the email post type | both refused |

Three bugs found this way and fixed: `wp_mail()` rejected the sender before
`phpmailer_init` could correct it on hosts whose name has no dot; a second
signup regenerated the confirmation key while the throttle declined to send a
replacement, leaving a dead link in somebody's inbox; and the tracking pixel
declared a `Content-Length` one byte too long.

## Licence

GPL-2.0-or-later.
