---
title: FAQ
slug: faq
order: 50
summary: Pricing, Craft Solo, accounts, lost links, drafts, view links, and how the keys are kept safe.
---

### What does it cost?

Lite is free. It covers the Penny page, one entry or global set per invite, field scoping, expiry,
revoke, re-issue and the audit trail. Pro is $79 with a $59/year renewal, and adds multiple targets,
new entries, categories, tags, assets and users, the control panel surface, one-time view links and
**Share once**, email, reminders, submission notices and hold for review.

### Does it work on Craft Solo?

The Penny page does. It creates no Craft user, so it never counts against Solo's one-user limit.

The control panel surface (Pro) does not. It creates a real, temporary user for each invite, and Solo
has no room for a second one.

View links (Pro) do. They create no user either.

### Does the recipient get an account?

Not on the Penny page. There is no sign-up, no password, and no Craft login in their browser. The
page borrows an identity for each request so that Craft's field inputs can render, but it is held in
memory for that request only. No session is written and no cookie is set.

On the control panel surface (Pro), yes, for the duration: Penny creates a user with a made-up
username and email address and no password, gives it permissions for only the invite's targets, and
suspends and deletes it when they hand the work in, or when the invite is revoked, deleted or expires.
The only way into that account is the invite link.

### They lost the link. Can I show it to them again?

No, and that is deliberate. Penny stores only a hash of the key, so there is nothing to show. Open
the invite and click **Re-issue the link**, or **Email a fresh link** on Pro. They get a new link and
the old one stops working, so there is never more than one live link per invite.

Reminders work the same way. Each reminder carries a new link.

### Can they come back and finish later?

For entries and categories, yes. **Save and finish later** saves their work to a draft and leaves the
link open until they send it in or it expires.

Globals, users, assets and tags have no drafts in Craft 5, so for those there is no saved progress:
what they type is written once, when they submit.

### Will a half-finished submission appear on my site?

Not for entries and categories. They work on a draft, and the live entry is untouched until they
click **Send it in**. With **Hold for review** on (Pro), it stays a draft until somebody approves it.

For globals, users, assets and tags, nothing is written until they submit. When they do, it is
written straight to the live element, with or without review, because there is no draft to hold it
in. The invite screen says so when you pick one of those.

### Can they change fields I didn't tick?

No. The fields you tick are the only ones Penny renders on the Penny page and the only ones it will
save. Anything else in the request is dropped. On the control panel surface, where Craft's editor may
show more, Penny puts any out-of-scope change back before the save reaches the database.

Some things are refused whatever you tick: a user's email, username, password, admin status,
permissions, groups and account status. An invite cannot be turned into access to an account.

### Can they reach other entries?

On the Penny page there is nothing else to reach: it shows the invite's targets and nothing more. On
the control panel surface, Craft is told to refuse any element that is not one of the invite's
targets, and any save outside them is blocked.

### How safe are the keys?

- A key is 32 random bytes, so there is nothing to guess in any practical sense.
- Only its SHA-256 hash is stored. A copy of your database contains no working links.
- Looking one up is a single database match on that hash, so there is no string comparison whose
  timing could leak anything.
- Wrong keys are rate-limited per IP address.
- A key that matches nothing and a key that is malformed get the same answer.
- Deleting an invite kills its link at once, not when the trash is emptied.
- The audit trail records the IP address and a hash of the browser's user agent. It never records the
  key.

### What happens when the deadline passes?

The link stops working at that moment. The status is worked out from the deadline every time the
invite is read, so it does not wait for a scheduled task. A control panel account belonging to an
expired invite can no longer save anything, and is removed at the next garbage collection.

### Can I send one link to several people?

You can, but it is one link: whoever submits first spends it for everyone. Make one invite per person
if you want to know who wrote what.

### How is a view link different from Craft's Share button?

Craft's **Share** button makes a link that anyone who has it can open, as often as they like, until
its token runs out. A view link, from **View once** on an invite or **Share once** on the edit
screen, opens once, for the one browser that opens it, for the **Viewing window** (30 minutes by
default). It is also on the invite list with its status and audit trail, can carry a message and a
deadline, can be emailed to the recipient on Pro, and can be revoked.

### Can an email scanner or a link preview use up a view link?

No. Opening the link only shows a page with an **Open the page** button, and nothing is spent until
somebody presses it. Mail security scanners, Microsoft Safe Links, and the previews Slack and
iMessage make all fetch the link, and none of them press buttons.

### Can they forward a view link?

Before they have opened it, yes, and whoever presses **Open the page** first is the one who sees it.
Everyone after that is told the link has already been used. The audit trail records the IP address
of the browser that pressed the button.

After they have opened it, forwarding the page's address does nothing: it only works in the browser
that opened it. That does not stop a screenshot, and the images and files on the page are served
from their ordinary public URLs. A view link protects the page, not the files on it. See
[Usage](https://justinholt.com/plugins/craft-penny/docs/usage#what-a-view-link-does-not-protect).

### Can I change the emails?

Yes. Put your own `invite.twig`, `reminder.twig` or `submitted.twig` in `templates/penny/emails/` and
Penny uses it instead of its own. See
[Usage](https://justinholt.com/plugins/craft-penny/docs/usage#email-templates).

### Can I show on the front end that a page is out with somebody?

Yes, with `craft.penny.isOut(entry)` or `craft.penny.invitesFor(entry)`. See
[Usage](https://justinholt.com/plugins/craft-penny/docs/usage#twig).

### How is this different from giving them a Craft account?

An account has to be created, put in a group, given permissions, and remembered and deleted later,
and Craft's permissions work per section rather than per entry. An invite covers only the elements
and fields you chose, and stops working on its own once it is used.

### Does Penny make outbound requests?

No. The only thing it sends is email, through Craft's own mailer, on Pro.
