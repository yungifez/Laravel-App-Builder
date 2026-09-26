# Direction 03: Deterministic transformation and Rector

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

The system should treat code changes as belonging to different classes, and should avoid using a frontier model when a deterministic transformation is possible.

A core design principle is:

**Generative models should create new information. Deterministic tooling should propagate known information.**

This distinction matters because a large fraction of software maintenance is not genuinely creative. It is repetitive transformation.

Examples:

- renaming APIs
- upgrading package usage
- replacing deprecated Laravel methods
- changing namespaces
- adding type declarations
- migrating known framework patterns
- converting usage from one package version to another
- normalizing recurring agent-generated patterns
- applying known architectural conventions
- updating thousands of call sites after a capability API changes

These should not require repeated LLM reasoning.

## Rector should be a first-class subsystem

Rector should be treated as one of the platform's deterministic mutation engines.

Do not treat Rector merely as a Laravel upgrade utility.

Its strategic value is that it lets us encode an architectural or API decision once and then safely propagate that decision across one project or thousands of projects.

The ideal division is:

Application understanding → planning → classify required changes → deterministic changes use Rector or equivalent structured transforms → semantic/ambiguous changes use a coding agent → verification

Conceptually:

```
User intent
     ↓
Change planner
     ↓
Change decomposition
  /            \
deterministic   semantic
  ↓               ↓
Rector        coding agent
  \               /
   \             /
   verification
```

The planner should not blindly send the entire task to Claude.

It should determine which parts are already known transformations.

## Example

Suppose a first-party package changes this API:

```php
$team->invite($email);
```

to:

```php
$team->members()->invite($email);
```

Do not ask an LLM to discover and manually update every call site in every application.

Instead:

1. create a Rector rule
2. test it against fixtures
3. dry-run it
4. inspect the transformation
5. apply it
6. run relevant verification

Claude may be useful for designing the transformation rule initially, but once the rule is trusted, future executions should be deterministic.

This should be a recurring pattern:

```
AI solves transformation once
        ↓
transformation becomes infrastructure
        ↓
future applications do not pay AI cost again
```

## Rector rules as institutional memory

Every time we encounter a recurring code transformation, ask whether it should become a rule.

For example:

- an old platform API has been replaced
- agents repeatedly generate an undesirable pattern
- a Laravel release deprecates an old convention
- a trusted package changes its public interface
- a first-party capability package evolves
- an imported application uses an outdated Laravel pattern
- a safer framework-native implementation becomes available

Once encoded as a tested transformation, the platform has permanently learned that migration.

This means the system can improve without retraining a model.

The system should accumulate:

- transformation rules
- migration recipes
- package adapters
- compatibility knowledge
- regression tests
- verification rules

over time.

This body of deterministic knowledge is part of the product moat.

## First-party packages should ship machine-executable upgrade paths

When we build our own capability packages, upgrades should ideally include more than prose documentation.

A package should be able to provide:

- migrations
- version compatibility information
- capability metadata
- Rector rules or transformation sets
- upgrade fixtures
- deprecation metadata
- known breaking changes
- verification rules

Think of this as machine-readable upgrade documentation.

For example:

```
Capability: Organizations
Package: builder/organizations
Version: 3.0

Upgrade:
  1.x → 2.x
    transform set: organizations-v2

  2.x → 3.x
    transform set: organizations-v3

Deprecated:
  Organization::invite()

Replacement:
  OrganizationInvitation::create()
```

The platform should be able to reason about an upgrade before applying it.

## Trusted packages should be operationally understood

A trusted package should mean much more than:

"This package seems reputable."

The platform should ideally know:

- what capability the package provides
- how to install it
- how to configure it
- what environment variables it expects
- what models/tables/routes it introduces
- what events it emits
- what authorization concepts it introduces
- how to test it
- how to upgrade it
- how to remove it
- how to migrate away from it
- known failure modes
- known incompatibilities
- known safe transformation rules

A package becomes deeply trusted when we understand its operational lifecycle.

This creates an important distinction:

Reputation trust: "This package is probably good."

Operational trust: "We know exactly how this package behaves inside our platform."

Prefer the second.

## Package adapters

Consider whether the system should have package adapters.

A package adapter could contain:

- capability manifest
- install recipe
- configuration schema
- environment variable schema
- Laravel-version support
- package-version support
- verification rules
- Rector rules
- uninstall/migration strategy
- agent guidance
- known extension points

This would allow ordinary ecosystem packages to behave more like native platform capabilities.

