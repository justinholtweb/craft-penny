# Release Notes for Penny

## 5.0.0 - 2026-08-18

### Added

- One-time content invites: pick what a recipient may edit, tick which of its fields they may
  touch, and hand them a single-use link.
- Targets: an existing entry, a new entry in a section (Pro), a global set, or a category, tag,
  asset or user (Pro). One per invite in Lite, unlimited in Pro.
- The Penny page — a standalone editing page rendering Craft's real field inputs, with no Craft
  user account created and no control panel session issued.
- Temporary control panel sessions (Pro): a scoped, disposable account dropped straight onto the
  edit screen, with a hand-in bar linking every target. Handing in applies the drafts (or holds
  them for review), spends the link, signs the recipient out and deletes the account. The session
  can save drafts but never apply them itself.
- Work in progress is held in a Craft draft where the element type supports one, so a half-finished
  session is never visible on the site and closing the tab loses nothing.
- Hold for review (Pro): a submission lands as a draft for approval instead of going live.
- Lifecycle: expiry dates, single submission, revoke, and re-issue. Status is derived, so a link
  stops working the moment it lapses rather than the next time a cron runs.
- Email delivery, expiry reminders and submission notices (Pro), with site-template overrides.
- A per-invite audit trail: created, sent, re-issued, opened, saved, submitted, applied, revoked,
  expired and denied, with IP and a hashed user agent — never the key.
- Console commands `penny/invites`, `remind`, `revoke`, `reissue` and `prune`.
- `craft.penny` Twig API: `invites()`, `invitesFor()`, `isOut()`, `currentInvite()`.
- Garbage collection: disposes of ephemeral accounts whose invites have lapsed, and trims the audit
  trail to the retention setting.
