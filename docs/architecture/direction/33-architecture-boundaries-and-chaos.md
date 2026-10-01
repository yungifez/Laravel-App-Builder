# Direction 33: Architectural boundaries and the chaos engine

The owner's words, 2026-10-01:

> You are designing the architecture of a Laravel-focused AI software builder called **Determinism**.
>
> Your task is not to immediately write code.
>
> Your task is to reason deeply about the best architecture for two tightly connected systems:
>
> 1. **Architectural Boundary Enforcement**
> 2. **Chaos / Verification Engine**
>
> Treat them as parts of one verification pipeline, not isolated features.
>
> The goal is to make AI-generated Laravel applications more predictable, structurally sound, and verifiable without forcing every project into one rigid architecture.
>
> ## Core philosophy
>
> Determinism should progressively turn architectural expectations and behavioral guarantees into machine-checkable rules.
>
> However, not every architectural rule should block development.
>
> Some violations should be:
>
> - **hard failures**
> - **soft failures**
> - **warnings**
> - **observations / architectural drift signals**
> - **suggestions for later cleanup**
>
> The architecture must therefore support **graduated enforcement** rather than a binary pass/fail model.
>
> We already have some informal Laravel architecture guidance. The new system should make this substantially stronger and more systematic.
>
> ---
>
> # Part 1: Design the Architectural Boundary System
>
> Focus heavily on Laravel-specific architecture.
>
> Reason about Laravel artifacts such as:
>
> - routes
> - middleware
> - controllers
> - FormRequests
> - policies
> - Gates
> - Actions
> - Services
> - Eloquent models
> - scopes
> - casts
> - observers
> - events
> - listeners
> - jobs
> - notifications
> - commands
> - service providers
> - migrations
> - resources
> - Livewire components
> - Inertia endpoints
> - facades
> - queues
> - cache
> - filesystem
> - external HTTP calls
> - transactions
>
> Do not assume all applications use every artifact.
>
> The system must support projects that are reasonably idiomatic Laravel but may use different organizational styles.
>
> ## Important architectural question
>
> Determine how we should represent:
>
> > **Which kinds of Laravel artifacts are allowed, discouraged, or forbidden from performing which kinds of operations?**
>
> For example:
>
>     Controller
>       may orchestrate
>       may authorize
>       may call application logic
>       should not perform complex database writes
>       should not call external HTTP directly
>
>     Policy
>       may read relevant state
>       must not mutate state
>       must not dispatch jobs
>       must not perform external effects
>
>     FormRequest
>       may validate
>       may authorize
>       must not perform business side effects
>
>     Model
>       may encapsulate domain-related state and relationships
>       should not perform arbitrary external HTTP calls
>
> Do not blindly accept these exact rules. Analyze whether they are good boundaries for AI-generated Laravel applications.
>
> ---
>
> # Part 2: Graduated Enforcement
>
> Design a formal severity model.
>
> For example:
>
>     BLOCK
>     ERROR
>     WARN
>     OBSERVE
>
> But explore better terminology if appropriate.
>
> The system should distinguish between:
>
> ## Hard architectural invariants
>
> Examples might include:
>
> - policy performs a destructive write
> - FormRequest sends email
> - production secret committed into source
> - tenant isolation boundary bypassed
> - controller directly performs an irreversible payment when that is forbidden by system policy
> - a protected verification mechanism is modified by a normal feature agent
>
> These may block the build.
>
> ## Strong architectural preferences
>
> Examples might include:
>
> - controller contains too much business logic
> - model contains orchestration logic
> - Action is bypassed even though the project normally uses Actions
> - observer contains too many effects
> - duplicated service logic
>
> These may produce warnings or require stronger review rather than blocking.
>
> ## Architectural drift
>
> Examples:
>
> - controller complexity gradually increasing
> - more and more direct model calls
> - rising coupling between domains
> - growing dependency count
> - more side effects per request
>
> These should likely be tracked over time instead of blocking an individual change.
>
> Explore how these levels should work.
>
> ---
>
> # Part 3: Do not make architecture purely static
>
> Static analysis is necessary but insufficient.
>
> Design the system around both:
>
> ## Static architecture evidence
>
> Examples:
>
> - namespaces
> - imports
> - AST
> - method calls
> - facades
> - dependency graph
> - route declarations
> - policy usage
> - model relationships
>
> ## Runtime architecture evidence
>
> Examples:
>
> - actual DB writes
> - queue dispatches
> - events emitted
> - HTTP calls
> - mail sent
> - cache writes
> - filesystem writes
> - transactions
> - model observers triggered
>
> A controller might indirectly cause an external HTTP request even though no HTTP call appears in the controller itself.
>
> Think carefully about how runtime traces can reveal architectural boundary violations that static analysis cannot.
>
> ---
>
> # Part 4: Architecture Capability Model
>
> Explore whether architectural enforcement can be modeled as:
>
>     Artifact
>         ×
>     Capability
>         ×
>     Constraint
>
> Potential capabilities include:
>
>     ReadDatabase
>     WriteDatabase
>     ValidateInput
>     Authorize
>     DispatchJob
>     EmitEvent
>     ExternalHTTP
>     SendMail
>     SendNotification
>     WriteFilesystem
>     ReadFilesystem
>     CacheRead
>     CacheWrite
>     StartTransaction
>     PerformIrreversibleEffect
>
> For example:
>
>     Policy:
>         ReadDatabase = allowed
>         WriteDatabase = block
>         ExternalHTTP = block
>
>     Controller:
>         ReadDatabase = warn
>         WriteDatabase = warn/error
>         ExternalHTTP = warn/error
>
>     Action:
>         WriteDatabase = allowed
>         ExternalHTTP = allowed with verification
>
> Investigate whether this model is sufficient.
>
> Identify where it breaks down.
>
> Consider whether context is needed, such as:
>
>     artifact + capability + domain + risk + operation
>
> ---
>
> # Part 5: Project-specific architecture
>
> Determinism should have sensible defaults, but not impose one architecture universally.
>
> Design how projects can modify the baseline.
>
> Potential sources:
>
> - generated project defaults
> - explicit architecture configuration
> - approved exceptions
> - project-specific conventions
> - risk level
> - domain boundaries
>
> Think carefully about exceptions.
>
> An exception should not simply be:
>
>     ignore_rule = true
>
> Prefer something auditable like:
>
>     rule
>     scope
>     reason
>     approved_by
>     expiration/review condition
>
> Explore whether temporary architectural debt should be first-class.
>
> ---
>
> # Part 6: Architecture changes must be privileged
>
> The feature-building agent must not be able to solve a failing architecture check by weakening the checker.
>
> Design privilege separation between:
>
> - normal feature agent
> - architecture planner
> - verification system
> - project owner
> - migration/refactor workflow
>
> Protected areas may include:
>
> - architecture policy
> - verification rules
> - chaos engine configuration
> - invariant definitions
> - CI enforcement
> - security boundaries
>
> Think carefully about how an architecture change is intentionally proposed and accepted.
>
> ---
>
> # Part 7: Design the Chaos Engine alongside this architecture
>
> Now connect this directly to the Chaos Engine.
>
> The Chaos Engine should not merely kill infrastructure.
>
> It should deliberately violate assumptions and test whether architectural and behavioral guarantees hold.
>
> Potential fault types:
>
>     timeout
>     unavailable dependency
>     duplicate execution
>     delayed job
>     reordered event
>     stale data
>     partial success
>     exception before write
>     exception after write
>     exception between multiple writes
>     retry
>     concurrent execution
>
> The Chaos Engine should understand Laravel concepts such as:
>
> - queued jobs
> - events/listeners
> - transactions
> - Eloquent writes
> - HTTP clients
> - cache
> - mail
> - notifications
> - storage
>
> ---
>
> # Part 8: Architecture should drive chaos
>
> This is a critical design goal.
>
> If architectural/runtime analysis shows:
>
>     Action:
>       WriteDatabase
>       ExternalHTTP
>       DispatchJob
>
> then Determinism should be able to derive relevant chaos scenarios automatically.
>
> For example:
>
>     WriteDatabase
>         → transaction interruption
>         → rollback verification
>
>     ExternalHTTP
>         → timeout
>         → success followed by local failure
>         → duplicate response
>
>     DispatchJob
>         → duplicate delivery
>         → delayed delivery
>         → retry
>
> Explore this relationship deeply.
>
> Architecture should not just reject bad code.
>
> It should supply knowledge to verification.
>
> ---
>
> # Part 9: Chaos should also test architecture
>
> The relationship must work in the opposite direction.
>
> Example:
>
> An architectural rule says:
>
>     Policy must never cause side effects.
>
> The Chaos/runtime engine observes:
>
>     evaluating Policy caused an event dispatch
>
> That is architectural evidence.
>
> Another rule says:
>
>     Controllers should orchestrate rather than execute external effects.
>
> Runtime trace:
>
>     HTTP Controller
>         → external Stripe request
>
> Maybe this is:
>
>     BLOCK
>     WARN
>     or REQUIRE_REVIEW
>
> depending on the project's architecture policy.
>
> Design how runtime execution feeds architecture analysis.
>
> ---
>
> # Part 10: Unified Verification Pipeline
>
> Design a pipeline where architecture and chaos are integrated.
>
> A possible starting point:
>
>     User request
>         ↓
>     Intent / risk classification
>         ↓
>     Feature plan
>         ↓
>     Architecture pre-check
>         ↓
>     Agent implementation
>         ↓
>     Static architecture analysis
>         ↓
>     Standard test suite
>         ↓
>     Runtime effect trace
>         ↓
>     Architecture runtime checks
>         ↓
>     Derived chaos scenarios
>         ↓
>     Chaos execution
>         ↓
>     Invariant verification
>         ↓
>     Mutation / adversarial verification
>         ↓
>     Architecture score / drift analysis
>         ↓
>     Release decision
>
> Do not assume this ordering is optimal.
>
> Critique it and design something better.
>
> ---
>
> # Part 11: Architectural feedback must influence agent behavior
>
> Determine how violations should feed back into the coding loop.
>
> For example:
>
> ## Block
>
>     Policy performs write.
>
> Agent must redesign implementation.
>
> ## Error
>
>     Controller bypasses required application boundary.
>
> Agent must fix unless architecture exception is approved.
>
> ## Warning
>
>     Controller complexity increased substantially.
>
> Agent may continue, but verification record includes it.
>
> ## Observation
>
>     Domain coupling increased.
>
> Track for future architectural audit.
>
> The model should not decide whether its own violation is acceptable.
>
> Enforcement level should come from deterministic policy.
>
> ---
>
> # Part 12: Architecture debt
>
> Explore an explicit architecture-debt system.
>
> Instead of silently accepting violations:
>
>     ArchitecturalDebt:
>         rule: controller_write
>         location: FooController::store
>         severity: warning
>         introduced_by: feature XYZ
>         reason: ...
>         created_at: ...
>         review_after: ...
>
> This could allow Determinism to remain practical instead of dogmatically blocking work.
>
> Consider:
>
> - debt budgets
> - debt trends
> - preventing severity increases
> - preventing new violations while grandfathering legacy ones
> - "ratchet" mechanisms
>
> The ratchet concept is especially important.
>
> Example:
>
>     Existing violations: 17
>     New branch: 18
>
> FAIL
>
>     Existing violations: 17
>     New branch: 17
>
> ALLOW
>
>     Existing violations: 17
>     New branch: 15
>
> IMPROVEMENT
>
> Explore this thoroughly.
>
> ---
>
> # Part 13: Baselines and ratcheting
>
> This may be one of the strongest ways to make the system usable on imperfect projects.
>
> Do not require:
>
>     zero violations
>
> Instead:
>
>     do not make the architecture worse
>
> For each metric/rule, compare:
>
>     baseline
>         vs
>     proposed change
>
> Examples:
>
>     forbidden dependencies
>     direct DB writes in controllers
>     domain coupling
>     external effects
>     complexity
>     architecture warnings
>     cross-domain model access
>
> Design a formal ratchet system.
>
> Consider when worsening architecture should still be allowed through explicit approval.
>
> ---
>
> # Part 14: Architecture scoring
>
> Investigate whether a score is useful.
>
> Be skeptical.
>
> A single:
>
>     Architecture score = 84
>
> may hide too much information.
>
> Perhaps use dimensions instead:
>
>     Boundary integrity
>     Side-effect containment
>     Authorization structure
>     Persistence discipline
>     Coupling
>     Async safety
>     Architectural debt
>
> Determine whether scoring creates useful signal or false precision.
>
> ---
>
> # Part 15: Relationship to other Determinism engines
>
> Architecture and Chaos will eventually coexist with engines such as:
>
> - Mutation Engine
> - Invariant Engine
> - Permission Matrix Engine
> - Tenant Isolation Engine
> - Idempotency Engine
> - Concurrency Engine
> - Rollback Engine
> - Effects Engine
> - Differential Engine
> - Replay Engine
> - Boundary Engine
> - State Transition Engine
>
> Design the architecture system so these engines can reuse its outputs.
>
> For example:
>
>     architectural analysis
>         ↓
>     operation uses queue
>         ↓
>     Idempotency Engine activated
>
> or:
>
>     model is tenant-scoped
>         ↓
>     Tenant Isolation Engine activated
>
> or:
>
>     operation contains multiple writes
>         ↓
>     Rollback Engine activated
>
> ---
>
> # Part 16: Avoid overfitting to one Laravel architecture
>
> Do NOT design:
>
>     every controller must call an Action
>     every request must use a FormRequest
>     every domain must use repositories
>     every operation must use a Service
>
> unless you can strongly justify it.
>
> The architecture system should primarily enforce:
>
> - dangerous boundary violations
> - explicitly chosen project rules
> - critical separation of responsibilities
> - containment of side effects
> - authorization boundaries
> - data integrity
> - predictable execution
>
> It should guide structure without turning Laravel into ceremony.
>
> ---
>
> # Part 17: Think like a language/runtime designer
>
> Approach this partly like designing a small type/effect system for Laravel.
>
> Ask:
>
> - Can architectural constraints behave like types?
> - Can side effects be treated like effects?
> - Can forbidden dependencies be rejected statically?
> - Can runtime effects be checked against declared effects?
> - Can architectural exceptions be explicit casts?
> - Can architecture debt behave like compiler warnings?
> - Can strict projects use `deny(warnings)` while looser projects allow them?
> - Can riskier operations automatically require stronger verification?
>
> Do not force compiler terminology if it does not fit, but explore the analogy seriously.
>
> ---
>
> # Part 18: Key product goal
>
> The desired outcome is:
>
> > The coding model is allowed to be creative inside a constrained space, while Determinism owns the boundaries and verification.
>
> The model should not have to remember every architectural rule.
>
> The environment should enforce them.
>
> At the same time:
>
> > Determinism must not become so rigid that every non-standard Laravel implementation is rejected.
>
> Design for this tension explicitly.
>
> ---
>
> # Deliverables
>
> Return a detailed architecture proposal containing:
>
> 1. **A unified conceptual model** for Laravel architecture enforcement.
> 2. **The rule representation**.
> 3. **The severity / graduated enforcement model**.
> 4. **Laravel artifact categories** and how they are detected.
> 5. **Capability/effect categories**.
> 6. **Static analysis architecture**.
> 7. **Runtime instrumentation architecture**.
> 8. **The architecture baseline + ratchet model**.
> 9. **Architectural debt representation**.
> 10. **Exception/approval design**.
> 11. **Protected control-plane design**.
> 12. **How architecture information feeds Chaos Engine scenario generation**.
> 13. **How Chaos Engine observations feed back into architectural enforcement**.
> 14. **The unified verification pipeline**.
> 15. **How other Determinism engines plug into this architecture later**.
> 16. **Examples using real Laravel code paths**.
> 17. **What should be hard-blocked by default**.
> 18. **What should be advisory by default**.
> 19. **What should only become blocking in strict mode**.
> 20. **Likely failure modes of this architecture itself**.
> 21. **Areas where static analysis will be unreliable and runtime evidence is necessary**.
> 22. **Areas where runtime evidence is insufficient and static constraints are necessary**.
> 23. **A proposed MVP architecture versus the long-term architecture**.
> 24. **Concrete package/library/tooling candidates worth investigating, but do not let existing tooling dictate the architecture**.
> 25. **Open research questions that need prototypes before committing to the design**.
>
> ## Final instruction
>
> Spend significantly more effort on the architecture than on implementation details.
>
> Challenge the initial ideas.
>
> Look for contradictions between flexibility and enforcement.
>
> Look for ways the architecture can become simpler while preserving guarantees.
>
> Do not optimize for making the current idea sound good.
>
> Optimize for producing the strongest architecture for Determinism.

And with it:

> Work with the other agent creating the chaos engine
