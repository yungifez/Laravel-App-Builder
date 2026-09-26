# Direction 14: Laravel-native deterministic active testing

> Source: direction from the project owner, recorded as given (formatting repaired
> only). The message was cut off during section 14 ("Data integrity"); the
> remainder has not been received yet.

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
- inconsistent

> _[The message ends here.]_
