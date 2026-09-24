# Penny — Craft CMS 5 Plugin

## Project Overview

Penny issues **one-time content invites**: an admin picks what somebody outside the team may edit,
ticks which of its fields, and gets a single-use link. The recipient fills it in, sends it back, and
the link dies. Distributed as `justinholtweb/craft-penny`. **Lite + Pro, $79 / $59 renewal.**

Modelled on WordPress's *One Time Login*, but that plugin hands over a whole admin session and this
one hands over a **scope**.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step: two hand-written asset bundles, no npm, no dependencies beyond Craft

## Architecture

### Namespace & package

- Namespace: `justinholtweb\penny`
- Package: `justinholtweb/craft-penny`
- Handle: `penny`

### The invariant

`services\Scope` is the **only** authority on what an invite may touch. The hosted renderer, the
hosted save, the control panel session's permission set and the control panel save guard all ask
it. So a surface can never show a field the save would reject — and, the part that matters, a save
can never write a field the surface never showed, whatever arrives in the POST.

Scope is stored as **field layout element UIDs**, not field handles: it is the one identifier that
covers native fields (title, slug) and custom fields alike, is what Craft's own conditional-field
logic keys on, and survives a field being renamed. `null` means the whole layout, so a field added
tomorrow is included without anybody re-ticking anything.

`Scope::NEVER_WRITABLE` refuses `admin`, `permissions`, `groups`, `email`, `username`,
`newPassword`, `suspended` and their kind whatever a field layout says. An invite is not a route to
an account.

### Keys

32 random bytes, base64url, **hash-only storage**. Lookup is a single indexed equality test on the
SHA-256, so nothing compares strings and there is no timing to measure. The plain key lives on the
element for exactly the request that minted it (`Invite::$_plainKey`) — long enough for the "copy
this link" screen and the email. That is why the CP offers *re-issue* rather than *show me again*,
and why a reminder re-issues too.

### Where the hosted page is served from

`<cpTrigger>/penny-invite/<key>` — a **control panel** route, because CP field inputs need CP asset
bundles and a CP request to render at all. It is anonymous, the same shape as Craft's own login
screen.

Deliberately **not** under `penny/…`: Craft demands a login for any non-action CP URL whose first
segment is a plugin handle, in `Application::handleRequest()`, *before* the controller is reached
and therefore before `$allowAnonymous` is ever consulted. `Plugin::HOSTED_SEGMENT` exists for that
reason alone. The pretty site link `/penny/<key>` just redirects there.

### Identity on the hosted page

Field inputs read the current user, and with none they render empty or throw. So the request borrows
one — `User::setIdentity()`, never `login()`: set for the request, no session written, no cookie
issued. The recipient's browser never holds a Craft login. **The scope, not that identity, is what
bounds them.**

### Drafts

Where the element type keeps drafts (entries, categories), everything happens on a draft and the
live element is untouched until submission. That makes a half-finished session safe, makes Matrix
and asset handling Craft's problem rather than ours, and makes review mode free — reviewing is just
not applying the draft yet.

Globals, users and assets have no drafts in Craft 5. Those are written once, at submission, and the
CP says so rather than pretending otherwise.

### The control panel surface (Pro)

A real, temporary user with permissions written **straight to the user, not to a group** — groups
live in project config, and writing project config mid-click fails on every environment with
`allowAdminChanges` off.

Two guards, because permissions are section-shaped and Penny's scope is element-shaped:

1. `Elements::EVENT_AUTHORIZE_*` → the CP itself refuses out-of-scope elements (403/404).
2. `Element::EVENT_BEFORE_SAVE` → the boundary on the way into the database, plus
   `Sessions::enforceFieldScope()`, which restores out-of-scope field values from the canonical
   element before the save goes through.

The session needs `saveEntries` + `savePeerEntryDrafts` to work on somebody else's draft, and those
are exactly what *Apply draft* asks for. So `Sessions::refusesCanonicalSave()` refuses the live copy
of anything that keeps drafts, in both guards, which also takes the button off the screen (Craft
gates it on `canSaveCanonical()`). The only way work goes live from a session is the hand-in bar →
`penny/session/hand-in` → `Sessions::handIn()`, which lifts that refusal for its own request.

## Traps found while building this

- **Craft ignores `$event->isValid` on `Elements::EVENT_BEFORE_SAVE_ELEMENT`.** It constructs the
  event, triggers it, and never reads it back — so a guard written there compiles, runs, logs, and
  stops nothing. The hook whose answer `saveElement()` actually honours is
  `Element::EVENT_BEFORE_SAVE` (a `ModelEvent`, via `$element->beforeSave()`). Found by watching a
  same-section neighbour entry get overwritten from a session that had "blocked" it.