The package itself remains unchanged.

Our adapter provides platform knowledge around it.

## Package replacement should eventually become possible

The platform should aim to make package abandonment or replacement manageable.

Example:

```
old/package
    ↓
trust engine detects declining maintenance
    ↓
approved replacement exists
    ↓
install replacement
    ↓
deterministic transforms migrate known API usage
    ↓
coding agent handles semantic gaps
    ↓
run tests and verification
    ↓
remove old package
```

This is a long-term capability, not necessarily MVP scope.

But the architecture should avoid making it impossible.

## Imported Laravel applications

Eventually users may bring existing Laravel repositories.

Do not force imported applications into our preferred architecture immediately.

Instead:

1. inspect the project
2. classify its Laravel structure
3. identify deviations from our blessed conventions
4. distinguish harmless variation from problematic structure
5. apply only safe deterministic normalization first
6. run tests
7. use frontier models only for meaningful semantic refactors

The goal is gradual normalization, not "rewrite this app into our architecture."

The platform should respect ordinary Laravel applications.

## Post-generation normalization

Coding models should not need to perfectly obey every trivial code convention in the prompt.

Avoid giant prompts containing dozens of mechanical formatting or modernization requirements if tools can enforce them later.

A likely pipeline is:

```
coding agent
    ↓
Rector normalization
    ↓
Pint
    ↓
PHPStan / Larastan
    ↓
Pest
    ↓
structural checks
    ↓
browser verification where appropriate
```

This reduces prompt complexity and model burden.

The model should focus on correctness and design.

Tooling should enforce mechanical consistency.

## Rector is not the application graph engine

Keep transformation and understanding conceptually separate.

Rector uses ASTs and can inspect code, but do not force it to become the system's entire application intelligence layer.

Prefer a separation like:

```
Laravel introspection
+ AST analysis
+ reflection
+ Composer metadata
+ application metadata
      ↓
Application Graph
      ↓
Planner
      ↓
Mutation engines
    - Rector
    - filesystem edits
    - coding agent
    - migration tooling
    - package manager
      ↓
Verification
```

The graph answers: "What is this?" "What depends on this?" "What should change?"

Rector answers: "Apply this known structural transformation."

The coding agent answers: "Reason about and implement the parts we don't already know."

## Deterministic change catalogue

Consider maintaining a catalogue of deterministic operations.

Examples:

- rename class
- move namespace
- replace method
- change method signature
- add interface implementation
- add/remove trait
- change Laravel API usage
- update first-party package API
- replace deprecated helper
- add explicit types
- migrate a package integration
- transform a known architectural pattern

The planner can select these operations rather than generating arbitrary source edits.

Over time, the deterministic catalogue should grow.

## Do not overuse Rector

Do not try to encode every refactoring as a Rector rule.

Rector is strongest where we can clearly identify A → B based on structural code conditions.

Use frontier models for:

- ambiguous architectural redesign
- business-rule changes
- domain modeling
- new workflows
- novel integrations
- difficult debugging
- separating responsibilities when there is no deterministic answer
- decisions requiring contextual tradeoffs

The system should distinguish "known transformation" from "engineering judgment."

## Rule generation

A potentially powerful workflow is:

```
recurring migration problem
      ↓
strong model designs Rector rule
      ↓
model generates fixtures
      ↓
run against representative code corpus
      ↓
human/automated review
      ↓
trusted rule
      ↓
deterministic future use
```

The AI becomes a tool for creating new deterministic infrastructure.

This is preferable to repeatedly using the AI to perform the same migration.

## Corpus-driven development

As the platform produces more applications, use the corpus to identify recurring patterns.

For example:

- recurring agent mistakes
- repeated package integration issues
- frequently repeated generated code
- recurring migration failures
- common framework modernization opportunities

These should feed back into:

- new Rector rules
- new first-party packages
- package adapter improvements
- better verification rules
- better task routing
- better application graph extraction

The platform should improve from real application experience.

## A useful long-term feedback loop

```
frontier model generates something repeatedly
      ↓
repeated pattern becomes visible
      ↓
platform engineers understand pattern
      ↓
pattern becomes:
    package
    rule
    template
    verifier
    capability
    or deterministic tool
      ↓
future model workload decreases
```

This means the system becomes less dependent on generative work as it matures.

That is desirable.

## Relationship to model routing

The planner should potentially route work through a hierarchy like:

