# Laravel Vibe Platform: Actionable Implementation Plan

**Revision:** 4, September 23, 2026; bounded prototype scope and first coding handoff  
**Status:** Build specification; no platform implementation or benchmark is claimed complete.  
**Architecture:** [laravel-vibe-platform-architecture.md](laravel-vibe-platform-architecture.md), especially section 27.

## 0. First build commitment

The first implementation commitment is one working internal prototype. The broader gates below remain the roadmap; they are not all prerequisites for demonstrating the core interaction. This section governs immediate scope. It does not waive security requirements or authorize production actions, paid infrastructure, external account connections or customer recruitment.

**Demonstration:** From the clean starter, request team invitations; see the feature built and tested; select its Invite button or permission summary; ask for owner-only access; observe the working change and its verification evidence; inspect a release-readiness summary.

### What to build now

| Increment             | Concrete output                                                                                               | Completion evidence                                                                                 |
| --------------------- | ------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Foundation and proofs | Pinned Laravel/Vue template, separate reference solution, one isolated workspace and a source-selection proof | Reproducible setup; source mapping and workspace restrictions exercised                             |
| Construction loop     | Saved plan revision, bounded agent tool loop, source checkpoints and embedded preview                         | Invitations are generated from the starter; fresh protected acceptance checks pass                  |
| Scoped change         | Minimal route/component/policy index and a selectable permission summary                                      | Owner-only request changes both server enforcement and UI; member/admin/other-tenant denial checked |
| Evidence and handoff  | Before/after summary, test findings and release-readiness manifest                                            | User can explain who may invite and distinguish verified behavior from unresolved checks            |

Use one local development workspace provider, one model provider and one fixture. Build only the durable run/operation records and trust boundaries needed for this loop. Full internal billing, organization management UI, broad package certification and automated provisioning are not needed for the demonstration.

The initial backend view can say “Owners and administrators can invite members,” with selection and an optional technical expansion. Graph browsing is not required. Index known route, controller, request, policy, action and Vue relationships; let the agent inspect bounded source when relationships are missing. Mark uncertainty rather than generating a comprehensive-looking but unsupported map.

### What waits for evidence

Defer exhaustive PHP call graphs, broad framework tracing, TIA integration, drag-and-drop, multiple workspace providers, public onboarding, billing subscriptions, mobile, repository imports and the freelancer marketplace. Laravel Cloud remains the deployment destination. For this prototype, produce a real readiness manifest and perform a separate provider capability proof when authorized; automated production deployment is not a requirement for testing the selection workflow. A readiness report is not a deployment.

Retain isolated execution, server-side tool authorization, cancellation, source-version checks and independent acceptance verification. These protect the prototype's operation and cannot be replaced with a model's assurance. Add the broader restoration, tenancy and provider gates before external alpha as specified below.

### Exit and next investment decision

Complete the demonstration from a clean starter, including a denied cross-tenant request and a deliberately failed test that prevents a verified status. Record exact commands, snapshots, model cost, repair attempts and intervention. No hand-edited invitation implementation may be substituted for agent generation during this demonstration.

Then run the comparative pilot in section 12, under the applicable access and isolation conditions. Expand only the parts that improve task completion, comprehension or verified cost. If users prefer plain prompting, retain source-grounded context internally and simplify the interface. If generation works but owners cannot understand changes, improve the summaries before adding compiler coverage.

Immediate execution follows G0, narrow P1/P2/P3 proofs, the minimum G2/G3 loop and the narrow G4 selection flow. Other work orders remain conditional. No platform code has been implemented merely by agreeing to this scope.

First coding assignment: `G0-1-foundation-work-order.md`. It bootstraps and verifies the local control plane; it does not implement the full prototype in one task.

## 1. What changed

The prior plan described 13 broad packages (WP-000 through WP-012), but postponed feasibility testing, mixed deployment states with agent states, and relied on unspecified isolation and verification guarantees. This revision replaces its calendar and execution order. Original WP identifiers are retained as scope references, not the new build sequence.

