# Mailhouse developer guide

## How delivery is intercepted

The interesting decision in this plugin is *where* it takes over `wp_mail()`.

The obvious approach is to short-circuit `pre_wp_mail`, parse the arguments and
build the message yourself. That means re-implementing header parsing, MIME
assembly and attachment handling, and it silently breaks every plugin that
hooks `phpmailer_init` expecting to adjust the message.

Instead, Mailhouse hooks `pre_wp_mail` only to swap the global `$phpmailer` for
a subclass, then returns `null` so WordPress carries on exactly as usual:

```php
// includes/mail/class-mailhouse-mailer.php
$phpmailer = new Mailhouse_PHPMailer( true );
return null;
```

`Mailhouse_PHPMailer` overrides one method. SMTP and PHP mail fall straight
through to PHPMailer. An API transport gets the message *after* `preSend()` has
assembled it:

```php
public function send() {
	$provider = Mailhouse_Mailer::provider();

	if ( ! $provider || ! $provider->is_api() ) {
		return parent::send();
	}

	if ( ! $this->preSend() ) {
		return false;
	}

	return $provider->send( $this );
}
```

Consequences worth knowing:

- `phpmailer_init`, `wp_mail_succeeded` and `wp_mail_failed` all fire normally.
  The log listens to the last two rather than to anything private.
- A transport reports failure by throwing `PHPMailer\PHPMailer\Exception`.
  `wp_mail()` catches it and turns it into `wp_mail_failed`, so the reason
  reaches the log without any special path.
- Sender forcing happens twice, on purpose: `wp_mail_from` / `wp_mail_from_name`
  catch the default sender *before* PHPMailer validates it (a host with no dot
  in its name produces `wordpress@localhost`, which PHPMailer rejects outright),
  and `phpmailer_init` catches the case where a caller set its own From header.

## Layout

```
includes/
├── functions.php                 Helpers: mailhouse_option(), dates, addresses
├── class-mailhouse.php           Module container, cron schedule
├── class-mailhouse-install.php   dbDelta schema, first-run defaults
├── class-mailhouse-settings.php  One option, constant overrides for secrets
├── class-mailhouse-crypto.php    sodium, falling back to OpenSSL
├── class-mailhouse-updater.php   Updates from this repo's releases
├── mail/
│   ├── class-mailhouse-mailer.php     Interception, sender rules, send()
│   ├── class-mailhouse-phpmailer.php  The subclass
│   ├── class-mailhouse-provider.php   Transport base class
│   ├── class-mailhouse-log.php        The log
│   ├── class-mailhouse-webhooks.php   Bounce and complaint callbacks
│   └── providers/                     One file per transport
├── list/
│   ├── class-mailhouse-subscribers.php  CRUD, statuses, privacy tooling
│   ├── class-mailhouse-tags.php         Tags and the pivot table
│   ├── class-mailhouse-forms.php        Shortcode, block, endpoint
│   ├── class-mailhouse-optin.php        Confirm, unsubscribe, preferences
│   └── class-mailhouse-notice.php       The popup shown after an email link
├── campaigns/
│   ├── class-mailhouse-emails.php       The mailhouse_email post type
│   ├── class-mailhouse-queue.php        Batched sending
│   ├── class-mailhouse-automations.php  Confirmation, welcome, new post
│   ├── class-mailhouse-merge-tags.php   {{tag|fallback}}
│   ├── class-mailhouse-template.php     The HTML wrapper
│   ├── class-mailhouse-palette.php      The theme's colours, read from theme.json
│   └── class-mailhouse-tracking.php     Signed pixels and click redirects
└── admin/                               Screens, settings, list tables, tools
```

Classes autoload by name: `Mailhouse_Provider_SendGrid` →
`class-mailhouse-provider-sendgrid.php`, searched across those folders. There
is no build step and no generated map.

## Tables

