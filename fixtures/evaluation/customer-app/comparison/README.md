# Comparison: does structured behavioural context improve verification?

Frozen before any model run. Do not change the cases, prompts, scoring
rules or configuration below after the first paid run; record deviations
in the results instead. The original pilot (`../pilot`, baseline `acff2de`)
and the evidence-fix regression check (`../pilot/results-evidence-fix`)
stay as they are.

## Question

Given the same code, the same check results and the same requirements,
does the pipeline's behaviour-aware review and evidence (plan, preservation
clauses, areas, evidence labels) detect more real problems, with fewer false
alarms, than tests alone or a generic AI reviewer?

## Design

**Two coding arms**, each run once per task: the pipeline (`eval:pipeline`)
and a plain coding agent given the request (`eval:plain`), as in the pilot.

**Snapshots.** Each arm's change as made, and that change with each of the
four sabotage patches added (`sabotage/`). Snapshots are frozen: every
verification condition judges the same code.

**Three verification conditions** per snapshot (`eval:conditions`). The
project's checks run once; every condition judges those same results.

| Condition | Gets | One model call? |
| --- | --- | --- |
| v1 Tests only | the check results | no |
| v2 Generic review | request, the project's requirements as written (every `.builder/` file), check results, diff | yes (`GenericReviewer`) |
| v3 Behaviour-aware review | request, the plan the pipeline derived for the task (acceptance criteria, preservation clauses), compiled project context, areas the diff touched, weakened tests, check results, diff; then the evidence labels | yes (`ChangeReviewer`) |

**Information parity.** v2 receives the original requirements the
pipeline's preservation clauses are derived from. Its blocking criteria are
the pipeline reviewer's, restated for unstructured input ("a change that
breaks a rule or behaviour in the project's requirements, unless the request
asks for that change"). The difference under test is structure, not access.
v3 on the plain arm's snapshots uses the pipeline's plan for the same task:
a plan comes from the request and the notes, not the code.

**Model configuration.** Every role (planner, coders, both reviewers) is a
fresh subagent on the same model, answering hand-offs (`responders.md`).
Each review condition gets exactly one call per snapshot with the same diff
limit (`builder.construction.limits.review_diff_characters`).

## Cases

Written by a separate agent that could not read the control plane, the
evidence code or the pilot (audited: clean). `CASES.md` explains each case;
`eval:validate` checks them mechanically (`validation.md` in the results).

| Task | Category |
| --- | --- |
| unique-team-names | natural-regression |
| personal-team-follows-name | natural-regression |
| owner-account-deletion | cross-feature |
| create-teams | ambiguous |
| transfer-ownership | authorized-change |
| members-rename-team | authorized-change |

Sabotage: two covered by the project's tests (s1, s2), two not (s3, s4).

## Ground truth

A snapshot is **defective** when any of the task's hidden tests or guard
tests fail, or a sabotage was added. Guards include behaviours the project's
own tests do not cover, so uncovered sabotage is defective by measurement,
not only by construction. Authorized-change tasks' hidden tests assert the
new rule: their clean snapshots must not be flagged.

## Scoring (`eval:compare`)

Per condition, separately:

1. **Changes as made:** caught, missed, false alarm, quiet; rejected
   authorized changes counted separately.
2. **Sabotage:** caught only when attributable: tests that newly fail
   compared with the change as made, a blocking finding naming a file the
   sabotage touched, the condition objecting where it did not on the change
   as made, or (v3) the sabotaged area newly flagged by the evidence.
3. **Evidence (v3):** flags on clean snapshots, flags in and outside the
   sabotaged area, and unrelated flags per genuine regression. Flags inside
   the sabotaged area are judged by hand.
4. **Cost:** tokens per role and per condition, from the orchestrator's
   ledger (`usage.jsonl`), not from agents' own accounts.
5. **Human intervention:** each pipeline run's end state and repairs, and
   the plain agent's status; plus every harness fix made during the run.
6. **Ambiguity** (`create-teams`): what each arm showed about the open
   question, judged by hand.

## Isolation

Every hidden file, reference solution and sabotage patch contains the
canary in `canary.txt`. Every coding and review subagent's transcript, and
every produced patch, is audited with `eval:audit` (canary and tool-call
paths). A contaminated run is reported, not silently dropped.

## Running it

In `.env`, point the harness at this suite (`BUILDER_EVAL_SUITE`), the
fixture (`BUILDER_EVAL_PROJECT`) and installed dependencies
(`BUILDER_EVAL_DEPENDENCIES`). Keep results outside the suite, for example
`BUILDER_EVAL_RESULTS=storage/app/evaluation/comparison`. Then, per task,
with `BUILDER_EVAL_HANDOFF` set on the command only:

```sh
php artisan eval:pipeline <task>
php artisan eval:plain <task>
php artisan eval:conditions <task>
php artisan eval:compare
```

`php artisan eval:dry-respond` answers every hand-off without a model (coders
apply the reference solution) to check the harness at no cost.
