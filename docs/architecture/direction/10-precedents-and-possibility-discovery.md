# Direction 10: Precedents and possibility discovery

> Source: direction from the project owner, recorded as given (formatting repaired
> only). The message was cut off during section 22; the remainder has not been
> received yet.

Add another major refinement to the discovery architecture:

Users often do not know what possibilities exist.

Even with good clarification questions, a non-technical user may not know enough about software, product design, or their category of application to specify the right behavior.

Therefore discovery should not only ask:

```
What do you want?
```

It should also be capable of saying:

```
Here are common ways products like this solve the problem.
Which direction fits you?
```

This should become a first-class part of the product.

## 1. Introduce a Pattern / Precedent Library

Maintain a knowledge layer containing common product patterns, workflows, design patterns, and capability approaches.

This is NOT intended to become a rigid template marketplace.

It should primarily help the system:

- ask better questions
- expose options the user may not know exist
- suggest sensible defaults
- explain tradeoffs
- reduce unnecessary reinvention
- help users make informed product decisions

Conceptually:

```
Project Context
      +
Current Application Behavior
      +
Relevant Product Precedents
      ↓
Better discovery question
      ↓
Confirmed user decision
```

## 2. Example

User:

```
I need booking for my cleaning company.
```

A weak system asks:

```
Describe your booking workflow.
```

A stronger system knows common patterns and presents the decision in understandable terms:

```
There are a few common ways this works.

Simple:
Customers request a time and you confirm it.

Automatic:
Customers only see times when someone is available.

Advanced:
Availability also considers staff skills, location,
travel time, buffers, and recurring jobs.

Which is closest to how you want to work?
```

The user did not need to know these approaches existed beforehand.

## 3. Do not expose complexity unnecessarily

The amount of guidance should adapt naturally to the user's depth.

### Non-technical user

Present a few understandable choices.

Example:

```
How should scheduling work?

Recommended:
Customers choose from available times and the system assigns staff.

Simpler:
Customers request a time and staff confirms it.

More advanced:
Availability considers staff, travel, skills, and buffers.

You can start simple and change this later.
```

### Power vibe coder

Allow deeper expansion:

```
Scheduling patterns

- request / approval
- availability-based booking
- resource scheduling
- skill-based assignment
- geographical dispatch
- recurring scheduling
- waitlists

Common related capabilities:
- buffers
- reminders
- cancellations
- rescheduling
- calendar synchronization
- no-show handling
```

The same precedent system should support both users through progressive disclosure.

## 4. Avoid hard beginner/expert modes

Continue using progressive disclosure rather than forcing the user to pick a technical persona.

Default to the simplest useful explanation.

Allow:

```
Show alternatives
Show more control
Advanced options
```

A power user can keep drilling deeper.

A non-technical user may simply accept the recommended approach.

## 5. Precedents should exist at multiple scopes

The Pattern Library should align with our hierarchical context model.

### Application-level precedent

Example:

```
Marketplace
```

Common major decisions:

```
- who receives payment
- whether the platform holds funds
- disputes
- commissions
- seller onboarding
- reviews
- messaging
```

Do not ask all of these immediately.

Use them to know which questions may become relevant later.

### Capability-level precedent

Example:

```
Messaging
```

Common patterns:

```
- one-to-one messages
- customer/support conversations
- group conversations
- threaded discussion
- realtime chat
- asynchronous inbox
```

### Behavior-level precedent

Example:

```
Refund payment
```

Common approaches:

```
- staff can refund freely
- refunds above a threshold require approval
- only owners can refund
- automatic refund under defined conditions
```

### Design precedent

Example:

```
Booking flow
```

Common approaches:

```
- service-first
- availability-first
- staff-first
- guided multi-step booking
```

The system should explain these in product language.

## 6. Use precedents to discover unknown unknowns

A major value of the system is helping users make decisions they did not know they needed to make.

Example:

User says:

```
Add subscriptions.
```

The system knows that subscription products commonly need decisions around:

```
individual vs organization billing
monthly vs annual billing
trials
cancellation behavior
grace periods
usage limits
failed payments
plan changes
```

Do not dump this entire list onto the user.

Instead determine which decision materially affects the next implementation.

Example:

```
One important thing before I build billing:
should the subscription belong to each person,
or does one subscription cover the whole organization?
```

The precedent library helps identify the important question.

## 7. Precedents should influence defaults, not dictate product decisions

Never treat precedent as product truth.

Use language such as:

```
A common approach is...

Most products of this type usually...

One simple option is...

You could also...
```

The user remains the decision maker.

Do not silently enforce:

```
"This is how booking software works."
```

Established patterns are evidence, not requirements.

## 8. Support recommendations with rationale

When recommending a default, explain why briefly.

Example:

```
Recommended:
Automatically assign available staff.

Why:
You said the main goal is reducing office scheduling work.
```

This is stronger than:

```
Most companies use this.
```

Recommendations should combine:

```
precedent
    +
user's own Project Context
```

Example:

```
Known project goal:
Reduce receptionist workload.

Common scheduling pattern:
automated availability.

Recommendation:
Use automated availability rather than manual approval.
```

The user's own context should matter more than generic precedent.

## 9. The library should contain patterns, not copied applications

Do not make the architecture dependent on cloning existing products.

Represent reusable knowledge.

For example:

```
Pattern:
Availability-based booking

Useful when:
Users should self-book without staff approval.

Usually requires:
availability source
conflict protection
cancellation/rescheduling rules

Common extensions:
buffers
staff selection
recurring booking
waitlist

Tradeoffs:
more setup than request/approval
less manual administration
```

This is more valuable than:

```
Clone Calendly.
```

