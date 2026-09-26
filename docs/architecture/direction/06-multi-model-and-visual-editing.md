# Direction 06: Multi-model execution and visual editing

> Source: direction from the project owner, recorded as given (formatting repaired
> only). The message was cut off in section 22; see the note at the end.

Do not treat any single model provider as the intelligence layer of the product.

We expect different frontier models to be better at different categories of work, and we personally already find some OpenAI models better for certain tasks and Anthropic models better for others.

The architecture should deliberately support mixing models and agent runtimes.

## 1. Multi-model execution is a first-class requirement

The control plane should route work based on capability, cost, latency, reliability, and task characteristics.

```
User intent
     ↓
Our control plane
     ↓
classify / decompose
     ↓
┌──────────────────────────────┐
│       Execution Router       │
└──────────────────────────────┘
     │          │           │
  OpenAI    Anthropic   deterministic
   agent      agent       tooling
     │          │           │
     └──────────┴───────────┘
                ↓
            workspace
```

Different stages of the same change may use different models. For example:

- product interpretation → Model A
- implementation → Model B
- independent review → Model C
- deterministic refactor → Rector, no model
- UI classification → small/cheap model
- difficult debugging → strongest available coding model

Do not assume one provider should handle an entire task from start to finish.

## 2. Avoid provider-specific architecture above the execution layer

The product should have an internal execution contract.

```
AgentTask
  - objective
  - relevant context
  - workspace
  - permissions
  - tool access
  - budget
  - model requirements
  - expected result
  - verification requirements

AgentResult
  - status
  - code changes
  - tool activity
  - cost
  - failures
  - notes
  - verification state
```

Adapters can implement this for the Claude Agent SDK, an OpenAI agent/coding runtime, and future providers.

Do not force all providers into an artificial lowest-common-denominator interface. Support optional capabilities when a provider offers something valuable. The control plane should understand provider capabilities and route accordingly.

## 3. Model routing should be empirical

Do not encode assumptions such as "Anthropic is always best at coding" or "OpenAI is always best at planning". Instead, gather task-level evidence.

Potential routing signals: task class, historical success rate, number of retries, token cost, wall-clock time, verification pass rate, regression rate, package/domain familiarity, context size, need for tool use, need for visual reasoning, need for long-running repository work.

The system should eventually be able to learn "for this class of Laravel migration, Provider X has a higher success rate" or "for small UI adjustments, Model Y is cheaper with equivalent verification results".

This routing knowledge should be operational telemetry, not hard-coded brand preference.

## 4. Use multiple models within one workflow where useful

Example: "Add contractor invitations."

- Control plane: understands the product-level request.
- Cheap model: classifies capability and likely affected behavior.
- Laravel tooling: extracts relevant structural context.
- Strong coding agent: implements the semantic change.
- Rector: performs any known structural transformations.
- Different model: reviews the behavior diff and tests for likely omissions.
- Deterministic verification: Pest / Larastan / Pint / structural checks.

Do not require a second model merely for theater. Use it when independent review materially improves confidence.

## 5. The visual editor should remain a major product surface

Do not remove the visual editor simply because the coding agents are becoming more capable. For the non-technical user, it is the most natural way to interact with an application.

The user should be able to select a visible element and either (1) directly change safe visual properties, or (2) describe a more substantial change and hand the selected context to an agent.

The visual editor should primarily manipulate real source-controlled UI, not maintain a separate proprietary layout representation.

## 6. Tailwind is a major advantage for visual editing

Many visual properties can be represented as constrained, deterministic changes: spacing, padding, margin, width, height, max width, alignment, flex/grid settings, typography, font size, weight, border radius, borders, shadows, responsive behavior, visibility, gap, positioning.

Where safe and unambiguous, the visual editor can directly transform Tailwind classes instead of invoking an AI model. Example: the user drags a spacing control and `px-4` becomes `px-8`. No agent required.

**Exploit deterministic structure before spending model intelligence.**

## 7. Visual editing should not be limited to CSS properties

When the requested change becomes semantic, use the agent.

Example: the user selects "Invite teammate" and says "Only show this button to managers and owners." This is no longer merely a Tailwind edit. The visual editor should create structured selection context and pass it to the control plane:

```
Selected visual element:  Invite teammate button
Current screen:           Project Members
Vue component:            InviteMemberButton.vue
Related behavior:         Invite project member
Related capability:       Organizations
Current permission:       Owner + Admin
Relevant source:          component, policy, action, tests
User request:             Only show this to managers and owners.
```

Then the normal planning/execution pipeline takes over.

## 8. Selection context should be a reusable primitive

A selected element should not merely give us a DOM node. The system should try to resolve it through progressively richer layers:

```
DOM element → Vue component → source location → route/screen → Behavior → Capability → relevant implementation references
```

This means the user can point at something visually and effectively say "Change THIS." without needing to know its implementation. That is a core UX advantage.

## 9. Visual editing should support both target users

- **Non-technical user**: select element; see simple controls (Text, Size, Spacing, Alignment, Appearance, Hide/show) and perhaps "What this does" for interactive elements. They can make safe changes directly or describe what they want.
- **Power vibe coder**: can reveal Tailwind classes, component, props, states, behavior, permissions, related API, tests, source. They may choose a direct property edit, prompt an agent, open source, or inspect behavior history.