| Table | Holds |
|---|---|
| `{prefix}mailhouse_subscribers` | One row per address. `manage_key` is the unsubscribe token; `confirm_key` is cleared on confirmation. `revision` counts changes to who the subscriber is and where they stand — see [Watching the list change](#watching-the-list-change). |
| `{prefix}mailhouse_tags` | Tags. |
| `{prefix}mailhouse_subscriber_tags` | Pivot, primary key on both columns. |
| `{prefix}mailhouse_queue` | One row per recipient per campaign. Unique on `(campaign_id, subscriber_id)` — that key is what makes a double-click on Send harmless. Rows stay after sending and carry the open and click counts, and the id the transport gave the message. `claim_token` and `claimed_at` mark a row a batch has taken; `remote_job_id` and `reported` are reserved. |
| `{prefix}mailhouse_log` | One row per `wp_mail()` call. |

Every timestamp is UTC. `mailhouse_date()` converts on the way out.

Schema changes apply themselves on the next request of any kind.
`Mailhouse_Install::maybe_install()` runs on `init` at priority 0, ahead of the
webhook and tracking handlers, and costs one read of an autoloaded option once
the schema is current. It used to run on `admin_init` only, which a background
auto-update never reaches: on a site nobody logs in to, new code would have
run against old tables indefinitely. Two requests arriving together do not
both run it.

## Hooks

### Actions

| Hook | Fired when | Arguments |
|---|---|---|
| `mailhouse_loaded` | Every module is up | `Mailhouse $plugin` |
| `mailhouse_subscriber_added` | A row is created, confirmed or not | `object $subscriber` |
| `mailhouse_subscriber_updated` | An address, name, custom field, status or one of its dates changes | `object $after, object $before, string[] $changed` |
| `mailhouse_subscriber_confirmed` | Somebody joins for real | `object $subscriber` |
| `mailhouse_subscriber_unsubscribed` | Somebody leaves | `object $subscriber, string $source` |
| `mailhouse_subscriber_bounced` | An address is retired as undeliverable | `object $subscriber, string $reason, string $kind` — `hard`, or `soft_limit` for a mailbox that was full once too often |
| `mailhouse_subscriber_resubscribed` | Somebody who unsubscribed comes back from their preferences page | `object $subscriber, string $by` |
| `mailhouse_subscriber_tags_changed` | The set of tags on a subscriber changes | `int $subscriber_id, int[] $added, int[] $removed` |
| `mailhouse_tag_saved` | A tag is created or renamed | `object $tag` |
| `mailhouse_tag_deleted` | A tag is deleted | `object $tag, int[] $holders` |
| `mailhouse_webhook_event` | Any provider event, matched or not | `array $event, ?object $subscriber` |
| `mailhouse_subscribers_deleting` | Rows are about to be deleted | `array<int,object> $rows`, keyed by id; `string $reason` — `admin`, or `erasure` from the privacy eraser |
| `mailhouse_subscribers_deleted` | Rows are deleted | `int[] $ids` |
| `mailhouse_campaign_queued` | A campaign is queued | `int $campaign_id, int $queued` |
| `mailhouse_campaign_finished` | Its last message goes out | `int $campaign_id, array $stats` |
| `mailhouse_phpmailer` | Just before send | `PHPMailer $mail, ?Mailhouse_Provider $provider` |

### Filters

| Hook | Changes | Arguments |
|---|---|---|
| `mailhouse_capability` | Who may manage the plugin | `string $cap` |
| `mailhouse_default_settings` | Default settings | `array $defaults` |
| `mailhouse_option` | One setting on read | `mixed $value, string $key` |
| `mailhouse_settings_tabs` | The settings screen definition | `array $tabs` |
| `mailhouse_providers` | Available transports | `string[] $classes` |
| `mailhouse_log_row` | A log row before writing; return false to skip | `array $row` |
| `mailhouse_merge_tag_values` | Merge tag values for one recipient | `array $values, array $context` |
| `mailhouse_email_html` | The finished HTML email | `string $html, array $args` |
| `mailhouse_campaign_headers` | Headers on bulk mail | `string[] $headers, object $subscriber` |
| `mailhouse_public_tags` | Tags offered on the preferences page | `object[] $tags` |
| `mailhouse_confirm_redirect` | Where confirmation sends people | `string $url, object $subscriber` |
| `mailhouse_theme_colours` | Colours read from the theme | `array $colours` |
| `mailhouse_brand_palette` | The front end's colours, plus `is_dark` | `array $palette` |
| `mailhouse_notices` | The messages the popup can show | `array $messages` |
| `mailhouse_notice_destination` | Where an email link lands | `string $url, string $key` |
| `mailhouse_webhook_events` | Normalised events parsed from a callback | `array $events, string $provider, array $payload` |
| `mailhouse_webhook_tolerance` | Clock skew allowed on a signed callback | `int $seconds` |
| `mailhouse_update_repo` | Repository updates are read from | `string $repo` |

## Watching the list change

Until 1.6.0 about half the ways a subscriber could change fired nothing: an
administrator editing an address, somebody correcting their own name, any
change of tags. Every write now goes through one of a few functions, and each
of those says what it did.

**`Mailhouse_Subscribers::update()`** compares what it is asked to write with
what is stored. When one of the columns in `Mailhouse_Subscribers::SYNCED`
actually differs — `email`, `first_name`, `last_name`, `fields`, `status`,
`source`, `created_at`, `confirmed_at`, `unsubscribed_at` — it adds one to the
row's `revision` and fires `mailhouse_subscriber_updated` with the row after,
the row before, and the names of the columns that changed:

```php
add_action(
	'mailhouse_subscriber_updated',
	function ( $after, $before, $changed ) {
		if ( in_array( 'email', $changed, true ) ) {
			// $before->email became $after->email.
		}
	},
	10,
	3
);
```

It does **not** fire for the `sent`, `opens`, `clicks` and `bounces` counters
or for the keys, and not for a write that sets a column to the value it
already had. `sent` moves on every campaign; a listener told about each of
those would hear nothing else. Custom fields are compared as data, so the same
fields in a different order are not a change.

`confirm()`, `unsubscribe()` and `mark_bounced()` all write through
`update()`, so each fires `mailhouse_subscriber_updated` first and its own
hook after.

**`revision`** is the cheap way to ask whether two copies of a row are the
same. It moves on the changes above and on a change to the subscriber's tags,
and on nothing else.

**Tags.** `attach()`, `detach()` and `set()` work out what really changed and
fire `mailhouse_subscriber_tags_changed` once, with the ids added and the ids
removed. Attaching a tag somebody already has is not a change and fires
nothing. `set()` writes only the difference — it used to remove every tag and
add them back, which reset each `added_at` and could leave somebody with no
tags if it stopped half-way. Deleting a tag fires `mailhouse_tag_deleted` once
with everybody who held it, not once per holder.

**Deleting.** `mailhouse_subscribers_deleting` fires before the rows go,
carrying them. They are keyed by subscriber id rather than listed, and not
only for convenience: `do_action()` unwraps a one-element list that holds an
object, so a plain list would arrive as a bare row whenever exactly one
subscriber was deleted.

**Changing a status.** Two functions, for the two cases where it is not the
subscriber's own click doing it:

```php
// An administrator's decision. Leaving the list goes through unsubscribe()
// or mark_bounced(), so the date is recorded, queued mail is dropped and the
// usual hooks fire. Joining it does not fire `mailhouse_subscriber_confirmed`,
// so a correction made in the admin sends nobody a welcome email.
Mailhouse_Subscribers::set_status( $subscriber, 'unsubscribed', 'admin' );

// The owner of the address, from their own preferences page. The only thing
// that may undo an opt-out. A bounced address cannot bring itself back.
Mailhouse_Subscribers::resubscribe( $subscriber, 'owner' );
```

The edit form under **Subscribers** uses the first. Before 1.6.0 it wrote the
status column directly, so an administrator unsubscribing somebody there set
no date, cleared nothing from the queue and told nobody.

## Adding a transport

Extend `Mailhouse_Provider` and register the class:

```php
class Acme_Mailer extends Mailhouse_Provider {

	public function id() {
		return 'acme';
	}

	public function label() {
		return 'Acme Mail';
	}

	public function fields() {
		return array(
			'acme_key' => array(
				'label'    => 'API key',
				'type'     => 'password',
				'required' => true,
			),
		);
	}

	public function send( $mail ) {
		$response = $this->request(
			'https://api.acme.test/send',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'from'    => $mail->From,
						'to'      => wp_list_pluck( $this->to( $mail ), 'email' ),
						'subject' => $mail->Subject,
						'html'    => $this->html( $mail ),
						'text'    => $this->text( $mail ),
					)
				),
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			$this->fail_response( $response );  // throws
		}

		return true;
	}
}

add_filter( 'mailhouse_providers', function ( $classes ) {
	$classes[] = 'Acme_Mailer';
	return $classes;
} );
```

The base class gives you `to()`, `cc()`, `bcc()`, `reply_to()`, `html()`,
`text()`, `attachments()` and `custom_headers()`, all normalised out of the
PHPMailer object, plus `request()` and `fail_response()`. A field named in
`fields()` is rendered, sanitised and — for `password` fields — encrypted
automatically, and picks up `MAILHOUSE_ACME_KEY` from `wp-config.php` if it is
defined.

Two things are worth adding once it sends. Neither is required: a transport
with neither is treated as accepting on `true` and as *ambiguous* when it
throws.

```php
// What the provider called the message. `request()` keeps its last answer,
// and `response_value()` / `response_header()` read from it.
public function message_id() {
	return $this->response_value( array( 'id' ) );
}
```

Failures are sorted for you when they go through `request()` and `fail()` or
`fail_response()`: see [What a send tells you](#what-a-send-tells-you).

## Bounce callbacks

`?mailhouse_webhook=<provider>&k=<secret>` — a query-string endpoint rather
than a pretty URL, so it needs no rewrite rules and cannot break on a site with
plain permalinks or one whose rules were never flushed. The secret may also
travel as an `X-Mailhouse-Key` header, which keeps it out of access logs.

Everything arriving there is untrusted input from the public internet, so two
things guard it:

1. **The URL secret**, checked with `hash_equals()`. Every provider can carry
   it.
2. **A signature over the body**, where the provider offers one — HMAC-SHA256
   for Mailgun (over its own timestamp and token) and Resend (Svix: `id`,
   timestamp and body), ECDSA-SHA256 for SendGrid. Signed callbacks are also
   checked against a 15-minute clock skew, so a captured callback cannot be
   replayed for ever.

Verification is skipped when no key is configured for that provider, because
Brevo and Postmark have no signing scheme at all and refusing them would mean
no bounce handling for those two. Where a key *is* set, an unsigned or badly
signed callback is refused outright.

Each provider's payload is normalised to a list of
`array( 'email', 'type', 'reason', 'message_id', 'event_id', 'occurred_at' )`,
where `type` is one of `hard`, `soft`, `complaint`, `unsubscribe` or
`delivered`. The last three say which message and which notification this
is, where the provider says: `message_id` is the id the send call returned,
`event_id` is the provider's own id for the callback, and `occurred_at` is a
Unix time. They are empty or zero when the provider does not supply them.
Nothing acts on them yet — they are carried so that a bounce can be matched
to the message that bounced. Anything else — opens, clicks,
deferrals, processed notices — is dropped at the parser, because acting on it
either duplicates our own tracking or punishes an address for a condition the
provider is still retrying.

`Mailhouse_Webhooks::process( $provider, $body )` does the verifying, parsing
and applying and returns counts or a `WP_Error`; `handle()` is only the request
plumbing around it. That split is what makes the parsers testable without a
network, and it is how one of the transports without a parser yet — SES,
Mailjet, SparkPost, SMTP2GO, Elastic Email, MailerSend, ZeptoMail, Mailchimp
Transactional — can be bolted on: point its callback at any provider id and
rewrite the events with `mailhouse_webhook_events`.

Repeats are no-ops. Providers retry callbacks they could not deliver, and some
send the same event twice regardless, so each branch stops when the subscriber
is already in the state the event describes — otherwise a fortnight of retries
inflates the bounce counter and fills the log with copies of one bounce.

`active_providers()` reports the transports actually in use, across both the
site and campaign streams, which is what the settings screen and the status
report are built from.

## Theme colours

`Mailhouse_Palette::detect()` returns the email colours the active theme
implies, as setting keys to hex. It is used in two places: the "Use my theme's
colours" button, and `seed_defaults()` on a fresh install. Never on upgrade --
changing the look of a site's email behind its back is worse than a mismatch it
has already accepted.

Two things make the result trustworthy rather than merely plausible:

- **Only what the theme declares.** `wp_theme_has_theme_json()` gates the whole
  thing, and only the `theme` and `custom` palette tiers are read. WordPress
  ships a default palette of its own, and presenting that as the theme's would
  be a lie.
- **Core's own styles are filtered out.** `wp_get_global_styles()` merges the
  theme with core's theme.json, which declares a literal button background of
  `#32373c` and nothing for page background, text or links. So a literal is
  trusted for those three, while the button is accepted only when it resolves
  through the theme's own palette -- otherwise every site would get core's grey
  wearing the theme's name. There is a test for exactly that.

Values arrive as a literal hex, as `var:preset|color|slug`, or as the CSS
custom property that form compiles to; all three are resolved against the
palette. Named colours and `rgb()` are left unfilled rather than guessed at.

`mailhouse_shade_colour( $hex, $amount )` moves a colour toward white or black
depending on its own lightness, which is what separates the message card from
the page on a dark brand. `mailhouse_contrast_colour( $hex )` picks black or
white text for a background.

## The post-click popup

`Mailhouse_Notice` prints a message into `wp_footer` when the request carries
`?mailhouse_notice=<key>`, where the key names one of a fixed server-side set.
An unrecognised key prints nothing at all, so the argument cannot be used to
put words on the site.

It is a real element, not something a script builds: mail clients open links in
all sorts of browsers, and a confirmation nobody can see because a script did
not run is worse than a plain page. The stylesheet and script load only on a
request that shows one. JavaScript adds closing in place, Escape, and clearing
the query argument with `replaceState` so a refresh does not replay it.

Colours come from the email design -- surface, ink, brand -- passed as custom
properties on the element, so the popup matches the site rather than following
the reader's dark-mode preference into a white card on a dark site.

## One palette for the front end

`mailhouse_brand_palette()` is where the emails, the popup and the standalone
pages get their colours. It reads `template_accent`, `template_card_bg` and
`template_ink`, falls back on any that is not a usable hex, and derives the
rest:

| Slot | What it is |
|---|---|
| `accent`, `surface`, `ink` | Straight from the design |
| `on_accent` | Black or white, whichever reads on the accent |
| `muted` | The ink, softened, for secondary text |
| `edge` | The surface, shifted, for borders that have to show against it |
| `field` | The surface, barely shifted, for inputs and notices |
| `is_dark` | Whether the design is dark |

It exists because the three surfaces each used to work their colours out
separately, and the standalone pages lost. They were written when there was
only a brand colour to read, took `template_accent` and hardcoded white for
everything else, and never caught up when the message background and text
colours arrived in 1.3.0 -- so a dark design produced a dark email, a dark
popup and a white preferences page. One function decides now.

`is_dark` is in there because the right answer to `prefers-color-scheme`
depends on the design. A site that has chosen a dark design should stay dark
whatever the reader's browser asks for -- that is the rule the emails already
followed, and the popup's reason for ignoring the preference outright. A site
still on the light default keeps following the reader, which is what the
standalone pages have always done. Callers need to know which they are looking
at, so `Mailhouse_Optin::page_html()` emits the dark-mode block only for a
light design, and sets `color-scheme` to match either way.

## Templates

Both are overridable from the active theme:

| File | Replaces |
|---|---|
| `mailhouse/email.php` | The HTML wrapper around every campaign |
| `mailhouse/message.php` | The confirm / unsubscribe / preferences page |

In `email.php` you get `$content` and `$args` (subject, preview, subscriber,
unsubscribe_url, preferences_url, footer, address, accent, logo). In
`message.php` you get `$title` and `$body`.

## Sending programmatically

```php
Mailhouse_Mailer::send(
	'reader@example.com',
	'Subject',
	'<p>Body</p>',
	array(
		'campaign_id'   => 0,   // tags the log entry
		'subscriber_id' => 0,
		'reply_to'      => 'replies@example.com',
	)
);
```

Adding somebody to the list:

```php
$result = Mailhouse_Subscribers::add(
	array(
		'email'       => 'reader@example.com',
		'first_name'  => 'Ada',
		'tags'        => 'weekly, product',   // created if missing
		'source'      => 'checkout',
		'send_emails' => true,                // false when importing in bulk
	)
);
// array( 'id', 'status', 'action', 'is_new' ) or WP_Error
```

`add()` never creates a second row for an address that is already there, and
never quietly reactivates somebody who unsubscribed — they go back through
confirmation.

### What a send tells you

`Mailhouse_Mailer::send()` returns `true` when the transport took the message
and `false` otherwise, as it always has. `false` covers two very different
things, though: *the server said no*, and *the connection dropped and nobody
knows*. The first is safe to try again. The second may already be in
somebody's inbox.

```php
$sent   = Mailhouse_Mailer::send( $to, $subject, $html );
$result = Mailhouse_Mailer::last_result();

// array(
//     'outcome'    => 'accepted' | 'rejected' | 'ambiguous',
//     'permanent'  => bool,     // rejected only: trying again will not help
//     'message_id' => string,   // accepted only: the transport's id, if it gave one
//     'error'      => string,
// )
```

The rule every transport follows: a failure is a **rejection** only when it
can be shown the message never left. Everything else is **ambiguous**.

| What happened | Outcome |
|---|---|
| SMTP `250` after the body; a `2xx` from an API | `accepted` |
| The message could not be built — no valid recipient, say | `rejected`, permanent |
| API `4xx`; a refusal inside a `200`; SMTP `5xx` | `rejected`, permanent |
| API `408`, `425`, `429`, `503`; SMTP `4xx`; could not connect, resolve or authenticate | `rejected`, worth retrying |
| Any other API `5xx`; a timeout or reset after the request was written; the line going dead after an SMTP body was sent | `ambiguous` |

The queue records `message_id` on the row it sent (`provider_message_id`).
What it does with a failure has not changed: it retries, up to three times.

`'no_fallback' => true` keeps the backup transport out of one message. Sending
again through a second route after an unclear failure on the first is how one
person gets two copies; pass it wherever that matters more than getting the
message out.

### How the queue takes its work

`Mailhouse_Queue::claim( $limit )` takes rows with one `UPDATE` that marks
them `sending` and stamps them with a token, then reads back the rows carrying
that token. The database guarantees two runs arriving together come away with
different rows. Before 1.6.0 the rows were read and nothing was marked; only a
transient stood between two runs and the same batch, and a transient is a
check followed by a write with a gap between them.

It also means a row says `sending` *before* anything is handed to a transport.
A run that dies part-way leaves rows that say so; `reap_stale()` returns them
to `pending` after ten minutes, which is what became of them before claims
existed. Rows a batch claimed and did not reach inside its time budget are
handed straight back by `release()`.

The transient is still there. It keeps the pace to one batch a minute, which
is what "emails per minute" in the settings promises; it is no longer what
stops a message going twice.

## Updates

Mailhouse is not on the wordpress.org directory, so it answers for its own
updates. The whole mechanism is one header and one filter.

The header matters more than it looks:

```
 * Update URI: https://github.com/estuty/mailhouse
```

Without it, WordPress asks wordpress.org about every plugin whose folder name
it recognises — and a plugin installed from a zip has no way of saying where it
came from. Anybody publishing a plugin under the slug `mailhouse` would have
had it offered as an update to this one, and installing that would have
overwritten it. Declaring the header both stops WordPress asking wordpress.org
and routes the question to `update_plugins_github.com` instead, the filter
added in WordPress 5.8 and keyed on the header's host.

That key is a host, not a plugin, so **every** plugin on the site that updates
from github.com arrives at the same filter. `Mailhouse_Updater::offer()`
therefore checks the basename it was asked about before answering — returning
our release for somebody else's plugin would have WordPress install Mailhouse
straight over the top of it.

Two things are deliberate:

- **The version comparison is left to WordPress.** `offer()` reports the latest
  release whether or not it is newer; core files it under `response` or
  `no_update` itself. A filter that stayed quiet when the site was current
  would also lose the "View details" link.
- **The release asset is required, and the source archive is never used.**
  GitHub's `zipball_url` unpacks to a folder named after the repository and the
  commit. WordPress installs a plugin into whatever folder the zip contains, so
  that would land beside the existing copy as a second plugin rather than
  replacing it. A release with no `mailhouse-<version>.zip` on it offers
  nothing, which is the better failure.

The answer is cached in a site transient for 12 hours, and a failed lookup is
cached for 15 minutes so a renamed repository or a rate limit is not re-asked
on every row of the Plugins screen. `Mailhouse_Updater::status()` reads that
cache without ever making a request, which is what Tools → Status shows.

Point it elsewhere with `MAILHOUSE_UPDATE_REPO` in `wp-config.php` or the
`mailhouse_update_repo` filter. The repository has to be public: GitHub answers
404, not 403, for a private one, so the symptom is simply no updates.

## Cutting a release

`.github/workflows/release.yml` does the packaging, because the asset is
load-bearing and forgetting it is silent — a release with no zip on it offers
nothing to anybody, and looks exactly like a release that reached everybody.

Push a `v*` tag and it builds `mailhouse-<version>.zip` and attaches it. If no
release exists yet it opens a **draft** with the zip already on it, which keeps
the release out of `releases/latest` until the notes are written; publishing it
fires the workflow again, which finds the asset and checks it against the tag
rather than replacing it. `workflow_dispatch` re-runs it for any existing tag,
and its `force` input re-uploads.

It refuses to package a few things:

| Refused | Why |
| --- | --- |
| A tag that is not `vMAJOR.MINOR.PATCH` | the tag is the version every site compares against |
| The tag, the `Version:` header, `MAILHOUSE_VERSION` and readme.txt's stable tag disagreeing | a tag ahead of the header is the expensive one: core installs the update, reads the old version back out of the header, and offers the same update again, for ever |
| A missing or non-github.com `Update URI` | without it the question goes to wordpress.org |
| A PHP file that does not parse | cheap to check on the way past |
| A zip whose root is not a single `mailhouse/` holding that version | WordPress installs a plugin into whatever folder the zip contains |

Two settings are pinned on the archive, and neither is decoration:

```bash
git -c core.autocrlf=false -c core.eol=lf archive --format=zip -6 --prefix=mailhouse/ "$TAG"
```

`git archive` applies line-ending conversion, and Git for Windows ships
`core.autocrlf=true` in its **system** config — so the same tag archived from a
fresh Windows clone comes out CRLF and 1.6KB larger than from a clone with it
turned off. `-6` is git's default compression, pinned for the same reason. With
all three fixed the tag decides the bytes: rebuilding `v1.4.0` this way
reproduces the published asset's sha256 exactly.

That reproducibility is what the verify path leans on. Where the asset is
already attached, the workflow downloads it and diffs
`.github/zip-manifest.php` output — every entry's name, CRC and size — instead
of hashing the file, because `git archive` stamps the commit date as each
entry's mtime and the commit sha as the archive comment. Two archives of
identical trees therefore differ in bytes while their contents do not.

The tag is checked out under `src/`, separately from the workflow's own
checkout, so the helpers are always the ones from the ref that started the run
rather than whatever happened to be in the tree being packaged — otherwise it
could only ever build tags new enough to contain it.

## Tests

Eight scripts under `tests/`, run against a real WordPress rather than mocks —
the things worth checking here are `dbDelta` actually creating the tables, a
rewrite rule actually matching, and PHPMailer actually assembling a message,
all of which a mock would get wrong.

```bash
php tests/test-core.php      # activation, settings, list, queue, transports
php tests/test-admin.php     # every admin screen, and the form endpoint
php tests/test-import.php    # CSV, Noptin and WP Mail SMTP importers
php tests/test-features.php  # transports, design, digests
php tests/test-webhooks.php  # bounce parsing and signature verification
php tests/test-branding.php  # theme colours, the design, the popup
php tests/test-updates.php   # the update check, start to finish
php tests/test-foundations.php  # change hooks, revisions, the queue claim, send results
```

`test-foundations.php` includes a race: two PHP processes started on the same
tick, each claiming rows seven at a time out of five hundred until none are
left. It asserts no row was claimed twice and none was missed. Five rounds by
default; `MAILHOUSE_CLAIM_ROUNDS=50` for a longer look. The same race run
against a read-then-mark claim loses rows to both processes within a round or
two, which is how the test is known to be able to fail.

`test-updates.php` stands in for GitHub with `pre_http_request` rather than
calling it, so the shapes worth worrying about — a release with no zip, a
renamed repository, a rate limit — can each be put in front of the code on
demand. What is real is WordPress: it runs `wp_update_plugins()` and reads the
transient core wrote, because the question being asked is whether core accepts
what the filter hands it.

`tests/bootstrap.php` walks up from the plugin folder looking for
`wp-load.php`; set `MAILHOUSE_WP_ROOT` to point it somewhere else. Each script
cleans up after itself and exits non-zero on failure, so they drop into CI as
they are.

They activate the plugin and write real rows. Run them on a development site,
not a live one.

`test-admin.php` fails on any PHP notice or warning raised inside the plugin
while a screen renders, which is the cheapest way to catch the class of bug
that only shows up on a screen nobody opened during development.

## Things deliberately not done

- **No IMAP bounce polling.** Bounces come from provider callbacks, which means
  an SMTP-only site gets none. Reading a bounce mailbox over IMAP would cover
  that case, and parsing arbitrary DSNs reliably is a project of its own.
- **No callback parsers for every transport yet.** Five of them report bounces:
  SendGrid, Mailgun, Brevo, Postmark and Resend. The rest send a different
  shape, and each needs its own parser.
- **No A/B testing, no automation trees.** Three automations cover what most
  sites need; the rest is a different product.
- **No block editor for emails.** Block markup carries layout CSS that mail
  clients discard, and several blocks render as nothing in Outlook.
- **No REST API.** The queue is internal, and exposing a subscriber list over
  REST is a liability rather than a feature.
