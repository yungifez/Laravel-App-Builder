# Direction 01: Product thesis

> Source: product direction from the project owner, recorded as given (formatting
> repaired only). The consolidated architecture that answers it is in
> [../architecture.md](../architecture.md).

You are helping design and build a Laravel-native AI application builder.

The goal is NOT to build “Claude Code with a Laravel UI,” and it is NOT to compete by having a smarter coding model than Anthropic, OpenAI, or whoever releases the next frontier model.

The product should assume that coding models will continue becoming dramatically better.

Our job is to build the layer around those models that makes them unusually effective, reliable, cheap, and safe when building real Laravel applications.

## Product goal

The initial customer is a non-technical or semi-technical business owner who wants to go from:

idea → working application → production deployment → continued maintenance

without needing to understand a terminal, Laravel architecture, deployment infrastructure, database migrations, queues, policies, testing, or dependency management.

The application should eventually be able to build serious production SaaS products, not just landing pages and CRUD demos.

## Core technical thesis

Laravel is highly opinionated.

That is our biggest advantage.

A generic coding agent has to enter an arbitrary repository and discover:

- architecture
- conventions
- authentication
- authorization
- routing
- validation
- ORM patterns
- deployment assumptions
- testing conventions
- package choices
- frontend architecture
- background processing
- application boundaries

We should avoid making the model rediscover things that Laravel already makes deterministic or strongly conventional.

The guiding principle is:

**Exploit framework determinism before spending model intelligence.**

Whenever something can be understood, generated, verified, or constrained mechanically using Laravel conventions, metadata, static analysis, reflection, Artisan, Composer, Pest, migrations, routes, container bindings, or our own package metadata, prefer that over asking an LLM to infer it.

The frontier model should spend its reasoning on genuinely ambiguous product and engineering problems.

## Stack

Initial blessed stack:

- Laravel 13
- PHP 8.5
- Vue 3
- TypeScript
- Inertia
- Tailwind CSS
- Pest
- PostgreSQL
- Laravel Wayfinder where useful

Prefer Laravel first-party functionality and first-party packages wherever they solve the problem well.

Do not make the system unnecessarily rigid about arbitrary folder structures. We care more about semantic traceability than enforcing one exact filesystem layout.

Controllers should generally orchestrate rather than contain large amounts of business logic.

Services / Actions are preferred for substantial business operations.

Use Laravel-native concepts instead of inventing parallel abstractions.

## The product should have three major layers

### 1. Product layer

This is what the customer interacts with.

Examples:

- project creation
- idea → requirements
- planning
- user flows
- feature definition
- visual editing
- application preview
- deployment
- environment configuration
- domains
- production approvals
- human review where needed
- understandable explanations of what is changing

The user should feel like they are operating a software-building product, not operating an AI coding terminal.

### 2. Intelligence / control layer

This is our main technical differentiation.

Responsibilities include:

- understanding the Laravel application structurally
- maintaining product intent
- task decomposition
- context selection
- blast-radius analysis
- dependency understanding
- model routing
- package trust
- verification
- test selection
- change planning
- escalation when an agent gets stuck
- production safety checks

This layer should be model-agnostic.

Claude, OpenAI models, future coding models, or specialist models should be replaceable execution engines.

Do not architect the product so its moat depends on one model vendor.

### 3. Execution layer

This is where coding agents operate.

Possible engines include:

- Claude Agent SDK
- OpenAI coding agents
- other future coding agents
- our own specialist agents

They operate inside isolated project environments with appropriate access to:

- source files
- git
- shell
- Composer
- npm
- Artisan
- tests
- browser tooling
- logs
- database tooling

We should not try to rebuild a frontier-model coding harness unless we have a concrete advantage in doing so.

Use excellent existing agent runtimes where possible.

## Laravel semantic understanding

The system should understand Laravel concepts as semantic objects, not merely PHP files.

Examples:

A route is not just text in `routes/web.php`.

It may imply:

route → middleware → controller → request validation → authorization → action/service → model interaction → events/jobs → response → frontend page → tests

A `FormRequest` should be recognizable as a validation and possibly authorization boundary.

A Policy should be understood as an authorization relationship.

A migration should be understood as an application schema transition.

A Job should be understood as asynchronous behavior with retry/failure semantics.

An Event and Listener should be understood as side-effect or domain-event relationships.

An Eloquent relationship should become an edge in our representation of the application.

The system should derive as much application structure as possible automatically from Laravel itself.

Useful sources may include:

