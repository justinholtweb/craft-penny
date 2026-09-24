---
title: Configuration
slug: configuration
order: 20
summary: Every setting and its default, the config file, and the user permissions Penny adds.
---

All of Penny's settings are on one screen: **Penny → Settings**, or **Settings → Plugins → Penny**.
None of them are required, and the defaults are a sensible starting point for most sites.

## Links and defaults

| Setting | Default | What it does |
|---|---|---|
| Invite URL prefix | `penny` | The first part of the link you hand out, so links look like `https://example.com/penny/<key>`. Letters, numbers, hyphens, underscores and slashes. |
| Default expiry | 14 days | How long a new invite lasts. You can change it on each invite. 0 means new invites have no deadline. |
| Default surface | Penny page | Where new invites send their recipient: the **Penny page** or the **Control panel** (Pro). |
| Acting user | empty | Whose identity the Penny page borrows so that field inputs can render. Empty means whoever created the invite. |

### How the link works

The link in the email is a site URL, `/<prefix>/<key>`. It redirects to the page where the editing
happens, which is served from your control panel URL at `/<cpTrigger>/penny-invite/<key>`. It has to
be a control panel URL because Craft's field inputs need control panel assets to render, but the page
has none of the control panel's navigation, so the recipient has nowhere else to go.

If a page on your site already lives at `/penny`, change the prefix. Leave it empty and Penny
registers no site route at all, and hands out the control panel URL instead.

### The acting user

Field inputs such as element selects and asset uploads read the current user, and with no user at all
they render empty or fail. So each request on the Penny page borrows one, in memory only. No session
is written, no cookie is set, and the recipient's browser never holds a Craft login.

That identity does not decide what the recipient can change. The invite's scope does, and it is
enforced when the content is saved. What the acting user does affect is what the inputs offer: a
relation field lists what that user can see. If your invite authors have narrow permissions and a
relation field comes up short, set an acting user with wider ones.

If the configured user is missing or suspended, Penny falls back to the invite's author, and then to
the first active admin.

## Control panel sessions (Pro)

| Setting | Default | What it does |
|---|---|---|
| Session length | 3600 seconds | How long a control panel session lasts before the recipient has to open the link again. Anything below 60 is treated as 60. |
| Delete the temporary account afterwards | on | On, the account is deleted once its invite is finished. Off, it is kept but suspended. |

This surface creates a real Craft user for the duration. **Craft Solo allows one user, so on Solo the
control panel surface cannot work.** Use the Penny page there.

## Email

| Setting | Default | What it does |
|---|---|---|
| Send the link when an invite is created | on | Pro. Emails the link to the recipient's address when the invite is created. Off, you copy the link and send it yourself. |
| Remind this many days before expiry | 3 | Pro. Used by `php craft penny/invites/remind`. 0 turns reminders off. |
| Notify on submission | empty | Who hears about a submission when the invite does not say. One address per line. |

New invites start with the **Notify on submission** addresses from here, and you can change them per
invite. Submission notices are sent on Pro only.

Emails go through Craft's own mailer, so they use whatever you set under **Settings → Email**.

## The Penny page

| Setting | Default | What it does |
|---|---|---|
| Accent colour | `#ED8228` | The colour of buttons and highlights on the Penny page and in the emails. |
| Logo | none | An image shown at the top of the Penny page. |
| Default heading | empty | Shown above the form. Empty uses "Could you fill this in?". |

## Housekeeping

| Setting | Default | What it does |
|---|---|---|
| Keep the audit trail for | 365 days | Older events are removed during Craft's garbage collection. 0 keeps them forever. |
| Failed attempts allowed per hour | 20 | Per IP address. Only wrong or malformed keys count. 0 turns the limit off. |

A correct key never counts as a failed attempt, and it clears the counter for that address, so a
recipient working through a long form is never locked out by their own visits.

## Config file

Every setting can be overridden per environment in `config/penny.php`, the same as any Craft plugin:

```php
<?php

return [
    'inviteUriPrefix' => 'content-request',
    'defaultExpiryDays' => 7,
    'defaultSurface' => 'hosted',
    'actingUserId' => null,
    'cpSessionDuration' => 3600,
    'deleteSessionUsers' => true,
    'sendOnCreate' => true,
    'remindDaysBefore' => 3,
    'notifyEmails' => "editor@example.com\nsam@example.com",
    'keepEventsDays' => 365,
    'maxAttemptsPerHour' => 20,
    'accentColor' => '#ED8228',
    'logoAssetId' => null,
    'hostedHeading' => '',
];
```

`defaultSurface` is `hosted` (the Penny page) or `cp` (the control panel). On Lite, leave it on
`hosted`: an invite set to the control panel cannot be saved without Pro.

## Permissions

Penny adds these under **Settings → Users → [group or user] → Penny**. Admins have all of them.

| Permission | Lets them |
|---|---|
| View invites | See **Penny → Invites** and open an invite. |
| Create and edit invites | Make and change invites, re-issue a link and, on Pro, email a fresh one. |
| Delete and revoke invites | Turn a link off, and delete an invite. |
| Approve submitted content | Publish content that was held for review (Pro). |

The last three sit under **View invites** and need it.
