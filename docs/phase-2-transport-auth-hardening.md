# Phase 2 — Transport/Auth Layer Hardening (Execution Artifact)

## Objective
Isolate transport and auth concerns into explicit services while preserving existing request/response contracts and auth semantics.

## Scope
- Internal service extraction and validation flow cleanup only.
- Preserve current headers, options, and route permissions.
- No breaking change to REST/CLI/admin surfaces.

## 1) Target Security Service Segments

### A. Request signer
- Responsibility:
  - Build signature payload
  - Compute HMAC signature
  - Provide outbound header pack (`ts`, `body-hash`, `signature`)
- Contract:
  - Header names and format remain unchanged.

### B. Request verifier
- Responsibility:
  - Validate required auth headers
  - Validate timestamp skew window
  - Validate body hash
  - Validate signature against shared secret
- Contract:
  - Keep allow/deny outcomes aligned with current behavior.

### C. Replay/abuse controls
- Responsibility:
  - Per-IP rate-limit decisioning
  - Skew/replay guardrails
  - IP/CIDR allowlist checks
- Contract:
  - Respect current options (`allow_legacy_auth`, `auth_rate_limit_per_minute`, `auth_max_skew_seconds`, `ip_allowlist`).

### D. Auth audit stream
- Responsibility:
  - Emit consistent allow/deny/legacy audit events
  - Preserve redaction and avoid secret leakage

## 2) Phase-2 Work Breakdown (Small PRs)

### PR-1: Security interfaces and adapters
- Introduce internal interfaces for Signer/Verifier/RateLimiter/Auditor.
- Wire adapters around existing logic with zero behavior change.

### PR-2: Move outbound signing into signer service
- Delegate `CSMCR_HTTP::remote` signing/header assembly to signer service.
- Keep outbound exception/validation semantics stable.

### PR-3: Move inbound validation to verifier pipeline
- Delegate `auth()` checks to verifier service pipeline.
- Keep legacy-auth toggle and deny paths unchanged.

### PR-4: Audit normalization
- Standardize auth audit payload shape and event labels.
- Ensure i18n/logging policies remain consistent.

## 3) Compatibility Gates (Must Pass)
- [ ] Existing auth headers unchanged (`x-csmcr-ts`, `x-csmcr-body-hash`, `x-csmcr-signature`).
- [ ] Existing options continue to govern decisions.
- [ ] Route permission callbacks and access gates unchanged.
- [ ] Legacy-auth fallback behavior unchanged when enabled.
- [ ] HTTPS enforcement behavior unchanged when enabled.

## 4) Acceptance Checks
- [ ] Signed request validation passes on happy path.
- [ ] Missing/invalid headers deny as before.
- [ ] Skew/rate-limit/allowlist checks remain deterministic.
- [ ] Outbound signing still interoperates with peer site.
- [ ] No secret leakage in logs/audit entries.

## 5) Rollback Plan
- Keep PRs split by concern (signer/verifier/audit).
- Revert latest concern-specific PR if contract drift is detected.
- Preserve facade entrypoints so rollback does not affect callers.

## 6) Handoff to Phase 3
- [ ] Verifier/signer services available for migration orchestrator.
- [ ] Auth decision flow documented with examples.
- [ ] Regression scenarios captured for stateful migration phase.
