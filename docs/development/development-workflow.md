# Development Workflow

Version: 0.130.4

This is the only canonical developer workflow for Walls Delivery Calc.

## Cycle

Use this cycle for every non-trivial change:

```text
discussion
-> architecture decision
-> branch selection
-> prompt preparation
-> Codex implementation
-> documentation update
-> checks
-> review
-> versioning
-> PR
-> merge
```

## Discussion

Before implementation, define:

- business goal;
- affected user flows;
- touched subsystems;
- constraints from WordPress, WooCommerce, HPOS, carrier APIs, cron, and local runtime;
- expected result;
- acceptance criteria;
- docs that must be read or updated.

If the task touches checkout, shipment creation, pickup points, rules, calendar, addresses, admin AJAX, JS, or carrier APIs, say that explicitly.

## Architecture Decision

Choose the smallest design that fits the existing architecture. Identify:

- modules and ownership boundaries;
- data shape changes;
- migrations or settings changes;
- cron/Action Scheduler impact;
- security checks;
- test and regression coverage;
- documentation updates.

Do not start a rewrite because another design is possible. Prefer existing registries, adapters, mappers, repositories, and contracts.

## Branch Selection

The user normally creates or chooses the branch. Use short names:

```text
feature/<topic>
fix/<topic>
docs/<topic>
audit/<topic>
chore/<topic>
```

One branch should have one primary goal.

## Prompt Preparation

Prepare Codex prompts from [codex-prompt-template.md](codex-prompt-template.md). Include:

- version bump;
- branch;
- docs to read first;
- allowed and forbidden changes;
- checks;
- whether `tree.txt` must be updated;
- commit/push/PR instruction.

## Codex Work

Codex should:

1. read the requested docs and relevant code;
2. inventory before cross-cutting edits;
3. implement confirmed fixes;
4. update docs, tests, and version with code;
5. update `tree.txt` when structure changes;
6. run requested checks;
7. report concrete changes and residual risks.

## Documentation Update

Update docs in the same task when changing:

- public or internal contracts;
- payload keys;
- DI/registry wiring;
- carrier extension steps;
- regression manifest;
- subsystem behavior;
- operational commands.

Do not add stage notes, roadmap docs, "current", "final", "v2", or migration-plan docs as active documentation.

Documentation and version updates happen before review, so reviewers inspect the code, tests, docs, version, and `tree.txt` together.

## Checks

Minimum checks for code changes:

```bash
php -l <changed PHP files>
node --check <changed JS files>
php tests/shipments/run-shipment-regression-profile.php --group=framework
php tests/shipments/run-shipment-regression-profile.php
git diff --check
```

For docs-only changes, run docs link check, relevant architecture/documentation smokes, and `git diff --check`.

## Review

Review should verify:

- behavior matches acceptance criteria;
- ownership boundaries are preserved;
- no hidden carrier switch entered generic framework code;
- security checks are still present;
- tests cover the changed contract;
- docs describe the real code;
- version bump and `tree.txt` are already included when required.

## PR And Merge

Only create commits, push, or PRs when explicitly asked. Before merge, ensure:

- requested checks passed;
- baseline/optional allowances are documented if present;
- docs links are not broken;
- `tree.txt` reflects the final structure;
- final report lists changed docs, tests, and version.

## Versioning

Use SemVer:

- new substantial branch or feature stage: bump `MINOR` and reset patch to `0`;
- continuation/fix/docs stabilization in the same branch: bump `PATCH`;
- update plugin header, `WDC_VERSION`, docs, and version assertions together.

Version bump is part of implementation, not a post-merge task.

## tree.txt

Update `tree.txt` when files are added, removed, renamed, or moved:

```bash
tree /a /F > tree.txt
```

Then remove trailing whitespace and extra blank lines so `git diff --check` is clean.

## Test Rules

- Add smoke tests only for real contracts or defects.
- Put shipment/carrier smokes in `tests/shipments/regression/shipment-regression-manifest.php` when they protect regression quality.
- Do not keep optional/baseline allowances without an active reason.
- Do not duplicate assertions already covered by a stronger framework smoke.
