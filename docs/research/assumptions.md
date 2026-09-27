# Assumptions made while building

This log lists the assumptions the builder agent made without asking the
owner. Each entry says what was assumed, why, and how to undo it. Review
them and correct any that are wrong.

## 2026-09-27

- **Asked-for list reads the app's code, not the test map.** A test counts
  as "still there" when its name appears as a whole word in the app's test
  files (`tests/`, `*.test.*`, `*.spec.*`) at the branch head. A test that
  was renamed but still checks the same thing shows as "changed or gone".
  Why: the last test map can predate a kept change, and a false "nothing
  checks this" is worse than a cautious "changed or gone". Undo: in
  `DescribeAskedFor`, use the test map instead.
- **Made-up colours block a change.** Colours written out in screen files
  (hex, `rgb()`, `oklch()` and similar) send the change back to the coder,
  like the safety scan does. Why: direction 26 lists "token use instead of
  invented values" as structurally inferable, and colour is the clearest
  case. Undo: set `BUILDER_DESIGN_SCAN=false`, or make it a non-blocking
  note.
- **Only colours, not spacing or sizes.** Arbitrary spacing such as
  `p-[17px]` is not flagged. Why: Tailwind v4 makes any multiple of the
  spacing unit a theme utility, and the architecture already accepts
  `w-[73%]`. A spacing rule needs the app's own scale first.
- **The escape comment must mention the colour.** A comment on the line or
  the line above that contains "colour", "color" or "brand" lets a colour
  through. Why: it gives a reason a person can read, like the safety scan's
  "safe" comment. Undo: change `REASON_COMMENT` in `InventedColours`.
- **The 5-hour loop runs every 4 hours.** Cron cannot repeat every 5 hours
  evenly, so the loop was rounded to every 4 hours.
- **Pictures without alt block a change.** An `<img>` a change adds to a
  screen needs `alt`, or `alt=""` for decoration. Why: direction 26 lists
  accessibility, and this is the part structure alone can decide. It shares
  the `BUILDER_DESIGN_SCAN` switch with the colour check, so one switch
  turns off all screen checks. Undo: give it its own setting, or make it a
  note.
- **Unknown pictures pass.** Tags with spread attributes (`v-bind="…"`,
  `{...props}`) or tags that run into unchanged lines are not flagged. Why:
  the description may be there, and a false send-back wastes a coding
  round.
- **Proof passes fold after the first.** "How I know it works" shows the
  first pass, then "N more checks passed" (folded), then problems caught,
  test reach and gaps. Why: eight lines on a typical change broke the
  low-text rule, and the gaps and catches are what the owner must not
  miss. Undo: in `ChangeProof.vue`, show `passes` in full.
- **Pictures with requests, up to 4, each up to 5 MB.** PNG, JPEG, WebP and
  GIF only. Why: this matches what Lovable accepts, and SVG can hold code.
  Undo: `BUILDER_REQUEST_IMAGES_MAX` and `BUILDER_REQUEST_IMAGE_MAX_KB`.
- **The planner and reviewer see the pictures too** (changed the same day).
  They get them as image attachments, which costs a little more per plan
  and review. Why: a plan that cannot see "make it look like this" misses
  what the owner meant.
- **Pictures stay inside `.git/attachments` in the workspace.** Why: they
  must never become part of the owner's code, and `.git` already holds the
  agent's task file and the change's diff.
- **Kept pictures are never deleted.** A request that is refused after the
  pictures were saved (for example, a follow-up on a change that cannot be
  built on) leaves them on disk. Why: rare, and small. Clean-up can come
  with the workspace clean-up work.
- **Screens are opened at 390, 820 and 1280 px after the checks pass**
  (2026-09-27). The box image carries Chromium, and the local `runner`
  service now runs that image. Why: direction 26 lists "no sideways
  overflow at 390, 820 and 1280 px" as a browser check on touched screens.
  Measuring the app's real pages is the only honest way to say it fits a
  phone. Cost: about 40 s per change that touches a screen, and a bigger
  box image. Undo: `BUILDER_SCREEN_CHECK=false`.
