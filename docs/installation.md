---
title: Installation
slug: installation
order: 10
summary: Requirements, installing, the Lite and Pro editions, and your first invite.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Penny has no other dependencies and no build step.

## Installing

From the Plugin Store, or:

```sh
composer require justinholtweb/craft-penny
php craft plugin/install penny
```

Installing creates three tables: invites, their targets, and the audit trail. It creates no invites
and changes nothing on your site until you make one.

## Editions

**Lite is free.** It gives you the Penny page, one target per invite, entries and globals, field
scoping, expiry, revoke, re-issue and the audit trail. **Pro is $79, with a $59/year renewal for
updates**, and adds more targets per invite, more kinds of content, the control panel surface,
one-time view links, email, and review.

| | Lite | Pro |
| --- | --- | --- |
| The Penny page | ✓ | ✓ |
| Entries and globals as targets | ✓ | ✓ |
| Field-level scoping | ✓ | ✓ |
| Expiry, single submission, revoke, re-issue | ✓ | ✓ |
| Audit trail | ✓ | ✓ |
| Accent colour, logo and heading on the Penny page | ✓ | ✓ |
| Twig API and console commands (except reminders) | ✓ | ✓ |
| Targets per invite | 1 | unlimited |
| New-entry targets | | ✓ |
| Category, tag, asset and user targets | | ✓ |
| Temporary control panel sessions | | ✓ |
| View links and **Share once** | | ✓ |
| Email delivery, reminders, submission notices | | ✓ |
| Hold for review | | ✓ |

## Switching to Pro

Buy Pro in the Plugin Store, or switch the edition under **Settings → Plugins**. The edition is
stored in project config, so it travels with a deployment like everything else there.

Lite shows a note at the top of Penny's settings saying which features are Pro, and the Pro options
on the invite screen are visible but disabled.

## Your first invite

1. Go to **Penny → Invites → New invite**.
2. Give it a name. This is for you: "Bio from Sam", "September newsletter copy".
3. Under **What they can work on**, pick an entry or a global set.
4. Under **Which fields**, leave **Everything on this element** or choose **Only the fields I pick**
   and tick them.
5. Set a deadline in **Expires** if the default does not suit, and write a message for the top of
   their page.
6. Click **Create the invite**.

The next screen shows the link. Copy it now: Penny stores only a hash of it, so that screen is the
only time it can be shown. If you lose it, open the invite and **Re-issue the link**, which gives you
a new one and turns the old one off.

On Pro, if you filled in **Recipient email** and **Send the link when an invite is created** is on
(the default), Penny emails the link as well.

## Where things live

- Every invite and its status: **Penny → Invites**
- What happened to one invite: the **What has happened** table on its edit screen
- Plugin settings: **Penny → Settings** (admins), or **Settings → Plugins → Penny**
- Who may make invites: **Settings → Users → [group] → Penny**. See
  [Configuration](https://justinholt.com/plugins/craft-penny/docs/configuration#permissions).

## Customising the emails

Copy any of the plugin's `src/templates/_emails/invite.twig`, `reminder.twig` or `submitted.twig`
into your site's `templates/penny/emails/` folder. Penny uses your copy when one exists. See
[Usage](https://justinholt.com/plugins/craft-penny/docs/usage#email-templates) for the variables
each template receives.

## Uninstalling

```sh
php craft plugin/uninstall penny
```

This drops Penny's three tables and deletes every invite, so every outstanding link stops working
and the audit trail is gone. Take a database backup first if you may need to show who changed what.

Content the recipients submitted is ordinary Craft content and stays. So do drafts that were never
submitted; you can delete those from the entry's draft menu.

Uninstalling also removes any temporary control panel accounts that are still open.