The product decisions remain: Laravel; Inertia/Vue/Tailwind first; Shadcn Vue directly; services and actions with flexible file paths; optional human help; no mandatory customer plan approval; protected production actions require confirmation; Laravel Cloud for finished apps; mobile and repository import later.

The architecture now provides durable run recovery, isolated code execution, versioned evidence, and release approval tied to source and configuration. No execution model is allowed to bypass these contracts.

## 2. Outcomes and release boundaries

Build three demonstrable outcomes in order:

1. **Internal construction loop:** A request becomes a saved plan, a source change in an isolated workspace, a live preview and a fresh verification report.
2. **Differentiated internal alpha:** Selecting a UI element or humanized backend step gives the agent the right files, policies, tests and acceptance criteria for an edit.
3. **External private alpha:** The same loop works across isolated customers with metered usage, recovery and confirmed deployment.

A selection prototype happens before substantial orchestration work. Full visual dragging follows reliable mapping. Laravel breadth is the long-term scope; reference fixtures define what we have actually tested, not what users may ask for.

External users are not admitted on the strength of a local Docker demo. Tenancy, sandbox conformance, protected verification and restoration tests are entry gates.

## 3. Platform shape and decisions

| Area                 | Initial implementation                                                                                         |
| -------------------- | -------------------------------------------------------------------------------------------------------------- |
| Control plane        | Laravel modular monolith, Inertia/Vue UI; web and queue-worker deployments share code                          |
| Durable storage      | PostgreSQL for plans, events, operations, graph, approvals and usage                                           |
| Coordination         | Redis for queues/cache; transactional outbox and DB state survive lost notifications                           |
| Artifacts and source | Object storage with hashes; separate Git repository for each generated app                                     |
| Runtime              | Private gateway plus TypeScript executor; local Docker adapter, managed isolated compute before external alpha |
| Preview              | Separate proxy and registrable domain; session-scoped access; registered workspace ports only                  |
| Analysis             | PHP/Laravel adapter, TypeScript Vue/Vite adapter; sandboxed execution; PostgreSQL graph                        |
| Verification         | Fresh isolated checkout, protected runner and platform-owned acceptance harness                                |
| Model interaction    | One adapter initially; frontier planner, economical coder, independently constructed review context            |
| Deployment           | Laravel Cloud adapter behind capability discovery and a manifest approval workflow                             |

Keep the gateway provider adapter near the Laravel orchestration module where practical. A separate gateway process is justified by private networking and runtime permissions, not a desire for more services.

Use a platform monorepo with logical areas for control plane, executor, contracts, analyzers and test fixtures. These are not folder constraints for customer apps. Add a package only when there is executable behavior or a real contract to put in it. No empty service forest, dedicated graph database, custom hypervisor or orchestration engine.

Choose compatible dependency versions in the first foundation task, record them in lockfiles and an environment manifest, and use the same toolchain image in CI and workers. Avoid floating “latest” dependencies in work orders. Vendor selection remains an integration decision with a tested fallback.

## 4. State and contracts

The architecture document section 27 is normative for the following boundaries.

| Contract          | Minimum executable deliverable                                                                                                        |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Run events        | JSON Schema and fixtures; per-run sequence, plan/snapshot identity, valid transitions and replay cursor                               |
| Tool operations   | Request/result schemas; operation ID, payload hash, fencing token, expected workspace revision, deadlines, status/cancel              |
| Preview selection | Session, origin, source definition, rendered instance, snapshot and mapping confidence                                                |
| Verification      | Candidate identity, toolchain/policy identity, pass/fail/error/skipped/not-applicable, artifacts and actual execution vs cache replay |
| Application IR    | Typed nodes/edges, producer and evidence references, versioned source locations, completeness and invalidation                        |
| Release           | Manifest digest, destination/config/migration identities, verification attestation, expiring scoped approval and capability flags     |

Do not finalize every future IR node upfront. Stabilize only the fields required by the next consumer; add backward-compatible fields with fixtures. Unknown major schema versions fail explicitly.

