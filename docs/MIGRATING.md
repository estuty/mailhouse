# Moving in from Noptin and WP Mail SMTP

Both are detected automatically. Nothing is deleted from either — you import,
check the result, and only then deactivate them.

Do it in this order. Delivery first: importing a list before mail can leave the
site means a queue full of confirmations that cannot be sent.

---

## 1. Delivery, from WP Mail SMTP

**Mailhouse → Tools → Move in from another plugin → WP Mail SMTP → Import.**

What comes across:

| WP Mail SMTP | Mailhouse |
|---|---|
| From address, From name, and their "force" switches | The same |
| Return path | Send bounces back to the From address |
| Mailer: SMTP, SendGrid, Mailgun, Sendinblue, Postmark, PHP mail | The matching transport (Sendinblue is Brevo) |
| SMTP host, port, encryption, auth, username, password | The same |
| API keys, Mailgun domain and region, Postmark stream | The same |

What does not:

- **Amazon SES, Gmail/Google Workspace OAuth, Outlook OAuth, Zoho, SMTP.com,
  Sendlayer.** Mailhouse has no OAuth transports. For Google Workspace or
  Microsoft 365, use **SMTP** with an app password — it is simpler and it does
  not break when a token expires. For SES, use its SMTP interface.
- **Credentials that live in `wp-config.php`.** Nothing there is copied, because
  nothing there is in the database. Add the Mailhouse constants instead:

  ```php
  define( 'MAILHOUSE_SMTP_PASS', '...' );
  define( 'MAILHOUSE_SENDGRID_KEY', '...' );
  define( 'MAILHOUSE_MAILGUN_KEY', '...' );
  define( 'MAILHOUSE_BREVO_KEY', '...' );
  define( 'MAILHOUSE_POSTMARK_TOKEN', '...' );
  define( 'MAILHOUSE_RESEND_KEY', '...' );
  ```

- **The old email log.** Two schemas, different shapes. Keep WP Mail SMTP
  installed but deactivated for a while if you need the history, or export it
  first.

Then: **Settings → Delivery**, check what arrived, and **Tools → Send a test
email**.

### Deactivate WP Mail SMTP

Only once the test passes. Two plugins both reconfiguring PHPMailer is a coin
toss over which one wins, and Mailhouse will warn you in the admin until one of
them goes.

---

## 2. The list, from Noptin

**Mailhouse → Tools → Move in from another plugin → Noptin → Import.**

Noptin's column names have moved between versions, so the importer reads
whatever is actually in the table.

| Noptin | Mailhouse |
|---|---|
| `email` | `email` |
| `first_name`, `second_name` | `first_name`, `last_name` |
| `active = 1` (Noptin stores this inverted) | Status **unsubscribed** |
| `confirmed = 1` | Status **subscribed** |
| Neither — on the list, never confirmed | Status **pending** |
| `date_created` | Signup date, preserved |
| — | Everyone gets a **Noptin import** tag |

**Nobody is emailed by the import.** Not the confirmations, not the welcomes.
An import that mails four thousand people is a disaster, so it does not happen.

### About the pending ones

Addresses Noptin never confirmed arrive as pending, which means nothing is sent
to them until they confirm. That is the safe reading, and it is the one that
protects your sending reputation.

If you are confident they genuinely opted in — a single opt-in form you ran
yourself, with records — you can promote them: **Subscribers**, filter to
**Pending**, select all, **Mark as subscribed**. Think about it first. A list
with unconfirmed addresses on it is how domains get blocked, and that is not
undoable.

Alternatively, select them and **Resend confirmation** to ask properly. Expect
most not to answer; the ones who do are worth more than the ones who did not.

### What does not come across

- **Forms.** Noptin's form designs do not translate. Replace each one with
  `[mailhouse_form]` or the **Newsletter form** block, then set the defaults
  under **Settings → Signups** so you rarely need attributes.
- **Campaigns and automations.** Past campaigns and their statistics stay in
  Noptin. Rewrite the automated emails — there are three, they are short, and
  the defaults are already written for you under **Mailhouse → Emails**.
- **Custom fields and Noptin's own tags/lists.** These vary too much between
  versions and add-ons to map reliably. Export what you need to CSV from Noptin
  first, then import it here; a `tags` column is honoured.
- **Merge tag syntax.** Noptin's `[[first_name]]` works in Mailhouse
  unchanged — the double-bracket form is accepted alongside `{{first_name}}`
  precisely so pasted content still works.

### Unsubscribe links in mail already sent

Links in emails Noptin already delivered keep pointing at Noptin's endpoints.
While Noptin is still installed they keep working. Once it is gone they will
not, which is an argument for leaving it installed-but-deactivated for a few
weeks, or for sending one issue from Mailhouse — every one of those carries a
fresh unsubscribe link and a `List-Unsubscribe` header.

---

## 3. Afterwards

1. **Subscribers** — do the counts look right?
2. **Emails** — edit the confirmation and welcome emails so they sound like you.
3. Sign up through your own form, with a real address, and confirm. End to end.
4. **Emails → Add email**, send a **test** to yourself, then send a small
   broadcast to a tag with two people on it before you send to everybody.
5. **Tools → Status** — cron running, transport configured, no conflicts.
6. Deactivate Noptin and WP Mail SMTP.

Keep both installed but deactivated for a month. Their tables are still there
if something turns out to be missing; deleting a plugin is what removes them.

---

## Coming from something else

No importer, but a CSV always works: export from the old plugin with at least an
`email` column, and optionally `first_name`, `last_name`, `status` and `tags`,
then **Tools → Import subscribers from a CSV**. Existing addresses are updated
rather than duplicated, so a re-run is harmless.
