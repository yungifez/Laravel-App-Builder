# Direction 15: Human-readable visual properties on a Tailwind substrate

> Source: direction from the project owner, recorded as given (formatting repaired
> only).

Add another V0 product/architecture direction:

The visual editor should expose **human-readable design controls** while using Tailwind as the deterministic implementation substrate.

A large amount of frontend editing should not require a coding agent at all.

The core mechanism can be surprisingly simple:

```
current Tailwind classes
    +
new classes derived from user intent
    ↓
cn(...)
    ↓
normalized classes
    ↓
patch source
```

This can power a substantial part of the V0 visual editor.

## 1. Human-readable controls, Tailwind underneath

Do not expose raw Tailwind by default.

Example:

User selects a card.

Instead of:

```
border
rounded-md
px-4
py-2
```

show:

```
Border
1 px

Corners
Medium

Horizontal padding
16 px

Vertical padding
8 px
```

Power users can optionally expand:

```
Tailwind:
border rounded-md px-4 py-2
```

The simple user manipulates normal design concepts.

The implementation remains ordinary Tailwind.

## 2. Sliders can map to Tailwind scales

Example:

```
Border width

0 ─────●──── 8
       2 px
```

Internally:

```
0px → border-0
1px → border
2px → border-2
4px → border-4
8px → border-8
```

Likewise:

```
Padding
16 px
```

maps to:

```
p-4
```

If the user chooses a value not present in the normal Tailwind scale:

```
15 px
```

use an arbitrary value:

```
p-[15px]
```

Prefer canonical Tailwind values where they exist.

Fall back to arbitrary Tailwind values where necessary.

This preserves design-system consistency without constraining the user unnecessarily.

## 3. Users should be able to choose units

Example:

```
Width

Unit:
px
%
rem
vw
```

Then:

```
75%
    → w-3/4

320px
    → canonical Tailwind width if appropriate
    → otherwise w-[320px]

24rem
    → canonical width where available
    → otherwise w-[24rem]

50vw
    → w-[50vw]
```

The editor should think in a generic style representation such as:

```
property: width
value: 75
unit: percent
variant: base
```

A Tailwind adapter translates that into the best source representation.

The UI should not be architecturally coupled to Tailwind class names.

## 4. Unit changes should explain behavioral consequences

Changing:

```
50%
```

to:

```
400px
```

is not merely a formatting change.

The first adapts to the container.

The second is fixed.

The user should receive lightweight guidance such as:

```
50% changes with the available space.
400px stays fixed.
```

Do not expose CSS terminology unless the user asks for it.

This lets non-technical users make informed design decisions.

## 5. Semantic design scales

Some properties work better as semantic choices than raw numeric sliders.

Examples:

### Corner radius

```
Square
Subtle
Medium
Rounded
Pill
```

mapping to:

```
rounded-none
rounded-sm
rounded-md
rounded-xl
rounded-full
```

### Shadow

```
None
Subtle
Normal
Strong
Dramatic
```

mapping to:

```
shadow-none
shadow-sm
shadow
shadow-lg
shadow-2xl
```

The visual editor should choose the interaction model that best matches the property.

## 6. Layout is especially deterministic

Most common application layout can be expressed through a small set of human concepts.

Avoid exposing:

```
display: flex
flex-direction
flex-wrap
justify-content
align-items
```

Prefer:

```
Direction
Across / Down

Items
Single line / Wrap to next line

Alignment
Start / Center / End / Stretch

Distribution
Packed / Space between / Evenly spaced

Gap
16 px
```

This maps directly to Tailwind.

Examples:

```
Across + single line
    → flex flex-row flex-nowrap

Across + wrap
    → flex flex-row flex-wrap

Down
    → flex flex-col

Align center
    → items-center

Space between
    → justify-between

Gap 16px
    → gap-4
```

Again:

```
human concept
    ↓
Tailwind utility
    ↓
cn(existing, new)
    ↓
normalized source
```

## 7. Grid can be equally simple

User sees:

```
Layout
List / Grid

Columns
1 / 2 / 3 / 4 / Auto

Column spacing
16px

Row spacing
16px
```

Internally:

```
grid grid-cols-3 gap-4
```

Responsive editing:

```
Phone: 1 column
Tablet: 2 columns
Desktop: 4 columns
```

maps to:

```
grid-cols-1 md:grid-cols-2 lg:grid-cols-4
```

A non-technical user should never need to know what `md:` or `lg:` means.

## 8. Responsive layout should be first-class

The editor should expose something like:

```
Device
All / Phone / Tablet / Desktop
```

Then the user edits the selected property at that breakpoint.

Example:

Current:

```
w-full lg:w-1/2
```

Human representation:

```
Phone:
100%

Tablet:
100% inherited

Desktop:
50%
```

User changes desktop to 60%.

Generate:

```
lg:w-3/5
```

Then:

```
cn("w-full lg:w-1/2", "lg:w-3/5")
```

produces the normalized result.

No coding agent required.

## 9. States can use the same system

Expose:

```
State
Normal
Hover
Focus
Active
Disabled
```

Example:

