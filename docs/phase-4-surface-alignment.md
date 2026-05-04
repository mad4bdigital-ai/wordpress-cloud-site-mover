# Phase 4 — API/CLI/Admin Surface Alignment (Execution Artifact)

## Objective
Align REST, WP-CLI, and Admin action surfaces behind shared application handlers while preserving all external contracts.

## Scope
- Internal handler alignment and response normalization.
- Preserve route paths, CLI verbs/options, admin action names and nonce/capability gates.
- No breaking contract changes in this phase.

## 1) Alignment Targets

### A. Shared action handlers
- Create shared internal handlers for security/job operations used by:
  - REST callbacks
  - WP-CLI commands
  - Admin actions
- Goal: one business flow, multiple entry surfaces.

### B. Response schema normalization
- Define canonical result envelope for operations:
  - status (`ok|warning|error`)
  - code (stable machine key)
  - message (localized human message)
  - data (operation payload)
- Keep legacy response shapes where externally required; map internally.

### C. Error mapping consistency
- Map domain errors to consistent REST/CLI/admin outcomes.
- Preserve existing strict-mode behavior and exit semantics.

### D. i18n/RTL consistency
- Ensure all newly introduced user-facing strings are translatable.
- Ensure admin labels/messages remain RTL-safe and semantically consistent with CLI/REST summaries.

## 2) Phase-4 Work Breakdown (Small PRs)

### PR-1: Shared security action service
- Extract security check/status business logic into shared service.
- Rewire REST/CLI/admin call sites to service.

### PR-2: Shared job control service
- Extract retry/cancel/status transitions used across surfaces.
- Rewire call sites while preserving surface command names.

### PR-3: Response/error formatter utilities
- Add small formatter helpers for consistent result envelopes.
- Keep backward compatibility adapters for existing outputs.

### PR-4: i18n/UX parity pass
- Review all newly touched strings and ensure text-domain usage.
- Validate RTL rendering for added admin messages.

## 3) Compatibility Gates (Must Pass)
- [ ] REST route paths/methods unchanged.
- [ ] WP-CLI command tree and options unchanged.
- [ ] Admin action names + nonce/capability checks unchanged.
- [ ] Strict-mode semantics unchanged (especially security check).
- [ ] Existing automation consumers still parse outputs successfully.

## 4) Acceptance Checks
- [ ] Same operation via REST/CLI/Admin yields equivalent outcome state.
- [ ] Error cases map consistently across three surfaces.
- [ ] Output remains backward-compatible where required.
- [ ] i18n coverage complete for new/changed strings.

## 5) Rollback Plan
- Isolate each PR by domain (security, jobs, formatter, i18n pass).
- Revert concern-specific PR when surface regression appears.
- Keep compatibility adapters to avoid external breakage during rollback.

## 6) Handoff to Phase 5
- [ ] Shared handlers stable and documented.
- [ ] Contract/parity matrix updated with aligned outputs.
- [ ] Regression suite requirements finalized for rollout phase.
