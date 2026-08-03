# Codex Prompt Template

Version: 0.131.8

Use this as a template, not as a concrete task.

```text
Project:
walls-delivery-calc

Branch:
<branch-name>

Version:
<old-version>
↓
<new-version>

Commit / push / PR:
Do not commit.
Do not push.
Do not open PR.

Web search:
Allowed:
- <when browsing is allowed>

Forbidden:
- <when browsing must not be used>

Required:
- <when browsing is mandatory, if any>

Before starting, read:
- docs/README.md
- docs/development/development-workflow.md
- <task-specific docs>
- <task-specific source files if known>

Goal:
<concrete objective>

Context:
<why this change is needed>

Allowed to change:
- <paths or modules>

Do not change:
- <paths, behavior, APIs, UX, or architecture outside scope>

Required work:
1. <step>
2. <step>
3. <step>

Version bump:
- Update plugin header.
- Update WDC_VERSION.
- Update version assertions.
- Update docs that mention the version.

tree.txt:
- Update tree.txt if files are added, removed, renamed, or moved.
- Ensure git diff --check is clean.

Checks:
- php -l <changed PHP files>
- node --check <changed JS files>
- docs link check
- <targeted smokes>
- php tests/shipments/run-shipment-regression-profile.php --group=framework
- php tests/shipments/run-shipment-regression-profile.php
- git diff --check

Final report must include:
1. Version.
2. Changed code/docs/tests.
3. What was intentionally not changed.
4. Test results.
5. Residual risks or active debt.
6. Confirmation that commit/push/PR were not performed.
```
