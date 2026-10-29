# Mailhouse admin guide

Everything here is done from the WordPress admin. No code.

---

## 1. Make email leave the site

**Mailhouse → Settings → Delivery.**

WordPress out of the box hands mail to PHP's `mail()`. Nothing about that
message is authenticated, so Gmail, Outlook and everyone else treat it as
suspicious. This is the one setting that matters most.

### Who it comes from

| Field | What to put |
|---|---|
| From address | An address at **your own domain**. Not Gmail, not Yahoo — you cannot prove you are allowed to send as them, and it will be rejected. |
| From name | Whatever a reader should see. Usually the site name. |
| Force on every email | On, unless a shop or form plugin needs to send as somebody else. |
| Default Reply-To | Where replies should land, if the From address is nobody's mailbox. |
| Send bounces back to the From address | Leave on. Otherwise bounces go to the web server, where nobody reads them. |

### The transport

Pick one and fill in its fields:

- **SMTP server** — works with Google Workspace, Microsoft 365, Fastmail, your
  host, or a transactional service that also offers SMTP. Port 587 with
  STARTTLS is the usual answer; 465 means SSL/TLS. **Quick setup** fills in
  the server for Gmail, Microsoft 365, Outlook.com, Zoho, Yahoo, iCloud,
  Amazon SES and Hostinger.
- **SendGrid / Mailgun / Brevo / Postmark / Resend / Amazon SES / Mailjet /
  SparkPost / SMTP2GO / Elastic Email / MailerSend / Zoho ZeptoMail /
  Mailchimp Transactional (Mandrill)** — create an API key with
  send permission only, and verify your sending domain in their dashboard
  first. The API is generally faster and more informative when something goes
  wrong than SMTP is.
- **PHP mail** — the default. Change it.

Under **Backup and newsletter transport** you can also pick a second
transport that takes over when the main one refuses a message, and a separate
transport for newsletters, so a spam complaint about bulk mail cannot drag
down password resets and receipts.

Then go to **Tools → Send a test email**. It tells you what the transport said,
not just whether it worked.

> **Keeping credentials out of the database.** Anything you type in a password
> field is encrypted before it is stored. If the database is backed up
> somewhere you do not fully control, put the secret in `wp-config.php` instead:
>
> ```php
> define( 'MAILHOUSE_SMTP_PASS', 'the-password' );
> ```
>
> The field then says so and is ignored. The other constants are
> `MAILHOUSE_SENDGRID_KEY`, `MAILHOUSE_MAILGUN_KEY`, `MAILHOUSE_BREVO_KEY`,
> `MAILHOUSE_POSTMARK_TOKEN`, `MAILHOUSE_RESEND_KEY`, `MAILHOUSE_SES_SECRET_KEY`,
> `MAILHOUSE_MAILJET_SECRET`, `MAILHOUSE_SPARKPOST_KEY`, `MAILHOUSE_SMTP2GO_KEY`,
> `MAILHOUSE_ELASTICEMAIL_KEY`, `MAILHOUSE_MAILERSEND_KEY`,
> `MAILHOUSE_ZEPTOMAIL_TOKEN` and `MAILHOUSE_MANDRILL_KEY`.

### Email design

**Settings → Email design** sets the look of every email: logo, header
colour, brand and button colours, footer text, postal address and social
links. With **Use this design for all email the site sends** on (the default),
WordPress's own messages and other plugins' simple emails get the same header
and footer. Emails that arrive as a complete designed page, such as most shop
receipts, are left alone. **Open a preview** shows the saved design;
**Send me a test** mails it to you.

### New post notifications

Under **Emails** there is a **New post notification**, created switched off.
Publish it to turn notifications on. In its settings you choose:

- which post types and categories trigger it;
- one email per post, or a daily or weekly digest at a set hour;
- whether it sends automatically, or is saved as a draft and you get an
  email asking you to review and send it.

The notification goes out two minutes after a post is published, so a typo
fixed straight away is fixed in the email too. Password-protected posts are
never sent, and authors can tick **Don't email subscribers about this post**
in the editor sidebar. Use `{{post_card}}` for a ready-made card with the
image, title, excerpt and a **Read more** button, and `{{post_list}}` in a
digest.

### Making it actually arrive

The plugin cannot do this part for you. At your DNS host, for the domain you
send from:

1. **SPF** — one TXT record listing who may send as you. Your provider gives
   you the exact value.
2. **DKIM** — the provider gives you a record; it signs your mail.
3. **DMARC** — a TXT record at `_dmarc.yourdomain.com`. Start with
   `v=DMARC1; p=none; rua=mailto:you@yourdomain.com` and tighten it later.