- **Tap targets use WCAG 2.2 AA (24 px), not 44 px.** A smaller control
  passes when nothing else is within its 24 px circle, and a link in a line
  of text passes. Why: at 44 px, every starter-kit text link failed. That
  would send back almost every change for problems it did not make. The
  starter kit passes at 24 px with spacing. Undo: `MIN_TARGET` in
  `resources/screen-check/check.mjs`.
- **Only the screens a change touched are blamed.** A page counts when its
  Inertia page component matches a `pages/…` file in the change. Blade
  pages, shared components and layouts are measured but never send a change
  back. Why: a problem the app already had elsewhere must not block a
  change. Undo: compare against a measurement of the app before the change
  (costs a second build).
- **Which pages:** every GET route without parameters, up to 15, home
  first. Routes that log out, verify, confirm, export or download, and API
  routes, are skipped. Why: visiting them could change the app or they are
  not screens. A page with parameters (`/teams/{team}`, up to 5 routes) is
  opened through the first link to it that another measured page shows.
- **Signing in:** the app's seeder runs first (`db:seed`, and a failure is
  ignored). The check then gives the app's first user a random password, or
  makes a user when there is none, through `App\Models\User`, in the
  workspace database only. It signs in through the app's own `/login` form.
  Why: the seeded user owns the seeded records, so pages such as a team
  page show real content instead of "not found". When the app has no such
  model or form, only the pages open to guests are measured.
- **The app is served with SQLite.** The command touches
  `database/database.sqlite` and runs migrations, matching the Laravel
  default `.env.example`. An app that needs another database is not
  measured, and nothing is said. Undo: change
  `builder.verification.screens.command`.
- **Faint words are a gap, not a send-back.** The screen check measures
  contrast (WCAG 2.2 AA) at 390 px. Words on a touched screen that fall
  short show in the proof as "Some words on it are hard to read…". Why: on
  the starter kit, the only failures were theme colours (white on red-500
  at 3.76 to 1, muted tab text at 4.35 to 1). A change to one screen should
  not rewrite the theme. Undo: return faint words from `ScreenCheck::found`.
- **Hidden keyboard focus is a gap, not a send-back.** At 1280 px the check
  presses Tab through the first 20 controls. A control whose outline, ring,
  border, background, colour and underline do not change on focus is named
  in the proof. Why: focus styles live in shared components, and the
  starter kit passes. Undo: return them from `ScreenCheck::found`.
- **"What I know" adds up kept proof.** The overview line now adds "N tests
  added" (test functions in kept, not undone changes) and "N screens checked
  on a phone" (touched screens the latest passing verification found to
  fit). Why: the owner should see what the app has gained over time, not
  only per change. A test later renamed or removed still counts; the
  per-part list already says when a test has changed since.
- **Pictures of changed screens are kept and shown.** Up to 3 touched
  screens get a JPEG at each width (about 30 to 90 KB each), kept on the
  `local` disk under `screen-shots/{verification}`. The proof shows the
  first screen's three pictures above the fold. Why: owners believe what
  they see, and seeing their screen on a phone is the clearest proof that
  it was checked there. Old pictures are never deleted yet; clean-up can
  come with workspace clean-up. Undo: `BUILDER_SCREEN_SHOTS_MAX=0`.
- **"Why is this here?" follows the line's git history.** When the owner
  selects a part, `git log -L` follows its line back through later edits
  and moves. If a kept request made the line, the panel says "Added when
  you asked …". If the line came with the app, it names the latest kept
  request that changed it ("Changed when you asked …"). Undone requests
  and hand edits are skipped. Only the part's opening line is followed, so
  a request that changed only its inner words is not named. Why: git
  already holds the answer, with no model call and no new table.
- **"What happens if I change this?" uses the notes' Effects.** When the
  owner opens "Ask me to change it" on a selected part, the panel names
  the other areas that the part's area Effects point to. It says "I check
  those too" when each one has tests, since every change runs the whole
  suite. Otherwise it says "Nothing checks … yet". All Effect strengths are
  shown. Hand edits to a part's look do not show it, because a colour or
  spacing change does not reach other areas.
