# Phase 1 Taskboard — Bootstrap & Service Boundaries

## Purpose
Translate the Phase 1 artifact into executable, reviewable mini-PR tasks with clear non-breaking checks.

## Inputs
- `docs/phase-0-contract-baseline.md`
- `docs/phase-1-bootstrap-service-boundaries.md`

## Task Groups

### T1 — Container-lite skeleton
- [ ] Add internal container/service-registry class (no external API exposure).
- [ ] Register existing settings/jobs/logger services.
- [ ] Keep static entrypoints untouched.

**Checkpoints**
- [ ] No route/CLI/admin contract changes vs baseline.
- [ ] No option-key/default changes.

### T2 — Bootstrap boundary slimming
- [ ] Restrict main plugin bootstrap to constants + loader + init hooks.
- [ ] Move non-bootstrap composition code into core service bootstrap.

**Checkpoints**
- [ ] Plugin initialization order preserved.
- [ ] Activation/deactivation hooks still fire as before.

### T3 — Compatibility facades delegation pass
- [ ] Delegate selected static helpers internally to services.
- [ ] Keep method signatures and return formats stable.

**Checkpoints**
- [ ] REST route outputs remain parse-compatible.
- [ ] CLI outputs remain parse-compatible.

### T4 — Phase 1 verification bundle
- [ ] Run baseline contract diff check (manual checklist against Phase 0 baseline).
- [ ] Confirm no new public verbs/routes/actions were introduced.
- [ ] Prepare Phase 2 handoff note.

**Checkpoints**
- [ ] All Phase 1 compatibility gates marked pass.
- [ ] Rollback notes documented per mini-PR.

## PR slicing rule
- One task-group per PR whenever possible.
- If mixed changes are unavoidable, split by commit and document rationale.

## Exit Criteria
- [ ] T1..T4 completed.
- [ ] Zero intentional public contract change.
- [ ] Phase 2 prerequisites ready.
