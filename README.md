<p align="center"><img src="src/icon.svg" width="120" alt="Penny"></p>

<h1 align="center">Penny</h1>

<p align="center">One-time content invites for Craft CMS 5 — hand a client a single-use link that drops them straight into the fields you want filled in, and nothing else.</p>

---

You need a bio from a new team member, a paragraph from a client, next month's opening hours. The
usual options are bad: create them a Craft account, invent a user group, pick permissions, and
remember to delete all of it afterwards — or give up and take it over email.

Penny is the third option. You pick the entry (or the global, or the section they should create
something in), tick the fields they may touch, and it gives you a link. They open it, fill it in,
send it back, and the link stops working.

It is modelled on WordPress's *One Time Login*, but where that plugin hands over a whole admin
session, Penny hands over a **scope**.

## Requirements

Craft CMS 5.3+ and PHP 8.2+.

## Installation

From the Plugin Store, or:

```sh
composer require justinholtweb/craft-penny
php craft plugin/install penny
```

## How it works

**You make an invite.** Give it a name for your own records, pick what the recipient works on, and
tick which of its fields they can edit. Set a deadline if you want one.

**Penny gives you a link, once.** Only a hash of the key is stored, so a database dump does not
contain a working link — and Penny genuinely cannot show you the same link twice. Copy it, or let
Penny email it (Pro). Later you can *re-issue*, which mints a new link and turns the old one off.

**They open it.** No account, no password, no sign-up. They see the fields you chose, your note at
the top, and a submit button.

**They send it in.** The link is now spent. You get an email (Pro), and the invite's page in the
control panel shows the whole trail: created, sent, opened, saved, submitted, from which address,
and when.

## What an invite can point at

| | |
| --- | --- |
| An entry that exists | The common case — "update this page" |
| A new entry in a section | They fill in a blank one; it stays disabled until you say otherwise |
| A global set | Opening hours, the address in the footer, the announcement bar |
| A category, a tag, an asset, a user | Anything else with a field layout |

One target in Lite, as many as you like in Pro.

## Where they edit

**The Penny page** — a standalone page with your logo on it and nothing else. It renders Craft's
real field inputs, so Matrix, CKEditor, asset uploads and relation fields all behave exactly as
they do in the control panel. No Craft user account is created, which means it works on a Solo
licence and your recipient never holds a login to your site.

**The control panel** (Pro) — for a recipient who needs the whole editor. Penny creates a
temporary, tightly-permissioned account, drops them on the edit screen, and deletes the account
when they hand the work in. It creates a real Craft user for the duration, which a Solo licence counts.

## Nothing is live until they say so

Where Craft keeps drafts — entries and categories — the recipient works on a draft. The live
element is not touched until they submit, so a half-finished session is never visible on your site
and closing the tab loses nothing.

Turn on **hold for review** (Pro) and submission does not publish either: the work stays a draft
until somebody approves it in the control panel.

Globals, users and assets have no drafts in Craft 5. Those are written once, at submission, and
Penny says so on the invite screen rather than pretending otherwise.

## The scope is the point

The fields you tick are the only fields Penny will render *and* the only fields it will write. A
posted value for anything else is dropped — not rejected with an error, just dropped, because a
hostile POST and a tab left open across a scope change look identical from the server.

On the control panel surface, where Craft's own editor renders the whole field layout, out-of-scope
fields are hidden and any change to them is put back before the save reaches the database.

Some things are never writable, whatever a field layout says: `admin`, `permissions`, `groups`,
`email`, `username`, `newPassword`, `suspended` and their kind. An invite is not a route to an
account.

## Lite and Pro

| | Lite | Pro |
| --- | --- | --- |
| The Penny page | ✓ | ✓ |
| Entries and globals as targets | ✓ | ✓ |
| Field-level scoping | ✓ | ✓ |
| Expiry, single submission, revoke, re-issue | ✓ | ✓ |
| Audit trail | ✓ | ✓ |
| Targets per invite | 1 | unlimited |
| New-entry targets | | ✓ |
| Category, tag, asset and user targets | | ✓ |
| Temporary control panel sessions | | ✓ |
| Email delivery, reminders, submission notices | | ✓ |
| Hold for review | | ✓ |
| Logo, accent colour and heading on the Penny page | ✓ | ✓ |
| Console commands and the Twig API | ✓ | ✓ |

## Email templates

The emails are ordinary Twig, and your own site templates win. Put a template at any of these paths
and Penny will use yours instead:

```
templates/penny/emails/invite.twig
templates/penny/emails/reminder.twig
templates/penny/emails/submitted.twig
```

## Twig

```twig
{# Is this page out with a client right now? #}
{% if craft.penny.isOut(entry) %}
    <p>Waiting on the client.</p>
{% endif %}

{# Every live invite covering this entry #}
{% for invite in craft.penny.invitesFor(entry).all() %}
    {{ invite.recipientName }} — due {{ invite.expiryDate|date('j M') }}
{% endfor %}
```

## Console

```sh
php craft penny/invites                  # what is out, and where it has got to
php craft penny/invites/reissue 42        # a fresh link, printed
php craft penny/invites/revoke 42         # turn a link off
php craft penny/invites/remind            # chase invites about to expire (put this on cron)
php craft penny/invites/prune             # tidy stale sessions and trim the audit trail
```

`remind` re-issues the link it sends, because only a hash of the original is stored and there is
nothing to re-send. One live link per invite, always. Each invite is reminded once, however often
the command runs.

## Settings

Everything is under **Settings → Plugins → Penny**: the URL prefix invites are served from, the
default deadline and surface, session length, email defaults, the accent colour and logo for the
Penny page, how long the audit trail is kept, and how many wrong guesses an IP address gets per
hour.

## Security

- Keys are 32 random bytes. Only their SHA-256 is stored, and lookup is a single indexed equality
  test on that hash — no string comparison whose timing could be measured.
- Wrong keys are rate-limited per IP. A correct key is never counted as a guess, so nobody is
  throttled by their own progress.
- A key that matches nothing and a key that is malformed get the same answer, because telling them
  apart is only useful to somebody guessing.
- Deleting an invite kills its link immediately, not when the trash is emptied.
- The audit trail records the IP and a hash of the user agent. It never records the key.

## Testing

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-penny/tests/integration/checks.php   # 72 checks
```

## Licence

Proprietary. See [LICENSE.md](LICENSE.md).