Run states: queued → planning → implementing → verifying → reviewing → completed, with transitions to needs_user_decision, cancelling/cancelled or failed. Coding repair returns to implementing. Deployment is a separate workflow after candidate verification.

One writer per project branch in alpha. Lease expiry produces a new fencing token. Duplicate queue delivery claims existing work; unknown operation outcomes trigger reconciliation. No implementation task may substitute blind retries for this behavior.

Current source, analyzed source, verified candidate and production release are separate pointers.

## 5. Delivery gates and dependencies

Each row is a gate containing several small work orders. A single Luna assignment should normally be one independently verifiable increment within a gate.

| Gate                                | Depends on                                       | Accountable owner                    | Concrete demonstration                                                                      |
| ----------------------------------- | ------------------------------------------------ | ------------------------------------ | ------------------------------------------------------------------------------------------- |
| G0: Foundation and fixtures         | None                                             | Platform lead                        | Clean starter, separate invitation reference solution, pinned toolchain and runnable checks |
| G1: Feasibility proofs              | G0                                               | Runtime + frontend + analysis owners | Isolated command, source selection, route/trace mapping, provider capability report         |
| G2: Deterministic construction loop | G1 runtime proof                                 | Control-plane + runtime owners       | Scripted change travels through durable run, workspace, preview and verification            |
| G3: Model-driven construction       | G2                                               | Orchestration owner                  | Planner/coder/reviewer produce a tested feature through the same tools                      |
| G4: Graph-scoped editing            | G1 mapping proofs + G3                           | Analysis + frontend owners           | Selection → grounded context → authorization edit → updated evidence                        |
| G5: Release and external alpha      | G3 + provider proof; G4 for differentiated alpha | Platform + deployment owners         | Approved revision deployment, cross-tenant isolation, recovery and usage accounting         |
| G6: Deterministic visual edits      | G4                                               | Frontend tooling owner               | Supported Tailwind edits survive source build, browser checks and undo                      |

G1 proofs may proceed independently under fixed contracts. G2–G4 form the main integration path. Deployment automation is not a prerequisite to proving click-to-context. No unconditional 13-week promise: estimate after G1 exposes actual provider and source-mapping constraints.

## 6. Gate work orders

### G0: Foundation and fixtures

**G0.1 Environment baseline.** Inspect any existing repo and instructions; bootstrap only missing pieces. Pin PHP/Laravel, Node, Vue/Inertia, test runner and package manager in lockfiles/image configuration. Add setup, lint, type-check, test and build commands. Record exact commands in README; a second clean checkout must pass without production credentials.

**G0.2 Fixture split.** Create:

- A clean starter with authentication, teams and roles, but no invitations.
- A separate reference solution with the invitation behavior below.
- A platform-owned external acceptance suite, outside the agent-writable source.

**G0.3 Contract seed.** Implement schemas, positive and negative fixtures for tool operations and snapshot identity. Add the first valid/invalid transition tests. Do not create all future tables or UI screens.

Acceptance: clean bootstrap and fixture tests pass; the acceptance suite fails on the starter's missing feature and passes on the reference solution. This prevents a prebuilt feature from being counted as successful generation.

### G1: Time-boxed proofs of difficult assumptions

Each proof has a one-to-two developer-day investigation budget as a planning estimate. It ends with working evidence, a stated limitation, or a proposed scope adjustment. Hitting the budget never makes a failed proof pass.

| Proof               | Required evidence                                                                                                                                    | Fallback if unsupported                                                              |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| P1 Runtime          | Launch PHP/Node, run tests, stream output, kill process group, destroy workspace; access-denial tests for host/other project/control-plane endpoints | Local-only internal prototype until a managed provider passes conformance            |
| P2 Source selection | Map a button, nested Shadcn slot, repeated row and conditional/teleported component; move a file and reject ambiguous stale selections               | Component-level context and agent edits; advertise finer precision only where proved |
| P3 Laravel evidence | Route/controller/request/policy mapping plus success, denial and validation traces; a dispatched fake is labeled differently from a worker execution | Static links plus explicit gaps; do not invent universal hooks                       |
| P4 Cloud release    | Document revision targeting, push-trigger control, migrations, credentials, logs, failed release recovery and unknown-response reconciliation        | Operator-assisted approved deployment; no claim of immutable artifact promotion      |
| P5 Pest integration | Pin compatible Pest/coverage versions; distinguish executed/replayed tests; establish whether a stable graph export exists                           | Full-suite verification and our own trace/impact data; TIA is optional               |

