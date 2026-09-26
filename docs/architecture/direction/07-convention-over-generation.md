# Direction 07: Convention over generation

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

Laravel is NOT the marketing category.

Laravel is the current technical substrate that gives us leverage.

The product should be positioned around a more general idea:

**Build software through opinionated, understandable, proven patterns instead of regenerating architecture from scratch every time.**

A useful internal phrase is:

**Convention over generation.**

This is intentionally analogous to "convention over configuration."

The principle is:

- If the framework already solves a problem well, use the framework.
- If a first-party package solves it well, use that.
- If a trusted ecosystem package solves it well, use that.
- If we repeatedly solve the same problem, turn it into a trusted capability/package.
- Use generative AI primarily for the parts that are genuinely specific, ambiguous, or novel.

The platform should reduce unnecessary generation over time.

## Do not market this primarily as a Laravel builder

The target customer does not need to care that the backend is Laravel.

For a non-technical customer, concepts should look like: Customers, Appointments, Payments, Staff, Documents, Notifications, Reporting; not: Controllers, Models, Jobs, Policies, Laravel packages.

For a power vibe coder, technical details can progressively appear.

For a developer, Laravel can be fully visible.

But Laravel should generally be implementation detail rather than the product's public identity.

## Why Laravel still matters deeply

Although Laravel is not the headline, it is a major strategic advantage because it is unusually opinionated and mature.

It provides strong conventions around: HTTP routing, authentication, authorization, validation, ORM/data access, migrations, queues, events, notifications, scheduling, files, caching, realtime, APIs, testing, dependency injection, configuration, deployment patterns.

This lets us constrain the problem before an AI model sees it.

A generic coding agent must often discover architecture.

Our system should already understand a large portion of the application's shape.

That is the internal advantage.

## The product should feel framework-agnostic to the customer

A user should think "I am building my application.", not "I am building a Laravel application."

The system can expose Laravel when useful, but should not require users to understand it.

This distinction is especially important because the product should theoretically be able to support other opinionated stacks later.

Do NOT architect V0 as a generic multi-framework system.

We should exploit Laravel very aggressively first.

But avoid unnecessary product-level assumptions that make the user-facing model itself Laravel-specific.

Good user-facing concepts: Capability, Behavior, Data, Person / Role, Automation, Connection, Screen.

Bad user-facing core concepts: Controller, Route, Eloquent Model, FormRequest, Job.

Internally those Laravel concepts remain extremely useful.

## Separate product ontology from implementation ontology

Think in two layers.

### Product ontology

What the user understands:

```
Application
Capability
Behavior
Actor
Data
Rule
Automation
Connection
Surface
```

### Implementation ontology

What the platform understands:

```
Laravel route
controller
request
policy
action
model
event
listener
job
notification
package
Vue component
test
```

The platform maps between them.

Do not collapse these layers.

This mapping is part of the product's value.

## The system should explain software at the level people naturally think about it

Traditional source code organizes software around implementation.

Business owners think about software around outcomes.

Implementation:

```
POST /appointments
StoreAppointmentRequest
AppointmentPolicy
CreateAppointment
AppointmentCreated
SendAppointmentConfirmation
```

Product view:

```
Book an appointment

Customers choose an available time and submit their details.
The appointment is saved and a confirmation is sent.
```

These are two representations of the same thing.

The product should allow users to move progressively between them.

## "Convention over generation" has architectural consequences

This should not be only a slogan.

Use it to make architecture decisions.

Before generating code, ask:

1. Does the framework already provide this?
2. Does a first-party package already provide it?
3. Does a trusted package already provide it?
4. Does one of our capabilities already provide it?
5. Is this a known deterministic transformation?
6. Is this truly application-specific enough to justify generation?

The preferred direction should be:

```
reuse
→ configure
→ compose
→ transform
→ generate
```

Generation is last, not first.

## This improves more than cost

Do not frame this only as token optimization.

Convention over generation should improve: consistency, maintainability, upgradeability, security, testing, observability, documentation, user understanding, package reuse, supportability, model context size, model reliability.

The fewer arbitrary architectural decisions we create, the easier applications are to understand later.

## The application should remain ordinary software

The generated application should still be a normal Laravel application.

Our platform should not require a proprietary runtime for the application to function.

A developer should still be able to clone it and understand it using standard Laravel knowledge.

The platform adds: product understanding, observability, deterministic tooling, trusted capabilities, agent orchestration, visual interaction, behavior review, maintenance automation.

It should not replace normal application architecture with a proprietary interpreter.

## Generality without genericity

The product should be capable of building much more than simple CRUD SaaS applications.

