# Penny — plan

One-time content invites for Craft CMS 5. Package `justinholtweb/craft-penny`, namespace
`justinholtweb\penny`, handle `penny`.

The pitch: **hand a client a single-use link that drops them straight into exactly the fields you
want filled in — and nothing else.** Modelled on the WordPress *One Time Login* plugin, but where
that one hands over a whole `wp-admin` session, Penny hands over a *scope*.

## Decisions locked (2026-08-18)

1. **Paid, Lite + Pro** — $79 one-time / $59 renewal, priced with Fold and Stub. Lite is the
   single-target, hosted-page, entries-and-globals build; Pro is everything.
2. **Two editing surfaces, chosen per invite:**
   - `hosted` — a standalone Penny page rendering the real Craft field UIs. No Craft user account
     is created, so it works on Solo, and the recipient never gets a control panel session.
   - `cp` — a temporary, tightly-permissioned Craft user dropped onto the real CP edit screen,
     revoked on submit or expiry. **Pro only**, because it creates real user accounts.
3. **Targets:** existing entries, a new entry in a chosen section/entry type, globals, and
   users/categories/assets. One target in Lite, many in Pro.
4. **Lifecycle:** the link dies on first successful submission or at its expiry date, whichever
   comes first, and an admin can revoke or re-issue at any time. Work in progress is preserved
   between visits, so closing the tab does not lose anything.

## Why this plugin exists

| Want | Craft 5 today | Penny |
| --- | --- | --- |
| "Let the client write their own bio, once" | Create a user, invent a group, pick permissions, remember to delete them | One invite, one link, self-destructs |
| "Only these three fields" | Field layout conditions, or nothing | Tick the fields on the invite |
| "Don't let them see the rest of the site" | Section-level permissions at best | Scope is per *element*, enforced on read and on write |
| "Don't publish it until I've read it" | Nothing | Review mode — the submission lands as a draft |
| "The link should stop working" | Nothing | Expiry, single submission, revoke, re-issue |
| "Who filled this in, and when?" | Nothing | Per-invite audit trail |

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Hosted editing page | ✓ | ✓ |
| Existing entries + globals as targets | ✓ | ✓ |
| Field-level scoping | ✓ | ✓ |
| Expiry, single-submission, revoke, re-issue, audit trail | ✓ | ✓ |
| Targets per invite | 1 | unlimited |
| New-entry targets (create in a section) | | ✓ |
| User / category / asset targets | | ✓ |
| Temporary control-panel session surface | | ✓ |
| Email delivery, reminders, submission notifications | | ✓ |
| Review mode (submission lands as a draft) | | ✓ |
| Branding of the hosted page | | ✓ |
| Console commands + Twig API | | ✓ |

## Architecture

### The invariant

`services\Scope` is the **only** thing that answers "may this invite touch this element, and which
of its fields". The hosted renderer, the hosted save, the CP session's permission set and the CP
save guard all ask it. A surface can therefore never show a field the save would reject, and — the
part that matters — a save can never write a field the surface never showed, whatever is posted.

Nothing trusts a posted element ID or field handle. The POST is intersected with the scope; keys
outside it are dropped, not rejected, because a hostile POST and a stale tab look identical from
the server and only one of them deserves an error message.

### Keys

A key is 32 random bytes, base64url. **Only its SHA-256 is stored**, so a database dump does not
contain a working link and Penny cannot show an admin a link twice. The link is displayed once at
generation (and emailed, in Pro); after that the only options are *re-issue* — which mints a new
key and invalidates the old one — or delete. Reminders re-issue for the same reason.

Lookup is by hash, so it is a single indexed equality test and there is no string comparison to
time. Failed lookups are rate-limited per IP.

### Data model

`{{%penny_invites}}` — an **element**, so the CP index, search, sources, sorting and soft-delete
all come free.