Normal:

```
Border
1px
```

Hover:

```
Border
2px
```

Internally:

```
border hover:border-2
```

Same property representation, different variant scope.

This should work with standard Tailwind variants deterministically.

## 10. cn is a normalization primitive

Use `cn`/Tailwind merge semantics as the underlying conflict resolver.

Example:

```
cn(
    "flex flex-col gap-4",
    "flex-row"
)
```

becomes:

```
flex flex-row gap-4
```

Likewise:

```
cn(
    "grid-cols-2 md:grid-cols-3",
    "md:grid-cols-4"
)
```

becomes:

```
grid-cols-2 md:grid-cols-4
```

This means the visual mutation algorithm can remain very small:

```
visual property
    ↓
Tailwind adapter
    ↓
new utility / variant
    ↓
cn(existing, new)
    ↓
patch source
```

Do not reimplement Tailwind conflict semantics unnecessarily.

## 11. The editor should patch source, not accumulate runtime wrappers

Do not rewrite every element as:

```
cn("old classes", "new classes")
```

Instead:

```
parse source
    ↓
merge current + desired utility
    ↓
write normalized classes back to source
```

Example:

Before:

```
<div class="border rounded-lg">
```

User chooses border 2px.

Compute:

```
cn("border rounded-lg", "border-2")
```

Write:

```
<div class="border-2 rounded-lg">
```

Keep the generated source clean.

## 12. Preview can update before source commit

For responsive interaction:

```
drag slider
    ↓
apply temporary style/class to preview DOM
    ↓
immediate visual feedback
    ↓
user releases/commits
    ↓
normalize class set
    ↓
patch source
    ↓
HMR catches up
```

This gives the editor a Figma/Wix-like feel while preserving ordinary source-controlled Vue/Tailwind code.

## 13. Establish a Visual Editability Contract

Generated frontend code should follow conventions that make deterministic editing reliable.

Prefer:

- Tailwind utility classes
- known variants
- CSS variables/theme tokens
- traceable class composition
- clean source mapping

Avoid by default:

- random custom CSS overriding utility properties
- unexplained inline styles
- unnecessarily complex selectors
- arbitrary variant complexity when standard variants work
- opaque class-generating helper functions

The purpose is not to restrict frontend design.

It is to keep common visual properties directly manipulable.

## 14. Know when deterministic editing is unsafe

Tailwind merging does not solve every CSS problem.

Potential complications:

- `!important`
- arbitrary CSS properties
- complex arbitrary selectors
- custom CSS classes
- dynamic class expressions
- unknown helper functions
- styles inherited from parents
- CSS specificity outside Tailwind

The deterministic editor should only claim confidence where it actually understands the mutation.

Pattern:

```
deterministic Tailwind mutation
    ↓
confidence sufficient?
   yes → apply
   no  → semantic/agent fallback
```

Do not become a CSS theorem prover.

## 15. Dynamic class expressions need source awareness

Example:

```
:class="cn(
    'border rounded-lg',
    selected && 'border-blue-500',
    error && 'border-red-500'
)"
```

The visual editor should distinguish:

```
Default
Selected
Error
```

If the user edits border color while the Error state is active, determine whether they mean:

- default border
- selected border
- error border

Where this can be mapped deterministically through AST/source information, do so.

If the expression is too complex:

```
:class="calculateStyles(foo, bar)"
```

escalate.

## 16. Explicit visual controls and semantic visual requests should use different paths

Example:

User moves:

```
Border width
1px → 2px
```

This is explicit.

Use deterministic mutation.

But user types:

```
Make overdue invoices feel more urgent.
```

That is semantic.

Use:

```
intent classification
    ↓
agent reasoning
    ↓
generated Tailwind/state behavior
```

After the agent introduces a state such as:

```
overdue
    → border-red-500
```

the visual editor may then make that state directly editable.

This gives us:

**Explicit design control → deterministic**

**Semantic design intent → model reasoning**

## 17. Property schema should drive the editor

Rather than hardcoding every inspector control separately, define metadata.

Example conceptually:

```
width:
    control: slider
    units:
        px
        percent
        rem
        vw
    Tailwind group:
        width

borderRadius:
    control:
        semantic-slider
    units:
        px
        rem
    suggestions:
        Square
        Subtle
        Medium
        Rounded
        Pill

layoutDirection:
    control:
        choice
    options:
        Across
        Down
```

The visual editor is then driven by a reusable property model.

Tailwind becomes an implementation adapter.

## 18. Human-readable suggestions can remain deterministic

Example:

A button currently has:

```
px-4 py-2
```

The editor might show:

```
Size

Compact
Comfortable ✓
Large
```

These can initially be deterministic presets.

The system does not need an LLM to produce basic design suggestions.

Later, a cheap model may provide contextual suggestions such as:

```
This is the primary action.
A larger button may improve prominence.
```

But selecting that suggestion should still resolve to deterministic property changes wherever possible.

AI suggests.

Deterministic tooling executes.

## 19. This should be part of V0

The visual editor is one of the areas where the product can visibly demonstrate its architecture.

