# Chat Start

Version: 0.125.3

Use this at the start of a new ChatGPT planning/review chat.

## Read First

1. [README.md](../README.md)
2. [development-workflow.md](development-workflow.md)
3. [architecture/plugin-architecture.md](../architecture/plugin-architecture.md)
4. The subsystem doc for the task.
5. [testing-and-regression.md](testing-and-regression.md)

For shipment/carrier work, also read:

1. [architecture/shipment-framework.md](../architecture/shipment-framework.md)
2. [new-carrier-guide.md](new-carrier-guide.md)

## Do First

- Restate the business goal.
- Identify affected subsystems.
- Decide whether the task is docs-only, code-only, or code+docs.
- Prepare the Codex prompt from [codex-prompt-template.md](codex-prompt-template.md).

## Do Not

- Do not ask Codex to code before the affected docs/code are identified.
- Do not restore old stage docs as sources of truth.
- Do not preserve legacy aliases without an active consumer.
- Do not ask for commit/push/PR unless the user explicitly wants it.

## Prepare Before Codex

- Branch.
- Version bump.
- Docs to read first.
- Allowed paths.
- Forbidden paths.
- Required checks.
- Expected final report shape.
