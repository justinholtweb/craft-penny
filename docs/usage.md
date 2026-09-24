---
title: Usage
slug: usage
order: 30
summary: Making an invite, choosing its scope, what the recipient sees, review, the link lifecycle, email templates, Twig and the console.
---

## The shape of the thing

```
Invite ──< Target
  │          └── scope: which fields of its layout
  ├── one live key (only its hash is stored)
  └──< Event (the audit trail)
```

- An **invite** is one request to one person: a name for your records, a message for them, a
  deadline, and the one link that opens it.
- A **target** is one thing they may work on: an entry, a global set, a new entry in a section, and
  so on. Lite allows one per invite; Pro allows as many as you like.
- The **scope** is which fields of that target's field layout they may see and write. It is the only
  thing Penny consults when deciding what to show and what to save.
- An **event** is one line in the audit trail: created, sent, opened, saved, submitted, applied,
  revoked, expired, re-issued, denied. Each records the time and IP address.

## Making an invite

**Penny → Invites → New invite.**

The main column holds the name, the message, and the targets. The side pane holds where they edit,
which site, the deadline, **Hold for review**, and the recipient's name and email.

### Targets

Each target starts with **What kind**:

- **Edit something that exists.** Pick an entry or a global set (Lite), or a category, tag, asset or
  user (Pro).
- **Create something new** (Pro). Pick a section and an entry type. The recipient fills in a blank
  entry, which is created **disabled** so that nothing appears on the site until you enable it.

Give a target a **What to call it** label and a line of **Instructions** if the element's own name
would mean nothing to the recipient. The label becomes the heading of that section of their page.

### Choosing the fields

**Which fields** has two settings:

- **Everything on this element** covers the whole field layout, including any field you add to it
  later.
- **Only the fields I pick** lists the layout's fields tab by tab, including native ones such as the
  title, and you tick the ones they may fill in.

Some things are never offered and never written, whatever the layout contains: a user's email,
username, password, admin flag, permissions, groups and account status among them. An invite cannot
be used to take over an account.

### Scope is enforced when saving

The fields you tick are the only fields the Penny page renders, and the only fields Penny will write.
Values posted for anything else are dropped without an error. A hostile request and a tab left open
while you changed the scope look the same to the server, and neither is worth an error page.

## Where they edit

**The Penny page** is a standalone page with your logo and accent colour, your message, and the fields
you chose. It renders Craft's real field inputs, so Matrix, CKEditor, asset uploads and relation
fields behave as they do in the control panel. No Craft account is created.

**The control panel** (Pro) gives the recipient Craft's full editor. When they open the link, Penny
creates a temporary user with permissions for just the invite's targets, signs them in, and sends
them to the first target's edit screen. The navigation and search are hidden, and when every target
is limited to picked fields, the other fields are hidden too. A bar along the bottom of every page
links to each of the invite's targets and holds an **I'm finished, hand it in** button. Three checks
sit behind that:

- Craft refuses to open or save any element that is not one of the invite's targets, and refuses to
  delete or duplicate anything at all.
- The recipient can save their draft but cannot apply it. **Apply draft** is not offered, and a
  save of the live entry is refused, so hold for review cannot be skipped from the editor.
- On save, any out-of-scope field the recipient managed to change is put back to its current value
  before the save goes through.

Handing in does what **Send it in** does on the Penny page: the drafts are applied (or held, if the
invite is held for review), the link is spent, the submission notice goes out, and the recipient is
signed out onto a thank-you page. Their temporary account is suspended at that moment and deleted
straight after.

Opening the link again while it is live resumes the same account. If they never hand in, the
account is removed when the invite is revoked or deleted, or after it expires, during Craft's
garbage collection or the next `php craft penny/invites/prune`.

## What the recipient sees

On the Penny page:

- Your heading, their name, and the deadline, if there is one.
- Your message.
- One section per target, with its label, instructions and fields.
- **Send it in**, which submits everything and spends the link.
- **Save and finish later**, for targets that keep drafts. Their work is saved, the link stays open,
  and they can come back to it.

If something fails validation, nothing is submitted, the link stays live, and the page comes back
with the problems listed at the top of the affected section. Errors on fields they were not given
are not shown to them; they see "Something on this form could not be saved." instead.

After submitting they see a thank-you page. Any later visit to the link says it has already been
used.

## Drafts, and what they mean for live content

Where Craft keeps drafts (entries and categories), the recipient works on a draft that Penny creates
the first time they open the link. The live element is not touched until they submit, so a
half-finished session never shows on your site and closing the tab loses nothing.

Globals, users, assets and tags have no drafts in Craft 5. For those targets there is no **Save and
finish later** and no review step: what they type is written to the live element when they submit.
The invite screen says so under any target of that kind.

## Hold for review (Pro)

Turn on **Hold for review** and submitting does not publish. The drafts stay drafts, the invite's
status becomes **Awaiting review**, and the notification (if any) says it is waiting for approval.

Anyone with **Approve submitted content** sees **Approve and publish** on the invite. That applies
each draft and marks the invite **Submitted**. You can also open the draft from the entry itself and
edit it before approving.

Review only holds content that has a draft. Targets without drafts are written at submission either
way.

## The link, and why you only see it once

A key is 32 random bytes. Penny stores only its SHA-256 hash, so a database backup contains no
working links, and Penny cannot show you a link a second time.

The screen after **Create the invite** shows the link once. Reloading it shows "That link is no
longer available". From then on, the ways to get a working link are:

