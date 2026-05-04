# Phase 3 — Migration State Machine & Persistence (Execution Artifact)

## Objective
Introduce an explicit migration state model and durable persistence boundaries while preserving existing job contracts and recovery behavior.

## Scope
- Internal state/persistence modeling.
- Preserve existing admin/CLI retry entrypoints and job surface semantics.
- No external contract breakage in this phase.

## 1) Target State Model

### Canonical states
- `pending`
- `running`
- `failed`
- `completed`
- `canceled` (if present in current flow)

### Transition rules (high-level)
- `pending -> running`
- `running -> completed`
- `running -> failed`
- `failed -> running` (retry path)
- `running -> canceled` (if cancellation exists)

### Invariants
- Only one active step lock holder at a time.
- Retry counters are monotonic per step attempt.
- Failed artifact generated exactly once per failed terminal transition.
- Transition to `completed` requires no outstanding failed step.

## 2) Persistence Boundaries

### A. Job metadata persistence
- Status, step pointer, retry counts, last_error, timestamps.

### B. Lock persistence
- Step lock key ownership/timeout handling.

### C. Artifact persistence
- Failed report payload shape and storage path conventions.

## 3) Phase-3 Work Breakdown (Small PRs)

### PR-1: State enum + transition guard helper
- Add internal state helper with allowed transitions.
- Integrate guards with existing job state updates.

### PR-2: Retry/lock normalization
- Centralize retry increment/reset logic.
- Normalize lock acquire/release/timeout flow.

### PR-3: Failed artifact contract stabilization
- Define and enforce consistent failed-report payload keys.
- Ensure report generation is idempotent at terminal failure.

### PR-4: Retry entrypoint hardening
- Keep `retry_failed` behavior stable while using new guards.
- Ensure admin and CLI retry paths share same state transition logic.

## 4) Compatibility Gates (Must Pass)
- [ ] Existing job status labels remain compatible.
- [ ] Existing `retry_failed` entrypoints remain unchanged.
- [ ] Existing lock timeout option (`job_step_lock_timeout_seconds`) still governs timeout behavior.
- [ ] Existing max retry option (`max_step_retries`) semantics preserved.
- [ ] Failed report remains downloadable and parseable.

## 5) Acceptance Checks
- [ ] Interrupted run can resume without duplicate step execution.
- [ ] Failed run transitions are deterministic and auditable.
- [ ] Retry from failed returns to running with correct counters.
- [ ] Lock timeout recovery works without deadlock.
- [ ] Completed runs do not emit failed artifacts.

## 6) Rollback Plan
- Keep PRs separated by concern (state guard / lock+retry / artifacts).
- Revert latest concern-specific PR on transition regression.
- Preserve legacy facade methods so operational entrypoints remain stable.

## 7) Handoff to Phase 4
- [ ] Unified state transition helper available to API/CLI/Admin handlers.
- [ ] Retry and failure behavior documented for operator-facing surfaces.
- [ ] Regression scenarios exported to parity test matrix.