Without these, good delivery is luck.

---

## 2. Watch what happens

**Mailhouse → Email log.**

Every message and its outcome. When somebody says "I never got the reset
email", this is where the answer is: sent, or failed with the reason the
transport gave.

- **Delivered** means the transport accepted it, not that it reached an inbox.
  A message can still bounce or be filtered afterwards.
- **Failed** shows the error. "Could not instantiate mail function" means PHP
  `mail()` is not working — set up a real transport. "550 …" or "Invalid API
  key" comes straight from the provider.

Under **Settings → Log & alerts** you can keep entries for a chosen number of
days, and be emailed when delivery starts failing (at most once an hour, so a
broken transport cannot flood you).

**Save message content** is off deliberately. With it on you can resend from
the log, but password reset links and order details then sit in the database
until the retention window passes. Turn it on while debugging; turn it off
after.

---

### Bounces and complaints

A bounce does not happen while you are sending. It arrives at your provider
minutes or days later, and they are the only one who knows. So they have to
tell you — and then Mailhouse can retire dead addresses without anybody
reading a mailbox.

Sending to addresses that no longer exist is the fastest way to ruin a
domain's reputation, so this is worth ten minutes of setup.

**Settings → Log & alerts → Bounces and complaints.** Copy the endpoint shown
for your transport and paste it into the provider:

| Provider | Where | Events to send |
|---|---|---|
| SendGrid | Settings → Mail Settings → Event Webhook | Bounced, Dropped, Spam Reports, Unsubscribed, Delivered |
| Mailgun | Sending → Webhooks | Permanent Failure, Temporary Failure, Complained, Unsubscribed, Delivered |
| Brevo | Transactional → Settings → Webhooks | Hard bounce, Soft bounce, Blocked, Marked as spam, Unsubscribed, Delivered |
| Postmark | Servers → your server → Webhooks | Bounce, Spam Complaint, Subscription Change, Delivery |
| Resend | Webhooks → Add | `email.bounced`, `email.complained`, `email.delivered` |

Send the delivered event too if the provider offers it. It is what clears a
soft-bounce count, so a mailbox that was briefly full does not creep towards
being retired.

If campaigns go out through their own transport, you will see **two**
endpoints. Set both up: a bounce reported to one provider says nothing about
the other, and the newsletter stream is the one bounces matter most on.

The other transports — SES, Mailjet, SparkPost, SMTP2GO, Elastic Email,
MailerSend, ZeptoMail, Mailchimp Transactional — can report bounces too, but
Mailhouse does not parse their callback formats yet. SMTP and PHP mail have no
callbacks at all; that is one of the better arguments for using a mail API.

**Then set the signing key.** Mailgun, Resend and SendGrid can sign what they
send, and the fields are right below the endpoint. Without a signature, the
only thing protecting the endpoint is the secret in the URL — and anyone who
learns that URL could mark your subscribers as bounced, which is a quiet way of
cutting people off your list. Set the key where one exists.

If a URL ever leaks, **Replace the key**. Every endpoint already saved in a
provider dashboard stops working until you paste the new one in, so do it in
that order.

### What each event does

| What happened | What Mailhouse does |
|---|---|
| Hard bounce — the address does not exist | Status becomes **Bounced**. Anything queued for them is dropped. |
| Soft bounce — mailbox full, server busy | Counted, nothing else. At three (configurable) the address is retired. A confirmed delivery resets the count to zero. |
| Spam complaint | **Unsubscribed** immediately, whatever their status was. Non-negotiable: continuing after a complaint is what gets a domain blocked outright. |
| Unsubscribed at the provider | **Unsubscribed** here too. |
| Opens, clicks, deferrals | Ignored. Deferrals are temporary and the provider is still retrying; opens and clicks are tracked here already, if you turned that on. |

All of it appears in the email log with the provider's own wording, so "why did
this person stop receiving the newsletter" has an answer you can read. Bounced
and Spam complaint views appear in the log as soon as there is something in
them.

A bounce for somebody who was never a subscriber — a receipt, a password reset
— is logged too, but there is no list membership to change.

## 3. Collect subscribers

### A form

Paste this into any page, post or widget:

```
[mailhouse_form]
```

Or add the **Newsletter form** block. Both produce the same form.

Useful attributes:

```
[mailhouse_form title="Stay in touch" description="One email a month." name="first" tags="weekly" layout="inline"]
```

