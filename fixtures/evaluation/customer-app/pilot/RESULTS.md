# Pilot results

Run on 2026-10-08 against the pre-registration in `README.md` (commit
`fc9ee31`, with the recorded refinements). Three tasks, two coding arms, one
presentation arm. All model work was done by Claude Code subagents on the
same model. Raw results, reports, patches and the usage ledger are in
`results/`; the owner bundles are in `results/bundles/`, with their key in
`results/bundle-key.json` (seed 2).

## Summary

| Outcome | Pipeline | Plain agent | Structured report (plain's evidence) |
| --- | --- | --- | --- |
| Hidden tests on the change as made | 15/15 passed | 15/15 passed | same as plain |
| Project checks on the change as made | all green, 3/3 | all green, 3/3 | same as plain |
| Repairs needed | 0 | n/a | n/a |
| Covered sabotage (s1) detected | 3/3 | 3/3 | 3/3 |
| Uncovered sabotage (s2) detected | **3/3** (reviewer) | 0/3 | 0/3 |
| Uncovered sabotage: area labelled "checked" anyway | **3/3 (overclaim)** | no claim made | "All checks passed" |
| Ambiguity (personal teams) surfaced | as a stated decision | mentioned in passing | mentioned in passing |
| Leaks (subagent touched hidden material) | 0 | 0 | 0 |

## What it shows

1. **No difference in the coding.** Both arms built all three features
   correctly on the first attempt. The plain agent handled the trap in
   `owner-only-removal` (two existing tests encode the old rule) as well as
   the pipeline did, and explained it in plain words. These tasks are too easy
   to separate the arms. The pipeline's context, brief and preserve clauses
   showed no measurable effect on correctness here.

2. **The independent reviewer is the pipeline's one clear win, and it is
   not unique.** No test covers the removed team-name limit (s2). The pipeline
   rejected it 3/3 with a blocking finding that named the defect, and tied it
   to the plan's "must stay the same" list. The plain arm cannot catch it,
   because nothing reviews its diff. But the catch came from a model reading
   the diff, which a plain agent plus any AI code-review step could also do.
   This pilot does not show the pipeline's *evidence system* adding value.

3. **The evidence labels overclaim, which is the pipeline's core promise.**
   In all three s2 cases the report said "Renaming a team keeps working —
   checked: tests for this area ran and passed" while its own reviewer
   rejected the change for weakening renaming. "Checked" means the area has
   some tests and the suite passed (`AssessPreservation`), not that the
   behaviour was tested. An owner reading the "Should stay the same" section
   would be reassured falsely. The plain and structured reports made no
   coverage claim at all, which is less helpful but not misleading.

4. **Ambiguity: neither asked.** Both arms chose the conventional answer
   (personal teams cannot be deleted). The pipeline listed it under
   "Decisions made for you" with a reason. The plain agent mentioned it inside
   a technical paragraph. Whether that visibility matters is for the owner
   sessions. The pipeline has no way to ask before building.

5. **Cost is not measurable from this run.** Each subagent carries about 50k
   tokens of fixed context, so token counts mostly count subagents. The
   coders used almost the same (pipeline 213k, plain 205k for three tasks).
   The pipeline adds a planner and a reviewer call per change, plus a full
   verification. Real per-change cost needs the API path.

## Product findings

- **Overclaiming "checked" labels** (finding 3). Smallest fix: never label an
  area "checked" when the review found a blocking problem or an unexpected
  change in it, and say "has tests that passed" rather than "checked".
- **"Checks: unverified" when everything passed** is confusing. The
  reviewers flagged it as a minor finding in six of nine reviews.
- **"Decisions made for you" mixes business decisions with implementation
  detail** (route files, policy classes). The personal-team decision sat
  next to "the route is added to routes/settings.php".

## Threats to validity

- Three tasks, all small, on one fixture written alongside the tasks.
- The tasks, hidden tests and sabotage were written by the same model family
  as the agents (before any run, and frozen).
- Subagents are Claude Code, not the product's SDK runner. The pipeline's
  planner and reviewer were called through hand-offs instead of the API.
- Every subagent received this repository's `AGENTS.md` automatically. It
  names the reference-solutions folder but holds no answers; equal for all
  arms. Tool-call audits found no access to hidden material.
- New features carried an identical technical contract note (route name) so
  the hidden tests could find them.
- Two harness defects were found and fixed during the pilot, before scoring
  was final: route helpers were generated before applying a change
  (rescored), and the pipeline's report listed setup steps the other reports
  did not (regenerated from stored evidence).

## Recommendation

Do not scale this task set to 15: it cannot separate the arms on coding,
and it already answers the verification question. Before a full run:

1. Fix the overclaiming labels. That is the pipeline's distinctive promise,
   and it currently fails.
2. Add an arm: the plain agent plus a generic AI review of its diff. Without
   it, the reviewer's win cannot be attributed to the pipeline.
3. Use harder tasks: several areas, long-lived requirements, changes that
   must legitimately rewrite existing tests, and more ambiguous requests.
4. Run the owner sessions (part B) now on `results/bundles/`. They do not
   depend on the above.
