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
├── mail/
│   ├── class-mailhouse-mailer.php     Interception, sender rules, send()
│   ├── class-mailhouse-phpmailer.php  The subclass
│   ├── class-mailhouse-provider.php   Transport base class
│   ├── class-mailhouse-log.php        The log
│   └── providers/                     One file per transport
├── list/
│   ├── class-mailhouse-subscribers.php  CRUD, statuses, privacy tooling
│   ├── class-mailhouse-tags.php         Tags and the pivot table
│   ├── class-mailhouse-forms.php        Shortcode, block, endpoint
│   └── class-mailhouse-optin.php        Confirm, unsubscribe, preferences
├── campaigns/
│   ├── class-mailhouse-emails.php       The mailhouse_email post type
│   ├── class-mailhouse-queue.php        Batched sending
│   ├── class-mailhouse-automations.php  Confirmation, welcome, new post
│   ├── class-mailhouse-merge-tags.php   {{tag|fallback}}
│   ├── class-mailhouse-template.php     The HTML wrapper
│   └── class-mailhouse-tracking.php     Signed pixels and click redirects
└── admin/                               Screens, settings, list tables, tools
```

Classes autoload by name: `Mailhouse_Provider_SendGrid` →
`class-mailhouse-provider-sendgrid.php`, searched across those folders. There
is no build step and no generated map.

## Tables

| Table | Holds |
|---|---|
| `{prefix}mailhouse_subscribers` | One row per address. `manage_key` is the unsubscribe token; `confirm_key` is cleared on confirmation. |
| `{prefix}mailhouse_tags` | Tags. |
| `{prefix}mailhouse_subscriber_tags` | Pivot, primary key on both columns. |
| `{prefix}mailhouse_queue` | One row per recipient per campaign. Unique on `(campaign_id, subscriber_id)` — that key is what makes a double-click on Send harmless. Rows stay after sending and carry the open and click counts. |
| `{prefix}mailhouse_log` | One row per `wp_mail()` call. |

Every timestamp is UTC. `mailhouse_date()` converts on the way out.

## Hooks

### Actions

| Hook | Fired when | Arguments |
|---|---|---|
| `mailhouse_loaded` | Every module is up | `Mailhouse $plugin` |
| `mailhouse_subscriber_added` | A row is created, confirmed or not | `object $subscriber` |
| `mailhouse_subscriber_confirmed` | Somebody joins for real | `object $subscriber` |
| `mailhouse_subscriber_unsubscribed` | Somebody leaves | `object $subscriber` |
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

## Tests

Three scripts under `tests/`, run against a real WordPress rather than mocks —
the things worth checking here are `dbDelta` actually creating the tables, a
rewrite rule actually matching, and PHPMailer actually assembling a message,
all of which a mock would get wrong.

```bash
php tests/test-core.php     # activation, settings, list, queue, transports
php tests/test-admin.php    # every admin screen, and the form endpoint
php tests/test-import.php   # CSV, Noptin and WP Mail SMTP importers
```

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

- **No bounce handling.** Doing it properly needs provider webhooks or IMAP
  polling. The `bounced` status exists in the schema for it, and nothing sets it
  yet. Marking somebody bounced by hand works.
- **No A/B testing, no automation trees.** Three automations cover what most
  sites need; the rest is a different product.
- **No block editor for emails.** Block markup carries layout CSS that mail
  clients discard, and several blocks render as nothing in Outlook.
- **No REST API.** The queue is internal, and exposing a subscriber list over
  REST is a liability rather than a feature.
