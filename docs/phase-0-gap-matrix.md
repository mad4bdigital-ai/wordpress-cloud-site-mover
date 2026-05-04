# Phase 0 — Discovery & Gap Matrix (Execution Artifact)

## Objective
Establish a complete contract inventory and current-vs-target gap classification before any structural refactor.

## Scope
- No runtime behavior changes.
- Documentation and analysis only.

## 1) Contract Inventory Checklist

### A. REST contracts
- [ ] Route path inventory (method + path + permission/auth expectations)
- [ ] Request schema (required/optional fields)
- [ ] Response schema (success/error)
- [ ] Error/status code mapping
- [ ] Backward compatibility notes

### B. WP-CLI contracts
- [ ] Command tree and verbs
- [ ] Required/optional flags
- [ ] Output schema / message expectations
- [ ] Exit code behavior

### C. Admin actions/contracts
- [ ] Actions + nonce gates
- [ ] Capability checks
- [ ] Settings write/read contracts
- [ ] UX-side expected status messages

### D. Options/settings contracts
- [ ] Option keys + defaults
- [ ] Validation/sanitization rules
- [ ] Migration/upgrade expectations

### E. Jobs/state contracts
- [ ] Job state model currently in use
- [ ] Retry/lock semantics
- [ ] Failed artifact schema

### F. Logging/audit contracts
- [ ] Event categories
- [ ] Payload shape
- [ ] PII/secrets redaction rules

- Baseline snapshot: `docs/phase-0-contract-baseline.md`

## 2) Gap Matrix (Current vs Target)

| Domain | Current (CSMCR) | Target (WPMDB-inspired) | Gap Type (Adopt/Adapt/Keep) | Risk (L/M/H) | Planned Phase | Notes |
|---|---|---|---|---|---|---|
| Bootstrap/DI |  |  |  |  |  |  |
| Transport/Auth |  |  |  |  |  |  |
| Migration Orchestration |  |  |  |  |  |  |
| Migration State |  |  |  |  |  |  |
| Queue/Workers |  |  |  |  |  |  |
| API/CLI/Admin Parity |  |  |  |  |  |  |
| Quality/Testing |  |  |  |  |  |  |

## 3) Risk Scoring Guide
- **Low**: Internal-only change, no contract impact.
- **Medium**: Internal change with indirect contract sensitivity.
- **High**: Contract or state transition behavior likely affected.

## 4) Definition of Done (Phase 0)
- [ ] 100% contract inventory completed across REST/CLI/Admin/Options/Jobs/Logs.
- [ ] Gap matrix rows completed with Adopt/Adapt/Keep classification.
- [ ] Each High-risk gap has mitigation and rollback note.
- [ ] Phase 1 backlog items extracted and ordered.

## 5) Phase 1 Handoff Checklist
- [ ] Container/bootstrap changes clearly scoped.
- [ ] No external contract changes scheduled in first Phase 1 PR.
- [ ] Smoke checks identified for bootstrap safety.