| Column | Why |
| --- | --- |
| `id`, `uid`, dates | usual; `id` FKs `elements.id` |
| `keyHash`, `keyIssuedAt` | see above; unique index on `keyHash` |
| `surface` — `hosted` \| `cp` | which editing surface this invite opens |
| `recipientName`, `recipientEmail` | who it is for; the email is also the CP user's email in `cp` mode |
| `message` | the note shown at the top of the hosted page |
| `expiryDate` | hard stop, in the site's time zone |
| `dateSent`, `dateFirstOpened`, `dateSubmitted`, `dateRevoked` | the lifecycle, and the audit trail's spine |
| `requireReview` | submission stays a draft instead of being applied |
| `notifyEmails` JSON | who hears about a submission |
| `branding` JSON | Pro: logo, accent colour, heading, sign-off |
| `authorId` | who created it — and, in `hosted` mode, the acting identity |
| `sessionUserId` | `cp` mode: the ephemeral user, so it can be revoked and swept |
| `siteId` | the site the invite edits in |

`{{%penny_targets}}` — one row per thing the invite may touch, cascade-deleted with the invite.

| Column | Why |
| --- | --- |
| `inviteId` | owner |
| `kind` — `element` \| `new` | edit something that exists, or create something |
| `elementType` | the element class |
| `elementId` | null for `new` until the draft exists |
| `entryTypeId`, `sectionId`, `parentId` | `new` targets only |
| `draftId` | the in-progress draft, if the type supports drafts |
| `layoutElementUids` JSON | the scope: which layout elements are editable. `null` = the whole layout |
| `label`, `instructions` | what the recipient is told to do |
| `sortOrder`, `dateSaved`, `resultElementId` | ordering, and what came of it |

Scope is stored as **field layout element UIDs**, not field handles, because that is the one
identifier that covers native fields (title, slug) and custom fields alike, is what Craft's own
conditional-field logic keys on, and survives a field being renamed.

`{{%penny_events}}` — append-only audit: `created`, `sent`, `opened`, `saved`, `submitted`,
`applied`, `revoked`, `expired`, `reissued`, `denied`. Records IP and a user-agent hash, never the
key.

### In-progress work

Where the element type supports drafts (entries, categories), Penny creates a **real Craft draft**
on first open and the recipient edits that. Nothing touches the live element until submission, so a
half-finished session is never visible on the site, Matrix and asset changes are handled by Craft
rather than re-implemented, and *review mode* is free: just do not apply the draft.

Globals, users and assets are not draftable in Craft 5. Those targets are edited against the live
element and written on submit, and Penny says so in the CP when such a target is added.

### The hosted surface

Served from an **anonymous control-panel route** (`<cpTrigger>/penny/i/<key>`), because CP field
inputs need CP asset bundles, `Craft.*` JS globals and a CP request to render — and Craft's own
login and set-password screens already prove the route type works anonymously
(`Controller::$allowAnonymous` short-circuits the `accessCp` check). The page uses Penny's own
minimal layout, not `_layouts/cp`, so there is no nav, no breadcrumb and no way out.

The emailed link is the pretty site URL `/<inviteUriPrefix>/<key>`, which validates and redirects.

Field HTML needs an identity — element select fields, asset uploads and permission-aware inputs all
read the current user. Penny sets the invite author as the identity **in memory only**
(`User::setIdentity()`, never `login()`), so no session is written, no cookie is issued, and the
recipient's browser is never holding a Craft login. The scope, not that identity, is what bounds
what they can do.

### The CP surface (Pro)

On redemption Penny creates a user in a Penny-managed group whose permissions are computed from the
invite's targets, logs them in, and redirects to the real edit screen. It is revoked — suspended,
then deleted by garbage collection — on submit, on expiry, or on revoke.

Two guards, because permissions alone are a section-level tool and Penny's scope is per element:
an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler rejects any save outside scope while a Penny CP
session is active, and a CSS bundle strips the CP chrome the session has no business using.

The settings screen warns that this surface creates real user accounts, which Craft Solo counts.

## Build order

1. Scaffold, editions, settings, install migration, records, models, enums.
2. `Invite` element + query + CP index + editor + link screen.
3. `Scope` + `Editor` (draft creation, form render, whitelisted save).
4. Hosted surface: gateway route, anonymous CP route, layout, save, done screen.
5. CP surface: ephemeral user, permission computation, save guard, chrome stripping, revocation.
6. Notifications: invite, reminder, submission. Queue jobs.
7. Console commands, garbage collection, Twig variable.
8. Docs, README, CHANGELOG, icon, integration checks.
