# Direction 17: OpenAI agent SDK as failover

> Source: direction from the project owner, recorded as given.

> Wait make sure we also use the open ai adk if they have one for the failover

## Findings when this was recorded (September 2026)

OpenAI offers two relevant SDKs:

- **Codex SDK** (`@openai/codex-sdk`, TypeScript): runs the Codex coding agent
  programmatically. It wraps the `codex` CLI, which reads the codebase, runs
  commands in a sandbox, patches files and follows `AGENTS.md`. It is the direct
  counterpart of the Claude Agent SDK.
- **OpenAI Agents SDK**: a general agent framework that added a shell tool, an
  `apply_patch` tool, a model-native harness and sandbox execution in April 2026,
  launched in Python first with TypeScript planned.

Sources: [Codex SDK on npm](https://www.npmjs.com/package/@openai/codex-sdk),
[Codex SDK README](https://github.com/openai/codex/blob/main/sdk/typescript/README.md),
[The next evolution of the Agents SDK](https://openai.com/index/the-next-evolution-of-the-agents-sdk/),
[Apply Patch tool](https://platform.openai.com/docs/guides/tools-apply-patch).

The architecture uses the Codex SDK as the OpenAI adapter (§27.4).