Laravel's primitives are broad enough to express: marketplaces, internal business software, booking systems, APIs, workflow applications, community products, dashboards, subscription products, content platforms, integration services, automation-heavy backends, realtime applications, administration systems.

Do not artificially constrain the product to "SaaS templates."

At the same time, do not abandon the opinionated stack in pursuit of theoretical flexibility.

The product should be broad in what users can build while narrow and disciplined in how the platform builds it.

This is an important distinction:

**Broad outcomes, constrained implementation.**

## This is part of the moat

Generic coding agents can support arbitrary stacks.

That flexibility is their strength, but it limits how many assumptions they can safely make.

Our product should exploit the opposite.

Because we control the development environment, we can know: how auth normally works, where authorization belongs, how jobs work, how migrations work, how packages are installed, how tests are structured, how frontend/backend routing connects, which packages are trusted, which transformations are safe, which capabilities already exist.

That allows us to provide better context, verification, observability, and maintenance than simply dropping a generic coding agent into a random repository.

## Strong models should reduce our orchestration burden

As frontier models improve, do not respond by building increasingly elaborate planning layers around them.

Use stronger models for what they are good at.

Our durable work should remain in areas models do not automatically own: product intent, application observability, package trust, package lifecycle knowledge, capability reuse, deterministic transformations, behavior-level review, safe production operations, visual selection/context, empirical routing, framework-specific verification.

The goal is not to out-reason the coding model.

The goal is to provide a better software-building environment around it.

## Product positioning

Avoid positioning such as "Claude Code for Laravel" or "Lovable for Laravel". Those descriptions may be useful shorthand internally, but they undersell the product and make the competitive frame too narrow.

The product story is closer to:

- "Build software with AI without losing control of how it works."
- "AI software development built on proven patterns instead of constant reinvention."
- "Build real software in a structured, understandable way."

Do not spend time polishing marketing slogans yet.

Instead, use this framing to test whether architectural decisions support the product thesis.

## Two users remain central

Continue designing for both:

- **Non-technical owner**: cares about what the application does, what people can do, what happens automatically, what data exists, who has access, what changed. They should not need to know the implementation framework.
- **Power vibe coder**: wants deeper control, behavior details, implementation references, package information, API details, tests, source code, direct technical prompting.

Use progressive disclosure, not separate products.

## Visual interaction fits this philosophy

The visual editor is not merely cosmetic.

It gives users a natural way to identify intent.

A user can click something and say "Change this."

The platform can resolve: rendered element → component → behavior → capability → relevant implementation.

A simple style adjustment may be deterministic.

A semantic change can be sent to the appropriate agent with highly relevant context.

This is an example of the platform translating human intent into structured engineering context.

## Long-term evolution

As the platform matures, repeated AI-generated solutions should increasingly become: framework conventions, trusted package integrations, first-party capability packages, deterministic rules, verification rules, reusable UI components, package adapters.

The mature platform should need LESS arbitrary generation for common application concerns than the early platform.

That is desirable.

The system should accumulate engineering leverage.

## Do not mistake reuse for low-code templates

This should not become a rigid low-code system where users are limited to predesigned blocks.

Trusted capabilities establish reliable foundations.

AI remains available for application-specific behavior and novel requirements.

The system should be able to escape the predefined path whenever the user's requirements genuinely demand it.

Think:

```
strong default path
+
unrestricted code escape hatch
```

rather than:

```
finite template catalogue
```

## Strategic test

For every major product decision, ask:

- Does this make the application easier to understand?
- Does this reduce unnecessary reinvention?
- Does this make future changes safer?
- Does this benefit from Laravel's opinionated structure?
- Does this remain useful if the coding model becomes dramatically smarter next year?
- Does this help both the non-technical owner and the power vibe coder?
- Does this preserve ordinary source code as the final artifact?

If several answers are no, challenge the feature.

## What was asked

Reassess the current architecture using "convention over generation" as a central principle. Identify:

1. Which parts of our system are currently still over-reliant on generated code.
2. Which recurring concerns should preferentially become capabilities.
3. Which Laravel defaults we should deliberately exploit in V0.
4. Which areas should remain fully open-ended for coding agents.
5. Whether our Product Behavior Index remains sufficiently framework-independent at the product layer.
6. Which implementation details are leaking unnecessarily into the product model.
7. Where the architecture is starting to resemble low-code and should retain an escape hatch.
8. Which architectural choices make us more resilient to future frontier-model improvements.
9. Which parts of this thesis can be proven in the first vertical slice.
10. What we should explicitly NOT build yet.

Keep pushing toward simplification. The objective is to establish a small foundation that can eventually support broad application outcomes through constrained, predictable implementation, with AI handling the genuinely application-specific parts.