- **`Elements::EVENT_BEFORE_SAVE_ELEMENT` passes an `ElementEvent`, not a `ModelEvent`** — the
  element is `$event->element`, not `$event->sender`.
- **Craft requires a login for non-action CP URLs whose first segment is a plugin handle**, in
  `Application::handleRequest()`, before any controller runs. `$allowAnonymous` cannot save you;
  only a different first segment can.
- **Every element authorisation check starts with the site**: `Elements::_siteAuthCheck()` demands
  `editSite:<siteUid>` on a multi-site install before it looks at the section at all. A permission
  set that is otherwise complete gets refused with no explanation.
- **A draft's creator decides who may open it.** Penny creates the draft as the invite's author, so
  the recipient needs `viewPeerEntryDrafts` / `savePeerEntryDrafts` to reach their own working copy.
- **The same setter is fed by the form and by the database.** `setNotifyEmails()` gets a textarea
  from the CP and the column's JSON from an element query; splitting the JSON `[]` on newlines
  produced an address called "[]" and the mailer threw *after* a successful submission. Decode JSON
  first, and validate addresses at the mailer too — a notification is never worth 500ing a
  submission that already succeeded.
- **Craft's element select renders two inputs for the same value.** An empty placeholder named
  `…[elementId]`, so that clearing the field still posts something, and one named `…[elementId][]`
  inside each chip carrying the actual id. JavaScript that reads the field by its obvious name gets
  the empty one and concludes nothing is selected — which is silent, because an empty string is a
  perfectly good value.
- **Craft's element select does not fire a DOM `change` event.** It triggers Garnish events on a
  JavaScript object, and the hidden inputs it writes fire nothing at all, so `$container.on('change')`
  never runs. Watching the chip list for `childList` mutations is version-independent and does not
  reach into Craft's internals.
- **Choosing an element starts an animation**, flying a copy of the chip from the modal into the
  field. Rebuilding that part of the DOM while it is in flight strands the copy in the middle of the
  page — and reads the chip's id before it has been written. Debounce anything that reacts to a
  selection by a few hundred milliseconds.
- **Craft's asset cache-buster is the source *directory's* mtime, not the file's.** Editing a file
  in place leaves `?v=` unchanged, so browsers keep the old copy even after
  `clear-caches/cp-resources` has republished the new one, and you debug stale JavaScript for an
  hour. `touch` the `dist` directory.
- **`craft\base\Model::datetimeAttributes()` does not auto-detect DateTime properties**, but
  `Typecast::properties()` does convert them, which is why custom `?DateTime` columns work without
  an override.
- **Craft handles have no hyphens in some places and do in others** — plugin handles may be
  kebab-case, so "a handle can't contain a hyphen" is not a guarantee you can build on.
- **Only nested elements have `getOwner()`.** Calling it on anything else goes through `__call` and
  throws `UnknownMethodException` — and the control panel asks the authorize events about the
  signed-in *user* on every page, so an unguarded call 500s the whole CP session. The console checks
  never render a CP page and never saw it; only walking it in a browser did.
- **Applying a draft writes change tracking after the request**, from rows it read while the draft
  was applied — still stamped with the id of whoever edited the draft. Hard-delete that user in the
  same request and the deferred insert fails its foreign key and 500s a hand-in that has already
  succeeded. Delete in `onAfterRequest()`, registered after the apply, so it runs after Craft's own.
- **`Craft::$app->getRequest()` is a `craft\console\Request` outside a web request** and has no
  `getUserIP()`. Anything reachable from a console command has to type-check first.
- **Project config writes are buffered until the request ends**, so a bare script switching the
  plugin edition must call `saveModifiedConfigData()` itself.
- **Entry types outlive their sections in Craft 5**, so a test teardown that only drops the section
  leaves one behind and the next setup collides with its handle.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-penny/tests/integration/checks.php    # 72 checks
ddev exec php /var/www/craft-penny/tests/integration/edition.php pro
ddev exec bash -c 'find /var/www/craft-penny/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`tests/integration/walkthrough.php setup|check|teardown` builds a fixture section, entry and live
invite and prints the link (`setup cp` for the Pro control panel surface), for walking the whole
thing over HTTP the way a recipient would.

The checks are idempotent and self-cleaning — fields, entry type, section, entry and every invite
are removed in a `finally` block, pass or fail, and the edition is put back.

## Coding conventions

- `Craft::t('penny', '…')` for user-facing strings; `src/translations/en/penny.php` lists them all
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — the details pane is already inside the page form, and a
  second action input makes Save run whichever action came last. Post from JS with
  `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Every state change on an invite goes through `services\Invites`, because every one of them also
  has to write an audit row