Deliverable for each: small runnable fixture, exact reproduction command, expected output, capability matrix and architecture decision. No paid provisioning or external connection without the user's applicable confirmation. Mock interfaces can be built meanwhile; provider proof remains pending.

### G2: Deterministic construction loop

**G2.1 Records and writer ownership.** Projects, tenant membership/policies, plan revisions, snapshots, runs/steps, operation journal and run events. Add one-writer acquisition with fencing and conflict responses.

**G2.2 Durable delivery.** Transactional outbox, queue dispatcher, state guards and status reconciler. Simulate crashes after state commit, after remote command start and before result persistence. Duplicate delivery must not repeat a mutation.

**G2.3 Workspace lifecycle.** Provision from snapshot; use scoped grants; implement read/search, hash-checked patch, process start/status/cancel and checkpoints. Artifact upload credentials are scoped to assigned objects. Revoke sessions and grants on destruction. Reconcile orphaned workspaces.

**G2.4 Preview.** Proxy the registered Laravel and Vite ports, including HMR. Add nonce/origin-validated bridge, session expiry and project isolation. Preview is passive input and cannot invoke privileged tools.

**G2.5 Fresh verification.** Freeze source and launch a separate clean worker. Collect full tests, browser checks, formatting, static analysis and dependency/security findings. Report skipped/error distinctly. Verify against platform-owned assertions; workspace edits cannot change the runner policy.

Demo: a scripted tool sequence adds a small field to the fixture, restarts preview and produces a report tied to the new snapshot. No model calls are needed to diagnose this pipeline.

Exit tests: duplicate job, lost response, stale patch, expired lease, cancellation, modified test script and cross-project access. All must fail or recover in the specified way.

### G3: Model-driven construction

**G3.1 Plan and task packet.** Save immutable intent and acceptance criteria; quick mode uses documented assumptions. Build bounded context from current source, relevant plan and evidence. Customer approval is not a prerequisite to ordinary coding.

**G3.2 Coding loop.** Replace the scripted G2 driver with a model adapter. Tool authorization is server-side. Apply request-size limits, output bounds, operation/attempt budgets and idempotent usage settlement.

**G3.3 Independent review.** Assemble acceptance criteria, source diff, deleted/weakened tests and fresh verification evidence without using the coder's completion claim as truth. Reviewer returns structured findings with evidence links.

**G3.4 Interrupt/revise.** New intent saves a new revision. Cancel affected running work; preserve checkpoints. Old runs cannot promote output as satisfying the new plan.

Initial configurable attempt limits: two repairs of the same failure, 30 tool operations and 20 minutes. Exhaustion returns choices to revise, use a stronger model or involve a human; it does not mandate human review. Transport retries are separate from coding repair budgets.

Exit: the system creates invitations from the clean starter; deliberately malformed model output, fake success claims, exhausted credits and interrupted execution remain controlled.

### G4: Graph-scoped editing

**G4.1 Graph ingestion.** Store nodes, edges, locations, evidence contributions and graph versions. Stage and publish atomically. Keep analysis completeness separate from application verification status.

**G4.2 Minimal extraction.** Framework route declarations, resolvable PHP symbols, Vue imports, request/policy references and selected runtime observations. Record dynamic/unresolved edges honestly.

**G4.3 Humanized views and selection.** Group technical nodes into user-visible steps while retaining membership/source links. Map selected DOM instances to source definitions and snapshot. Ambiguous shared-component scope is exposed before mutation.

**G4.4 Edit and refresh.** Select invitation authorization and request “owners only.” Agent receives the relevant action/policy, UI, tests and plan. Rebuild evidence; removed contributions disappear only after successful replacement collection. A failed collection preserves stale historical evidence with an explicit label.

