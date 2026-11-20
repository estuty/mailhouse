# Mailhouse

One WordPress plugin to manage your entire email workflow from start to finish.

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
- **Your site's colours, read from the theme.** Any block theme's `theme.json`
  palette becomes the email palette in one click, dark brands included. Nothing
  generic is ever passed off as yours.
- **New post notifications.** One email per post or a daily/weekly digest,
  sent automatically or held for review.
- **Sender control.** Force the From address and name, set a default Reply-To,
  send bounces back to a mailbox somebody reads.
- **Email log.** Every message, its result, and the transport's own error text
  when it fails. Searchable, with retention, and resend for anything whose body
  was kept.
- **Bounce and complaint callbacks.** SendGrid, Mailgun, Brevo, Postmark and
  Resend report back; a hard bounce retires the address by itself, a spam
  complaint unsubscribes, soft bounces are counted and forgiven on the next
  confirmed delivery. Authenticated by a URL secret plus a body signature where
  the provider offers one.
- **Failure alerts.** One email an hour, at most, when delivery is failing.
- **Encrypted credentials**, or define them as constants in `wp-config.php` and
  keep them out of the database entirely.
- **Test send** that reports what the transport actually said.
- **Updates like any other plugin's**, reported from this repository's releases
  rather than from wordpress.org: a new version shows on the Plugins screen
  with its notes and installs in one click.

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

