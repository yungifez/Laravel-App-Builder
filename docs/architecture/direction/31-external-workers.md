# Direction 31: External workers

The owner's words, 2026-09-28:

> Please refine the architecture plan based on the actual codebase, not just this discussion.
>
> Inspect the current repository, existing architecture documents, current Laravel structure, services/actions, persistence model, task/workspace concepts, and anything already implemented around agents, previews, Git, verification, or project context.
>
> Where this proposal conflicts with the actual codebase, prefer a pragmatic evolution of the existing architecture rather than blindly introducing new abstractions.
>
> Also add your own ideas where you see a simpler, safer, or more technically coherent implementation.
>
> Do not merely copy these concepts into documentation. Critique them.
>
> The specific problem to solve is:
>
> > How can we allow external workers such as Claude Code to participate in implementation without exposing the internal machinery that makes our platform valuable?
>
> The solution should assume:
>
> - anything sent to an external worker may be inspected by the user;
> - tool names, returned context, task packets, and worker instructions may therefore be visible;
> - our moat cannot depend on a secret system prompt;
> - the control plane should expose only the minimum information required for a worker to complete one bounded task.
>
> # 1. Treat external workers like untrusted execution clients
>
> Claude Code, Codex, human developers, and other external workers should conceptually sit outside the trusted Control Plane boundary.
>
> They can receive:
>
> - a bounded task;
> - relevant code;
> - selected product facts;
> - selected constraints;
> - verification requirements;
> - access to approved query tools;
> - preview access;
> - an isolated task workspace.
>
> They should not receive:
>
> - the complete Product Model;
> - internal retrieval/ranking scores;
> - Effect-scoring algorithms;
> - assumption-priority algorithms;
> - model-routing strategy;
> - internal reasoning traces;
> - unrelated Behaviors;
> - complete project history;
> - raw embeddings/indexes;
> - internal graph structures;
> - proprietary orchestration prompts.
>
> Think:
>
>     CONTROL PLANE
>         ↓
>     compiled task interface
>         ↓
>     EXTERNAL WORKER
>
> not:
>
>     CONTROL PLANE DATABASE
>         ↓
>     external worker can query everything
>
> # 2. The worker receives compiled outputs, not internal state
>
> The analogy should be a compiler.
>
> A compiler may contain complex internal logic, but the consumer receives only the compiled artifact.
>
> Our worker should receive something like:
>
>     TASK
>
>     Goal:
>     Managers can invite contractors.
>
>     Relevant current behavior:
>     Owners and administrators can invite employees.
>
>     Must preserve:
>     - Owner/admin invitation behavior.
>     - Invitation notifications.
>     - Organization isolation.
>
>     Verify:
>     - Manager → contractor: allow.
>     - Manager → employee: deny.
>     - Member → contractor: deny.
>
> It should not receive:
>
>     Behavior relevance score: 0.92
>     Effect rank: 0.84
>     retrieved from nodes X/Y/Z
>     assumption confidence: 0.71
>     context compiler selected because...
>     internal dependency reasoning...
>
> The product should expose conclusions, not the machinery used to reach them.
>
> # 3. Use opaque product-query APIs
>
> Workers may need more information during implementation.
>
> Do not expose the Product Model directly.
>
> Provide narrow semantic queries.
>
> Good:
>
>     get_current_behavior("membership.invite")
>
>     get_relevant_constraints(task_id)
>
>     ask_product_question(
>         "Do contractors become billable before accepting?"
>     )
>
>     report_assumption(...)
>
> Bad:
>
>     dump_product_graph()
>
>     list_all_assumptions()
>
>     search_all_internal_memory()
>
>     get_context_rankings()
>
>     retrieve_raw_embeddings()
>
> Each API should answer a task-oriented question.
>
> The worker should not be able to reconstruct the entire internal representation simply by enumerating everything.
>
> # 4. Scope every query to the active task
>
> A worker operating on:
>
>     membership.invite-contractor
>
> should not automatically be allowed to query arbitrary Billing, Payroll, or unrelated product history.
>
> Use task-scoped capability tokens or equivalent authorization.
>
> Conceptually:
>
>     Worker
>       │
>       ├── allowed:
>       │     task context
>       │     relevant Behaviors
>       │     relevant constraints
>       │     relevant preview
>       │
>       └── denied:
>             unrelated project intelligence
>             other project data
>             raw internal state
>
> If a worker believes additional context is necessary, it requests expansion.
>
> Example:
>
>     request_context_expansion(
>         reason="Invitation acceptance appears to update billing seats.",
>         target="billing.seats"
>     )
>
> The Control Plane decides whether to grant it.
>
> This protects both IP and user/project isolation.
>
> # 5. Context expansion should remain server-side
>
> The worker can say:
>
>     I need to understand how invitation acceptance affects billing.
>
> Our backend should determine:
>
> - which Behavior matters;
> - which decisions matter;
> - which Change Records matter;
> - which constraints matter;
> - how much context to return.
>
> Then return a compact answer.
>
> Do not expose the retrieval process.
>
> Example response:
>
>     Contractors become billable after accepting an invitation.
>
>     Preserve:
>     Pending invitations must not consume seats.
>
>     Relevant implementation references:
>     MembershipAccepted
>     SeatCalculator
>     BillingSeatTest
>
> That is enough.
>
> # 6. Hide internal vocabulary where possible
>
> Even power-user workers do not necessarily need to know our exact architecture.
>
> Instead of revealing:
>
>     Effect graph edge
>     Context Compiler node
>     epistemic item
>     invariant compiler
>
> the worker can see neutral task concepts:
>
>     related behavior
>     relevant rule
>     known decision
>     verification requirement
>
> This reduces unnecessary disclosure of our conceptual architecture.
>
> Internally we can keep richer terminology.
>
> # 7. Separate worker-visible reasoning from control-plane reasoning
>
> Do not send chain-of-thought or detailed internal reasoning to workers.
>
> The worker should receive decisions and evidence.
>
> Example:
>
> Bad:
>
>     We considered Behaviors A, B, C and ranked B most highly because...
>
> Good:
>
>     This change may affect Billing Seats.
>
>     Evidence:
>     tests for Billing Seats share affected implementation.
>
> Similarly:
>
> Bad:
>
>     The assumption classifier gave 0.78 materiality...
>
> Good:
>
>     This assumption affects data ownership and should be confirmed before implementation.
>
> Expose reasons users/workers can act on.
>
> Do not expose internal inference mechanics.
>
> # 8. Product questions should pass through our platform
>
> Workers should not independently establish durable product truth.
>
> If Claude believes:
>
>     Managers probably should invite employees too.
>
> it should report:
>
>     product decision required
>
> The Control Plane decides whether existing knowledge resolves it.
>
> If not, our interface asks the user.
>
> This prevents the external worker from becoming the hidden product manager.
>
> It also means the durable question/answer history stays with us.
>
> # 9. Durable answers belong to us
>
> A worker may discover or ask:
>
>     Do contractors consume seats while pending?
>
> The eventual answer becomes:
>
>     Project Decision:
>     Contractors become billable only after acceptance.
>
> That answer is stored in our Product Model.
>
> The worker receives the decision for the current task.
>
> It does not own the durable memory.
>
> This is critical.
>
> External workers should consume project intelligence without becoming its storage layer.
>
> # 10. Minimize worker-visible Change Record internals
>
> The worker may need:
>
>     current task
>     recent relevant changes
>
> It does not need the entire semantic evolution history.
>
> Provide only relevant historical facts.
>
> Example:
>
>     Previous related decision:
>     Pending contractors do not consume seats.
>
> rather than:
>
>     Here are the last 48 Change Records concerning memberships.
>
> Again, server-side selection.
>
> # 11. Use code references instead of exporting our internal implementation map
>
> If our Product Model knows:
>
>     Behavior → Policy → Action → Test
>
> the worker can be told:
>
>     Relevant implementation:
>     app/Policies/MembershipPolicy.php
>     app/Actions/InviteMember.php
>     tests/Feature/InviteMemberTest.php
>
> It does not need to know how that mapping was produced or stored.
>
> This is another compiled artifact.
>
> # 12. Treat preview instrumentation similarly
>
> We can expose useful observations:
>
>     POST /invitations → 201
>     MemberInvited dispatched
>     notification queued
>     no errors
>
> Do not expose:
>
>     complete internal observability architecture
>     unrelated traces
>     raw platform telemetry
>     global instrumentation data
>
> Preview tools should be task-scoped and intention-oriented.
>
> # 13. Verification requirements should be explicit; verification selection should remain ours
>
> Worker sees:
>
>     Verify:
>     Manager can invite contractor.
>     Manager cannot invite employee.
>     Existing owner invitation remains valid.
>
> Worker does not need to know all internal rules used to determine why those tests were selected.
>
> Likewise, TIA may tell our system that Billing Seats should also be verified.
>
> Return:
>
>     Additional verification required:
>     pending contractor must not consume billing seat.
>
> Do not expose the whole TIA/Effect selection engine unless needed for debugging.
>
> # 14. Submission boundary
>
> External worker submits:
>
>     patch / commit
>     implementation summary
>     assumptions discovered
>     verification it ran
>
> Our platform independently determines:
>
> - actual changed files;
> - actual affected Behaviors;
> - actual relevant constraints;
> - TIA impact;
> - verification scope;
> - full-suite requirement;
> - acceptance.
>
> The worker should never receive the authority to decide:
>
>     This is now canonical truth.
>
> # 15. Assume some architectural ideas will still be visible
>
> Do not overestimate secrecy.
>
> A sophisticated user may notice that our task packets consistently contain:
>
>     goal
>     preserve
>     verify
>
> They may infer that we track assumptions or Behaviors.
>
> That is acceptable.
>
> If simply discovering:
>
>     "they use Goal + Preserve + Verify"
>
> allows someone to clone the product, then the moat is too weak.
>
> The defensible value should be in:
>
> - accumulated product understanding;
> - high-quality context compilation;
> - deterministic Laravel integration;
> - Behavior ↔ tests ↔ implementation relationships;
> - TIA-derived Effects;
> - assumption history;
> - accepted Change Records;
> - expert guidance;
> - verification infrastructure;
> - user experience;
> - years of learned application-specific evidence.
>
> Protect implementation details, but do not depend on obscurity.
>
> # 16. Consider a Worker Gateway
>
> Evaluate whether the codebase should have a dedicated Worker Gateway layer.
>
> Conceptually:
>
>     External Worker
>           │
>           ▼
>     Worker Gateway
>           │
>           ├── validates active Task
>           ├── enforces scope
>           ├── sanitizes responses
>           ├── rate limits queries
>           ├── records worker requests
>           ├── strips internal metadata
>           └── forwards approved requests
>                     │
>                     ▼
>              Control Plane
>
> This would create a strong architectural boundary between:
>
>     internal product intelligence
>
> and:
>
>     externally consumable task intelligence.
>
> Do not add this abstraction blindly if an existing service/API boundary already satisfies the role.
>
> Inspect the current codebase and determine the simplest fit.
>
> # 17. Worker query logs may become useful research data
>
> Because workers have to explicitly ask for missing information, their queries can reveal deficiencies in our Context Compiler.
>
> Example:
>
> Task packet supplied:
>
>     Goal
>     constraints
>     implementation refs
>
> Claude repeatedly asks:
>
>     What happens after an invitation is accepted?
>
> That suggests acceptance behavior should have been included automatically.
>
> Therefore external-worker queries become feedback for improving context selection.
>
> This creates a useful loop:
>
>     task compiled
>         ↓
>     worker asks questions
>         ↓
>     accepted change
>         ↓
>     analyze unnecessary queries
>         ↓
>     improve future context compilation
>
> This should be captured without exposing the compiler itself.
>
> # 18. Refine the Task Worker Protocol against the actual codebase
>
> Please inspect the repository and answer:
>
> 1. What existing entities/services already approximate Task, Change, Project Context, Workspace, Preview, or Verification?
>
> 2. Where should the Worker boundary live in the current Laravel application?
>
> 3. What should be represented as ordinary Laravel models versus services/value objects versus ephemeral runtime state?
>
> 4. What existing APIs/actions can be reused?
>
> 5. What would be over-engineering at the current stage?
>
> 6. How should task-scoped authorization work using the existing architecture?
>
> 7. How should local Claude Code authenticate with our Worker Gateway without receiving general account credentials?
>
> 8. What is the minimum viable query interface needed for the first external-worker experiment?
>
> 9. What should remain server-only?
>
> 10. What information inevitably becomes worker-visible?
>
> 11. How can we ensure an external worker cannot enumerate or reconstruct unrelated Product Model state?
>
> 12. How does the remote preview currently work, or how should it evolve to support task-specific workspaces?
>
> 13. Which parts of the proposed protocol can be tested immediately using the current application?
>
> # 19. Add your own architectural ideas
>
> Do not constrain yourself to this exact design.
>
> If the existing codebase suggests a better boundary, propose it.
>
> Especially look for ways to improve:
>
> - isolation
> - token efficiency
> - simplicity
> - Laravel-native implementation
> - authorization
> - auditability
> - task resumability
> - local/remote synchronization
> - external-worker safety
> - IP separation
> - verification
> - preview lifecycle
> - developer experience
>
> Prefer Laravel conventions and existing framework primitives over introducing custom infrastructure.
>
> If Laravel already solves part of the problem, use Laravel.
>
> If existing project code already solves part of the problem, reuse it.
>
> # 20. Be critical about what actually needs to be secret
>
> Classify internal concepts into three categories.
>
> ### Must remain server-side
>
> Examples may include:
>
> - ranking algorithms
> - retrieval/index internals
> - provider routing
> - proprietary scoring
> - project-wide Product Model
> - unrelated customer/project state
>
> ### Fine to expose as compiled output
>
> Examples may include:
>
> - task goal
> - relevant product rules
> - acceptance criteria
> - relevant implementation references
> - selected historical decisions
>
> ### Intentionally transparent
>
> Potential examples:
>
> - tests that must pass
> - product decisions
> - relevant assumptions
> - why a user clarification is required
> - actual verification results
>
> Do not try to hide information whose transparency improves trust and does not meaningfully compromise the system.
>
> # Final principle
>
> The external worker should behave like a contractor receiving an excellent technical brief.
>
> The contractor knows:
>
>     what needs to be done
>     what matters
>     what must not break
>     where to look
>     how success will be checked
>
> The contractor does not receive:
>
>     the company's entire internal knowledge-management system
>     how project intelligence was ranked
>     how every strategic decision is stored
>     how all other projects are organized
>
> The same principle should govern Claude Code.
>
> Please update/refine the architecture accordingly after inspecting the actual repository.
>
> Do not treat this document as authoritative where the codebase demonstrates a better implementation path.
>
> Use it as a product/architecture requirement, then propose the simplest implementation that preserves the boundary.

Then, while the architecture was being refined:

> If we can make it agnostic, ie claude and codex