- `route:list`
- reflection
- AST/static analysis
- Composer metadata
- migrations
- model relationships
- policies
- FormRequests
- events/listeners
- jobs
- notifications
- commands
- middleware
- service container bindings
- frontend route/page relationships
- Pest test relationships

Do not depend on an LLM to maintain a giant manually-created graph if the framework can provide the information deterministically.

## Application graph

We do want an application graph, but it should be grounded primarily in structural facts.

Think of it as:

```
Laravel-derived structural graph
+ product intent
+ human/AI semantic annotations
= application model
```

The graph should help answer questions such as:

- What will this requested change affect?
- Which tests should run?
- Which files are actually relevant?
- Which authorization boundaries are involved?
- Which data models participate?
- Is there an existing capability that already solves this?
- Does a proposed change violate an application invariant?
- Which parts of the system need context passed to the coding agent?

The graph should act partly as a **context compiler**.

Instead of sending a coding model an entire large repository every time, determine the relevant slice of the application and provide that context.

Do not micromanage a frontier coding model unnecessarily.

Assume models like Claude Opus-class systems are already strong software engineers.

Our control plane should make them better informed, cheaper, safer, and more consistent, not constrain them so heavily that we reduce their capabilities.

## Trusted package ecosystem

This is a major part of the strategy.

Laravel already has an unusually strong package ecosystem.

We should create a package trust system.

Preferred dependency order:

1. Laravel native/default functionality
2. Laravel first-party package
3. Our own first-party package
4. Explicitly audited/trusted ecosystem package
5. Dynamically evaluated package
6. Unknown package requiring review

Do not allow agents to casually install arbitrary Composer/npm packages merely because they appear to solve a task.

The package trust engine should eventually consider things such as:

- current Laravel compatibility
- PHP compatibility
- release recency
- maintenance activity
- security advisories
- dependency health
- license
- package abandonment
- maintainer reputation/history
- test quality
- upgrade history
- semantic-versioning behavior
- popularity as one signal, but not as trust itself
- whether Laravel already provides the capability
- whether we have successfully used the package in production
- whether it introduces unusual architectural behavior

Package trust should become machine-readable.

## First-party capability packages

We should build our own packages when we notice the agent repeatedly implementing the same cross-application problem.

Do NOT rebuild things Laravel already solves well.

Instead, package recurring SaaS capabilities where standardization gives us a real advantage.

Potential examples:

- organizations / teams
- invitations
- role management
- feature access
- onboarding
- usage metering
- API keys
- audit logs
- activity feeds
- import/export
- approval workflows
- file workflows
- webhooks
- SaaS billing abstractions
- subscription lifecycle helpers
- admin functionality
- notifications
- soft-delete/recovery workflows

These are examples, not a mandatory package list.

The important pattern is:

agent repeatedly generates capability → identify stable abstraction → turn it into trusted package → document semantics → integrate it into platform → future projects compose it instead of regenerate it

Over time, the ideal system generates LESS foundational code.

It should increasingly compose trusted capabilities and generate only:

- application-specific domain logic
- bespoke workflows
- custom integrations
- custom UI
- business-specific behavior

The best code for an AI builder to generate is often code it no longer needs to generate.

## Package metadata

Our first-party packages should eventually expose machine-readable metadata beyond ordinary Composer metadata.

For example:

- capabilities provided
- models introduced
- tables introduced
- routes introduced
- events emitted
- events consumed
- authorization concepts
- extension points
- configuration
- invariants
- supported modifications
- installation requirements
- testing hooks
- upgrade notes
- agent guidance

This allows the control plane to understand installed capabilities without reverse-engineering them every time.

For example, if `platform/teams` is installed, the system should already know that the app has concepts such as:

- teams
- memberships
- roles
- invitations
- ownership

Then a request such as “allow managers to invite employees” can map onto existing capabilities rather than creating a second team system.

## Verification

Do not rely only on “the browser page loaded” as proof of correctness.

Use multiple verification layers.

Examples:

- static analysis
- type checking
- Pest tests
- targeted test selection
- browser tests
- Laravel structural checks
- dependency checks
- migration checks
- authorization checks
- package policy checks
- security review
- independent AI review for substantial changes

Laravel-aware checks can catch things that generic coding agents may miss.

Examples:

A new mutating route:

- does it require authentication where expected?
- is authorization defined?

A tenant-owned model:

- is tenant isolation enforced?

A migration:

- is it safe for existing production data?
- is rollback behavior acceptable?

