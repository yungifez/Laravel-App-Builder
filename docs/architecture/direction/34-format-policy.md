# Direction 34: Deterministic format policy

The owner's words, 2026-10-05:

> # Deterministic Format Policy System
>
> Design a deterministic **Format Policy System** for fields whose accepted format depends on semantic type, region, business rules, or an explicitly selected standard.
>
> The goal is to prevent coding agents from repeatedly inventing regexes, validation rules, normalization behavior, storage formats, and UI behavior for common structured values.
>
> Examples:
>
> - email
> - phone number
> - postal / ZIP code
> - ISBN
> - URL
> - currency
> - dates
> - time
> - country codes
> - province/state codes
> - usernames
> - identifiers
> - tax/business numbers
> - licence/registration numbers
> - custom structured codes
>
> The core principle is:
>
> > **Resolve the intended format once, store the decision, then deterministically generate validation, normalization, UI behavior, tests, and storage behavior from that decision.**
>
> Do not make the coding agent independently reinvent format rules each time.
>
> ---
>
> # 1. Problem
>
> A request such as:
>
> > Add a phone number field.
>
> is incomplete.
>
> Questions may include:
>
> - What country or countries?
> - Are international numbers accepted?
> - What should be stored?
> - What should users type?
> - Should punctuation be accepted?
> - Should numbers be normalized?
> - Is extension support required?
>
> Similarly:
>
> > Add a postal code field.
>
> cannot safely imply one regex because:
>
> - Canada
> - United States
> - United Kingdom
> - Nigeria
> - other countries
>
> all have different conventions.
>
> Some formats are global and well-defined:
>
> - ISBN-10
> - ISBN-13
> - email syntax
> - URL parsing
>
> Others require contextual resolution.
>
> The agent should not guess these arbitrarily.
>
> ---
>
> # 2. Format Policy
>
> Represent the resolved requirement as a **Format Policy**.
>
> Conceptually:
>
>     FormatPolicy
>     ├── semantic_type
>     ├── format
>     ├── region
>     ├── normalization
>     ├── validation
>     ├── storage
>     ├── display
>     ├── source
>     ├── confidence
>     └── provenance
>
> Example:
>
>     semantic_type:
>     phone
>
>     format:
>     international_phone
>
>     region:
>     CA
>
>     input:
>     national_or_international
>
>     normalization:
>     E.164
>
>     storage:
>     E.164
>
>     display:
>     regional
>
>     source:
>     user_decision
>
> The coding agent should generally consume the resolved policy rather than constructing these rules itself.
>
> ---
>
> # 3. Separate semantic type from format
>
> Do not equate:
>
>     phone
>
> with:
>
>     Canadian phone number.
>
> The semantic type says what the value means.
>
> The Format Policy says what representation and constraints apply.
>
> Examples:
>
>     semantic_type: postal_code
>     region: CA
>
> versus:
>
>     semantic_type: postal_code
>     region: US
>
> versus:
>
>     semantic_type: postal_code
>     region: ANY
>
> Likewise:
>
>     semantic_type: isbn
>     format: isbn13
>
> or:
>
>     semantic_type: isbn
>     format: isbn10_or_isbn13
>
> This distinction is important.
>
> ---
>
> # 4. Resolution hierarchy
>
> When determining a field's format, resolve context from the most specific applicable level.
>
> Conceptually:
>
>     field
>       ↓
>     feature
>       ↓
>     project
>       ↓
>     organization/account defaults
>       ↓
>     inferred context
>       ↓
>     ask user
>
> Possible hierarchy:
>
>     Organization
>         ↓
>     Project
>         ↓
>     Feature
>         ↓
>     Field
>
> Example:
>
> Project setting:
>
>     primary_country = CA
>
> Then:
>
>     customer.postal_code
>
> can inherit:
>
>     region = CA
>
> unless the feature or field overrides it.
>
> Do not repeatedly ask:
>
> > What country is this postal code for?
>
> if the project already establishes Canada.
>
> ---
>
> # 5. Deterministic formats
>
> Where a well-established deterministic standard exists, resolve it automatically.
>
> Examples:
>
> ## ISBN
>
> Do not use only regex.
>
> Validate:
>
> - structure;
> - ISBN-10 checksum where applicable;
> - ISBN-13 checksum;
> - normalization of spaces/hyphens where appropriate.
>
> Possible policy:
>
>     semantic_type:
>     isbn
>
>     accepted:
>     isbn10 + isbn13
>
>     normalized_storage:
>     digits/x canonical form
>
> ---
>
> ## Email
>
> Use conservative email syntax validation.
>
> Do not attempt to determine whether the mailbox actually exists through regex.
>
> Separate:
>
>     syntactic validity
>
> from:
>
>     email ownership / deliverability
>
> Those are different concerns.
>
> ---
>
> ## URL
>
> Prefer parser-based validation over giant handcrafted regexes.
>
> Policy may specify:
>
>     schemes:
>     https only
>
> or:
>
>     schemes:
>     http + https
>
> ---
>
> # 6. Region-dependent formats
>
> Some semantic types require region information.
>
> Examples:
>
> - phone
> - postal code
> - tax number
> - business registration number
> - province/state
> - licence numbers
>
> If region is already established, use it.
>
> Example:
>
>     project.primary_country = CA
>
> Request:
>
>     Add postal code.
>
> Resolved:
>
>     semantic_type = postal_code
>     region = CA
>
> No question needed.
>
> ---
>
> # 7. Missing region context
>
> If a required format dimension is unknown, do not let the coding agent invent it.
>
> The format resolver should produce an unresolved decision.
>
> Example:
>
>     Field:
>     postal_code
>
>     Missing:
>     country
>
> Then the platform can ask:
>
> > Which postal codes should this field accept?
>
> Options might be generated from the relevant context:
>
>     Canada
>     United States
>     Canada + United States
>     International
>     Something else
>
> The user answers about their domain, not implementation.
>
> Do not ask:
>
> > Which regex should we use?
>
> ---
>
> # 8. Store the answer
>
> Once resolved:
>
>     postal_code.country = CA
>
> store this as durable project/feature/field configuration.
>
> The next agent should not need to ask again.
>
> The answer can then deterministically drive:
>
> - backend validation;
> - frontend hints;
> - input component behavior;
> - normalization;
> - storage;
> - fixtures;
> - tests;
> - example values;
> - accessibility text.
>
> The decision should have provenance.
>
> Example:
>
>     value:
>     CA
>
>     source:
>     user
>
>     decided_at:
>     ...
>
>     scope:
>     project
>
> ---
>
> # 9. Auto Mode
>
> Auto Mode may resolve format context without interrupting the user when evidence is sufficiently strong and the consequence of being wrong is low enough.
>
> Possible evidence:
>
> - established project country;
> - organization country;
> - selected currency;
> - existing addresses;
> - existing format policies;
> - explicit business context;
> - feature context;
> - existing schema/validation;
> - user location only when appropriate and authorized;
> - previously confirmed decisions.
>
> Auto Mode should record:
>
>     selected value
>     source
>     confidence
>     evidence
>     provenance
>
> Example:
>
>     region:
>     CA
>
>     source:
>     inherited_project_context
>
>     evidence:
>     project.primary_country = CA
>
>     confidence:
>     deterministic
>
> ---
>
> # 10. Auto Mode must not silently resolve conflicts
>
> Example:
>
>     project.primary_country = CA
>
> but:
>
>     feature shipping_region = US
>
> and the user asks:
>
>     Add postal code to shipping address.
>
> Do not blindly inherit Canada.
>
> The resolver should recognize stronger feature-specific context.
>
> Likewise, if evidence genuinely conflicts:
>
>     UNRESOLVED
>
> Do not average conflicting signals into a fake confidence score and guess.
>
> ---
>
> # 11. Confidence should not be the only criterion
>
> Decision policy should consider:
>
>     confidence
>     consequence if wrong
>     reversibility
>     scope
>
> Example:
>
> Inferring Canadian postal formatting for a temporary search filter may be low risk.
>
> Inferring a country-specific tax identifier format for financial reporting may be high risk.
>
> Even reasonably strong inference may still require confirmation where consequences are high.
>
> ---
>
> # 12. Conservative fallback
>
> If a format cannot be safely resolved and asking immediately is unnecessary, prefer conservative behavior.
>
> Examples:
>
> - accept a broader valid representation;
> - avoid destructive normalization;
> - preserve original input;
> - flag unresolved format context;
> - ask before hardening the constraint.
>
> Do not create a restrictive regex from weak assumptions.
>
> The system should prefer:
>
> > temporarily less restrictive
>
> over:
>
> > confidently reject valid user data because the agent guessed wrong.
>
> ---
>
> # 13. Normalization is separate from validation
>
> A value may be valid in several user-entered forms but stored canonically.
>
> Example phone input:
>
>     (250) 555-1234
>     250-555-1234
>     +1 250 555 1234
>
> may normalize to:
>
>     +12505551234
>
> Therefore distinguish:
>
>     input acceptance
>     validation
>     normalization
>     canonical storage
>     display formatting
>
> Do not collapse them into one regex.
>
> ---
>
> # 14. Preserve original input where necessary
>
> Some domains may require the exact user-supplied representation.
>
> The policy should be able to specify:
>
>     canonical_value
>     original_value
>
> where relevant.
>
> Do not automatically discard meaningful formatting.
>
> ---
>
> # 15. Storage policy
>
> Format decisions should also determine storage semantics.
>
> Examples:
>
> Phone:
>
>     canonical E.164 string
>
> Postal code:
>
>     normalized string
>
> Money:
>
>     integer minor units + currency
>
> Date:
>
>     date type where time is irrelevant
>
> Country:
>
>     canonical ISO country code
>
> Do not let separate agents make inconsistent storage choices for the same semantic type.
>
> ---
>
> # 16. Display policy
>
> Storage format and display format are different.
>
> Example:
>
>     stored:
>     +12508795004
>
>     displayed:
>     (250) 879-5004
>
> Display may depend on locale/region.
>
> The system should preserve canonical storage while allowing user-friendly rendering.
>
> ---
>
> # 17. Format Registry
>
> Maintain a deterministic registry of known semantic types.
>
> Conceptually:
>
>     FormatRegistry
>
>         email
>         phone
>         postal_code
>         url
>         isbn
>         country_code
>         currency
>         date
>         datetime
>         time
>         percentage
>         username
>         slug
>         ...
>
> Each entry can define:
>
> - required dimensions;
> - supported variants;
> - canonical normalization;
> - validation implementation;
> - storage recommendations;
> - UI hints;
> - deterministic tests;
> - authoritative standards/library implementation.
>
> Avoid free-form regex generation where a known implementation exists.
>
> ---
>
> # 18. Region adapters
>
> Region-specific behavior should be modular.
>
> Example:
>
>     postal_code
>         ├── CA
>         ├── US
>         ├── GB
>         ├── NG
>         └── generic
>
> Phone handling should preferably use a mature phone-number library rather than maintaining our own giant international numbering database.
>
> The platform owns policy selection.
>
> Libraries can own complicated standards implementation.
>
> ---
>
> # 19. Custom formats
>
> Applications will inevitably have domain-specific structured values.
>
> Example:
>
>     Employee ID:
>     ABC-2026-00123
>
> Support explicit custom formats.
>
> A custom format may specify:
>
>     name
>     description
>     validation
>     normalization
>     examples
>     storage
>     scope
>
> However, custom regex should be considered a lower-level escape hatch, not the default representation for known semantic types.
>
> ---
>
> # 20. Agent contract
>
> Coding agents should not manually implement known formats when a registered Format Policy exists.
>
> Agent workflow:
>
>     request contains structured field
>             ↓
>     detect semantic type
>             ↓
>     query Format Resolver
>             ↓
>     policy resolved?
>        /          \
>      yes           no
>       ↓             ↓
>     consume       request decision
>     policy
>       ↓
>     implement using generated/
>     approved primitives
>
> The agent should generally receive:
>
>     field:
>     customer_phone
>
>     semantic_type:
>     phone
>
>     policy:
>     phone.CA.e164
>
> rather than:
>
> > Figure out how Canadian phone numbers work.
>
> ---
>
> # 21. Generated implementation
>
> A Format Policy may deterministically generate or configure:
>
> ## Backend
>
> - Laravel validation rule;
> - normalization;
> - casts/value objects where appropriate;
> - storage constraints.
>
> ## Frontend
>
> - input mode;
> - autocomplete;
> - placeholder/example;
> - formatting/masking where appropriate;
> - validation feedback.
>
> ## Tests
>
> Generate deterministic cases:
>
>     valid
>     invalid
>     normalization
>     boundaries
>     regional variants
>
> Example:
>
>     CanadianPostalCodeTest
>
> rather than asking the coding model to invent coverage.
>
> ---
>
> # 22. Do not over-mask inputs
>
> Input masks can make valid data harder to enter.
>
> The format engine should distinguish:
>
>     visual assistance
>
> from:
>
>     validation requirements.
>
> Phone numbers in particular should not become impossible to paste because the UI assumes one presentation.
>
> Prefer tolerant input + canonical normalization.
>
> ---
>
> # 23. Laravel integration
>
> The Laravel implementation should remain conventional.
>
> Potential components:
>
>     FormatPolicy
>     FormatResolver
>     FormatRegistry
>
> and Laravel validation rules such as:
>
>     ValidPhone
>     ValidPostalCode
>     ValidIsbn
>
> Do not create a giant DSL unless the codebase demonstrates a need for one.
>
> Inspect the existing codebase and determine whether:
>
> - enums;
> - value objects;
> - configuration;
> - service classes;
> - rule objects;
> - metadata attached to field definitions
>
> provide the simplest representation.
>
> ---
>
> # 24. Translation interaction
>
> Any user-facing validation message, placeholder, hint, or format example should follow the project's translation/i18n policy.
>
> Do not hard-code user-facing format errors.
>
> Example:
>
>     __('validation.phone.invalid')
>
> rather than:
>
>     "Invalid phone number"
>
> The format engine should be able to generate the required translation keys.
>
> ---
>
> # 25. Project-wide reuse
>
> Once the project decides:
>
>     phone numbers:
>     international input
>     E.164 storage
>
> new phone fields should inherit this unless overridden.
>
> Likewise:
>
>     primary_country:
>     CA
>
> can resolve many future formats automatically.
>
> This is how the system gradually asks fewer questions.
>
> ---
>
> # 26. Format changes
>
> Changing a Format Policy may have data consequences.
>
> Example:
>
>     Canadian-only phone
>         ↓
>     international phone
>
> may be safe.
>
> But:
>
>     international phone
>         ↓
>     Canadian-only phone
>
> may invalidate existing records.
>
> Therefore Format Policy changes should identify:
>
> - existing affected fields;
> - stored data;
> - compatibility;
> - migration requirements;
> - validation impact.
>
> Do not treat policy changes as purely visual edits.
>
> ---
>
> # 27. Tests as policy enforcement
>
> Where practical, the selected policy should have reusable deterministic conformance tests.
>
> For example:
>
>     policy: isbn13
>
> automatically contributes:
>
>     valid checksum accepted
>     invalid checksum rejected
>     normalization correct
>
> The application-specific tests then only need to verify the field is wired to the selected policy.
>
> ---
>
> # 28. User-facing interaction
>
> Keep questions simple.
>
> Bad:
>
> > Select the validation strategy for this field.
>
> Good:
>
> > What kind of phone numbers should customers be able to enter?
>
>     Canada only
>     Canada + US
>     International
>
> Bad:
>
> > Choose postal regex.
>
> Good:
>
> > Which addresses will this form support?
>
>     Canada
>     United States
>     Both
>     International
>
> The engine translates that business answer into technical behavior.
>
> ---
>
> # 29. Evidence and provenance
>
> Every non-obvious resolved format should know why it exists.
>
> Example:
>
>     field:
>     customer.phone
>
>     policy:
>     international/E.164
>
>     source:
>     project setting
>
>     originating decision:
>     project supports customers worldwide
>
> This matters when future requirements change.
>
> ---
>
> # 30. Distinguish hard facts from inference
>
> Possible sources:
>
>     STANDARD
>     inherently determined by selected standard
>
>     USER_DECISION
>     explicitly chosen
>
>     PROJECT_INHERITANCE
>     inherited from project configuration
>
>     FEATURE_INHERITANCE
>     inherited from feature configuration
>
>     EXISTING_APPLICATION
>     derived from established current behavior
>
>     AUTO_INFERENCE
>     inferred by Auto Mode
>
> Do not store all of these as though the user explicitly decided them.
>
> ---
>
> # 31. Unknown is a valid state
>
> Do not force every field into a prematurely precise Format Policy.
>
> Allow:
>
>     format:
>     unresolved
>
> or:
>
>     format:
>     generic
>
> when appropriate.
>
> Unknown should trigger:
>
> - broader validation;
> - conservative storage;
> - possible future clarification;
>
> not fabricated certainty.
>
> ---
>
> # 32. Chaos relationship
>
> Do not expect the Format Policy engine to solve every complex domain.
>
> It owns known, mechanical format behavior.
>
> Chaos should catch higher-level problems such as:
>
> - inconsistent policies across related fields;
> - suspicious overrides;
> - business rules that conflict with format configuration;
> - formats that appear too restrictive for actual workflows;
> - existing data incompatible with a proposed change;
> - context where a supposedly universal format is actually domain-specific.
>
> Principle:
>
> > Format Engine handles mechanical representation.
> > Chaos catches contextual misuse.
>
> ---
>
> # 33. V1
>
> V1 should support a small high-value registry.
>
> Start with:
>
> - email
> - URL
> - phone
> - postal code
> - ISBN
> - country
> - currency
> - date
> - datetime
> - percentage
> - generic regex/custom pattern
>
> Support:
>
> - semantic type;
> - region where required;
> - normalization;
> - validation;
> - storage recommendation;
> - inheritance;
> - explicit user resolution;
> - Auto Mode inference;
> - provenance;
> - deterministic tests.
>
> Do not attempt to encode every government identifier in the world in V1.
>
> Add formats as applications encounter them.
>
> ---
>
> # 34. Example end-to-end flow
>
> User says:
>
> > Add a phone number to customers.
>
> System sees:
>
>     project.country = Canada
>
> Format Resolver:
>
>     semantic_type:
>     phone
>
>     region:
>     CA
>
> Existing project policy:
>
>     phone input:
>     national + international
>
>     storage:
>     E.164
>
> No user question required.
>
> Agent receives:
>
>     Customer phone
>     Use project phone policy.
>
> Generated behavior:
>
>     input accepts:
>     250-555-1234
>     +1 250 555 1234
>
>     normalized storage:
>     +12505551234
>
>     translated validation errors
>
>     deterministic tests
>
> ---
>
> Another example:
>
> User says:
>
> > Add a shipping postal code.
>
> Project:
>
>     company located in Canada
>
> Feature:
>
>     ships worldwide
>
> Resolver must not blindly infer Canada.
>
> System asks:
>
> > Which addresses should customers be able to use?
>
>     Canada only
>     Canada + US
>     International
>
> User chooses:
>
>     International
>
> Store that at the shipping-address feature scope.
>
> Future shipping-address fields inherit it.
>
> ---
>
> # 35. Core principle
>
> The system should move from:
>
>     agent receives:
>     "Add postal code validation"
>
> and improvises implementation
>
> to:
>
>     semantic type detected
>         ↓
>     required dimensions resolved
>         ↓
>     stored Format Policy
>         ↓
>     deterministic validator
>     + normalization
>     + storage
>     + UI configuration
>     + tests
>
> The model should resolve ambiguity only where product context requires judgment.
>
> It should not repeatedly reinvent standards.
>
> Please inspect the current codebase and refine this architecture based on what already exists. Avoid unnecessary abstractions, add your own ideas where they make the system simpler or more robust, and identify which portions should be implemented deterministically versus left for Chaos to review.
