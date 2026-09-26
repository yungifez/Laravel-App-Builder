# Direction 14: Laravel-native deterministic active testing

> Source: direction from the project owner, recorded as given (formatting repaired
> only). The first copy was cut off during section 14; this is the complete
> message, sent again.

Add a later-stage assurance concept:

**Laravel-native deterministic active testing.**

This is not for V0, but the architecture should leave room for it because Laravel gives us a major advantage over generic DAST/security tools.

A generic scanner starts outside the application:

```
running app
    ↓
crawl
    ↓
discover routes/forms
    ↓
infer parameters
    ↓
guess authentication boundaries
    ↓
probe
```

Our platform can start from the framework's own structure.

Laravel already exposes a large amount of deterministic application knowledge through things such as:

- `route:list`
- route metadata
- middleware
- FormRequests
- validation rules
- route model binding
- policies
- gates
- auth guards
- Eloquent models
- casts
- relationships
- enums
- controllers/actions
- jobs/events
- tests
- signed URLs
- session/CSRF behavior

Therefore the assurance engine does not need to rediscover the application from scratch.

This should be considered another expression of:

**Exploit framework determinism before spending model intelligence.**

## 1. Start from known routes rather than crawling blindly

Suppose Laravel exposes:

```
POST /organizations/{organization}/invitations
```

Before executing a single request, we may already know:

```
handler:
InviteMemberController / InviteMember

middleware:
auth

parameter:
organization → Organization model binding

validator:
StoreInvitationRequest

authorization:
MembershipPolicy / Gate / explicit check

expected request fields:
email
role

related tests:
invitation tests
```

A generic scanner has to discover much of this experimentally.

We already have it.

Active testing can therefore begin from a structured map of the reachable application.

## 2. FormRequests are especially valuable

Example:

```
return [
    'email' => ['required', 'email'],
    'role' => ['required', Rule::in(['member', 'manager'])],
];
```

This tells the assurance engine a great deal about the expected input space.

Possible deterministic probes include:

### email

```
missing
malformed
valid
oversized
```

### role

```
missing
member
manager
unexpected value
```

The engine does not need an LLM to invent these basic cases.

Laravel has already declared the contract.

This can be combined with other framework information to generate targeted request matrices.

## 3. Authorization testing becomes much smarter

The Behavior Index may say:

```
Invite member

Allowed actors:
Owner
Manager

Organization scoped:
yes
```

Laravel implementation tells us:

```
route
middleware
policy
model binding
```

The assurance engine can construct targeted tests such as:

```
Owner A → Organization A       expected allow
Manager A → Organization A     expected allow
Member A → Organization A      expected deny

Owner B → Organization A       expected deny
Manager B → Organization A     expected deny

Unauthenticated → Organization A
expected deny
```

This is substantially more useful than randomly probing endpoints.

It also fits the Product Behavior Index:

```
product expectation
    +
Laravel implementation facts
    ↓
generated authorization test matrix
```

## 4. Behavior knowledge improves deterministic testing

The security/assurance system should not operate only from code structure.

Combine:

```
Behavior Index
Project Context
advisory Effects
Laravel structure
```

Example:

Behavior:

```
Remove staff member
```

Known product rule:

```
Staff cannot access the organization after removal.
```

Possible Effects:

```
Assigned jobs
Billing
Audit history
```

Implementation:

```
DELETE /members/{member}
MembershipPolicy
RemoveMember
Membership model
```

The active tester can probe:

```
removed user attempts protected route
removed user uses stale session
removed user calls API directly
removed user accesses another resource belonging to the organization
```

And it can verify product consequences beyond simply checking whether the DELETE endpoint returned 200.

## 5. Deterministic active testing should remain separate from adversarial reasoning

We now have two complementary mechanisms.

### Adversarial model

Asks:

```
What could go wrong?
```

Creates hypotheses.

Example:

```
Perhaps a manager from another tenant
can manipulate this membership.
```

### Deterministic active test

Asks:

```
Can we reproduce that?
```

Then attempts the request in an isolated environment.

Conceptually:

```
adversarial reasoning
    ↓
failure hypothesis
    ↓
generate deterministic probe
    ↓
execute
    ↓
reproduced / not reproduced
```

This is stronger than having an LLM simply say:

```
"There may be an authorization issue."
```

