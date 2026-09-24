# Plugin Store promo images

Marketing images for the Penny listing on the Craft Plugin Store, rendered in the same theme as the
plugin's marketing page at
[justinholt.com/plugins/craft-penny](https://justinholt.com/plugins/craft-penny).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `penny-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp). **Promos are JPEG, never PNG** — Chrome can only write PNG, so
`build.sh` converts with `sips` at quality 90 and deletes the intermediate. `fonts.css` is generated
and gitignored.

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover — name, tagline, app icon, price | — |
| 2 | A link, not a login | an invite's week, from created to "This link has already been used." |
| 3 | Tick the fields. That's the scope. | **real** screenshot of the invite screen: target and field picker |
| 4 | Your note, their fields, one button | **real** screenshot of the Penny page the recipient lands on |
| 5 | It can't save what it never showed | `Scope::filterFieldValues()` and a POST with an out-of-scope field dropped |
| 6 | A database dump holds no links | how keys are minted, stored and found, plus the **real** audit trail |
| 7 | Hand over a scope, not a site | drafts, hold for review, CP sessions, emails |

Slides 5 and 6 quote the code, so they have to agree with it: `filterFieldValues()` is copied from
`src/services/Scope.php`, the never-writable list is a subset of `Scope::NEVER_WRITABLE`, and the key
rows follow `Keys::mint()` / `Keys::hash()` and the lookup in `Access::resolve()`.

The screenshots in `shots/` are real captures from the plugin-testing harness, taken against demo
data (a "Team" section, one entry, one invite) that was torn down afterwards. The CP screen comes
from `~/Sites/plugin-shots` (`specs/penny.json`). The Penny page is anonymous, so it was captured
without logging in, after filling the fields and pressing *Save and finish later*, which is why
the audit trail reads *Progress saved*. The blue bars beside its fields are Craft marking values
the draft changed. Slides 3 and 6 show two regions of the same CP capture, cropped in CSS (`.crop`).

The watermark is the coin silhouette from `src/icon-mask.svg`, filled white. It has no tile, so at
watermark scale it reads as the coin rather than as a grey box.
