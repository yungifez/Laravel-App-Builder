# Direction 08: Product discovery and hierarchical context

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

Further refine the architecture around how the system learns what the user is actually trying to build.

A major product principle is:

**The platform should guide users through good agile/product-development behavior without making them feel like they are doing project management.**

The user should not be forced to write: user stories, epics, acceptance criteria, personas, sprint plans, requirement documents, architecture briefs.

Instead, the system should quietly collect the same useful information through natural, contextual questions over time.

The experience should feel like building with an intelligent collaborator, not filling out Jira.

## 1. Do not front-load requirements discovery

Avoid a giant onboarding questionnaire.

Do not ask 20 questions before the user sees anything working.

Initial discovery should be lightweight. Examples of useful early questions:

- What are you trying to build?
- Who is it for?
- What is the main thing people need to accomplish?
- How does this work in the real world today?
- What is currently painful or inefficient?
- What should the finished product make easier?

Then begin building.

Additional questions should appear only when the answer materially affects the next implementation decision.

## 2. Questions should be contextual and opportunistic

The system should identify missing information as the project evolves.

Example. User: "Add scheduling."

Existing project context already tells us:

```
This is software for a cleaning company.
Customers book online.
Staff assign cleaners.
```

But we do not know whether customers choose a cleaner. That decision affects workflow and potentially the data model.

The agent can naturally ask:

```
Should customers choose a specific cleaner,
or should the system assign whoever is available?
```

The answer is stored and reused later.

Do not ask the same question again unless the user changes the decision.

## 3. Only ask when the answer matters

A question is useful when ambiguity could materially affect things such as: data model, permissions, money, business workflow, destructive behavior, external integrations, major product behavior, long-term extensibility, legal/compliance expectations, important UX direction.

If the ambiguity is low consequence, choose a sensible default and continue.

Do not interrupt users over trivial details merely because the system could ask.

A useful internal principle is:

```
Ask when the cost of a wrong assumption
is meaningfully greater than the cost of interrupting the user.
```

## 4. Allow both pre-build and post-build clarification

Some decisions should be clarified before implementation. Example: "Should billing belong to the individual user or the organization?" A wrong assumption here could affect the entire architecture.

Other questions are easier to answer after seeing something. Example: "I built the booking flow so the system automatically assigns available staff. Is that how you want scheduling to work?"

The platform should use both styles.

- **Pre-build clarification**: use when a wrong decision creates substantial rework or risk.
- **Post-build confirmation**: use when prototyping first makes the decision easier for the user to understand.

This should feel iterative.

## 5. Product context should be hierarchical

Do not maintain one giant flat requirements document.

Context should exist at multiple scopes:

```
Application
    ↓
Capability
    ↓
Behavior
    ↓
Current task
```

Each level should inherit relevant context from its parent.

### Application-level context

Information affecting the whole product: real-world use case, target users, primary business goal, terminology, product identity, design direction, accessibility expectations, operating environment, important global constraints, business model, long-term direction, primary devices, regulatory context if relevant.

Example:

```
Product:  Appointment and operations software for independent physiotherapy clinics.
Main goal: Reduce front-desk workload.
Users:    Patients, Reception staff, Practitioners
Design:   Calm, professional, high-trust.
Usage:    Patients mainly use mobile. Staff mainly use desktop.
```

### Capability-level context

Information specific to an area of the product. Example:

```
Capability:      Appointments
Purpose:         Allow patients to self-book routine appointments
                 while staff handle exceptions.
Important rules: Avoid double-bookings.
                 Staff can override normal availability.
Related:         Customers, Staff, Notifications, Billing
```

### Behavior-level context

Information specific to an individual behavior. Example:

```
Behavior:        Cancel appointment
Actor:           Patient
Desired outcome: Release the time slot and notify the clinic.
Rule:            Patient can cancel until 48 hours before.
Staff override:  Yes.
Side effects:    Cancellation email, Calendar update
```

This hierarchical structure should allow context to become more precise without duplicating global information.

## 6. Context inheritance

Lower levels should inherit sensible defaults from higher levels.

Example: application-level design context "Primary users include older clinic staff. Prefer large controls. Avoid dense interfaces." A newly-created staff screen should automatically inherit these expectations unless a capability or screen overrides them.

Another example: application-level terminology "The product calls organizations 'Clinics.'" Do not generate UI saying "Workspace" inside another feature.

The system should preserve vocabulary consistently.

## 7. Context can be overridden locally

Inheritance must not become rigidity.

Example: global design "Low information density." Capability override: "Reporting screens may use higher data density." Specific screen override: "Monthly report table is intentionally compact."

