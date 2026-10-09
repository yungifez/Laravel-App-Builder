# Direction 32: Deterministic verification engines

The owner's words, 2026-10-01:

> You are exploring a product architecture for an AI application builder focused initially on Laravel.
>
> Do not jump straight into implementation. Your job is to explore the design space deeply, identify useful abstractions, find adjacent ideas from software engineering, testing, distributed systems, compilers, formal methods, reliability engineering, security testing, and agent systems, and return with a structured set of promising directions.
>
> The core question is:
>
> **What deterministic or mostly deterministic engines can we build around an AI coding agent so that correctness does not depend primarily on the model being smart or careful?**
>
> The coding agent can make mistakes. The platform should contain systems that independently constrain, test, attack, replay, compare, or verify its work.
>
> We are especially interested in engines similar in spirit to chaos engineering:
>
> - Chaos Engine
> - Mutation Engine
> - Invariant Engine
> - State Transition Engine
> - Permission Matrix Engine
> - Tenant Isolation Engine
> - Boundary Engine
> - Idempotency Engine
> - Concurrency Engine
> - Rollback Engine
> - Effects Engine
> - Differential Engine
> - Replay Engine
> - Schema Consistency Engine
> - Route/API Contract Engine
> - Dependency Version Engine
> - Reachability Engine
> - Time Engine
> - Patch Scope Engine
> - Blast Radius Engine
>
> But do not treat this list as complete.
>
> The goal of this exploration is to find **new families of deterministic verification mechanisms**, not merely rename existing test types.
>
> ## Important constraint
>
> Do not assume all Laravel applications follow the same architecture.
>
> A project may use:
>
> - controllers directly
> - Actions
> - Services
> - repositories
> - domain modules
> - Livewire
> - Inertia
> - jobs
> - observers
> - events
> - custom authorization
> - Spatie permissions
> - custom tenancy
> - old Laravel patterns
> - highly unconventional internal structure
>
> Avoid solutions that require forcing all projects into a single architecture.
>
> Prefer approaches based on:
>
> - observable behavior
> - runtime effects
> - database state
> - request/response behavior
> - execution traces
> - contracts
> - declared invariants
> - Laravel runtime/framework metadata
> - reproducible experiments
>
> rather than assumptions about folder structure or architectural style.
>
> ## What I want you to investigate
>
> Explore concepts from other areas that could be adapted into deterministic verification engines.
>
> Look particularly at ideas from:
>
> ### Testing
>
> - mutation testing
> - property-based testing
> - fuzzing
> - differential testing
> - metamorphic testing
> - model-based testing
> - combinatorial testing
> - contract testing
> - snapshot testing
> - golden master testing
> - fault injection
> - concolic/symbolic execution
>
> ### Distributed systems
>
> - chaos engineering
> - linearizability testing
> - Jepsen-style verification
> - replay
> - deterministic simulation
> - failure injection
> - event reordering
> - partition simulation
> - retry/idempotency analysis
>
> ### Databases
>
> - invariant checking
> - transaction isolation testing
> - constraint inference
> - consistency verification
> - state transition validation
> - migration safety analysis
>
> ### Security
>
> - authorization matrix testing
> - taint analysis
> - confused-deputy detection
> - IDOR testing
> - tenant isolation verification
> - privilege boundary testing
> - capability security
>
> ### Compilers/static analysis
>
> - abstract interpretation
> - dataflow analysis
> - control-flow analysis
> - effect systems
> - type systems
> - contracts
> - model checking
> - program slicing
> - dependency graphs
>
> ### Reliability engineering
>
> - SLO verification
> - fault trees
> - failure mode analysis
> - redundancy testing
> - recovery testing
>
> ### Agent systems
>
> - independent verifier agents
> - action constraints
> - tool schemas
> - execution sandboxes
> - reproducibility
> - plan-vs-diff comparison
> - self-generated tests and how to avoid circular validation
>
> ## Main objective
>
> Find mechanisms that turn:
>
> > “The model probably wrote correct code.”
>
> into:
>
> > “The system gathered evidence that this behavior is correct.”
>
> The ideal engine should have:
>
> 1. A clearly defined input.
> 2. A deterministic or reproducible procedure.
> 3. Observable outputs.
> 4. A clear failure condition.
> 5. Minimal dependence on architectural style.
> 6. A way to integrate with Laravel.
> 7. A way to decide automatically when the engine should run.
> 8. A useful relationship with other engines.
>
> ## For every promising engine, describe
>
> ### Name
>
> Give it a clear conceptual name.
>
> ### What it verifies
>
> What class of mistakes does it target?
>
> ### Inputs
>
> What does it need?
>
> Examples:
>
> - feature contract
> - HTTP request
> - database schema
> - runtime trace
> - model state
> - route
> - diff
> - previous application version
>
> ### Procedure
>
> How does it operate?
>
> Be concrete.
>
> ### Outputs
>
> What evidence does it produce?
>
> ### Failure condition
>
> What exactly causes the engine to say something is wrong?
>
> ### Laravel integration
>
> How could this reasonably be implemented in Laravel?
>
> Prefer framework-level hooks and runtime instrumentation where possible.
>
> ### Determinism level
>
> Classify it as:
>
> - deterministic
> - seeded/reproducible
> - heuristic but mechanically verified
> - LLM-assisted
>
> Explain why.
>
> ### Architecture dependence
>
> How much does it depend on the project following conventions?
>
> Prefer low dependence.
>
> ### Cost
>
> Classify roughly as:
>
> - cheap
> - moderate
> - expensive
>
> Consider execution time, infrastructure, model usage, and test setup.
>
> ### Best trigger
>
> What kinds of changes should automatically invoke it?
>
> For example:
>
> - authorization changes
> - money movement
> - migrations
> - queued jobs
> - external integrations
> - state transitions
> - tenant-scoped resources
>
> ### Relationship to other engines
>
> Could another engine supply its inputs or validate its output?
>
> ## Look for composability
>
> One of the most important questions is whether these engines can share a common abstraction.
>
> For example:
>
>     Feature
>         ↓
>     Effects
>         ↓
>     Risks
>         ↓
>     Verification engines
>         ↓
>     Scenarios
>         ↓
>     Runtime observations
>         ↓
>     Invariant evaluation
>
> Investigate whether we can define generic primitives such as:
>
> ### Operations
>
> - read
> - write
> - create
> - update
> - delete
> - transition
> - dispatch
> - emit
> - consume
> - call external service
>
> ### Effects
>
> - database mutation
> - queue dispatch
> - email
> - notification
> - HTTP
> - payment
> - storage mutation
> - authentication/session mutation
>
> ### Faults
>
> - timeout
> - unavailable
> - duplicate
> - delayed
> - reordered
> - stale
> - partial success
> - exception
>
> ### Properties
>
> - exactly once
> - at most once
> - at least once
> - atomic
> - isolated
> - authorized
> - unique
> - reversible
> - eventually true
> - never true
>
> Explore whether these primitives are enough, where they break down, and what additional primitives are required.
>
> ## Pay special attention to circular verification
>
> An AI agent may:
>
> 1. misunderstand the requirement,
> 2. implement that misunderstanding,
> 3. write tests matching the misunderstanding,
> 4. pass every test.
>
> Find deterministic techniques that break this loop.
>
> Examples might include:
>
> - mutation testing
> - independent behavioral contracts
> - before/after differential analysis
> - external invariants
> - adversarial scenario generation
> - runtime side-effect comparison
>
> Go much deeper than these examples.
>
> ## Also investigate “unknown unknowns”
>
> Think about ways to discover bugs we did not explicitly anticipate.
>
> Can the system:
>
> - infer useful invariants from schema constraints?
> - infer properties from existing tests?
> - generate permutations of actors/resources?
> - derive boundaries automatically?
> - infer risky side effects?
> - discover race-sensitive operations?
> - identify irreversible effects?
> - recognize that an operation should probably be idempotent?
> - discover unexpected new effects after a patch?
> - automatically compare execution behavior before and after a change?
>
> ## Avoid shallow ideas
>
> Do not return suggestions like:
>
> - “run more tests”
> - “use static analysis”
> - “ask another LLM to review it”
>
> unless you turn them into a concrete engine with defined inputs, procedure, evidence, and failure conditions.
>
> We are looking for platform primitives.
>
> ## Final output
>
> Return:
>
> 1. A map of the overall verification space.
> 2. The existing engine ideas grouped into families.
> 3. New engine ideas discovered during exploration.
> 4. The 10 most promising engines for this platform.
> 5. Any especially powerful engine combinations.
> 6. Gaps that still appear fundamentally hard.
> 7. A proposed minimal common abstraction for the engine system.
> 8. A suggested execution hierarchy from cheap checks to expensive checks.
> 9. Ideas that initially sound good but are likely dead ends.
> 10. Three directions that deserve deeper prototypes before implementation.
>
> Be critical.
>
> Do not assume every idea is good.
>
> Try to find where each abstraction breaks.
>
> The purpose of this exploration is not to validate the current design. It is to discover a better one.

Then, after reading the exploration:

> Ok, but we need to make sure this is asynchronous and does not block the user

> How doable is this?

> Start with step 1, then spike the recorder

Step 1 was the answer's first step: measure, inside today's verification,
whether the tests a change adds fail without it, how many of its new lines a
test runs, and what it did to the app's routes. The recorder is the first
engine of the exploration: it records what one request reads, writes, queues
and sends.

And while the first step was being built:

> Keep going until the engine is complete
