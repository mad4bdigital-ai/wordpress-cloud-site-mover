# Implementation Roadmap: CSMCR → WPMDB-Pro-inspired Architecture

## Scope and guardrails
- Preserve existing auth/access gates and options compatibility.
- Keep PRs small and reversible.
- No breaking changes to existing REST/CLI/admin contracts unless explicitly versioned.

## Phase 0 — Discovery and Gap Matrix
### Objectives
- Build component map of current plugin vs reference package layout.
- Produce contract inventory: REST routes, CLI commands, options, job state, logs.

### Deliverables
- Gap matrix table (Current / Target / Risk / Migration strategy).
- Compatibility checklist for multilingual strings and existing options.

### Candidate files
- `cloud-site-mover-clean-room.php`
- `includes/Core/Loader.php`
- `includes/Core/Plugin.php`
- `includes/Core/Settings.php`
- `includes/Jobs/Manager.php`
- `includes/Reports/Logger.php`

---

## Phase 1 — Bootstrap & Service Boundaries
### Objectives
- Move remaining orchestration out of monolith into service classes.
- Keep bootstrap file focused on wiring and constants.

### Deliverables
- Service registry/container-lite for internal dependencies.
- Backward-compatible facades from old static calls to new services.

### Candidate files
- `cloud-site-mover-clean-room.php`
- `includes/Core/Loader.php`
- `includes/Core/Plugin.php`
- `includes/Core/Container.php` (new)

---

## Phase 2 — Transport/Auth Layer Hardening
### Objectives
- Isolate request signing/verification and replay defenses in dedicated module.
- Keep current headers/options working while tightening validation flow.

### Deliverables
- `Security/AuthVerifier` + `Security/RequestSigner` modules.
- Centralized audit events with structured context.

### Candidate files
- `cloud-site-mover-clean-room.php`
- `includes/Security/AuthVerifier.php` (new)
- `includes/Security/RequestSigner.php` (new)
- `includes/Reports/Logger.php`

---

## Phase 3 — Migration State Machine & Persistence
### Objectives
- Introduce explicit migration lifecycle states/checkpoints.
- Decouple retry/lock logic from UI triggers.

### Deliverables
- `MigrationStateManager` abstraction.
- Durable state + step retry metadata and standardized failure payload.

### Candidate files
- `includes/Jobs/Manager.php`
- `includes/Migration/StateManager.php` (new)
- `includes/Migration/Runner.php` (new)
- `cloud-site-mover-clean-room.php`

---

## Phase 4 — API/CLI/Admin Surface Alignment
### Objectives
- Ensure parity and consistency across REST, WP-CLI, and Admin actions.
- Normalize error schema and security diagnostics output.

### Deliverables
- Unified command handlers for security/job actions.
- Shared response formatter utilities.

### Candidate files
- `cloud-site-mover-clean-room.php`
- `includes/Admin/ActionsController.php` (new)
- `includes/CLI/Commands.php` (new)

---

## Phase 5 — Quality, Compatibility, and Rollout
### Objectives
- Add integration checks for auth, retries, and migration state transitions.
- Harden upgrade path for existing installs and options.

### Deliverables
- Test checklist and regression matrix.
- Rollout notes and fallback toggles.

### Candidate files
- `README.md` (or `docs/` notes)
- `cloud-site-mover-clean-room.php`
- `includes/Core/Settings.php`

---

## Work mode per phase
1. Publish mini design note.
2. Ship small patch.
3. Run syntax + smoke checks.
4. Document risks and rollback.


# Detailed Phase Plans

## Phase 0 execution artifact
- See `docs/phase-0-gap-matrix.md` for the executable checklist, matrix template, risk scoring, and DoD.

## Phase 0 — Discovery and Gap Matrix (Detailed)
### Work packages
1. Inventory all existing contracts:
   - REST routes, methods, payload keys, auth expectations.
   - WP-CLI commands/options/output contracts.
   - Admin actions (nonce/capability gates) and settings schema.