```
application default
    ↓
capability refinement
    ↓
behavior/surface override
```

This pattern should apply to: terminology, design, permissions, workflow assumptions, presentation, user expectations, business rules where appropriate.

## 8. Store confirmed answers gradually

Each meaningful answer should update Project Memory. Examples of categories:

- **Product identity**: What is being built? Why does it exist?
- **Users**: Who uses it? What are their roles? What are their technical abilities?
- **Real-world context**: How does this process currently work outside the software?
- **Goals**: What outcome matters most?
- **Terminology**: What does this business call things?
- **Design direction**: How should the product feel? What devices matter? What accessibility expectations exist?
- **Workflow decisions**: How should the real-world process map into software?
- **Constraints**: What must never happen?
- **Behavior decisions**: What should this particular feature do?
- **Rejected approaches**: What did the user explicitly not want?
- **Future direction**: Where might the product go later?
- **Open questions**: What important decisions remain unresolved?

Do not treat every conversation sentence as permanent memory.

Store durable, product-relevant information.

## 9. Context needs provenance

A stored statement should distinguish where it came from:

- **CONFIRMED**: explicitly stated/approved by the user
- **DERIVED**: established from current implementation
- **PACKAGE CONTRACT**: provided by a trusted capability
- **AI INTERPRETATION**: semantic interpretation
- **PROPOSED**: suggested but not confirmed

For product intent, explicit user confirmation should have the strongest authority.

Do not silently turn an AI guess into a permanent product requirement.

## 10. Context should evolve

Project understanding will change. Early assumption: "Only clinic staff create appointments." Later: "Patients should self-book."

Do not treat the first statement as eternal truth.

Version context and preserve history.

The latest confirmed direction should drive implementation.

Git-style history or append/change tracking is preferable to silently overwriting decisions with no provenance.

## 11. This is hidden agile development

Internally, our concepts roughly correspond to conventional product-development artifacts. But do not expose those terms unnecessarily. Possible mappings:

| Agile artifact          | Platform concept                              |
| ----------------------- | --------------------------------------------- |
| Product vision          | What are we trying to achieve?                |
| Persona                 | Who is this for?                              |
| Epic                    | Capability                                    |
| User story              | Behavior                                      |
| Acceptance criteria     | What should happen when this works correctly? |
| Edge cases              | What could go wrong?                          |
| Backlog                 | Planned capabilities/behaviors                |
| Definition of done      | Verification requirements                     |
| Retrospective knowledge | Project Memory / lessons discovered           |
| Increment               | Completed verified behavior change            |

The platform quietly gives users the benefits of agile product development without requiring them to learn agile terminology.

## 12. The interaction should be enjoyable and lightweight

| Avoid                                             | Prefer                                                                                     |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Please define acceptance criteria for Story #184. | What should happen if the customer tries to cancel after the cutoff?                       |
| Define the actor for this use case.               | Who should be allowed to do this?                                                          |
| Prioritize these backlog items.                   | These three features are enough for a useful first version. Which one matters most to you? |

The user should feel like they are having a productive conversation about their business.

## 13. Questions can themselves be progressive

A simple user might receive "Who will use this?" A power user may expand into roles, permissions, lifecycle, data ownership, API access.

Same underlying discovery system.

Progressive disclosure should apply to product discovery just as it applies to application observability.

## 14. Context should improve agent performance

Before executing a task, the control plane should compile context from:

```
relevant application-level context
    + relevant capability context
    + relevant behavior context
    + current Product Behavior Index
    + relevant implementation facts
    + user's current request
```

Do not send the entire memory store.

Compile the smallest useful context.

Example. User: "Let managers invite contractors." Agent context:

```
Application terminology:  organizations are called Workspaces.
Capability:               Memberships.
Existing product decision: Managers may manage day-to-day workspace membership.
                           Only owners may transfer ownership.
Behavior:                 Invite member.
Current implementation:   Owner/Admin can invite.
Relevant code:            MembershipPolicy, InviteMember, invitation tests
```

This is substantially better than giving the agent only "Let managers invite contractors."

## 15. The discovery system can identify missing knowledge

When compiling a task, ask: "What important decision does this task depend on that Project Memory does not currently answer?"

- If nothing material is missing: proceed.
- If one important ambiguity exists: ask one concise question.
- If several major uncertainties exist: ask the minimum set necessary to avoid likely rework.

Do not use clarification as an excuse to avoid making progress.

## 16. Some defaults should come from our opinionated development model