A compelling V0 demo:

1. Generate a Laravel + Vue + Tailwind application.
2. Select a card in the preview.
3. Human-readable inspector appears.
4. Change:
    - width from 50% → 65%
    - border from 1px → 2px
    - corners from Medium → Rounded
    - layout from stacked → side by side
    - desktop padding from 16px → 24px
5. Preview changes immediately.
6. Source becomes clean Tailwind.
7. No coding-agent call occurs.

Then user says:

```
Make overdue invoices stand out more.
```

That request requires semantic reasoning.

The engine escalates to the coding agent.

This demonstrates the product principle clearly:

**Deterministic where possible. Intelligence where necessary.**

---

# Future framework/runtime expansion

Keep the core engine conceptually separate from Laravel-specific understanding.

Do not design:

```
Laravel = core engine
```

Prefer:

```
Core engine
    ↓
framework adapter
```

The core engine contains concepts such as:

- intent classification
- Project Context
- Behavior
- Effects
- Change Brief
- verification
- audit
- adversarial review
- model routing
- visual property model

V0 ships with a deeply integrated:

```
LaravelAdapter
```

which understands:

- routes
- middleware
- FormRequests
- policies
- gates
- Eloquent
- migrations
- queues
- events
- Blade
- Vue/Inertia
- Pest
- factories
- Artisan
- Laravel-specific verification

Laravel remains the strategic first environment because its conventions provide unusually strong deterministic knowledge.

## 20. Blade support can likely come before generic PHP

The Tailwind visual-editing system can work naturally with Blade.

Example:

```
<div class="flex flex-col gap-4">
```

Selection:

```
Layout:
Down
```

User changes:

```
Across
```

Mutation:

```
cn("flex flex-col gap-4", "flex-row")
```

Source:

```
<div class="flex flex-row gap-4">
```

Therefore a Blade-first frontend mode may be a relatively natural extension after Vue/Inertia.

## 21. Native PHP could be a later adapter

Future possibility:

```
NativePhpAdapter
```

But native PHP provides less standardized framework metadata than Laravel.

Laravel gives us:

- route enumeration
- standardized request validation
- authorization conventions
- ORM structure
- queues/events
- test patterns
- container/reflection infrastructure

Generic PHP may require more:

- AST analysis
- Composer inspection
- convention discovery
- runtime observation
- agent reasoning

Therefore:

```
Laravel:
high deterministic understanding

generic PHP:
more inferred understanding
```

This is not a judgment about PHP itself.

It reflects the amount of machine-readable architectural structure available.

## 22. Do not generalize prematurely

Do not weaken the Laravel-native V0 trying to accommodate every PHP architecture.

The strategic stance should be:

**Laravel is the first deeply understood environment, not necessarily the only environment forever.**

Go extremely deep on Laravel first.

Then observe which control-plane abstractions generalize naturally.

Do not sacrifice:

- Laravel introspection
- Laravel-native assurance
- deterministic framework understanding
- first-party package integration
- deep verification

for premature framework neutrality.

## 23. Adapter boundary should be narrow but real

The core engine may eventually ask framework adapters questions such as:

```
What behaviors/routes are relevant to this implementation reference?

What validation rules apply here?

What authorization mechanisms exist?

What tests relate to this behavior?

What deterministic verification can be run?

What source element corresponds to this rendered element?
```

The Laravel adapter can answer these richly.

Other adapters may answer partially.

This also allows the engine to express uncertainty honestly.

Example:

```
Framework understanding:
high
```

versus:

```
Some application structure could not be determined automatically.
```

Do not pretend every adapter provides Laravel-level certainty.

## 24. Strategic principle

The product should not merely generate Tailwind.

It should understand enough about the styling substrate to make a huge portion of frontend iteration interactive and deterministic.

Likewise, Laravel should not merely be the framework the agent writes.

Its conventions should become part of the intelligence substrate.

The recurring architecture is:

```
structured substrate
    ↓
deterministic understanding
    ↓
cheap direct mutation
    ↓
semantic fallback only where needed
```

This is increasingly one of the central themes of the product.

## What was asked

Integrate the visual-property/Tailwind editing system into the V0 architecture. Specifically address:

1. What is the minimal generic StyleValue/property model?
2. Which properties can be safely edited in V0?
3. How should human-readable units map to canonical Tailwind values?
4. When should arbitrary Tailwind values be used?
5. How should responsive variants be represented?
6. How should interactive states be represented?
7. How should cn/Tailwind merge be used for static source mutation?
8. How should dynamic class expressions be handled?
9. What should trigger agent fallback?
10. What rules belong in the Visual Editability Contract?
11. How should preview-first temporary mutation work before source commit?
12. How can the same system support Vue and Blade?
13. What framework-adapter boundary should exist now without over-generalizing V0?
14. Which parts of native PHP support could reuse the engine later, and which would lose Laravel-specific determinism?
15. Does this visual editing system provide enough direct user value to deserve being part of the first end-to-end V0 demo?

The important product claim is: **the user should manipulate understandable visual properties, while the platform maintains clean normal Tailwind source underneath.**