2. Build a side-by-side matrix against reference architecture domains:
   - Bootstrap/container, migration orchestration, state persistence, transport/auth, diagnostics.
3. Classify each gap:
   - `Adopt`, `Adapt`, or `Keep` with rationale and risk.

### Acceptance checks
- Contract inventory doc reviewed and complete.
- No runtime code changes in this phase.

### Rollback
- Docs-only; revert single commit if needed.

---

## Phase 1 execution artifact
- See `docs/phase-1-bootstrap-service-boundaries.md` for scoped PR breakdown, compatibility gates, and rollback.

## Phase 1 — Bootstrap & Service Boundaries (Detailed)
### Work packages
1. Introduce minimal container/service registry abstraction.
2. Move composition/wiring responsibilities out of monolith bootstrap.
3. Keep backward-compatible static facades while delegating internally.

### Compatibility rules
- Do not rename public actions/routes/commands.
- Preserve existing options keys and defaults.

### Acceptance checks
- Plugin boots without warnings.
- Existing admin page, routes, and CLI commands still resolve.

### Rollback
- Revert container/wiring commits; facades stay backward compatible.

---

## Phase 2 execution artifact
- See `docs/phase-2-transport-auth-hardening.md` for signer/verifier segmentation, compatibility gates, and rollback.

## Phase 2 — Transport/Auth Layer Hardening (Detailed)
### Work packages
1. Extract signature verification and outbound signing into dedicated services.
2. Centralize replay/skew validation and rate-limit decisioning.
3. Normalize auth audit events (allow/deny/legacy) with structured context.

### Compatibility rules
- Keep existing headers and option toggles (`allow_legacy_auth`, `enforce_https`).
- Maintain existing deny/allow semantics unless explicitly versioned.

### Acceptance checks
- Signed requests still validate.
- Legacy path works only when enabled.
- Rate-limit/allowlist behavior remains deterministic.

### Rollback
- Re-point calls back to previous static auth helpers.

---

## Phase 3 execution artifact
- See `docs/phase-3-migration-state-persistence.md` for explicit state model, transition guards, persistence boundaries, and rollback.

## Phase 3 — Migration State Machine & Persistence (Detailed)
### Work packages
1. Define explicit migration states and transition guards.
2. Extract retry/lock/checkpoint persistence to state manager.
3. Standardize failed-run artifact payload structure.

### Compatibility rules
- Keep current retry thresholds and lock timeout options.
- Preserve ability to retry failed jobs from admin/CLI.

### Acceptance checks
- Interrupted jobs resume correctly.
- Failed job artifacts remain downloadable and parseable.

### Rollback
- Revert state manager integration and preserve legacy job manager path.

---

## Phase 4 execution artifact
- See `docs/phase-4-surface-alignment.md` for shared-handler alignment, schema consistency gates, and rollback.

## Phase 4 — API/CLI/Admin Surface Alignment (Detailed)
### Work packages
1. Unify command handlers behind shared application services.
2. Normalize response/error schema where possible without breaking clients.
3. Ensure multilingual strings and RTL-safe admin labels across surfaces.

### Compatibility rules
- No breaking changes to route paths/CLI verbs.
- Keep nonce/capability checks exactly enforced.

### Acceptance checks
- Admin actions, REST operations, and CLI commands produce consistent outcomes.
- i18n coverage verified for newly introduced strings.

### Rollback
- Revert handler routing while retaining shared services introduced earlier.

---

## Phase 5 execution artifact
- See `docs/phase-5-quality-compatibility-rollout.md` for regression matrix, release gates, rollout controls, and rollback.

## Phase 5 — Quality, Compatibility, and Rollout (Detailed)
### Work packages
1. Build regression checklist for core migrations/security/auth paths.
2. Add smoke/integration scripts for key paths (auth, retries, resume, finalize).
3. Produce rollout notes and feature flags/fallback toggles where needed.

### Acceptance checks
- Regression matrix passed for critical scenarios.
- Upgrade path validated for existing option sets.

### Rollback
- Disable new feature flags and fall back to stable path.

---