Exit: mapping survives supported moves and rejects unsupported stale locations. A failed application still has an inspectable graph. TIA replay never creates fake fresh spans.

### G5: Release and external alpha

**G5.1 Release manifest.** Freeze code, lockfiles, configuration and migration plan. Attach verification identity. Obtain explicit scoped approval with expiry and atomic consumption. Serialize releases per environment; changed manifests require new approval.

**G5.2 Provider integration.** Apply P4's proven revision-control mechanism; disable unapproved push-to-deploy. Poll and reconcile uncertain outcomes. Test migrations with old/new application compatibility. Separate code rollback from database restoration; no automatic destructive down-migration.

**G5.3 Isolation and recovery.** Run sandbox conformance across at least two organizations. Test preview separation, artifacts, DB credentials and session revocation. Restore DB/source/artifacts into a clean recovery environment. Clean up orphaned resources.

**G5.4 Usage and operator workflow.** Reserve credits, meter provider operation IDs once, settle interrupted work and record unknown charges. Provide run failure evidence and an internal optional-review request flow; defer marketplace automation.

Exit: external-alpha acceptance matrix passes, no unresolved critical security finding, operator recovery is reproducible, and provider deployment limitations are accurately stated. Customer human engineering review remains optional.

### G6: Deterministic visual editor

Implement literal Tailwind text/style edits first; sibling reorder only for proven AST targets. Dynamic classes, loops, slots and shared primitives require capability checks. All writes acquire the same lease, check source hashes and rerun build/browser checks. Undo restores a source revision and revalidates it. Agent fallback is bounded and its failures remain visible.

Mobile, Livewire/April UI, customer-owned deployment accounts and existing-repo import stay in their previously agreed later phases.

## 7. Reference feature contract

These are initial fixture rules, not a restriction on customer SaaS domains.

- Actors: owner, administrator, member, unauthenticated visitor, another team's owner.
- Initial rule: owner and administrator can invite a member to their own team; members cannot. The later edit restricts invitation to owners.
- Input: normalized email and member role only. Client-supplied tenant/user/owner fields cannot change server-resolved scope.
- Creating an invitation creates a pending invitation, not membership. Reject existing members; repeated active invitations return a clear validation result without another notification.
- Token: high-entropy single-use secret, store its hash, default expiry seven days. Acceptance requires an authenticated verified email matching the invite.
- Persist within a transaction; dispatch notification after commit. Protect the database from duplicate active invitations and duplicate membership creation under concurrent requests.
- Development uses fake or captured delivery. Separately test queue execution; do not equate fake dispatch with delivery.
- Include acceptance, expiry, duplicate submission, wrong email, revoked/invalid token and concurrent acceptance cases.
- Add keyboard navigation, labeled fields, visible validation errors and readable contrast to the browser acceptance suite.

The queue strategy must explicitly state how notification failure/retry is recovered. Do not claim exactly-once email delivery unless the delivery provider supports and verifies the necessary idempotency.

## 8. Acceptance matrix

| Case                   | What must be demonstrated                                                       |
| ---------------------- | ------------------------------------------------------------------------------- |
| Happy path             | Invite and accept from the clean starter implementation                         |
| Alternate path         | Duplicate active invitation has no duplicate notification                       |
| Validation             | Invalid input leaves no invitation/membership write                             |
| Authorization          | Member, guest and other tenant denied server-side                               |
| Concurrent data change | Duplicate invitation/acceptance cannot create duplicate active records          |
| Worker crash           | Operation result reconciled without blindly repeating side effects              |
| Cancellation           | Future writes refused; process termination enforced; durable checkpoint remains |
| Stale selection        | Wrong snapshot/hash cannot be used for direct source mutation                   |
| Graph failure          | Partial analysis remains labeled; last complete version is available            |
| Test tampering         | Changing runner config or removing assertions does not satisfy protected checks |
| Release race           | Code/config drift invalidates approval before release execution                 |
| Restoration            | Source, project history and accepted evidence restored into a fresh environment |