- **Re-issue the link**: mints a new one and shows it once. The old link stops working immediately.
- **Email a fresh link** (Pro): the same, sent to the recipient's email instead of shown to you.

There is only ever one live link per invite.

## Statuses

| Status | Means | Link works? |
|---|---|---|
| Not opened yet | Issued, never opened. | Yes |
| In progress | Opened at least once, not submitted. | Yes |
| Awaiting review | Submitted with **Hold for review** on, not yet approved. | No |
| Submitted | Submitted and live. | No |
| Expired | The deadline passed before it was submitted. | No |
| Revoked | Turned off by hand. | No |

Statuses are worked out from the invite's dates every time they are read, so a link stops working
the moment its deadline passes. No scheduled task is involved.

**Penny → Invites** has a source for each status.

## Managing an invite

The side pane of an existing invite has:

- **Approve and publish**, when it is awaiting review and you may approve.
- **Re-issue the link**. Works on a live or revoked invite, and brings a revoked one back with a new
  link. If the deadline has already passed, the new link gets a fresh one, the same number of days a
  new invite would get (or none, if **Default deadline** is 0). A submitted invite cannot be
  re-issued; make a new one.
- **Email a fresh link** (Pro, when there is a recipient email).
- **Revoke**, while the link is live.
- **Delete**. The link stops working immediately, not when the trash is emptied.

The **What has happened** table lists every event with its detail, the IP address it came from, and
when. Only the first visit is logged as "Link opened", so a recipient who reloads the page all
afternoon does not bury the rest. The trail never records the key.

## Email (Pro)

Penny sends three emails:

- **The invite**, when the invite is created (if **Send the link when an invite is created** is on)
  or when you click **Email a fresh link**.
- **A reminder**, from `php craft penny/invites/remind`.
- **A submission notice**, to the invite's **Notify on submission** addresses, or to the default
  addresses in settings if the invite has none.

A failed notification never turns a successful submission into an error. It is written to Craft's
log under the `penny` category instead.

### Email templates

To change the wording or layout, put your own template in your site's `templates` folder:

```
templates/penny/emails/invite.twig
templates/penny/emails/reminder.twig
templates/penny/emails/submitted.twig
```

Penny uses a site template when one exists and its own otherwise. Each receives:

| Variable | In | What it is |
|---|---|---|
| `invite` | all | The invite element. |
| `settings` | all | Penny's settings, e.g. `settings.getAccentColor()`. |
| `url` | invite, reminder | The link to open. |
| `cpUrl` | submitted | The invite's page in the control panel. |

The subject lines are not part of the templates. They can be changed with Craft's static
translations, in `translations/<language>/penny.php`.

## Twig

`craft.penny` is read-only. Nothing in it can change an invite or spend a link.

```twig
{# Is this page out with somebody right now? #}
{% if craft.penny.isOut(entry) %}
    <p>Waiting on the client.</p>
{% endif %}

{# Every live invite covering this entry #}
{% for invite in craft.penny.invitesFor(entry).all() %}
    {{ invite.recipientName }}
    {% if invite.expiryDate %}— due {{ invite.expiryDate|date('j M') }}{% endif %}
{% endfor %}

{# Any invite query, like craft.entries #}
{% set waiting = craft.penny.invites()
    .status('awaitingReview')
    .all() %}
```

| Method | Returns |
|---|---|
| `craft.penny.invites(criteria)` | An invite query. |
| `craft.penny.invitesFor(element)` | A query for **live** invites with that element as a target. |
| `craft.penny.isOut(element)` | Whether any live invite covers that element. |
| `craft.penny.currentInvite()` | The invite behind the current control panel session, if this is one. |
| `craft.penny.isPro()` | Whether Pro is active. |

An invite query accepts the usual element query parameters plus `status` (`pending`, `opened`,
`awaitingReview`, `submitted`, `expired`, `revoked`), `live()`, `surface` (`hosted` or `cp`),
`recipientEmail`, `targetSiteId` and `forElementId`.

Useful properties on an invite: `title`, `recipientName`, `recipientEmail`, `message`, `expiryDate`,
`dateSent`, `dateFirstOpened`, `dateSubmitted`, `requireReview`, `getInviteStatus().label()`,
`getTargets()` and `getTargetSummary()`.

## Console

```sh
php craft penny/invites                  # every invite, its status, target and deadline
php craft penny/invites opened           # only one status
php craft penny/invites/reissue 42       # a new link for invite 42, printed
php craft penny/invites/revoke 42        # turn invite 42's link off
php craft penny/invites/remind           # remind recipients whose invites expire soon (Pro)
php craft penny/invites/prune            # end stale control panel sessions, trim the audit trail
```

`remind` takes `--days=<n>` to override **Remind this many days before expiry**, and `--dry-run` to
list who would be reminded without sending anything. `prune` also takes `--dry-run`.

### Reminders

`remind` looks at live invites that have a recipient email and an expiry date within the window, and
that **have not been opened yet**. Somebody halfway through does not need chasing, and a reminder
would take the link out from under them, because each reminder re-issues the link: only a hash of
the original exists, so there is nothing to re-send. The email tells them any earlier link has
stopped working.

Each invite is reminded at most once, however often the command runs, so it is safe to schedule
daily:

```
0 9 * * * php /path/to/craft penny/invites/remind
```

`prune` does the same work as Craft's garbage collection, so it only needs a schedule if garbage
collection is turned off on your site.