## Execution cadence per phase
- Design note (short)
- Small implementation PR(s)
- Validation checks
- Risk log + rollback notes

---

# Variance Review vs `wp-migrate-db-pro_v2.7.7`

## Evidence snapshot (from package structure)
- Reference includes DI/container bootstrap primitives (`class/Container.php`, `class/SetupProviders.php`).
- Reference has dedicated domains for HTTP transport (`Common/Http/*`), migration orchestration (`Common/Migration/*`), migration state (`Common/MigrationState/*`), queue workers (`Common/Queue/*`), plugin shell (`Common/Plugin/*`), and CLI (`Common/Cli/*`).

## Current-to-target variance matrix
| Domain | Current CSMCR status | Reference signal | Variance | Planned phase |
|---|---|---|---|---|
| Bootstrap/DI | Partial modularization (`includes/Core/*`) with remaining monolith orchestration | Container + provider setup present | Missing explicit container/provider graph | Phase 1 |
| Transport/Auth | Signed headers + skew/hash/allowlist/rate-limit exists | Dedicated HTTP service stack | Logic mostly present, but needs service isolation and stricter boundaries | Phase 2 |
| Migration orchestration | Job flow exists in main plugin + jobs manager | Dedicated migration manager/helper/finalize/initiate classes | Orchestration still too coupled to monolith | Phase 3 |
| Migration state persistence | Retry/lock/failure artifacts implemented | Separate migration state manager + persistence classes | Need explicit state model and transition guards | Phase 3 |
| Queue/workers | Basic cron/step lock behavior | Rich queue manager/worker/connection abstractions | No first-class queue abstraction layer | Phase 3/5 |
| API/CLI/Admin parity | Functional but uneven response/style consistency | Distinct plugin/CLI layers | Need unified app-service handlers and response conventions | Phase 4 |
| Quality gates | Lint + smoke mostly manual | Broader subsystem separation enables stronger tests | Need formal regression matrix and scripted checks | Phase 5 |

## Decisions
- Keep current external contracts stable while refactoring internals.
- Prioritize service-boundary extraction before introducing queue/state abstractions.
- Defer any route/CLI breaking changes unless explicitly versioned.

---

# Function Segments from `wp-migrate-db-pro_v2.7.7`

> الهدف: تجزئة وظائف المرجع إلى Segments قابلة للتنفيذ المرحلي داخل CSMCR بدون كسر العقود الحالية.

## Segment A — Bootstrap / DI / Providers
- Reference examples:
  - `class/Container.php`
  - `class/SetupProviders.php`
  - `class/WPMigrateDB.php`
- Functional role:
  - بناء الحاوية، تسجيل المزودات، تهيئة النظام.
- CSMCR mapping:
  - `includes/Core/Loader.php`, `includes/Core/Plugin.php`
- Planned phase: **Phase 1**

## Segment B — Plugin Shell (Admin Menu, Assets, Plugin Manager)
- Reference examples:
  - `class/Common/Plugin/Menu.php`
  - `class/Common/Plugin/Assets.php`
  - `class/Common/Plugin/PluginManagerBase.php`
- Functional role:
  - واجهة الإدارة، حقن الأصول، lifecycle management.
- CSMCR mapping:
  - monolith admin rendering + core plugin bootstrap
- Planned phase: **Phase 1 / Phase 4**

## Segment C — HTTP Transport & Remote Posting
- Reference examples:
  - `class/Common/Http/Http.php`
  - `class/Common/Http/RemotePost.php`
  - `class/Common/Http/Helper.php`
  - `class/Common/Http/WPMDBRestAPIServer.php`
- Functional role:
  - طبقة النقل، تجهيز طلبات remote، توحيد سلوك HTTP.
- CSMCR mapping:
  - `CSMCR_HTTP::remote()` + auth helpers in plugin class
- Planned phase: **Phase 2**

## Segment D — Migration Orchestration
- Reference examples:
  - `class/Common/Migration/InitiateMigration.php`
  - `class/Common/Migration/MigrationManager.php`
  - `class/Common/Migration/FinalizeMigration.php`
  - `class/Common/Migration/MigrationHelper.php`
