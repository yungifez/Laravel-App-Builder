# Direction 17: Grandma first

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

Add an explicit product rule:

**Grandma first. Power users can drill down. Never require Grandma to drill up.**

This is not meant literally as demographic targeting. It is an internal forcing function.

The test is:

> Could someone who understands their business extremely well but knows almost nothing about software make the correct decision here?

If not, we are leaking implementation complexity into the product.

The sophisticated architecture should remain underneath the experience.

## 1. Ask users about their world, not ours

Do not ask:

```
Choose your tenancy architecture.
```

Ask:

```
Can people from one clinic ever see another clinic's customers?
```

Do not ask:

```
Should this use a Policy?
```

Ask:

```
Who should be allowed to do this?
```

Do not ask:

```
Configure recurring jobs.
```

Ask:

```
Should this happen automatically every morning?
```

Do not ask:

```
Select your billing architecture.
```

Ask:

```
Does each person pay, or does one subscription cover the whole business?
```

The user knows:

- customers
- employees
- operations
- money
- business rules
- exceptions
- what should happen
- what must never happen

That should be enough.

The platform translates those answers into architecture.

## 2. Internal concepts should normally remain internal

The architecture may contain:

- Project Context
- Capability
- Behavior
- Actor
- Surface
- Effects
- Context Compiler
- Change Brief
- implementation references
- verification plans
- Jev/model routing
- Laravel Policies
- FormRequests
- queues
- worktrees

These are not default user-interface vocabulary.

Translate them.

For example:

```
Behavior
    → What people can do

Actor
    → Who can do it

Effects
    → This may also affect...

Preserve clause
    → Keep these working the same

Change Brief
    → Here's what I'm changing

Project Context
    → What I know about your business

Verification
    → Checks I ran

Behavior Diff
    → What changed

Capability
    → Appointments / Billing / Customers

Surface
    → Where people use this
```

## 3. Change Brief remains an internal artifact

The internal engine can compile:

```
REQUEST

INTENT

CURRENT BEHAVIOR

DESIRED BEHAVIOR

PRESERVE

EFFECTS

IMPLEMENTATION

VERIFY
```

Excellent.

But Grandma should see something like:

```
Here's what I'm changing

Managers will be able to invite contractors.

I'll keep these the same

- Owners can still invite people
- Invitation emails still send
- Billing stays unchanged

This may also touch

- New-member onboarding

[Make the change]
```

Same engine.

Different abstraction.

## 4. Project Understanding should feel like business understanding

Our current Project Understanding concept is correct, but the default UI should not look like an ontology editor.

Instead of:

```
Product
Users
Capabilities
Rules
Integrations
Future direction
```

prefer something closer to:

```
Your business

What this app is for

Who uses it

How things work

Important rules

Connected services

Things you want to add later
```

The user should feel like they are correcting our understanding of their business.

Not maintaining software metadata.

Power users can expand deeper views.

## 5. Progressive disclosure hierarchy

The same information should support several depths.

### Grandma

```
What does this do?
```

### Interested owner

```
When does it happen?
Who can do it?
What else might it affect?
```

### Power user

```
How is this implemented?
What rules/tests/packages are involved?
```

### Developer

```
Show me the source.
```

Conceptually:

```
WHAT
  ↓
WHY / WHEN
  ↓
HOW
  ↓
SOURCE
```

Never require the user to understand the lower levels to operate the higher ones.

## 6. Visual editor is already strongly aligned

Do not expose:

```
flex-row
flex-wrap
items-center
gap-4
```

Expose:

```
Direction:
Across

Items:
Wrap onto another line

Alignment:
Center

Space:
16px
```

Likewise:

```
lg:grid-cols-3
```

becomes:

```
Desktop:
3 columns
```

Power users can reveal Tailwind.

The default interface operates in visual concepts.

This is a model for the entire product.

## 7. Backend visualization should follow the same rule

Do not default to:

```
Route
    ↓
FormRequest
    ↓
Policy
    ↓
Action
    ↓
Event
```

Show:

```
When someone requests a refund

1. Check that they're allowed.
2. Check the refund details.
3. Send the refund.
4. Update the invoice.
5. Notify the customer.
```

Power user expands:

```
POST /invoices/{invoice}/refund
    ↓
RefundInvoiceRequest
    ↓
InvoicePolicy
    ↓
RefundInvoice
    ↓
RefundProcessed
```

Same system.

Different depth.

## 8. Discovery should remain conversational

Do not use:

```
questionnaire
requirements wizard
persona form
acceptance-criteria editor
```

Prefer:

```
"Do customers choose a cleaner,
or should you assign whoever is available?"
```

And when users do not know what is possible:

```
"Most businesses handle this one of these ways..."
```

Then give two or three understandable options.

The user should not need prior software-product knowledge.

## 9. Package decisions should disappear from the default experience

Grandma should not choose:

```
Cashier
Stripe SDK
custom subscriptions
```

She should answer:

```
Does each person pay,
or does one business subscription cover everyone?
```

The engine chooses implementation.

This is complexity cutting.

## 10. Publishing should use the same philosophy

Do not expose by default:

```
migrations
queues
environment variables
container image
Laravel Cloud service topology
```

Show:

```
Ready to publish

✓ App checks passed
✓ Database change checked
✓ Background tasks ready
✓ Restore point created

[Publish]
```

Power user expands technical detail.

## 11. Audit/review language should also translate

Instead of:

```
Run codebase audit.
```

Prefer:

```
Check my app.
```

Possible levels:

```
Quick check
Look for obvious problems.

Thorough check
Check important workflows and permissions.

Deep check
Look carefully for hidden problems and things that may have drifted over time.
```

The internal engine may use static analysis, multiple models, tests, dependency analysis, etc.

The user chooses assurance, not engineering tools.

## 12. KISS should be aggressive

The product should feel simple because the engine is sophisticated.

Do not expose architecture merely because we are proud of it.

If a decision can be represented with:

```
one sentence
two or three options
```

do that.

Do not build a graph editor for something that can be communicated in plain English.

## 13. Explicit V1 UX constraint

Add the rule:

> No internal architectural noun is allowed into the default UI unless Grandma needs it to make a business decision.

Every internal concept should have a user-language projection.

This translation layer should be considered part of the V1 product architecture, not cosmetic copywriting.

## 14. Why this matters competitively

Many AI builders claim to support non-technical users but still implicitly ask them to think like software builders:

- specify screens
- specify features
- review technical plans
- reason about repositories
- understand deployment concepts
- repeatedly articulate architecture

Our product should reduce that cognitive burden.

The goal is:

```
user understands the business
        ↓
system understands software
```

not:

```
user gradually learns enough software terminology
to operate the AI coder.
```

This aligns with the three product pillars:

### Complexity cutting

Remove implementation decisions from the user.

### Observability

Explain software in terms the owner understands.

### Evolution

Let the owner continue changing the application using product/business language even after the codebase becomes complex.

## Final design test

For every major V1 surface, ask:

> Could Grandma make the correct decision here without understanding how software is implemented?

If no:

simplify the interface or move the complexity underneath.

The sophistication belongs in the engine.

The simplicity belongs in the product.
