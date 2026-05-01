=== Cloud Site Mover Clean Room ===
Contributors: clean-room-build
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later

Clean-room WordPress migration helper for trusted admin-to-admin site moves. Supports resumable database push/pull, media/theme/plugin sync, profiles, WP-CLI, background runner, preflight checks, backups, reports, guarded multisite planning, portable manifests, and integrity snapshots.

== Important ==
This is not a copy of any commercial migration plugin. It is a clean-room implementation built around similar migration workflow concepts.

Use only on trusted sites. Take a full hosting backup first. Disable write permissions and remove the plugin from both sites after migration.

== Features ==
* DB push and pull between WordPress installs.
* Serialized search/replace handling.
* Media library sync.
* Theme and plugin folder sync with explicit write guard.
* Resumable jobs with AJAX runner and WP-Cron background runner.
* WP-CLI commands.
* Profiles.
* Preflight checks.
* Local DB backups before overwrite.
* Reports and rollback points.
* Full-site manifest and portable package manifest.
* Guarded multisite map and conversion simulation.
* Integrity snapshots and remote comparison reports.
* Restore plan generation.

== WP-CLI examples ==
wp csmcr ping
wp csmcr preflight
wp csmcr job start pull-db
wp csmcr job run-until-complete --max-steps=500
wp csmcr integrity-snapshot
wp csmcr compare-remote
wp csmcr restore-plan
wp csmcr cleanup

== Changelog ==
= 1.2.0 =
* Added integrity snapshot endpoint and report.
* Added remote snapshot comparison report.
* Added restore plan report.
* Added WP-CLI commands: integrity-snapshot, compare-remote, restore-plan.

= 1.1.0 =
* Added multisite conversion simulation and portable package manifest.

= 1.0.0 =
* Added full-site manifest, handoff plan, and multisite map.