For internal reliability measurement, run a fixed ten-task evaluation set including invitation changes, CRUD, authorization, background jobs and validation/data-shape changes. Record first-pass success, final verified success, intervention, total cost and latency. An initial target of at least eight completed tasks within the configured budget is a product experiment, not a quality guarantee. Security/release failure-injection cases must all pass; repeat the same fixture alone is insufficient.

## 9. Luna task packet and readiness

Every implementation assignment contains:

1. Exact outcome and explicit non-goals.
2. Starting commit and relevant current source.
3. Applicable architecture decisions and contracts.
4. Domain rules, including failure and permission behavior.
5. Permitted dependencies and interfaces.
6. Exact verification commands already established by G0.
7. Scope of files/behavior that may change; paths are task guidance, not permanent product rules.
8. Stop conditions and evidence required for completion.

An assignment is **ready** only if the preceding gate's needed outputs exist, contracts validate, dependencies are pinned, and acceptance assertions are concrete. Broad gate descriptions are not ready-to-code tickets. A blocked provider integration does not block unrelated local tasks.

Stop architectural work for frontier replanning when shared contracts or dependencies must change. Routine implementation choices remain delegated. Human review for customers is optional; internal platform changes still receive normal code review.

Completion report: implemented behavior, changed files, test commands/results, unresolved limitations, snapshot ID and acceptance-criterion mapping. No claim of completion based solely on generated test totals.

## 10. Next work order after the foundation: G0.2 invitation fixture

**Start condition:** G0.1 has established a clean Laravel/Inertia/Vue/Tailwind checkout and pinned commands. If absent, implement G0.1 first and report its environment manifest; do not invent the repo's existing state.

**Objective:** Build the reference solution and independent acceptance cases in section 7, while retaining a clean starter without invitation implementation.

**Implementation:**

- Reuse starter authentication and team primitives.
- Implement policies/Form Requests, thin controller, invite/accept actions, typed input where it improves the boundary, and focused model constraints.
- Reuse Laravel notifications and queue facilities. Introduce a reusable service only when it has actual behavior.
- Add Vue screens for invitation entry, validation and acceptance outcome.
- Add factories and isolated fixtures for all five actor categories.
- Add integration tests for transaction rollback, duplicates and denied cross-tenant access.
- Keep acceptance-harness policy outside the model-writable customer fixture.
- Document local fake delivery and a separate worker scenario.

**Non-goals:** Billing, sandbox orchestration, production email, mobile, package marketplace, arbitrary roles UI, graph engine and drag-and-drop.

**Acceptance:** Section 7 rules pass on the reference solution. The clean starter fails only for the intentionally missing feature. The browser scenario verifies both success and denial after the later owner-only rule change.

**Delivery:** Reviewable diff, exact recorded G0.1 check commands and results, fixture revision identities. Do not send real invitations or provision infrastructure.

## 11. Ownership, estimates and next action

For a three-developer team: one owns control plane/orchestration, one runtime/deployment, one analysis/preview/verification. They integrate against contracts; the product owner accepts behavior. A solo developer follows the same dependency gates sequentially.

Spend the first development block on G0 and the P1/P2/P3 proofs, then produce measured task estimates. P4 may require confirmed account access; document it as pending until exercised. Calendar dates follow measured throughput and provider results. The earlier 13-week sequence is superseded because it hid these unknowns.

Immediate next action: give `G0-1-foundation-work-order.md` to the coding model in the intended platform repository. That assignment covers environment/repository discovery, the runnable control plane, pinned toolchain and check scripts. Review its evidence before issuing G0.2. The two first differentiated-architecture proofs are source mapping and honest route/test evidence; complete them before investing in the full dashboard or graph UI.

## 12. Model improvement and product validation

Assume general coding agents keep improving. Laravel conventions, planner/coder routing and code indexing are useful implementation choices, but insufficient standalone competitive advantages. Our hypothesis is that nontechnical owners benefit from persistent product intent, understandable interaction, controlled changes, operating history and managed releases. Competitors can build these too; customer preference must be measured.

