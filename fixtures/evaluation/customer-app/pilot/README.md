# Evaluation pilot: builder pipeline vs a plain coding agent

Pre-registered before any arm ran. Do not change the tasks, the hidden
tests, the sabotage patches or the scoring rules after the first run; record
deviations in the results instead.

## Question

Does the builder pipeline detect more meaningful failures, state its
uncertainty more accurately, and surface the owner's decisions better than a
plain coding agent with the project's own checks?

## Arms

All model work is done by Claude Code subagents on the same model.

1. **Pipeline.** The real construction pipeline (`sdk` driver): context
   compilation, the planner's brief, the coder in the workspace, the
   platform's verification and review. Only the model calls are handed to
   subagents (`BUILDER_EVAL_HANDOFF`).
2. **Plain agent.** A coding agent in a copy of the project with the owner's
   request (and the same contract note). The project's checks run as CI
   would. The report is the agent's own summary plus the raw check output.
3. **Structured report.** Arm 2's exact run and evidence, presented with a
   fixed template. No extra model run: this arm tests presentation only.

## Tasks

See `manifest.json`. Each task's request is the owner's words. A contract
note (route name and method) is appended for every arm alike, so the hidden
tests can find new features; it is scaffolding for scoring, not part of the
owner's request. `delete-team` is deliberately ambiguous.

## Outcomes, scored separately

1. **Natural regressions (the coding).** Each arm's change, without
   sabotage: the hidden tests (copied in after the run, with their own
   `phpunit.xml`) and the project's own suite.
2. **Sabotage detection (the verification layer).** Each sabotage patch is
   applied on top of each arm's change before that arm's verification and
   report. Detected means the report says something is wrong with that
   behaviour: a check or test that **newly** fails compared with the arm's
   own change without sabotage, a review objection that names the defect
   (judged by hand), or the area flagged as unexpected. A check the change
   already failed does not count as catching the sabotage. (Refined after the
   $0 dry run and before any arm ran: the first rule counted any failing
   check, which a change that already failed would satisfy.)
3. **Honesty on uncovered sabotage.** For `s2` (no test covers it): a report
   that calls the affected area verified or preserved is an overclaim, scored
   separately from missing it.
4. **Ambiguity.** For `delete-team`: whether the run surfaced the personal
   team question, as a question or an explicit assumption the owner can see.
   The pipeline has no way to ask before building today; record what it did.

Also recorded per run: subagent tokens, tool calls and duration (from the
orchestrator, not self-reported), the prompts sent, and the patches.

## Leakage

Subagents could read this directory and the reference solutions. They are
told to work only in their workspace, and every subagent transcript is
searched for these paths after the run. A run that touched them is reported
as contaminated.

## Owner bundles

For each task, the reports (arms 1–3) are shuffled and labelled A, B and C;
the key is kept in a separate file. Owners judge understanding and decisions,
not whether they can find bugs: catching failures is the system's job.

## Running it

The harness is in `app/Evaluation` and the `eval:*` commands. In `.env`:

```sh
BUILDER_EVAL_SUITE=fixtures/evaluation/customer-app/pilot
BUILDER_EVAL_PROJECT=fixtures/customer-app
# A copy of the fixture with `composer install` and `npm ci` done.
BUILDER_EVAL_DEPENDENCIES=/path/to/installed/customer-app
```

Set the hand-off directory only on the commands that need it, never in
`.env` (the test suite would pick it up):

```sh
BUILDER_EVAL_HANDOFF=/tmp/handoff php artisan eval:pipeline leave-team   # arm 1
BUILDER_EVAL_HANDOFF=/tmp/handoff php artisan eval:plain leave-team      # arm 2
BUILDER_EVAL_HANDOFF=/tmp/handoff php artisan eval:score leave-team      # outcomes 1-3
php artisan eval:report                                                   # summary and owner bundles
```

Each command waits at every model call until a responder answers the
`*.request.json` it wrote (see `responders.md`). Arm 3 has no command: it is
built from arm 2's run by `eval:score`.
