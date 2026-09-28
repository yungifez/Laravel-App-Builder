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
  it is opening. The first build of "Studio Classes" hid everything behind
  the login, so the request also asks for the app's own front page. A new
  app is named after what the owner called it (`APP_NAME` in
  `.env.example`), not "Laravel".
- **Each app card shows a picture of the app.** The picture is the
  screen check's picture from the latest change that was kept or is
  waiting for the owner. It prefers the front page, at computer width. A
  change the owner turned down or undid is never used. Screen pictures
  are new, so older apps show their first letter until their next change.
- **Backwards compatibility is a switch the owner sees.** On "What I know",
  "Keep old information and links working" is off for an app started here
  that was never published. It is on for a published or imported app,
  because an imported app may already serve people elsewhere. The owner can
  set it either way, and "Decide for me" hands it back. When it is off, the
  agent changes things in place and keeps nothing for the old way. When it
  is on, the agent prefers a migration that carries data forward over
  keeping two ways side by side. Every change says which way it was built.
  Migrations are always added, never edited.
- **The new-app box takes pictures.** An owner can attach, drop or paste
  up to four sketches or screenshots with their first sentence. They go
  with the first version the same way pictures go with any change.
- **Payments and email are the first ready-made services.** The project
  menu has "Payments and email…". The owner picks one, pastes its keys and
  presses "Add to my app". The keys are stored encrypted and the app is
  changed to use them, as a normal change. I chose Stripe through Laravel
  Cashier, and Resend through Laravel's mail, because both have first-party
  Laravel support. Test and live Stripe keys are both accepted, because an
  owner needs live keys to take real money. Previews get the keys but always
  log email instead of sending it. Laravel Cloud gets the keys before each
  release. They are appended, so Cloud's own variables stay. The app's tests
  never get the keys. Stripe webhooks (for refunds and failed payments) are
  not set up yet. Nothing here has been tried with real keys.
- **The coding agent runs static analysis itself before it finishes.**
  The first build of "Studio Classes" took about 11 minutes. Two repair
  passes of 2 to 4 minutes each were only for static analysis errors (37,
  then 5), and the check takes 5 to 7 seconds. The agent was told not to
  run it, to save time. Now it runs the checks named in
  `BUILDER_CONSTRUCTION_SELF_CHECKS` (static analysis by default), and the
  rest still run afterwards as before. Not measured yet on a real build.
- **Templates are starting points the owner can see into.** The three
  example chips on the new-app page are now five starters, one JSON file
  each in `resources/starters` (`BUILDER_STARTERS_PATH`), so they can be
  changed without code. Picking one fills in the name and sentence, picks
  its look, and lists what the first version includes. The owner can untick
  any item. The kept items go into the first change's request, and the
  app's notes keep only the owner's sentence. Unlike Lovable's templates,
  these do not copy a finished app. They give a fuller first request, so
  the first version is still built, checked and proved like any change.
  The starters are Bright Cleaning, Studio Classes, Corner Shop, Care
  Clinic and Local Events.
- **A change waiting for the owner shows in the app pane.** Before, the
  pane kept showing the app without the change, and "Try it" opened a new
  tab. So a new app's owner saw the starter welcome page beside "your app
  is built". Now, while a change waits, the pane shows the app with it,
  marked "Not kept yet", with "Show it without". When its copy has stopped,
  "Show it with the change" starts it again. The design panel still shows
  the app itself. To let the copy show in a frame, every preview's cookie
  is now SameSite=None, Secure and partitioned, as the app's own preview
  already was. Browsers accept a Secure cookie on localhost. A preview
  domain served over plain HTTP elsewhere would need HTTPS.
- **The owner can sign in to their app inside the builder, and it opens
  where they left it.** The app's own cookies were SameSite=Lax, so the
  browser dropped them in the builder's frame, and sign-in failed without
  a message. The preview gateway now rewrites every cookie the app sets to
  SameSite=None, Secure and partitioned. It also lets the builder frame
  every preview, not only an editable one. The builder keeps the owner's
  last page for each app in this browser (local storage), and the preview
  link opens that page. Only a path on the preview host is accepted. The
  page is kept per browser, not per account, because it is a convenience.