## 10. User-provided references are highly valuable

Users naturally communicate intent by referencing existing products.

Example:

```
"Make this work like Lovable here."
```

or:

```
"I want Linear-style issue creation."
```

or:

```
"Use the checkout flow from Shopify."
```

Treat this as high-information context.

But do not blindly copy.

Ask what aspect they mean when ambiguity matters:

```
Is it the visual design, the workflow, or both?
```

Potential stored context:

```
Reference:
Linear issue creation

User likes:
fast inline creation workflow

Not necessarily:
visual styling
```

This can guide later implementation.

## 11. References can apply to different dimensions

A reference may represent:

- visual style
- interaction pattern
- workflow
- information architecture
- navigation
- behavior
- terminology
- density
- animation
- onboarding

Store what the user actually liked rather than simply storing:

```
"Use Product X."
```

Example:

```
Design reference:
Stripe dashboard

Desired aspects:
information hierarchy
restrained visual style

Not requested:
exact colors
```

This prevents later agents from over-interpreting references.

## 12. Precedent retrieval should be contextual

Do not load the entire pattern library into agent context.

Given:

```
current project
current capability
current behavior
current uncertainty
```

retrieve only relevant patterns.

Example:

```
Task:
Add recurring bookings.
```

Retrieve:

```
recurring scheduling patterns
staff continuity patterns
cancellation implications
```

Do not retrieve unrelated booking-system knowledge.

This should work similarly to the Context Compiler.

## 13. Pattern retrieval can improve clarification quality

The question engine should not merely detect:

```
information missing.
```

It should detect:

```
there are multiple common meaningful choices here.
```

Example:

Missing:

```
how memberships are billed.
```

Precedent library knows:

```
common options:
- per seat
- flat organization subscription
- usage-based
- hybrid
```

Then ask:

```
How do you want organizations charged?

- One price for the whole organization
- Per member
- Based on usage
- Something different
```

A simple user can pick one.

A power user can expand into details.

## 14. "Something different" must always exist

Do not let the precedent system become low-code constraints.

Every suggestion should allow:

```
Something different
```

Then the user can describe a custom workflow.

The system should treat precedents as a fast path, not the only path.

Principle:

```
strong default path
    +
unrestricted custom escape hatch
```

## 15. Precedents and "convention over generation"

This complements our existing principle.

We now have several levels of avoiding unnecessary invention:

```
framework convention
    ↓
trusted package
    ↓
first-party capability
    ↓
product precedent
    ↓
custom generation
```

Be careful:

Product precedent is not necessarily an implementation.

It is primarily guidance around product behavior.

For example:

```
"waitlist booking"
```

may identify a known product pattern even if the exact implementation is application-specific.

## 16. Sources for the precedent library

Potential sources may eventually include:

- intentionally curated internal product knowledge
- established public software/product patterns
- framework/package capability knowledge
- public product examples
- domain research
- successful patterns from our own first-party capabilities
- aggregate anonymized operational insights where privacy allows
- user-provided references

Do not assume customer source code can simply be mined for this.

Privacy restrictions from previous architecture decisions still apply.

## 17. Treat precedent quality seriously

A bad recommendation can steer an inexperienced user in the wrong direction.

Therefore precedent entries should ideally contain:

- category
- when it applies
- advantages
- disadvantages
- important decisions
- complexity
- common extensions
- known dependencies
- source/provenance
- confidence or curation status

Avoid pretending all patterns are universally good.

## 18. Precedent complexity can guide progressive disclosure

Patterns can have an approximate complexity level.

For example:

```
Booking

Level 1
Request and approval

Level 2
Availability-based self-booking

Level 3
Staff/resource scheduling

Level 4
Skill + geography + routing optimization
```

Do not expose these as technical "levels" unless useful.

They are primarily a mechanism to avoid overwhelming users.

The system can say:

```
The simplest option is...

If you need more control...

For more complex operations...
```

This is particularly useful for the non-technical target user.

## 19. The system can suggest future capabilities without forcing them now

Example:

User builds basic appointment booking.

Pattern knowledge knows that many systems later add:

```
reminders
rescheduling
cancellation rules
waitlists
```

Do not immediately generate all of them.

Potentially surface:

```
Common next steps

- reminders
- online rescheduling
- cancellation policy
```

This can help users discover possibilities while preserving incremental development.

This also fits the hidden-agile philosophy:

```
build a useful slice
    ↓
expose sensible next options
    ↓
user chooses
    ↓
next increment
```

## 20. Avoid feature bloat

The precedent system must NOT turn every project into:

```
"Here are the 87 features products like yours usually have."
```

This would reproduce exactly the bloated planning process we want to avoid.

Suggestions should be:

- timely
- directly relevant
- few in number
- explained in plain language
- optional

Prefer:

```
2-4 meaningful options
```

rather than:

```
giant checklist.
```

## 21. Product discovery becomes three-way reasoning

Our discovery engine should now combine:

### What the user has told us

Project Context.

### What the application currently does

Product Behavior Index.

### What established solutions commonly do

Pattern / Precedent Library.

Conceptually:

```
PROJECT CONTEXT
       +
CURRENT BEHAVIOR
       +
RELEVANT PRECEDENTS
       ↓
DISCOVERY / QUESTION ENGINE
       ↓
What decision do we actually need from the user?
       ↓
confirmed context
       ↓
implementation
```

This is stronger than clarification from missing fields alone.

## 22. Example end-to-end interaction

User:

```
I want subscriptions.
```

System already knows:

```
Product is collaboration software.
Organizations contain multiple users.
Owner manages the organization.
```

Relevant precedent identifies a major early decision:

```
or
```

> _[The message ends here.]_
