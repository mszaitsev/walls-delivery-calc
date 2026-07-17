# Walls Delivery Calc Documentation

Version: 0.122.0

Start here when changing the plugin. The documentation is intentionally small: each topic has one canonical owner, and historical stage notes were removed because this plugin has not been deployed to production.

## Canonical Map

| Topic | Canonical document |
| --- | --- |
| Whole plugin architecture | [architecture/plugin-architecture.md](architecture/plugin-architecture.md) |
| Dependency injection and composition root | [architecture/dependency-injection.md](architecture/dependency-injection.md) |
| Shipment Framework | [architecture/shipment-framework.md](architecture/shipment-framework.md) |
| Add a carrier | [development/new-carrier-guide.md](development/new-carrier-guide.md) |
| Regression runner and smoke policy | [development/testing-and-regression.md](development/testing-and-regression.md) |
| Coding and ownership rules | [development/coding-rules.md](development/coding-rules.md) |
| Checkout | [subsystems/checkout.md](subsystems/checkout.md) |
| Packaging | [subsystems/packaging.md](subsystems/packaging.md) |
| Locations, pickup points, FIAS/GAR | [subsystems/locations.md](subsystems/locations.md) |
| Rules | [subsystems/rules.md](subsystems/rules.md) |
| Carrier shipment behavior | [subsystems/shipments.md](subsystems/shipments.md) |
| Runtime, installation, hooks | [operations/installation-and-runtime.md](operations/installation-and-runtime.md) |
| Cron and background jobs | [operations/cron-and-background-jobs.md](operations/cron-and-background-jobs.md) |
| Troubleshooting | [operations/troubleshooting.md](operations/troubleshooting.md) |
| Current project status | [operations/project-status.md](operations/project-status.md) |
| Active technical debt | [operations/technical-debt.md](operations/technical-debt.md) |

## Supporting References

`docs/dpd/ws-integration-guide.docx` is a vendor reference for DPD SOAP behavior. It is useful evidence, but it is not a project source of truth. When it conflicts with code or canonical docs, verify against the current DPD integration code and update the canonical docs.

## Documentation Rules

- Update docs in the same change as code when behavior, extension points, payload keys, service wiring, tests, or regression manifest entries change.
- Do not add stage notes, "current", "final", "v2", or migration-plan documents as active docs.
- Do not preserve compatibility aliases in docs unless an active consumer exists.
- Put historical context only in an archive when it is still needed to understand a live decision.
- Prefer one canonical document per subsystem over multiple partial documents.