One system, progressive disclosure.

## 10. Do not turn the visual editor into an independent page builder

Avoid creating a Wix-style proprietary document tree that becomes the real source of truth while Vue code becomes generated output. The source code should remain authoritative. The visual editor should operate as an intelligent projection/editor of actual application code: where deterministic transformations are possible, edit source directly; where semantic reasoning is needed, create structured context and hand it to an agent. This preserves portability and developer maintainability.

## 11. Components need traceability

Visual editing depends on mapping rendered UI back to code. Explore mechanisms such as Vue dev metadata, source maps, compile-time instrumentation, component IDs during preview, Vite plugins, development-only data attributes, route/component maps, Wayfinder information, and AST/source indexing.

Do not pollute production output unnecessarily. The preview environment can contain richer instrumentation than production. The goal is rendered element → source component/location, reliably enough for selection-aware editing.

## 12. A selected element may correspond to behavior, not merely appearance

Example: "Delete account". The visual editor should potentially know:

```
Visual component: DeleteAccountButton
Behavior:         Delete account
Actor:            Account owner
Outcome:          Account deleted
Side effects:     sessions invalidated, scheduled cleanup, email
```

This is where the Product Behavior Index and visual editor connect. The user could select the button and see "What this does" without invoking an LLM.

## 13. The Product Behavior Index remains small and user-focused

Continue with the refined graph approach (Application → Capability → Behavior → Surface → implementation references), with supporting relationships for actors, permissions, important rules, data concepts, side effects and integrations. Do not re-expand this into a complete dependency graph. Use deeper engineering graphs only ephemerally.

## 14. Treat the behavior index as a projection, not truth independent of code

The repository remains authoritative. Each behavior fact should ideally have provenance, for example:

```
Permission: Administrators can refund invoices.
Evidence:   InvoicePolicy::refund

Behavior:   Cancelling subscription preserves access until end of period.
Evidence:   CancelSubscription, SubscriptionCancellationTest
```

If the supporting implementation changes, mark relevant records dirty and regenerate or revalidate them. The system must be capable of saying "unknown" or "not currently verified" rather than inventing a confident explanation.

## 15. Distinguish sources of behavioral information

- **DERIVED**: established mechanically from code
- **CONFIRMED**: explicitly confirmed as product intent
- **PACKAGE CONTRACT**: guaranteed by a trusted capability/package
- **AI INTERPRETATION**: semantic interpretation generated by a model
- **PROPOSED**: suggested rule/invariant awaiting confirmation

Do not treat all categories equally. Hard verification should rely mainly on confirmed intent, trusted contracts, or deterministic facts. AI interpretations should remain revisable.

## 16. Staleness is a first-class problem

The behavior index must not become stale documentation. Use Git and implementation references to track freshness:

```
Behavior record references source artifacts
    → source artifacts change → record becomes dirty
    → extractor / semantic updater runs → record refreshed
    → verification runs where appropriate → record becomes current
```

This should be incremental rather than rebuilding the entire index after every small change.

## 17. User-facing explanations must remain readable

Internally: Surface, Capability, Behavior, SideEffect, Actor. Externally, prefer language such as "Appointments. Customers can: book an appointment, reschedule, cancel. Automatically: send confirmation, send reminder." The non-technical user should not need to know that a "Surface" exists.

## 18. Behaviour review after agent changes

After substantial changes, show the user product-level consequences ("Invite teammates. Changed: Managers can now send invitations.") rather than only a Git diff. For power users, "Show technical diff" remains available. Users may not be able to meaningfully inspect source diffs.

## 19. Deterministic transformation governance

Deterministic changes become more dangerous as scale increases. A bad agent change may affect one application. A bad trusted Rector rule may affect thousands. Trusted deterministic rules require stronger governance: fixtures, applicability checks, dry-run, representative corpus testing, canary deployment, per-project verification, versioning, rollback path.

When uncertain whether a change is deterministic or semantic, prefer semantic execution. The safe failure direction is extra model cost, not a globally propagated wrong transform.

## 20. Invariants require provenance too

Do not let AI guesses silently become application law. An invariant may be explicitly stated by the user, provided by a trusted package contract, mechanically implied, inferred by AI, or proposed for review. Only appropriate sources should become hard protections. Do not burden the non-technical user with constant invariant confirmation. Use plain-language confirmation when genuinely important.

## 21. Privacy and learning

The platform should improve from aggregate operational outcomes without requiring access to customer source code as a training corpus. Useful telemetry may include agent success/failure by task type, package compatibility results, Rector rule success, verification failures, number of retries, model costs, runtime duration, escalation frequency, and structural patterns where privacy-preserving. Using customer code as a corpus should require explicit policy/consent decisions. Do not assume it is available.

## 22. Runtime architecture

The control plane should remain independent of the sandbox provider:

```
Control plane → Agent adapter → Runtime adapter → reproducible workspace
```

Potential runtime choices may include a provider-hosted agent sandbox, our own container/VM sandbox, or a future provider runtime.

Our blessed Laravel environment should be r…

_(The message was cut off here. The consolidated architecture assumes it
continued "reproducible".)_