## 6. The reverse flow also matters

Deterministic tools can discover unexpected behavior first.

Example:

```
Expected:
foreign-tenant request → 403

Actual:
foreign-tenant request → 200
```

Then:

```
deterministic finding
    ↓
adversarial/frontier model
    ↓
investigate cause
    ↓
explain product consequence
    ↓
propose fix
    ↓
propose regression test
```

So AI and deterministic assurance should feed one another.

## 7. Laravel introspection can reduce the active-test search space

A generic DAST system may need broad crawling and fuzzing.

We can prioritize from:

- route metadata
- mutation routes
- authenticated routes
- privileged actions
- tenant-owned model parameters
- FormRequests
- file uploads
- payment endpoints
- signed URLs
- webhooks
- sensitive model fields
- custom validation rules

Example:

```
GET /marketing-page
```

probably receives little attention.

But:

```
DELETE /organizations/{organization}
POST /refunds
POST /users/{user}/roles
POST /webhooks/...
PUT /billing/payment-method
```

receive much more.

The framework gives us useful semantic hints before any model is involved.

## 8. Validation contracts can generate boundary tests

Given Laravel validation such as:

```
amount => integer|min:1|max:10000
```

generate:

```
missing
0
1
10000
10001
string
negative
unexpected numeric representation
```

Given:

```
status => Rule::enum(OrderStatus::class)
```

generate:

```
each valid enum value
arbitrary value
null
missing
```

Again:

**do not use generative AI where the application has already formally declared the expected domain.**

## 9. Route model binding provides valuable attack/test information

Given:

```
/organizations/{organization}/members/{member}
```

Laravel binding provides concrete entity relationships.

The test engine can deliberately substitute:

```
member from same organization
member from another organization
nonexistent member
deleted member
user's own record
```

This is particularly useful for detecting cross-tenant or object-level authorization problems.

The engine should understand that these are high-value permutations.

## 10. Policies and observed behavior can be compared

Suppose Product Context says:

```
Managers can view invoices but cannot refund them.
```

Behavior Index says:

```
Refund invoice:
Owner only.
```

Laravel analysis finds:

```
InvoicePolicy::refund
```

The assurance engine can build explicit expectations and actively exercise them.

If code and expected product behavior disagree, surface the mismatch.

This is another reason Product Context and Behavior Index have value beyond documentation.

## 11. Existing tests are another source of knowledge

Do not throw away what developers or agents have already encoded.

Existing Pest/PHPUnit tests tell us:

- expected behavior
- factories
- valid object creation
- authentication setup
- known edge cases
- reusable fixtures
- test helpers

The assurance engine can use these to construct valid baseline requests before attempting adversarial mutations.

This reduces the difficult problem generic scanners have of reaching meaningful application state.

## 12. Use the app's own factories and seeders

Laravel factories provide another enormous advantage.

Instead of crawling until we happen to obtain:

```
organization
manager
customer
invoice
appointment
```

we can create controlled test states deliberately.

Example:

```
Organization A
  Owner A
  Manager A
  Member A

Organization B
  Owner B
  Manager B
```

Then generate cross-boundary probes systematically.

This is significantly more deterministic than attacking an arbitrary external deployment.

## 13. Laravel can help produce valid baseline requests

Active testing works much better when you begin with a known-valid operation and mutate it.

The platform can derive valid setup from:

```
factories
validation rules
routes
existing tests
application fixtures
```

Then:

```
known valid request
    ↓
mutate one dimension
    ↓
observe whether invariant holds
```

This is much more efficient than arbitrary fuzzing.

## 14. Possible future deterministic assurance categories

Later versions could include first-party checks for:

### Authentication

- unauthenticated route access
- stale sessions
- password reset behavior
- remember-me behavior
- session invalidation

### Authorization

- role matrix
- tenant isolation
- model ownership
- API/UI parity
- direct endpoint access

### Validation

- boundary values
- unexpected fields
- invalid enums
- missing values
- malformed requests

### Request integrity

- CSRF expectations
- signed route validation
- method restrictions
- replay/idempotency

### Data integrity

- duplicate requests
- inconsistent state transitions
- partial failures

### File handling

- validation
- extension/type mismatch
- access control
- storage visibility

### API behavior

