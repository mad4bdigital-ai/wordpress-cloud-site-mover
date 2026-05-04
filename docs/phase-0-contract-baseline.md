# Phase 0 — Contract Baseline (Current Snapshot)

## Source
- Derived from current `cloud-site-mover-clean-room.php` route, settings, and CLI definitions.

## REST Route Baseline (`cloud-site-mover/v1`)
- GET `/ping`
- GET `/security/status`
- GET `/security/check`
- GET `/preflight`
- GET `/db/tables`
- GET `/db/schema`
- GET `/db/rows`
- POST `/db/write-table`
- POST `/db/backup`
- GET `/files/list`
- GET `/files/manifest`
- GET `/files/chunk`
- POST `/files/write`
- GET `/multisite/plan`
- GET `/site/manifest`
- GET `/multisite/map`
- GET `/multisite/simulation`
- GET `/package/manifest`

## Security/Policy Options Baseline
- `allow_legacy_auth`
- `enforce_https`
- `ip_allowlist`
- `auth_max_skew_seconds`
- `auth_rate_limit_per_minute`
- `max_step_retries`
- `job_step_lock_timeout_seconds`

## Security Policy Snapshot Fields Baseline
- `allow_legacy_auth`
- `enforce_https`
- `auth_max_skew_seconds`
- `auth_rate_limit_per_minute`
- `job_step_lock_timeout_seconds`
- `ip_allowlist_count`
- `ip_allowlist_entries`

## WP-CLI Surface Baseline (`wp csmcr ...`)
- `ping`
- `dry_run`
- `backup_db`
- `pull_db`, `push_db`
- `pull_files`, `push_files`
- `reset_cursors`
- `profile list|save|load`
- `job status|start|step|cancel|retry-failed|run-until-complete`
- `background status|enable|disable`
- `preflight`
- `secret show|rotate`
- `security status|check [--strict]`
- `cleanup`
- `manifest`
- `handoff_plan`
- `multisite_map`
- `multisite_simulate`
- `package_manifest`
- `multisite_plan`

## Notes for Phase-1+ Execution
- Treat this document as protected baseline for non-breaking refactor checks.
- Any planned contract deviations require explicit versioning + migration note.