Download `mailhouse-<version>.zip` from
[Releases](https://github.com/estuty/mailhouse/releases) — not the source
archive, which unpacks to a folder WordPress would treat as a different plugin.

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

## Mailhouse Central (optional, in development)

A site can be connected to a [Mailhouse Central](https://github.com/estuty/mailhouse-central)
installation, so that several sites' newsletters can be seen from one
dashboard. It is off unless you turn it on, under **Settings → Central**:
paste the centre's address and the single-use token its dashboard gives
you.

- A site that is not connected does nothing new: no scheduled work, no
  requests, no extra database query.
- A connected site makes its own key pair. The private half is encrypted in
  the database and never leaves the site, or can be supplied as
  `MAILHOUSE_CENTRAL_PRIVATE_KEY` in `wp-config.php`.
- It reports in once a minute: versions, counts, and which mail transport
  is in use. Never a host name or a credential.
- What changes on the list — a sign-up, a confirmation, an unsubscribe, a
  bounce, a change of name, address or tags, a deletion — is written to an
  outbox table as it happens and sent to the centre on the next scheduled
  run, after the site's own mail has gone. A visitor's request never waits
  on the centre. What is sent is the subscriber's address, name, custom
  fields, status, dates, source, sign-up IP address and tags; never their
  `manage_key` or `confirm_key`, their WordPress user id, or anything about
  how the site sends mail.
- One scheduled run at a time talks to the centre. WordPress does not
  always stop two from running together (its own guard lapses after a
  minute, and a server's cron and a visitor can each start one); a run
  that finds another at work leaves the centre to it and carries on with
  the site's own mail. **Report in now** waits a few seconds for a run
  that is in the middle, and says so if it has not finished.
- If the centre cannot be reached the changes wait, and are sent in order
  when it can. After thirty days, or a hundred thousand of them, they are
  dropped and the two copies are compared instead; no subscriber is touched
  by that.
- When a site connects, the list it already has is copied to the centre a
  few hundred subscribers at a time, on the scheduled runs. Afterwards the
  two are compared once a day, when the centre asks, or when you press
  **Compare with the centre**: the site sends each subscriber’s number,
  revision and a hash, and then the full details of only those the centre
  holds differently. A comparison never changes anything on the site.
- If the site’s database is ever restored from a backup, the centre notices
  and takes the restored list as it is. It does not put anybody back.
- The site keeps working by itself whether or not the centre can be reached.
- A copy of a connected site at another address (a staging clone) notices,
  and sends nothing.
- `define( 'MAILHOUSE_CENTRAL_DISABLED', true );` in `wp-config.php` stops
  the connector entirely, whatever the settings say.

**Changes asked for by the centre.** Once an administrator of the centre
has allowed it for this website, the centre can ask this site to change a
subscriber's name, custom fields or tags; to unsubscribe, mark as bounced
or remove somebody; to add somebody; to send its confirmation message again
to somebody who has not confirmed; and to make, rename or delete a tag.

- Each request is carried out by the same function a click in this site's
  own admin would reach, under the same rules, on a scheduled run, before
  the mail queue.
- **Nothing the centre can ask makes anybody subscribed.** There is no
  such request. Somebody who has unsubscribed or bounced is not added back
  and is not sent a message because the centre asked; somebody added from
  the centre is sent this site's confirmation message and is pending until
  they follow the link, unless the centre's operator states that they had
  already agreed.
- A request is carried out only if it is signed by the centre this site
  registered with, was made for this website, comes after everything
  already dealt with, and has not expired. Each is carried out at most
  once, however many times it arrives. A change to a name or fields is
  refused if the subscriber has changed here since the centre last saw
  them.
- **A request to forget somebody goes further than removing them.** Their
  address is also taken out of the records of what this site sent them: a
  message still waiting to go is dropped, the send records stay for the
  campaign's counts and no longer say whose they were, and the mail log's
  entries for them are deleted. Once the centre has been told it is done,
  the note of that in the outbox no longer says who they were. (Removing
  somebody from the centre, and
  WordPress's own "Erase Personal Data" on this site, take them off the
  list and leave those records as they are.)
- What was asked and what became of it is kept in an inbox table: an id, a
  kind and an outcome, nothing about any subscriber. **Settings → Central**
  says how many requests have been carried out, refused or failed, and
  shows the last one that could not be trusted.
- `define( 'MAILHOUSE_CENTRAL_READ_ONLY', true );` in `wp-config.php` makes
  this site refuse every request. It goes on reporting and sending its own
  changes, and nothing here can be changed from the centre.

**The site's email template.** A newsletter written at the centre is a
body; it is this site that wraps it in its header, footer and colours. So
that the centre can show a campaign as this site would send it, a connected
site sends the centre its template once: the wrapper alone, with a marked
place for the body, the subject, the preview text and the two links that
are each subscriber's own. Nothing about any subscriber is in it.

- It is sent after connecting, and again when the design changes: the
  plugin's settings, the site's name or its theme. Otherwise the site looks
  once an hour whether what it would send is still what the centre has.
- With it go the site's name and colours, and who its newsletters are
  from: the address and name a reader would see, as the mailer would have
  them. That is the newsletters' own sender where one is set and is an
  address; the site's own where it is not, or where the site is set to
  send everything as one address. A change of sender is sent to the centre
  like a change of design. The centre goes by it when it warns that a
  central route would send a website's mail as somebody else.
- The centre keeps it only as something to look at: anything that could
  run is taken out there, and a template with no place for a body or no
  unsubscribe link is refused. **Settings → Central** says when the
  template was last sent, or why the centre would not keep it.
- A centre from before this answers that it has no such address. Nothing
  is wrong, and the site asks again a day later.

**Newsletters written at the centre.** A newsletter can be written in the
centre's dashboard and sent by this site: in this site's template, through
this site's mail transport, at this site's own pace, to this site's own
subscribers. A site is handed one only once an administrator of the centre
has allowed requests to this website, and only if this version of the
plugin is installed.

- What arrives is a signed request, checked like any other, carrying the
  subject and the body and the hash that names them. The body is cleaned
  again here, by WordPress's own filter and a list of what an email may
  contain. Nothing in it is run: a shortcode or a block in it arrives as
  the text it was written as.
- Who it goes to is fetched from the centre as this site's own subscriber
  numbers, never addresses. When they are all here they are counted and
  hashed, and nothing is sent unless both agree with what the signed
  request said. Somebody the centre lists and this site does not hold is
  not guessed at.
- It starts at the time the centre set, by this site's own clock, whether
  or not the centre can be reached at that moment, and stops at its
  deadline (three days later): a site that was away for a week does not
  come back and send last week's newsletter.
- **This site's rules still decide who is written to.** At the moment each
  message goes, the subscriber must be subscribed here. Somebody who
  unsubscribes after the centre made its list is skipped. Every message
  carries this site's unsubscribe link and headers, and its plain-text
  part has every link written out.
- **A message that may have gone is never sent again.** If the transport
  does not say whether it took a message, or the process sending it dies
  after handing it over, the message is held as *uncertain*. The backup
  transport is not used for these messages. Your own campaigns behave
  exactly as before: an unclear result is retried, and the backup takes
  over.
- It appears among **Mailhouse → Emails**, labelled *From Mailhouse
  Central*, with its progress. It cannot be edited, re-sent or given other
  recipients here. **You can pause it, resume it or call it off**; what was
  not sent is kept as a record, and the centre is told.
- **The centre is told what became of each message**: that your transport
  took it, refused it (and what it said), or did not say; that it was not
  sent because its subscriber had left, or because the newsletter was
  called off; and, if this site records opens and clicks, each reader's
  first. It is told by the centre's own number for the message. No address
  is sent, and any address in what a transport said is taken out first.
- **And what your provider says afterwards, where it says anything.** If
  your newsletters leave through a provider that calls this site back
  (SendGrid, Mailgun, Brevo, Postmark or Resend, with this site's address
  from **Settings → Bounces and complaints** given to it), each report is
  matched to the message it is about, by the name the provider gave that
  message when it took it, and the centre is told: delivered, bounced (and
  whether for good), or marked as spam. What this site does with a report
  is unchanged: a bounce still retires the address and a complaint still
  unsubscribes, connected or not. Only messages of the centre's
  newsletters are written to; your own campaigns' are left as they were.
  Through SMTP or PHP mail there is nothing to hear, and the centre shows
  "not available" for delivery and not a nought. **Settings → Central**
  says which of the two this site is.
- **The centre can hold it, let it go and call it off too.** Whoever holds
  it lets it go: the centre cannot lift a hold you put on, and you cannot
  lift the centre's. Either of you can call it off, and then nothing more
  of it is sent. Nothing the centre can ask makes this site send to
  anybody else, sooner, or after the deadline.
- **A message that may have gone is tried again only if somebody at the
  centre decides it should be**, knowing that its reader may then get it
  twice; and never after the deadline, or once the newsletter is called
  off.
- **A test.** The centre can ask this site to send a draft to up to five
  addresses its operator names, so that they see it as your subscribers
  would: in your template, through your transport. Nothing is created
  here by a test, and none of your subscribers is written to. This site
  counts them itself: sixty addresses an hour and no more, whatever the
  centre asks.
- A copy of this site at another address (a staging copy, say) sends none
  of it. Disconnecting from the centre calls off whatever of the centre's
  has not been sent. If the centre cuts this site off (an operator there
  revokes its key), this site holds what it has of the centre's and sends
  none of it, and carries on if it is connected again before the
  newsletter's deadline.
- **Your readers' own links are carried nowhere.** A newsletter from the
  centre may use `{{unsubscribe_url}}` or `{{preferences_url}}` as a
  link's own address. It may not put one inside another address, or in a
  picture's: that would hand each reader's key to whoever ran the server
  at the other end. The centre refuses such a newsletter when it is
  written, and this site, which does not take the centre's word for it,
  removes such an address when a newsletter arrives.
- **What a provider says of one of the centre's messages is taken only
  from the provider your newsletters leave by**, where that is a provider
  that calls back. A call in another provider's name says nothing about
  them. Where they leave through a mail server, the server may be any
  provider's relay, and whichever provider calls is believed, as before.

## Technical summary

- WordPress 6.0+, PHP 7.4+ (tested on 8.3), MySQL 5.7+ / MariaDB 10.3+
- **No build step.** No npm, no Composer, no bundler — what is in `assets/` is
  what the browser gets, and the block is plain `createElement`.
- **No external requests** except to the mail provider you configured.
- Eight tables, one option. Nothing in `wp_postmeta` except the emails' own
  settings.
- Delivery works by substituting a `PHPMailer` subclass, so everything
  WordPress and other plugins do to a message still happens — `phpmailer_init`,
  `wp_mail_succeeded` and `wp_mail_failed` all fire as normal.

## How it was verified

Against a real WordPress install (WordPress 7.1, PHP 8.3.33, MariaDB 11.8), not
mocks.

| Check | Result |
|---|---|
| PHP lint, 78 files, tests included | pass |
| `tests/test-core.php` — 85 assertions | pass |
| `tests/test-admin.php` — 31 assertions | pass |
| `tests/test-import.php` — 40 assertions | pass |
| `tests/test-features.php` — 80 assertions | pass |
| `tests/test-webhooks.php` — 53 assertions | pass |
| `tests/test-branding.php` — 89 assertions | pass |
| `tests/test-updates.php` — 65 assertions | pass |
| `tests/test-foundations.php` — 159 assertions | pass |
| `tests/test-central.php` — 169 assertions | pass |
| `tests/test-central-sync.php` — 145 assertions | pass |
| `tests/test-central-reconcile.php` — 65 assertions | pass |
| `tests/test-central-commands.php` — 138 assertions, one of them over 4,000 random steps | pass |
| `tests/test-central-campaigns.php` — 345 assertions | pass |
| Two processes claiming from one queue, 50 rounds of 500 rows | no row claimed twice, none missed |
| A real update installed from a published release | same folder, stays active |
| The release zip built by CI, rebuilt from its tag | byte-identical on Windows and Ubuntu |
| Unsubscribe and preferences pages under a dark, a light and a pale design | brand colours throughout, button text readable on all three |
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

A fourth, found later by reading a delivered message on the central
application's test rig: the plain-text part of every email had no links in
it, the unsubscribe link among them. The converter wrote each as
`text <address>` and then removed it with the tags. Links are now set aside
before the tags go and put back afterwards.

Two more, found by reading two providers' documentation again when their
reports began to be matched to messages. Postmark names the reader of a
*delivery* `Recipient`, where a bounce has `Email`: no delivery through
Postmark was ever read, so a soft-bounce count there was never cleared by
one. And Resend calls a bounce that may pass `Temporary`; only
`transient` was known, so a full mailbox retired its address. Both are
read now. A third is known and **not changed**: Postmark's
`SubscriptionChange` names its reader `Recipient` too, so a suppression
at Postmark does not unsubscribe anybody here.

And one found by the central application's integrity run: an hour of
sign-ups, edits, campaigns and faults at random on two sites, and then a
count. Everything a site knows about its connection is one option, and a
request wrote the whole of it back as it remembered it. Two scheduled
runs at once therefore each undid what the other had written, including
where the site's numbering of its changes stood; the centre was then sent
numbers it already held for other changes, and took the site for one
restored from a backup. Nobody's subscription was wrong at the end of it,
but the centre compared the whole list again to be sure. A change to the
connection now changes only its own fields, read and written under a lock
of the database's, and one run at a time works the connection.

## Licence

GPL-2.0-or-later.