- unexpected methods
- direct object access
- authenticated/unauthenticated variants
- pagination/boundaries

### Concurrency / workflow

- duplicate submissions
- racing state transitions
- stale updates

### Dependency/configuration

- known vulnerable dependencies
- unsafe environment/config
- exposed debug behavior

Do not attempt all of this in V0.

This is a later assurance layer.

## 15. Active tests should run in isolated environments

This should be an architectural rule.

Exploit-style or destructive probes should normally run against:

```
disposable sandbox
preview environment
snapshotted test environment
```

not production.

The engine should be free to:

- create test users
- modify data
- issue destructive requests
- exercise failures
- reset state

without threatening the actual application.

Production testing should require explicit policy/authorization and be far more constrained.

## 16. Combine assurance with the layered intelligence architecture

The assurance stack could become:

### Level 0 — deterministic

```
Laravel introspection
Pest
static analysis
dependency checks
route probes
authorization matrices
validation tests
active application probes
```

### Level 1 — decision models

```
prioritize risk
classify findings
choose which tests deserve expansion
```

### Level 2 — small models

```
cluster findings
explain results
consolidate reports
```

### Level 3 — adversarial frontier reasoning

```
generate novel failure hypotheses
reason across capabilities
investigate surprising results
```

### Level 4 — user/human

```
approve consequential remediation
resolve product ambiguity
```

Again:

**use the cheapest reliable intelligence first.**

## 17. The advantage is not merely "we include a scanner"

Do not reduce this idea to:

```
integrate Burp-like scanning.
```

The strategic difference is:

Generic scanner:

```
discover application
    ↓
infer intent
    ↓
attack discovered surface
```

Our system:

```
already understands application structure
    +
knows intended behavior
    +
knows users/permissions
    +
has advisory Effects
    +
has existing tests/factories
    ↓
construct targeted adversarial scenarios
    ↓
actively verify them
```

This is a fundamentally better starting position.

## 18. This strengthens the Laravel-native thesis

Laravel is not merely the language/framework in which generated applications happen to be written.

Its conventions and metadata become inputs to the control plane.

For generation:

```
framework structure reduces invention.
```

For context:

```
framework structure improves understanding.
```

For verification:

```
framework structure tells us what to check.
```

For active assurance:

```
framework structure tells us where and how to probe.
```

This is a deeper advantage than simply having good Laravel prompts.

## 19. Future assurance loop

Conceptually:

```
PRODUCT EXPECTATION
       +
LARAVEL STRUCTURE
       +
BEHAVIOR INDEX
       +
EFFECT HINTS
       ↓
TEST PLAN
       ↓
deterministic probes
       ↓
surprising results?
   │           │
  no          yes
   │           ↓
   │    adversarial reasoning
   │           ↓
   │    proposed fix/test
   ↓
assurance result
```

This creates an architecture in which AI reasoning and deterministic engineering tools reinforce each other.

## 20. Important constraint

Do not let this explode V0.

V0 still needs the core engine:

```
intent
    ↓
selective context
    ↓
Behavior
    ↓
Effects
    ↓
Change Brief
    ↓
agent
    ↓
verification
    ↓
behavior diff
```

Later assurance adds deeper deterministic attack testing.

The architecture should simply avoid decisions now that would make this difficult later.

## What was asked

Treat Laravel-native active assurance as a future extension and evaluate:

1. Which Laravel structures provide the highest-value deterministic knowledge?
2. How much of an authorization matrix could realistically be derived automatically?
3. How should FormRequests become test generators without producing useless combinatorial fuzzing?
4. How can factories/existing tests establish valid baseline state?
5. How should Behavior and Effects influence deterministic test prioritization?
6. Where does static framework introspection end and runtime probing begin?
7. How should adversarial models turn hypotheses into safe deterministic probes?
8. How should deterministic failures be handed back to reasoning models?
9. Which classes of assurance should be framework-specific versus generic?
10. What sandbox/environment capabilities would this require later?
11. What should explicitly remain out of V0?
12. Does this meaningfully strengthen the case for a Laravel-specific control plane compared with a framework-agnostic AI builder?

The core hypothesis is:

**A Laravel-native assurance engine should not need to rediscover the application like a generic external scanner. The framework, product context, and accumulated behavior knowledge already provide a structured starting point for targeted verification.**
