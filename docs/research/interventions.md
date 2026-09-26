# Human interventions log

Each entry records one time a human had to steer the building agent, or the
agent had to correct itself after a check or a live run. It is the research
log from [direction 21](../architecture/direction/21-complexity-moves-upward.md)
§6 and [architecture §31.4](../architecture/architecture.md#314-measure-human-interventions).

**Reasons** (use one): missing context, wrong interpretation, ignored
constraint, missed effect, bad verification, architecture drift. Add a
reason only when none of these fits.

**Who caught it:** the owner, the agent (from its own checks or a live run), or
the platform (a builder run's own verification or review).

Keep entries short. The pattern across entries matters more than any one.

## 2026-09-26: V1 build (M1 to M3), one overnight Claude Code session

| #   | What happened                                                                                                                                                                          | Reason             | Who caught it                | What would have prevented it                                                                       |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | ---------------------------- | -------------------------------------------------------------------------------------------------- |
| 1   | The owner pointed the agent at `../livesound-event-planner` for their preferred architecture and UI style, mid-build. The agent had not looked for it.                                 | Missing context    | Owner                        | A project note naming the reference project and its `DESIGN.md`.                                   |
| 2   | The planner returned a brief with no steps, and the first live run failed.                                                                                                             | Bad verification   | Platform (schema validation) | The schema required steps from the start (`min(1)`); now it does.                                  |
| 3   | A builder run's coder wrote a Vitest test for a verify item. No check runs Vitest, yet the run reported the item as "tested".                                                          | Bad verification   | Agent (reading a live run)   | Evidence counts a test only when the suite runs it (`not_run_by_checks`); the coder is told where. |
| 4   | The same coder kept writing Vitest tests on repair after being told the test did not count, and needed two repairs.                                                                    | Ignored constraint | Agent (reading a live run)   | The suite paths in the coder's instructions (added). A structural check would be stronger.         |
| 5   | A visual edit set "Pill" corners on an element that had `rounded-t-lg`. The adapter did not know one-side rounding, so both classes stayed and fought.                                 | Missed effect      | Agent (live browser check)   | Reading one-side rounding as "mixed" and replacing it (fixed, with a test).                        |
| 6   | The owner sent product direction notes (17–21) during the build. They changed wording on existing pages and added page structure (for example the Understanding page's five headings). | New direction      | Owner                        | Not a failure: direction the agent could not have known. Recorded to separate it from corrections. |

**Interventions per kept change:** not measured for this session. The builder's
own runs (changes #3 and #4 on the fixture) needed no human steering once
started; entries 3 and 4 were found by reading their logs.
