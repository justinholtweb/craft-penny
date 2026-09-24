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
| 6 | Opens once. For one person. | view links (Pro): **real** *Share once* popover, then the link fetched by a scanner (nothing spent), opened with the button (the page itself), and the same URL in another browser (closed) |
| 7 | Hand over a scope, not a site | drafts, hold for review, CP sessions, Share once, emails, hash-only keys |

Slide 5 quotes the code, so it has to agree with it: `filterFieldValues()` is copied from
`src/services/Scope.php`, and the never-writable list is a subset of `Scope::NEVER_WRITABLE`.

Slide 6 has to agree with `services/Views.php` and `controllers/ViewController.php`: a GET of the
link spends nothing, *Open the page* spends it and sets a signed cookie, and every render checks
that cookie, the revoke and the window (`viewWindowMinutes`, 30 by default — hence "the default"
on the slide). It replaced an earlier slide on hash-only keys and the audit trail, which is now a
card on slide 7.

The screenshots in `shots/` are real captures from the plugin-testing harness, taken against demo
data that was torn down afterwards. The CP screen comes from `~/Sites/plugin-shots`
(`specs/penny.json`). The Penny page is anonymous, so it was captured without logging in, after
filling the fields and pressing *Save and finish later*. The blue bars beside its fields are Craft
marking values the draft changed. Slide 3 shows a region of the CP capture, cropped in CSS (`.crop`).

The view-link shots (`penny-share-once.png`, `penny-view-open.png`, `penny-view-page.png`,
`penny-view-closed.png`) came from a second demo: a "Press releases" section with a site template,
one **disabled** entry and a view invite for "Priya" with a message. They were taken in order with
headless Chrome — the interstitial first, since pressing *Open the page* spends the link; then the
button, which lands on the entry's real URL; then that same URL in a fresh browser context, which
gets the closed page. *Share once* was clicked on the entry's edit screen while logged in as admin.
Other plugins' overlays in the harness (`#puppy-panel`, `#yo-panel`, `.stopsign-banner`, the queue
bar) were hidden before capture.

The watermark is the coin silhouette from `src/icon-mask.svg`, filled white. It has no tile, so at
watermark scale it reads as the coin rather than as a grey box.