- Functional role:
  - بداية/إدارة/إنهاء الهجرة وتدفق التشغيل.
- CSMCR mapping:
  - migrator + jobs flow داخل الملف الرئيسي + jobs manager
- Planned phase: **Phase 3**

## Segment E — Migration State & Persistence
- Reference examples:
  - `class/Common/MigrationState/MigrationState.php`
  - `class/Common/MigrationState/MigrationStateManager.php`
  - `class/Common/MigrationPersistence/Persistence.php`
- Functional role:
  - حفظ حالة الجلسة، checkpointing، transition guards.
- CSMCR mapping:
  - retries/locks/failure reports الحالية
- Planned phase: **Phase 3**

## Segment F — Queue / Worker Model
- Reference examples:
  - `class/Common/Queue/Manager.php`
  - `class/Common/Queue/Worker.php`
  - `class/Common/Queue/Cron.php`
  - `class/Common/Queue/Connections/*`
- Functional role:
  - إدارة طوابير الأعمال، worker execution، backends متعددة.
- CSMCR mapping:
  - cron + transient lock (محدود)
- Planned phase: **Phase 3 / Phase 5**

## Segment G — Profile & Form Data
- Reference examples:
  - `class/Common/Profile/ProfileManager.php`
  - `class/Common/Profile/ProfileImporter.php`
  - `class/Common/FormData/FormData.php`
- Functional role:
  - نماذج الإعدادات، profiles، import/export لسيناريوهات التشغيل.
- CSMCR mapping:
  - settings/profiles helpers الحالية
- Planned phase: **Phase 4**

## Segment H — CLI Surface
- Reference examples:
  - `class/Common/Cli/Cli.php`
  - `class/Common/Cli/Command.php`
  - `class/Common/Cli/CliManager.php`
- Functional role:
  - أوامر CLI موحدة ومتسقة مع بقية الأسطح.
- CSMCR mapping:
  - `CSMCR_CLI` الحالي
- Planned phase: **Phase 4**

## Segment I — Filesystem / Full-site / Media
- Reference examples:
  - `class/Common/Filesystem/Filesystem.php`
  - `class/Common/Filesystem/RecursiveScanner.php`
  - `class/Common/FullSite/FullSiteExport.php`
  - `class/Common/MF/*`
- Functional role:
  - الفحص/النسخ/التجميع لملفات الموقع والوسائط.
- CSMCR mapping:
  - package/manifest/simulation paths
- Planned phase: **Phase 3 / Phase 5**

## Segment J — Error / Logging / Compatibility
- Reference examples:
  - `class/Common/Error/Logger.php`
  - `class/Common/Error/ErrorLog.php`
  - `class/Common/Compatibility/*`
- Functional role:
  - توحيد تسجيل الأخطاء وتوافق البيئة والإضافات.
- CSMCR mapping:
  - `includes/Reports/Logger.php` + inline guards
- Planned phase: **Phase 2 / Phase 5**

## Segment K — Sanitization / Replace / Dry Run
- Reference examples:
  - `class/Common/Sanitize.php`
  - `class/Common/Replace.php`
  - `class/Common/DryRun/*`
- Functional role:
  - sanitize/replace pipeline + dry-run diff interpretation.
- CSMCR mapping:
  - dry_run/preflight flows الحالية
- Planned phase: **Phase 4 / Phase 5**

## Segment L — Addon/Extension Surface
- Reference examples:
  - `class/Common/Addon/*`
  - `class/Common/MF/Manager.php`
- Functional role:
  - نقاط توسعة وإدارة إضافات/مزايا إضافية.
- CSMCR mapping:
  - غير مفصول بالكامل حاليًا
- Planned phase: **Phase 5**

## Segment-driven delivery rule
- كل Segment يُنفَّذ عبر mini-design + PR صغير + checks + rollback notes.
- لا تعديل breaking على العقود الخارجية إلا بإصدار/نسخة واجهة واضحة.
