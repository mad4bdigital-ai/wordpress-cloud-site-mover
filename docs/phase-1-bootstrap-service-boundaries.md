# Phase 1 — Bootstrap & Service Boundaries (Execution Artifact)

## Objective
Refactor bootstrap/wiring into explicit service boundaries while preserving all existing external contracts.

## Scope
- Internal wiring and class boundaries only.
- No route/CLI/admin action renames.
- No behavior changes in auth/job execution semantics in this phase.

## 1) Target Internal Boundaries

### A. Bootstrap boundary
- Main plugin file should retain:
  - constants/version
  - file includes/bootstrap entry
  - hook registration entrypoints only

### B. Service registry boundary
- Introduce/standardize service registry (container-lite) to resolve:
  - settings service
  - logger service
  - jobs manager service
  - auth/transport services (adapters first)

### C. Facade boundary
- Existing static entrypoints remain as compatibility facades.
- Facades delegate to services internally.

## Execution taskboard
- See `docs/phase-1-taskboard.md` for actionable mini-PR tasks and checkpoints.

## 2) Phase-1 Work Breakdown (Small PRs)

### PR-1: Service registry skeleton
- Add minimal container class and registration map.
- Wire existing core services without behavior change.

### PR-2: Bootstrap slimming
- Move non-bootstrap logic out of main file entry area.
- Keep init hooks and constant definitions stable.

### PR-3: Facade delegation pass
- Delegate selected static helpers to services.
- Preserve method signatures and return payload shapes.

### PR-4: Compatibility pass
- Validate no changes in route paths, CLI verbs, admin actions/options keys.

## 3) Compatibility Gates (Must Pass)
- [ ] REST route paths unchanged.
- [ ] WP-CLI command verbs unchanged.
- [ ] Admin actions and nonce/capability checks unchanged.
- [ ] Options keys/defaults unchanged.
- [ ] i18n text domain usage preserved.

## 4) Acceptance Checks
- [ ] Plugin initializes without warnings/fatals.
- [ ] Admin Tools page loads and existing actions still execute.
- [ ] Core CLI smoke commands execute with same command surface.
- [ ] Security auth flow remains functionally identical in this phase.

## 5) Rollback Plan
- Keep each PR isolated to one boundary concern.
- Revert individual PR if compatibility gate fails.
- Maintain facades so rollback does not require consumer changes.

## 6) Handoff to Phase 2
- [ ] Service registry available for auth/transport extraction.
- [ ] Bootstrap boundary stabilized.
- [ ] Contract baseline report updated from Phase 0 artifact.