- **The designer says when a change reaches more than the part picked.**
  The preview's stamping step now also marks a part drawn once per list
  item (`v-for`) and a part shown only at times (`v-if`, `v-else-if`,
  `v-else`, `v-show`, or on a `<template>` around it). The panel then says
  "One of 12 in a list on this page. A change here changes all 12.", and
  the preview outlines the others. A part shown at times says so. Only a
  condition on the part itself (or its `<template>`) counts, not one on a
  part around it, so a page wrapped in one `v-if` does not mark everything.
  When the owner clicks something the app's templates do not draw (a
  chart's canvas, a library's insides, HTML the app fills in), the panel
  says the app makes it as it runs: the part around it can be changed
  here, and the thing itself through a request. Editing one item of a list
  on its own is not offered: that needs a condition in the code, so it
  goes through a request. Only Vue templates are read; Blade views are not.
- 2026-09-27 — Words a part shows through one `{{ }}` are changed where
  they are written. A heading that shows `{{ title }}` gets its words from
  a component's attribute (`title="Settings"`), a page's script
  (`layout: { title: 'Log in' }`) or a translation call (`__('Save')`).
  The preview sends the page files it drew, nearest first. The words are
  changed in the first file that writes them exactly once as a whole
  quoted string. Words written twice in that file are refused, because the
  builder cannot tell which one shows. The browser tab's title (`<Head>`,
  `<title>`) is skipped, because it is never drawn on the page. Words with
  the quote, a backslash or a line break go through a request. Only `.vue`
  files are searched. Undo and redo swap the words in that file.
- 2026-09-27 — The builder shows the email the app on show sent, in an
  "Emails" tab beside "App". A preview already writes email to its log
  instead of sending it. The preview now pins that log to the `single`
  channel (`MAIL_LOG_CHANNEL=single`), which every new Laravel app has,
  and the builder reads `storage/logs/laravel.log` (`BUILDER_PREVIEW_LOG`).
  Only the last 2 MB of the log are read, and the newest 50 emails are
  shown. The builder looks for new email every 5 seconds while the app
  runs, and counts the ones the owner has not opened in this browser. An
  email shows in a frame without scripts. Its links to the app open in the
  app on show, and other links open in a new tab. An app that sets its own
  mail or log settings in code, not in the environment, may write email
  elsewhere, and the tab then stays empty. "Problems" and "Saved data" tabs
  are planned in the architecture (§15).
- 2026-09-27 — A problem in the "Problems" tab counts as fixed when the
  change asked for it is kept and the app has not run into it since. Kept
  means accepted and not reverted. The owner can also clear a problem, and
  show it again. A fixed or cleared problem that happens again returns as
  "came back", and asking again starts a new change. A problem is known by
  its kind of error, its message with numbers left out, and its place in
  the code, so a change of line number counts as a new problem.
- 2026-09-27 — The "Saved data" tab reads the app's tables by running
  `php artisan db:show --json --counts` in the preview's workspace, with the
  preview's settings. It runs only while the owner has the tab open, at
  most every 4 seconds. Tables every Laravel app may keep for itself
  (migrations, cache, jobs, sessions and similar) are folded away. "Start
  again with examples" runs `migrate:fresh --seed` and "Empty it" runs
  `migrate:fresh`. Both change only the preview's own database and ask
  again before they run. A signed-in owner may need to sign up again.
- 2026-09-27 — A table in "Saved data" opens to its newest 50 rows,
  newest by `id` or `created_at` when it has one. They are read by a fixed
  `php -r` script that boots the app; the table name is passed as an
  argument, and only names `db:show` listed are read. Values of columns
  named like password, token, secret, remember, two_factor or a key are
  hidden. Long values are cut to 200 characters.
