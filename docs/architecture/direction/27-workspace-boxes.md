# Direction 27: Workspace boxes, with no provider hard-coded

> Source: direction from the project owner, recorded as given (formatting repaired
> only). It is part of a build session on 2026-09-26. It follows the assistant's
> account of local development, which said that coding agents run without a
> sandbox inside the control plane's own container.

**Owner:**

Oh we definitely should not do that lmao

**Assistant (summary):**

The agent can read any file in the control plane's container, including its
`.env`, its code and its prompts. Builds should run in a separate place that
holds only the customer's repository, with keys given per run.

**Owner:**

Local is for our testing here, but we would most likely provision boxes, how
does claude cloud do it

**Assistant (summary):**

Each session gets its own disposable box, created from an image with a setup
script and destroyed afterwards. No credential enters the box: git goes
through a proxy that holds a credential scoped to one repository and branch,
and all outbound traffic goes through a proxy with an allowlist. Our needs go
further, because the customer's own code runs in the box. So the box must hold
nothing of ours: no control-plane code, no `.env`, no keys and no prompts.
Model calls go through our gateway, which adds the real key and our
instructions on its side. The agent loop stays in the box.

(`docs/research/workspace-sandboxes.md` had already inspected that environment
from the inside: each session is a Firecracker microVM.)

**Owner:**

Yes something like that, but lets try keeping the implementation free from
hardcoding any specific provider