Before building the full compiler/editor, compare a minimal platform prototype with a current general coding agent using the same strong model and starter. Ask three to five nontechnical business owners to add a feature, alter permissions, diagnose a failed change and understand deployment impact. This is proposed research, not already performed. Record completion, assistance, time, confidence calibration and willingness to pay; do not substitute model benchmarks for customer behavior.

Continue investing in a graph feature only if it improves a measurable user outcome or reduces verified execution cost. Start with source selection and a small humanized flow; defer exhaustive graph extraction. If owners get equivalent results through a generic agent, change the product workflow or narrow the initial customer problem before expanding infrastructure.

Compare the planner/cheap-coder/reviewer pipeline with a single stronger coding model plus the same independent verification. Select by total cost per accepted result, latency and regression rate. The split is an initial strategy, not an architectural dependency: keep model roles configurable so improved models can simplify the pipeline.

### 12.1 Comparative pilot before broad compiler/editor investment

Run this pilot once G3 and a narrow G4 prototype are demonstrable, before expanding graph coverage or starting G6. A clickable prototype can test comprehension earlier, but it cannot establish implementation reliability. Recruitment and sessions are planned activities, not authorization to contact anyone now.

Use three to five nontechnical owners with real SaaS needs. Give each equivalent tasks: add a field, change invitation permissions, recover from a failed change and explain a proposed release. Alternate which tool they use first and use equivalent fixture variants to reduce practice effects. Give both tools equivalent requirements, credentials, time limits and onboarding assistance.

Compare two questions separately:

1. **Does our workflow help?** Use the same model, starter and independent acceptance tests where possible. Record any unavoidable harness differences.
2. **Would someone choose the product?** Compare with the best practical alternative in its normal configuration, even if it uses a different model.

Measure completed tasks, facilitator interventions, time, undetected permission errors, understanding of change impact, and total cost per independently accepted result. Count model retries, sandbox time, checks and support effort. Willingness to pay is preliminary evidence; actual paid continued use is stronger.

A proposed directional continuation gate is that a majority of pilot participants complete at least three of the four tasks with less assistance or fewer undetected errors, while critical authorization and release tests all pass. Report individual outcomes and failures; this small pilot is not statistical proof. Before a large expansion, seek repeated use across at least two later change sessions and a concrete paid-pilot commitment from at least two owners. These thresholds are planning choices, not achieved results or market forecasts.

### 12.2 How results change the build

| Finding                                            | Decision                                                                          |
| -------------------------------------------------- | --------------------------------------------------------------------------------- |
| Selection helps but graph browsing does not        | Keep graph-backed context; simplify the customer UI                               |
| Prompts outperform deterministic controls          | Defer drag-and-drop and focus on preview plus scoped prompts                      |
| A stronger executor beats the cheap-coder pipeline | Change routing; preserve independent verification and protected actions           |
| Owners value operation after launch                | Prioritize reliable changes, recovery, deployment history and support             |
| Owners only value initial generation               | Revisit pricing and retention assumptions before scaling                          |
| Generic agents perform equally well for owners     | Reduce infrastructure spend and test a more specific workflow or customer segment |

Repeat the fixed task evaluation when changing model/provider, context strategy or orchestration policy. Remove custom components whose benefit disappears, while retaining security and customer-approval invariants. Each proposed subsystem should state its measured user benefit, operational requirement or reuse gap before receiving a large work package.

## 13. Decision status

**Chosen for initial implementation:** Modular Laravel control plane, DB-backed durable steps/outbox, fenced single writer, immutable candidate identities, protected verification, minimal evidence graph and separate approved-release workflow.

**Must be proved:** Managed isolation provider, precise Vue selection cases, supported trace hooks/TIA integration, Laravel Cloud revision and migration behavior.

**Still configurable:** Model IDs, credit conversion, runtime budgets, package certification breadth and commercial human-review rates.

This revision changes implementation mechanics and sequencing. It does not require customers to approve every feature plan, mandate paid human review, or adopt fixed generated-application folder paths.
