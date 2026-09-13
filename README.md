# M&T Academy

The in-house training academy of **M&T Development**, run in association with
Centenary Networks. The sixth academy on the platform, after SPS, Fungi, Maziv,
Equinix and Tracker.

Stood up on **12 September 2026**. Built and tested locally; **not yet live** — see
[Standing it up](#standing-it-up) for the steps that need credentials and a decision.

---

## What this is

The same application as every other academy. The back end — accounts, enrolment,
material, reading, quizzes, the trainer role, in-person registers — is shared, and
**SPS is the source of truth**. Every shared file here is written by
`tools/sync-backend.php` in the SPS repository, which overwrites without asking.

So: never fix a shared `.php` or `.js` here. Fix it in SPS and sync it out. See
`DEPLOY-XNEELO.md` for which files are genuinely this site's own.

## Where it came from

Cloned from **Equinix**, deliberately. Equinix's nav is a small square logo with a
wordmark beside it, which is exactly the shape of M&T's mark. Everything that was
Equinix's was then taken out, and the way it was done is worth knowing, because a
plain find-and-replace would have produced a site that told lies about M&T:

- **Facts about Equinix's business were rewritten, not renamed.** Equinix describes
  itself as a digital-infrastructure company. Renaming would have had M&T training
  "data-centre technicians" and growing into "an AI-first infrastructure business".
  Those lines were rewritten from what M&T Development says of itself: residential
  sales and rentals, commercial offices, warehouses, retail centres and storage.
- **No named leader.** Every other academy's homepage carries a named chief executive
  with a photograph. Equinix's named Equinix's real CEO; renaming would have captioned
  her as M&T's. M&T's block has no name, no title, no photograph and nothing in the
  first person until M&T supply approved words. (The Tracker site was cloned from
  Equinix the same way and *does* still caption Equinix's CEO as Tracker's. Not yet
  live, but it needs fixing before it is.)
- **Hard-coded colours.** Equinix's red and purple were written as hex in ~150 places
  in the course-card artwork, not as CSS variables. Every colour proved to be
  Equinix's own was mapped to M&T's by role; colours shared with SPS (the danger red,
  the generic course-art gradients) were left alone.
- **`&` is escaped differently by context.** In markup it is `M&amp;T`. Inside inline
  scripts it is a literal `M&T`, because those strings reach the page through
  `textContent`, `document.title` or an `ESC()` that escapes `&` itself — `&amp;` there
  would be shown to the reader. In the calendar export (`pm-schedule.js`) it is literal
  too, and the event-ID host has no `&` at all.

## Brand

Sampled from M&T's own material in `_brand-source/`, not guessed.

| Token | Value | Where it came from |
|---|---|---|
| `--orange` (primary) | `#3e4096` | The logo tile, identical at every corner |
| `--orange-deep` | `#2f3194` | The "AI Assistant" badge on M&T's site |
| `--green` (secondary) | `#1f8a70` | Chosen — see below |
| `--dark` | `#1d1e3b` | Deep indigo for footer and dark sections |
| `--header` | `#eceef9` | Pale indigo tint behind the nav |

**Why the secondary is not indigo.** M&T's brand is one hue. But `--green` is not only
decoration: it marks a *passed* quiz, a *done* module and *present* on the in-person
register — and the register marks *late* with `--orange-deep`. With both ramps indigo a
facilitator could not tell present from late at a glance. The teal-green keeps "done"
reading as done; present and late fills measure 2.48:1 against each other.

Every text pairing meets WCAG AA. The tightest is soft ink on `--bg-soft`, 5.87:1.

**Logo:** `mt-logo.webp`, M&T's own file, unaltered. It is a 90px raster — enough for the
34px nav — and was not redrawn as SVG, because an approximation of somebody's trademark
is worse than a small copy of the real one. Ask M&T for a vector copy before it is ever
printed or shown large.

## What is placeholder

Must be supplied before the site is announced:

- [ ] **Phone number** — `lib/brand.php` carries the dummy `012 345 6789`.
- [ ] **Registered company name** — `lib/brand.php` says "M&T Development"; the legal
      entity is needed for the copyright line and the privacy notice.
- [ ] **Employee number format** — the form's example `MT1234` is invented.
- [ ] **Leadership message** — see above. Approved words, a name and a photograph.
- [ ] **Hero photograph** — the same stock image every academy uses, which Kgomotso has
      already asked to replace on all of them with one showing black, white, Indian and
      coloured people.
- [ ] **About-page photographs** — `images/poster-1.svg` and `poster-2.svg` are placeholders.
- [ ] **Mentorship copy** — the about page promises "mentorship with senior leaders and
      technical experts". That is the house wording on every client academy; M&T should
      confirm it is true for them.

## Standing it up

Step 1 is done; each step after it needs a credential or a decision.

1. **GitHub repository** — done 13 Sep 2026: <https://github.com/sibusis-code/m-t>, branch
   `xneelo-backend` (its default; there is no `main` — see the cutover note in `DEPLOY-XNEELO.md`).
2. **Repository secrets** — `MT_FTP_SERVER`, `MT_FTP_USERNAME`, `MT_FTP_PASSWORD`.
3. **Server folder** — `public_html/mtacademy` on Xneelo.
4. **Configuration** — `~/private/mtacademy-config.php`, with `'tenant' => 'mt'`. That one
   line is the entire separation between M&T's learners and every other company's.
5. **Deploy**, dry run first — GitHub → Actions → *Deploy M&T Academy*.
6. **Migrate** — fresh `setup_token`, open `/mtacademy/setup`, run it, empty the token.
7. **Check** — follow *Check before telling anyone* in `DEPLOY-XNEELO.md`.

The `mt` tenant is seeded by `install_seed_tenants()` in the shared `lib/install.php`,
so the migration creates it; there is no separate data step.