```
no model required      → deterministic transform
cheap model            → classification / summarization
standard coding model  → ordinary feature implementation
strong frontier model  → architecture / difficult debugging / ambiguous changes
strongest escalation   → repeated failures / critical reasoning
```

Before selecting a model, ask whether a model is required at all.

That should be a central cost-control principle.

## Relationship to the capability system

The application should increasingly be understood in terms of installed capabilities rather than only source files.

Example:

```
App capabilities:
  Identity
  Organizations
  Billing
  File Storage
  Audit History
```

Each capability may map to:

- Laravel native features
- Laravel first-party packages
- our first-party packages
- trusted ecosystem packages
- custom application code

The system should preserve this distinction.

The application graph should connect semantic capabilities to concrete code.

For example:

```
Capability: Organizations
    ↓
package: builder/organizations
    ↓
Organization model
Membership model
OrganizationPolicy
invitation routes
invitation notification
membership UI
tests
```

Then when product intent changes, the planner can reason from business concept to implementation surface.

## Model context should be compiled, not dumped

Do not assume larger context windows mean we should dump entire repositories into the model.

Use the application graph, capability metadata, and Laravel structural knowledge to compile relevant context.

Example task: "Managers should be able to invite contractors."

The model might receive:

- relevant product rule
- Organization capability metadata
- current role enum
- invitation action
- OrganizationPolicy
- relevant Vue page
- related tests
- known package invariants

instead of 500 unrelated files.

The model may still explore the repository when needed.

The context system should guide, not imprison, the agent.

## Application invariants

The platform should eventually represent important invariants explicitly.

Examples:

- organization must always have an owner
- users cannot access another tenant's records
- billing admin role is required to modify subscriptions
- deleting a customer must not delete financial history
- paid feature requires entitlement
- invitation tokens expire
- production migrations cannot lose existing data

Invariants may come from:

- first-party capability metadata
- trusted package adapters
- product planning
- explicit user requirements
- inferred application behavior

Verification should check these where possible.

This is one area where our structured platform can outperform a generic coding agent.

## Continuous modernization

Do not think only in terms of feature generation.

The system should also support software evolution.

Over the lifetime of an application:

- PHP changes
- Laravel changes
- packages change
- conventions improve
- vulnerabilities appear
- old APIs become deprecated
- first-party capabilities evolve
- application requirements change

The platform should be designed to keep applications healthy over years.

Potential future operations:

- upgrade Laravel
- upgrade PHP
- upgrade trusted packages
- apply safe modernization rules
- migrate away from abandoned packages
- improve static typing
- replace deprecated APIs
- update capability packages
- automatically generate upgrade branches and validate them

This long-term maintenance story may be one of the strongest differentiators from ordinary vibe coding platforms.

## Application ownership and portability

Despite all of this intelligence, generated applications must remain ordinary Laravel applications.

Avoid requiring a proprietary runtime just to execute the app.

A developer should still be able to:

```
git clone
composer install
npm install
php artisan migrate
npm run build
```

and understand the project using normal Laravel knowledge.

Our platform metadata can add value, but it should not make the application unusable without the platform.

This protects users from lock-in and makes the generated code easier for human developers to adopt.

## Strategic framing

The product should not attempt to beat Claude Code at being Claude Code.

The coding agent is an execution dependency.

Our differentiation comes from creating a world in which the coding agent operates unusually well.

That world consists of:

- Laravel conventions
- Laravel defaults
- Laravel first-party packages
- trusted ecosystem packages
- first-party capability packages
- application graph
- persistent product intent
- deterministic transformations
- package adapters
- verification
- deployment safety
- model routing
- visual/product-layer UX

The more structured and reusable this environment becomes, the less open-ended work the model has to perform.

## Desired architecture behavior

When receiving a requested change, the system should conceptually ask:

1. What does the user actually want at the product level?
2. Which application capability does this involve?
3. What concrete implementation surfaces are connected to that capability?
4. Is there already a Laravel/default/package solution?
5. Is this a known deterministic transformation?
6. Can Rector or another tool perform any part of this change?
7. What remaining parts require model reasoning?
8. What is the smallest useful context for the coding agent?
9. What invariants could this change affect?
10. Which tests and verification steps are relevant?
11. Is this safe to apply automatically?
12. Does this require user approval because it affects production, billing, secrets, or data?
13. After successful implementation, did we learn something reusable?
14. Should part of this solution become a package, adapter, rule, verifier, or capability definition?

That last question is very important.

The platform should continuously convert repeated generative work into reusable deterministic infrastructure.
