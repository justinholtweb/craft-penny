---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: What to check when a link will not open, an email does not arrive, or submitted content is not where you expected.
---

## The recipient says the link does not work

Open the invite in **Penny → Invites**. The status in the side pane and the **What has happened**
table usually answer it. Every refused visit to a real link is logged as "Access denied" with the
reason.

What the recipient saw tells you which case it is:

| They saw | What happened | What to do |
|---|---|---|
| This link is not valid. | The key matches no invite. Usually the link was cut short when it was copied or wrapped by an email client, or it has since been re-issued. | Check they have the whole link. If in doubt, re-issue and send the new one. |
| This link has expired. | The deadline passed. | Re-issue it. The new link gets a fresh deadline. |
| This link has been turned off. | It was revoked. | Re-issue it if they should have it back. |
| This link has already been used. | It was submitted. | Make a new invite. A submitted invite cannot be re-issued. |
| Too many attempts. Try again later. | Their IP address has made too many wrong guesses. | See below. |
| This link could not be opened. | A control panel invite could not create or sign in its temporary account. | See below. |

A link that never existed and a link that is malformed get the same answer on purpose. Telling them
apart would only help somebody guessing.

## Re-issuing an expired invite gives a link that is also expired

Re-issuing mints a new key but leaves the deadline alone. Change **Expires** on the invite, click
**Save**, and then **Re-issue the link**.

## "Couldn't re-issue this link"

The invite has been submitted. Its content is on the site, or waiting for review, and the invite is
finished. Make a new invite for any further changes.

## "Too many attempts"

Each IP address gets **Failed attempts allowed per hour** wrong or malformed keys (20 by default)
before Penny stops answering it. A correct key never counts.

This can catch a whole office behind one IP address if somebody there has been pasting broken links.
The counter clears an hour after the last failed attempt, and opening a valid link clears it too.
A valid link always opens, even once the limit is reached: the limit is there to stop guessing, and
somebody holding the real key is not guessing. You can raise the limit, or set it to 0 to turn it off, under
**Penny → Settings → Housekeeping**. The counter lives in Craft's data cache, so clearing that cache
also resets it.

## The link asks the recipient to log in

The Penny page only answers while the system is on. If **System status** is off under
**Settings → General**, the recipient is sent to the login screen like any other visitor. Turn the
system on, or wait until it is back.

## The link on the site gives a 404

The pretty link is `/<prefix>/<key>`, with the prefix from **Invite URL prefix**. Check the setting
has a value, that nothing else on the site claims the same path, and that the link the recipient has
uses the prefix you have now. Changing the prefix does not update links already sent.

## "This link could not be opened" on a control panel invite

Penny could not create the temporary account or sign it in. The usual causes:

- **Craft Solo.** Solo allows one user, and the control panel surface needs a second. Switch the
  invite to **Penny page**.
- **Pro is not active.** A control panel invite on Lite opens on the Penny page instead, so this
  means something else went wrong. Check the Craft log for lines from the `penny` category, which say
  why the account could not be saved.

## Saving an invite says "Control panel sessions are a Pro feature"

On Lite, every invite has to use the Penny page. If new invites keep picking the control panel,
**Default surface** in settings (or `defaultSurface` in `config/penny.php`) is set to `cp`. Set it
back to `hosted`.

## The recipient submitted, but nothing changed on the site

- **Hold for review is on.** The invite shows **Awaiting review**. Click **Approve and publish**. You
  need the **Approve submitted content** permission to see the button.
- **It was a new-entry target.** New entries are created disabled, so a submitted one exists but is
  not live. Find it in its section and enable it.
- **It was a control panel invite that was never handed in.** The recipient worked in Craft's own
  editor and closed the tab without clicking **I'm finished, hand it in**. Their draft is in the
  entry's drafts; apply it yourself, or ask them to open the link again and hand it in.

## "Something on this form could not be saved"

A save failed because of a field the recipient was not given, and Penny does not show people errors
about fields they cannot see. The usual cause is a required field outside the scope that is empty on
the element. Either fill that field in yourself, or add it to the invite's fields.

## A field changed back after a control panel session

That is the scope working. On the control panel surface Craft's editor can show fields that are not in
the invite's scope, and Penny puts any change to them back before the save reaches the database.
Add the field to the target's scope if they should be able to change it.

## A relation field or asset picker on the Penny page shows nothing, or too little

Field inputs render as the **Acting user**, which is the invite's author unless you set one. A
relation field offers what that user can see. Set an acting user whose permissions cover the sources
the field uses. What the recipient can actually save is still limited by the invite's scope.

## The invite email did not arrive

Check, in order:

1. Penny is on Pro. Lite does not send email.
2. The invite has a **Recipient email**.
3. **Send the link when an invite is created** is on, if you expected it on creation. Otherwise use
   **Email a fresh link** on the invite.
4. The invite's **What has happened** table shows "Link sent". If it does, Craft handed the message
   to your mail transport, and the problem is delivery. Send a test from **Settings → Email**.
5. If there is no "Link sent", look in the Craft log for lines from the `penny` category.

## Reminders did not go out

`php craft penny/invites/remind` only reminds invites that:

- are live,
- have a recipient email,
- have an expiry date within the reminder window, and
- **have not been opened yet**.

It also needs Pro, and **Remind this many days before expiry** above 0 (or `--days` on the command).
Run it with `--dry-run` to see who it would pick.

## Nobody got a submission notice

Submission notices are Pro. They go to the invite's **Notify on submission** addresses, or to the
default ones in settings if the invite has none. Addresses that are not valid email addresses are
skipped.

## Temporary users are still in the user list

A control panel account is removed as soon as the recipient hands in, or when its invite is revoked
or deleted. If the invite simply expires, it goes the next time Craft's garbage collection runs. To do it now:

```sh
php craft penny/invites/prune
```

If **Delete the temporary account afterwards** is off, the accounts are kept on purpose, suspended.
They have no password and cannot sign in.