The user should not have to decide everything. They generally should not need to choose: controller architecture, queue implementation, validation mechanism, migration style, policy implementation, test framework.

Those are implementation decisions covered by our conventions.

Discovery should focus primarily on product decisions, not engineering trivia.

This is another expression of **convention over generation**, and also **convention over questioning**.

Do not ask the user decisions the platform is qualified to make safely.

## 17. Real-world context is especially valuable

Do not limit discovery to desired software screens. Ask enough to understand the process the software represents.

Instead of "What pages should the cleaning application have?", prefer understanding:

```
Customer calls.
Office staff checks availability.
Cleaner gets assigned.
Cleaner completes job.
Customer gets invoiced.
```

Then determine how software should improve that process.

This helps prevent the AI from merely generating generic SaaS UI disconnected from how the business actually operates.

## 18. Design context is first-class product context

Do not treat design as a final styling step. Store design intent hierarchically too.

Application-level examples:

```
Tone:            calm and trustworthy
Density:         low
Audience:        non-technical clinic staff
Primary device:  desktop for staff, mobile for patients
Accessibility:   large interaction targets
```

Capability-level override: "Reporting may be more information-dense." Screen-level override: "Booking confirmation should have one obvious primary action."

This context should guide generated UI, visual editor defaults, component selection, and agent-generated redesigns.

The system should not repeatedly ask the user to restate aesthetic direction.

## 19. Questions should also emerge from contradictions

The system may discover:

```
Project Memory:    Only workspace owners manage billing.
Current behavior:  Administrators can update payment methods.
```

Do not silently choose one. Present the mismatch in plain language:

```
You previously said only owners should manage billing,
but administrators can currently change payment methods.

Should administrators keep this access?
```

The answer updates intent and may trigger a change.

This makes product discovery continuous rather than limited to initial onboarding.

## 20. Discovery should feed the Behavior Index

The Product Behavior Index shows what the application currently does. Project Context shows what the user intends. Together they enable **intended behavior vs current behavior**.

The platform should eventually help users spot: missing behavior, accidental behavior, permission mismatches, workflow mismatches, terminology inconsistencies, design inconsistencies, without requiring them to inspect code.

## 21. Do not create an enormous requirements database in V0

Keep the initial schema small. A reasonable early representation might include:

**ProjectContext**: scope, category, key, value/structured content, source, status, created_at, updated_at.

Where scope might be `application`, `capability`, `behavior`, `surface`, and category might be `goal`, `user`, `workflow`, `terminology`, `design`, `rule`, `constraint`, `future_direction`, `decision`.

Do not prematurely create fifty specialized tables unless evidence shows they are needed.

The conceptual model matters more than the first storage schema.

## 22. V0 should prove incremental learning

User starts: "I need booking software for my cleaning business."

System asks a small number of questions: "Who normally books?" → Customers. "Do customers choose a cleaner?" → No, assign whoever is available.

System builds first slice.

Later user says: "Add recurring bookings."

The system already knows: this is a cleaning company; customers book; cleaners are assigned automatically.

It asks only the NEW important question: "Should recurring bookings try to keep the same cleaner, or assign whoever is available each time?"

That answer is stored. Later changes inherit it.

This demonstrates that the system is learning the product rather than repeatedly treating every prompt as a fresh conversation.

## 23. Strategic principle

The platform should gradually become more useful because it knows more about the product.

Not because it stores endless chat history.

Because it accumulates structured, confirmed understanding.

```
early project:
AI asks several important questions.

mature project:
AI already understands most recurring product decisions
and asks only when genuinely new ambiguity appears.
```

This should make the building experience feel increasingly intelligent over time.

## What was asked

Integrate this hierarchical discovery/context system into the existing architecture. Address:

1. What is the minimal context schema for V0?
2. How should application/capability/behavior context inheritance work?
3. How should context overrides work?
4. How does the control plane determine whether a clarification question is worth asking?
5. How do we avoid annoying users with unnecessary questions?
6. How do we distinguish confirmed intent from AI interpretation?
7. How should context changes be versioned?
8. How does context interact with the Product Behavior Index?
9. How should contradictions between intent and implementation surface?
10. How should visual/design context fit into the same system?
11. Which agile concepts are actually useful to preserve internally?
12. Which agile/project-management concepts should we deliberately omit?
13. How should this context be compiled for coding agents without creating giant prompts?
14. How does this work for both non-technical owners and power vibe coders?
15. What is the smallest implementation that proves the system can learn a product progressively?

Keep the experience lightweight. The user should feel "This thing understands what I am building.", not "This thing made me become a product manager."