| Attribute | Values |
|---|---|
| `title`, `description`, `button` | Any text |
| `name` | `none`, `first`, `full` |
| `tags` | Comma separated. Tags that do not exist yet are created. |
| `layout` | `stacked` or `inline` |
| `redirect` | A URL to send people to after signing up |
| `consent` | `yes` or `no` |

Defaults for all of these live under **Settings → Signups**, so you usually
only need the bare shortcode.

You can also offer signup on the comment form and the registration form, under
the same tab.

### What happens when somebody signs up

1. They are stored as **pending**.
2. They get the confirmation email. Nothing else is sent until they click.
3. Clicking makes them **subscribed**, and the welcome email goes out.

This is double opt-in, and it is on by default. It is what stops a bot — or
somebody typing a stranger's address — from putting anyone on your list, and
it is most of the difference between a list that reaches inboxes and one that
does not.

The form answers with the same message whether the address was new, already
subscribed, or a bot tripping the honeypot. That is deliberate: any difference
would let a stranger use your form to test who is on your list.

### Tags

**Mailhouse → Tags.** A flat set of labels. A subscriber can carry any number,
and a broadcast can be aimed at one of them instead of everybody. Simpler than
separate lists, and nobody ends up stored three times.

---

## 4. Send something

**Mailhouse → Emails → Add email.**

- The **title** is internal. The **subject line** is what readers see.
- **Preview text** is the grey line the inbox shows after the subject. Fill it
  in; left empty, mail clients pick their own, usually badly.
- Write the body in the editor. Keep it simple — email clients are not
  browsers.
- **Merge tags** go anywhere in the subject or body. `{{first_name|there}}`
  uses "there" when you do not know their name. The full list is in the sidebar
  while you edit.

**Type** decides what it is:

| Type | Behaviour |
|---|---|
| Broadcast | A one-off. You press Send. |
| New post notification | Sent automatically whenever a post is published. |
| Welcome email | Sent once, when somebody joins. |
| Confirmation email | The double opt-in email. Must contain `{{confirmation_url}}`. |

The automations are on when the email is **published** and off when it is a
draft. Editing their wording is just editing the post.

### Sending a broadcast

1. Publish the email. Publishing does **not** send it.
2. In the **Sending** panel, choose who it goes to.
3. **Send test** to yourself first. Always.
4. **Send now**, or set a date and time first.

It goes out in batches — 20 a minute by default, adjustable under **Settings →
Newsletter → Sending**. Keep it under whatever your host or provider allows.
While it runs you can pause, resume or cancel; anyone who unsubscribes
part-way through will not receive it.

Afterwards the email's row shows how many were sent, and opens and clicks if
tracking is on.

> **Scheduled sends need cron.** WordPress runs scheduled work when somebody
> visits the site. On a quiet site that can be late. If your host offers a real
> cron job, point it at `wp-cron.php` every minute. The Tools screen tells you
> whether cron is working.

---

## 5. Tracking, and whether to

**Settings → Newsletter → Tracking.** Both off by default.

- **Opens** works by putting an invisible image in the email that reports back
  when it loads. Apple Mail loads it whether or not anybody read anything, so
  treat the number as a rough signal.
- **Clicks** works by routing every link through your site first.

Both are real privacy costs. If you turn them on, say so in your privacy
policy. Unsubscribe links are never tracked — they have to work regardless.

---

## 6. Importing and exporting

**Mailhouse → Tools.**

- **From another plugin.** Noptin and WP Mail SMTP are detected automatically.
  Nothing is deleted from them; import, check the result, then deactivate them.
- **From a CSV.** The first row must be a header with a column called `email`.
  `first_name`, `last_name`, `status` and `tags` are used if present. Existing
  addresses are updated, never duplicated.
- **Export** gives you everything, including tags and engagement counts.

Only import addresses that asked to hear from you. A bought list is how a
sending domain gets blocked, and it is not undoable.

---

## When something is wrong

| Symptom | Look at |
|---|---|
| Nothing arrives at all | Tools → Send a test. The error names the cause. |
| Mail arrives but goes to spam | SPF, DKIM and DMARC on your sending domain. |
| A campaign is stuck at "Sending" | Tools → Status: is cron running? |
| The confirmation email never comes | Email log. It will be there, sent or failed. |
| Two plugins fighting over delivery | The admin warns you by name. Deactivate one. |
| Somebody stopped receiving the newsletter | Subscribers: are they Bounced? The log says why. |
| Bounces are not being recorded | Open the endpoint URL in a browser. It should answer `{"ok":true}`; anything else means the key is wrong. |

**Tools → Status** has a summary worth copying into any support request.
