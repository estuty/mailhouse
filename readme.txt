=== Mailhouse ===
Contributors: mailhouse
Tags: smtp, newsletter, email log, mailing list, email
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable email delivery over SMTP or a mail API, with a searchable log -- plus a
self-hosted newsletter with opt-in forms, broadcasts and automations.

== Description ==

Mailhouse covers the whole path an email takes: writing it, and getting it off
the server. Those two jobs are usually split across a newsletter plugin and an
SMTP plugin, and the seam between them is where sites quietly break.

**Delivery**

* Send through SMTP, SendGrid, Mailgun, Brevo, Postmark, Resend, Amazon SES, Mailjet, SparkPost, SMTP2GO, Elastic Email, MailerSend, Zoho ZeptoMail or Mailchimp Transactional, with an optional backup transport.
* One branded design (logo, colours, footer, social links) for every email the site sends.
* New post notifications, one per post or as a daily or weekly digest.
* Force the From address and name so every plugin on the site sends as you.
* A log of every message, with the transport's own error text when one fails.
* Resend a failed message, or get an email when delivery starts failing.
* Credentials encrypted at rest, or defined as constants in wp-config.php.
* A test send that reports exactly what came back.
* Bounce and complaint callbacks, so dead addresses retire themselves.

**Newsletter**

* A self-hosted list with double opt-in, tags and custom fields.
* Signup forms as a shortcode or a block. They work without JavaScript.
* Broadcasts written in the post editor and sent from a queue in batches.
* Automatic confirmation, welcome and new-post emails.
* One-click unsubscribe (RFC 8058) and a preferences page for subscribers.
* Open and click tracking, off by default and clearly labelled.
* CSV import and export, and importers for Noptin and WP Mail SMTP.

**Privacy**

Nothing is sent anywhere except through the mail transport you configure. No
analytics, no upsell notices, no phoning home. Tracking is opt-in. Message
bodies are not logged unless you ask for it, so password resets and receipts do
not sit in the database.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Mailhouse -> Settings -> Delivery and choose a transport.
3. Go to Mailhouse -> Tools and send a test email.
4. Add `[mailhouse_form]` to a page, or use the Newsletter form block.

== Frequently Asked Questions ==

= Can it replace both my newsletter plugin and my SMTP plugin? =

That is what it is for. The Tools screen detects Noptin and WP Mail SMTP and
imports the subscribers and the delivery settings. Deactivate the old plugins
afterwards -- two plugins reconfiguring PHPMailer is a coin toss over which one
wins.

= Will it send 10,000 emails? =

It will, at the rate you set under Newsletter -> Sending, a batch a minute, from
a queue that survives a timeout. Whether they arrive is down to the transport
and your domain's SPF, DKIM and DMARC records; no plugin can substitute for
those.

= Where are my SMTP credentials stored? =

Encrypted in the options table, using the site's own salts. For anything
shared or backed up, define MAILHOUSE_SMTP_PASS (or the matching constant for
your provider) in wp-config.php instead, and leave the field empty.

= Does deleting the plugin delete my list? =

No. That is off by default and has to be switched on under Settings ->
Advanced before uninstalling.

== Screenshots ==

1. The dashboard: subscribers, delivery, the last week of sending, and the queue.
2. Delivery settings, with the fields for the selected transport.
3. The email log, and one message in full.
4. Writing a broadcast, with recipients and merge tags alongside.

== Changelog ==

= 1.2.0 =
* Bounce and complaint handling. SendGrid, Mailgun, Brevo, Postmark and Resend
  can now report back, and a hard bounce retires the address by itself. Find
  the endpoint under Settings -> Log & alerts.
* Soft bounces are counted rather than acted on, and the count clears when the
  provider confirms a delivery. The threshold is configurable; zero never
  retires an address on soft bounces alone.
* A spam complaint always unsubscribes, from any status.
* Callbacks are authenticated by a secret in the URL, and by a signature over
  the body where the provider offers one: HMAC for Mailgun and Resend, ECDSA
  for SendGrid. Signed callbacks are also checked for replay.
* Where campaigns use their own transport, both endpoints are offered -- a
  bounce reported to one provider says nothing about the other.
* Bounces, complaints and provider-side unsubscribes appear in the email log
  with the provider's own wording.
* The log now says "Accepted" rather than "Delivered" where it means the
  transport took the message, which is not the same thing.

= 1.1.0 =
* New: Email design tab. Logo from the Media Library, header, brand, button and background colours, social links, dark-mode support, a preview and a test send. Can be applied to all email the site sends.
* New: eight API transports -- Amazon SES, Mailjet, SparkPost, SMTP2GO, Elastic Email, MailerSend, Zoho ZeptoMail, Mailchimp Transactional -- and SMTP quick setup for Gmail, Microsoft 365, Outlook.com, Zoho, Yahoo, iCloud, Amazon SES and Hostinger.
* New: backup transport, and a separate transport for newsletters.
* New: post notifications can filter by category, send daily or weekly digests, wait for review, and be skipped per post. They go out two minutes after publishing. A ready-made {{post_card}} merge tag.
* Security: merge tag values are escaped in email HTML.
* Security: a public signup can no longer change an existing subscriber's name or fields, or resubscribe somebody who unsubscribed. They get a confirmation link instead. CSV imports and migrations no longer resubscribe people either.
* Security: the signup form's redirect only follows http(s) addresses on this site.
* Security: CSV exports neutralise spreadsheet formulas.
* Security: X-Forwarded-For is read from the right when proxy headers are trusted, and a signup with no IP is refused rather than exempt from the limit.
* Security: comment-form signups respect the spam check and the signup limit.
* Security: confirmation emails are sent just after the request, so response time no longer reveals who is subscribed.
* Security: password-protected posts are never emailed; the test-email preview only accepts Mailhouse emails.
* Security: separate encryption and authentication keys on the OpenSSL path (old values still decrypt), and a warning when the encryption key lives in the database.
* Security: subscriber keys are removed from logged headers, IPs from debug messages; weak migrated tokens are replaced; uninstall deletes exactly its own data.
* Settings: every select and transport value is checked against its allowed options on save.

= 1.0.0 =
* First release.
