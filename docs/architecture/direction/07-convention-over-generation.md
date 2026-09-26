# Direction 07: Convention over generation

> Source: direction from the project owner, recorded as given (formatting repaired
> only). The message was cut off; see the note at the end.

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

It provides strong conventions around HTTP routing, authentication, authorization, validation, ORM/data access, migrations, queues, events, notifications, scheduling, files, caching, realtime, APIs, testing, dependency injection, configuration, and deployment patterns.

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
```

_(The message was cut off here. The consolidated architecture completes the two
layers from the rest of the direction: the product ontology is Application,
Capability, Behavior, Data, Person / Role, Automation, Connection, Screen; the
implementation ontology is the Laravel substrate: routes, controllers,
FormRequests, policies, models, jobs, events, notifications, migrations,
packages, Vue components.)_
