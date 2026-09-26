# Architecture documents

- **[architecture.md](architecture.md)**: the consolidated architecture (current
  version). Start here.
- **[direction/](direction/)**: the product direction as given by the project
  owner, one document per topic, in the order it was given. These state intent;
  the architecture answers them.
    1. [Product thesis](direction/01-product-thesis.md)
    2. [Project runtime and model access](direction/02-project-runtime-and-model-access.md)
    3. [Deterministic transformation and Rector](direction/03-deterministic-transformation.md)
    4. [Two audiences, progressive disclosure and observability](direction/04-two-audiences-and-observability.md)
    5. [The Product Behavior Graph](direction/05-product-behavior-graph.md)
    6. [Multi-model execution and visual editing](direction/06-multi-model-and-visual-editing.md)
    7. [Convention over generation](direction/07-convention-over-generation.md)
    8. [Product discovery and hierarchical context](direction/08-product-discovery-and-context.md)
- **[source/](source/)**: the original planning documents.
    - [Implementation plan v1](source/implementation-plan-v1.md): gates G0–G6,
      the invitation contract and the pilot plan.
    - [G0.1 foundation work order](source/g0-1-foundation-work-order.md).

When new direction arrives, add it to `direction/` and update `architecture.md`
in the same change, noting the new version at the top.
