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
- **"Going online next" lists requests, not versions.** The publish panel
  lists the kept requests between the online version and the newest one,
  in the owner's words. It adds the requests undone since then ("Takes
  back: …") and a count of look changes made by hand. A request kept and
  undone in that window is left out, because it changes nothing online.
  Nothing is listed before the first publish, because then everything is
  new.
- **Decisions come from kept changes, with how-it-is-built choices hidden.**
  The Understanding page lists up to 12 decisions from the latest 30 kept
  changes. These are the owner's answers to questions and the plan's
  assumptions I made and they kept. An assumption that names code terms
  (migration, table, column, props, a backticked name, and similar) is
  treated as a developer choice and left out. This is a word list, so it
  can let some through or hide a real product choice. A decision that a
  later change replaced still shows, with its date, because it is history.
  "Change" opens the workspace with a request drafted from the decision
  ("Change this: "…" Instead, "), for the owner to finish and send. It
  is never sent for them.
- **The proof verdict rests on evidence lines, never a percentage.** Each
  change's proof opens with one verdict. "Checked, with gaps" shows when
  any gap is listed. "Well checked" needs a line showing the change itself
  was tried: separate checks, tests it added, app tests that ran its code,
  or its screens opened at three widths. Otherwise it is "Lightly checked".
  The app's own tests still passing counts as "nothing broke", not as
  evidence that the new behaviour works.
- **A change names the rules it had to keep.** The change proof lists the
  "must always be true" rules of each part the change's commit touched, read
  from the current project notes, not the notes at the time of the change.
  At most three rules show. They are never counted as evidence, because no
  check proves a rule held.
- **No separate checks means a gap, even when its own tests pass.** A change
  with no protected acceptance tests was shown as "Passed, but not proven"
  in the header and as "Well checked" in the proof. The proof now adds the
  gap "No check written apart from the change tried it", and the header
  reads "Passed, with gaps". Tests written with the change still show as
  evidence lines, but they cannot hide that gap.
- **One decisions list, not two.** The notes' "Decisions" section (every
  owner answer, written when given) no longer shows as its own section on
  "What I know". Its answers join the Decisions list. An answer that also
  came from a kept change shows once, with that change. Answers only the
  notes hold follow, marked "You chose this" with no link. A noted answer is
  split into question and answer at its last "? ".
- **"Simplify this" asks, it does not act.** Each part of the app that
  does more than one thing gets a "Simplify this" link on "What I know"
  (direction 18 §13). It fills in the request box with "Show me the
  simplest version of <part>, with fewer choices for people to make. Ask
  me before you remove anything." The owner edits or sends it. Like any
  change, it waits for them to keep it. There is no separate simplify
  mode yet.
- **App cards count changes not online yet.** A live app's card on "Your
  apps" says "N not online yet". N is the requests kept or undone since the
  online version, plus the hand edits since then. It is the same count as
  the publish panel's "Going online next" list. Each card costs one git
  rev-list; fine for a handful of apps.
- **A question says why it is asked.** When the planner marks a question
  as hard to change later (`reversible: false`), the chat's question card
  adds "This is hard to change later, so I am asking you." Easy choices are
  already made without asking (direction 18 §6); this makes that visible.
  Untagged questions (older runs) show no line. The change details page
  shows the same line.
- **What changed while you were away (direction 18 §9).** The page that
  explains the app remembers when the owner last opened it (one timestamp on
  the project, since a project has one owner). On the next visit, "What
  changed" says how many changes were kept since then and marks each one
  "New". A first visit marks nothing. Undone changes do not count. Opening
  the page is the "last checked" moment; the app workspace does not move it.
- **Goal-aware changes (direction 18 §4).** The owner writes their app's main
  goal on the page that explains it ("Goal", a section of the project notes,
  shown under what the app is for). The planner then says, in one sentence,
  how each change serves that goal. The change card and change page show
  that sentence with a target icon. No goal, or a change that does not bear
  on it, shows nothing: the planner is told never to stretch a change to fit.
  Follow-up ideas prefer ones that serve the goal. Goal-driven suggestions
  that the owner did not ask for are a later step.
- **Warn before publishing changes to stored data (SDLC gap: data safety).**
  When a change waiting to go online adds a database migration whose `up()`
  deletes, renames, reshapes (`->change()`) or rewrites stored data, the
  publish panel lists that change first with a plain warning, such as
  "Deletes information your app already keeps". A migration is recognised
  by `extends Migration`, not by its folder. `down()` is ignored, because
  it only runs on undo. It is a pattern match, not a proof: raw SQL in a
  `DB::statement` counts as a rewrite. It warns and never blocks. Backups
  and going back to the previous live version are later steps; they need
  per-host research.
- **Package security lookups are advice (SDLC gap: security).** Each
  verification looks up known security problems in the app's PHP and
  JavaScript packages (`composer audit`, `npm audit`), counting only high
  and critical ones. The result is read from each tool's JSON report, not
  its exit code, since a lookup that cannot reach the network also exits
  non-zero. A lookup that could not run says nothing. It never fails a
  change: a package problem is rarely the change's doing. The proof says
  either "No known security problems in the packages your app uses." or
  "Some packages your app uses have known security problems. Ask me to
  update them." The result is not listed among the checks.
  `BUILDER_SECURITY_AUDIT=false` turns it off.
- **A new app builds its first version at once.** You approved this cost
  on 2026-09-27. The owner's sentence becomes the first change: "Make the
  first version: …". It goes through the normal plan, build, checks, review
  and keep steps, so nothing is kept without the owner. Each new app spends
  model calls when it is made. The owner goes straight to that change's
  conversation instead of the template's welcome page.
  `BUILDER_FIRST_VERSION=false` turns it off. The new-app page also shows
  three example sentences to start from, and "Open my app" now shows that
  it is opening.
