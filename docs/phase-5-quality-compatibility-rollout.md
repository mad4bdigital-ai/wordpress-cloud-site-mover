# Phase 5 — Quality, Compatibility, and Rollout (Execution Artifact)

## Objective
Establish production-grade regression, compatibility, and rollout controls for phased refactor delivery.

## Scope
- Quality gates, test matrix, release controls, and rollback strategy.
- No behavior changes in this phase (planning/execution controls only).

## 1) Regression Matrix (Must Cover)

### A. Auth/Transport
- Signed request happy path
- Missing header deny path
- Invalid signature deny path
- Skew window boundary checks
- Rate-limit threshold behavior
- IP/CIDR allowlist allow/deny cases
- Legacy-auth enabled/disabled behavior

### B. Job State/Resumable Execution
- Start/step/complete happy path
- Fail + failed artifact generation
- Retry from failed to running
- Lock contention and lock-timeout recovery
- Max-step-retries boundary behavior

### C. Surface Parity
- REST vs CLI vs Admin operation parity for:
  - security status/check
  - retry-failed
  - status/read-only diagnostics

### D. Compatibility
- Existing option keys/defaults retained
- Existing route paths/verbs retained
- Existing CLI verbs/options retained
- Existing admin actions/nonce gates retained

### E. i18n/RTL
- New/changed strings translatable
- RTL rendering sanity for added admin labels/messages

## 2) Release Gates (Go/No-Go)
- [ ] All critical regression scenarios pass.
- [ ] No contract diffs for protected surfaces.
- [ ] No high-severity security regressions.
- [ ] Rollback playbook validated in staging.
- [ ] Observability/logging checks in place for rollout monitoring.

## 3) Rollout Strategy

### Stage 1: Canary
- Enable on limited internal/test environments.
- Monitor auth denials, retry/failure rates, and fatal errors.

### Stage 2: Controlled rollout
- Expand to selected production tenants/sites.
- Compare baseline metrics vs canary.

### Stage 3: General availability
- Complete rollout when quality thresholds remain stable.

## 4) Feature Flags / Fallback Controls
- Maintain toggles where possible for newly extracted internal flows.
- Keep compatibility adapters available during rollout window.
- Document immediate fallback path for each risky subsystem:
  - auth verifier
  - job state transition helper
  - shared surface handlers

## 5) Operational Runbook
- Pre-release checklist
- Incident triage checklist
- Rollback trigger criteria
- Post-release verification checklist

## 6) Handoff/Closure Criteria
- [ ] Regression matrix automated and versioned.
- [ ] Rollout + rollback playbooks reviewed.
- [ ] Documentation updated for operators.
- [ ] Parity objective with roadmap segments signed off.