A queued job:

- does failure/retry behavior make sense?

A privileged operation:

- is there a policy?
- are authorization tests present?

A major controller:

- has business logic become excessive?

These checks should be deterministic where possible.

## Planning and product intent

The system needs a persistent representation of what the application is intended to do.

Source code alone cannot always tell us:

- why a feature exists
- what users are supposed to accomplish
- which behavior is intentional
- business constraints
- future planned direction
- product terminology
- non-functional requirements
- sensitive production assumptions

Maintain a canonical product plan, but do not turn it into enormous stale documentation.

It should be structured enough to help agents understand the application and update it as the product evolves.

Useful concepts may include:

- product goals
- actors/users
- features
- user flows
- business rules
- invariants
- integrations
- permissions
- lifecycle states
- production constraints

## Model routing and economics

Do not use the most expensive model for everything.

The system should eventually classify work and route appropriately.

For example:

- simple deterministic changes → cheap model or no model
- standard Laravel implementation → normal coding model
- planning / architecture → strong reasoning model
- difficult debugging → stronger model
- repeated failures → escalate
- verification → potentially separate model
- package classification → specialist/cheap models

Use:

- prompt caching
- context caching
- structural retrieval
- targeted file context
- repository summaries
- application graph context
- deterministic tooling

to reduce token consumption.

Model costs should become a variable execution cost, not the fundamental product.

## Safety / production changes

The customer may be non-technical.

The system should distinguish development-time changes from high-consequence production operations.

Require stronger controls or explicit approval around things such as:

- production deployments
- destructive migrations
- secrets
- payment configuration
- external email/SMS sending
- paid infrastructure
- data deletion
- domain/DNS changes
- external integrations with meaningful consequences

The system should be able to explain these choices in normal business language.

## Visual editing

We want a visual editing experience eventually, but it must remain connected to real code.

Users should be able to:

- inspect screens
- select UI elements
- request changes
- potentially adjust visual properties directly
- understand which feature/component they are modifying

The visual layer must not become an independent state system disconnected from source code.

Components and screens should remain traceable to the application implementation.

## What NOT to do

Do not build:

- a generic coding IDE clone
- a thin chat wrapper around Claude
- a system whose value is “we have a better prompt”
- an enormous brittle orchestration graph that prevents the coding model from reasoning
- a custom implementation for every capability Laravel already provides
- a random-package generator
- a frontend-only vibe coding toy
- a proprietary architecture so unusual that normal Laravel developers cannot understand the generated application

Generated applications should remain recognizable, maintainable Laravel applications.

A developer should be able to clone the repository and work on it normally without needing our platform.

This is important.

The platform should add intelligence and structure around Laravel, not create dependency on an alien runtime.

## Near-term implementation philosophy

Start with a narrow vertical slice.

Do not try to build the entire final platform before proving the architecture.

We should first prove that we can:

1. create/manage a Laravel project
2. inspect its Laravel structure
3. derive useful semantic information
4. hand a coding agent targeted context
5. allow the agent to make a meaningful feature change
6. run targeted verification
7. show the result in a preview
8. track what changed and why

Then progressively add:

- richer application graph
- package registry
- trust engine
- model routing
- first-party capability packages
- visual editing
- production deployment
- human review
- broader SaaS workflows

## Architectural quality bar

When making architectural decisions, repeatedly ask:

1. Can Laravel already tell us this deterministically?
2. Can a Laravel default solve this?
3. Is there a first-party Laravel package?
4. Is there an existing trusted package?
5. Is this a recurring problem we should solve once as a first-party capability?
6. Does this actually require frontier-model reasoning?
7. Are we helping the coding agent or unnecessarily constraining it?
8. Does this make generated applications more maintainable outside our platform?
9. Does this reduce future model cost?
10. Does this become more valuable as frontier models improve?

Our architecture should ideally become BETTER every time Anthropic/OpenAI/etc. release stronger models.

A frontier-model release should improve our execution layer rather than threaten the business.

## What was asked

Turn this thesis into an actionable architecture and implementation plan. Challenge weak ideas. Identify where we are overengineering, where Laravel gives deterministic leverage not yet considered, which components to build versus delegate to the Claude Agent SDK or another agent runtime, and the smallest compelling vertical slice. For each major subsystem describe responsibility, boundaries, data it owns, interfaces, what is deterministic, what requires AI, what can wait, and major technical risks. Optimize for something we can actually implement and test, not for an impressive diagram.
