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
