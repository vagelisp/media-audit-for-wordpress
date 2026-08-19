=== Media Audit ===
Contributors: eboxnet
Tags: media, uploads, audit, cleanup, wp-cli
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Find files that may be unused in WordPress uploads, spot missing Media Library files, and safely review, quarantine, back up, or delete them.

== Description ==

Media Audit reconciles files in the uploads directory against WordPress attachment metadata and database references.

Files without a detected reference are shown as likely stray candidates. They are not declared safe to delete. The plugin encourages a review, dry-run, and quarantine workflow before permanent removal.

= Features =

* AJAX-powered dashboard under Tools > Media Audit.
* Scan summaries including candidate count and potential disk space.
* Persistent storage-impact statistics for reclaimed space and completed cleanup actions.
* Search, sorting, bulk selection, and per-user saved results.
* Dry-run, timestamped quarantine, verified backup-and-remove, and guarded deletion.
* Recognition of attachment originals, generated sizes, edited originals, and backups.
* Checks for references in posts, metadata, options, and configured custom tables.
* Optional non-core database table scanning.
* WP-CLI table, CSV, JSON, and YAML output.
* CLI age and size filters, CI mode, and explicit permanent-delete confirmation.
* Complete in-dashboard WP-CLI reference with copy-ready recipes.
* Optional last-moment attachment and database revalidation, enabled by default.
* Configurable scan/action batches and pauses for slow servers.
* WordPress-native standalone-file deletion through `wp_delete_file()`.
* Stoppable batched scans that preserve reviewed partial findings.
* Separate Media Library integrity checks for missing originals and generated image sizes.
* WordPress-native cleanup of reviewed missing-file attachment records.
* No external service or telemetry.

= Important safety note =

A file can be referenced by an external system, theme or plugin code, encoded data, a custom database, or another location the plugin cannot inspect. Make a verified backup and test quarantined files before deleting anything permanently.

== Installation ==

1. Upload the `media-audit` directory to `/wp-content/plugins/`.
2. Activate Media Audit through the Plugins screen.
3. Open Tools > Media Audit.
4. Configure ignore patterns or custom table checks if needed.
5. Begin with a narrow subdirectory scan.

== Frequently Asked Questions ==

= Does a finding mean the file is safe to delete? =

No. A finding means the plugin did not detect a reference in the locations it checked. Treat it as a candidate requiring review.

= What does quarantine do? =

Quarantine moves selected files into a timestamped directory below the configured quarantine folder while preserving their original relative paths. The quarantine directory is excluded from future scans.

= Can I restore a quarantined file? =

Yes. The Quarantined files panel lists recoverable files and restores each one to its original uploads path. Restore is blocked rather than overwriting a file that already exists there.

The same panel can permanently delete selected quarantined files or all recoverable quarantined files. Downloadable ZIPs and CLI backup trees are not included in Delete all.

= What does Download ZIP & remove do? =

In the dashboard it creates one ZIP, verifies every archived entry using its size and SHA-256 hash, and only then removes the originals and starts the ZIP download. WP-CLI `--backup-delete` retains its server-side timestamped backup-tree workflow.

= Can I preview an action? =

Yes. Dry-run is an opt-in checkbox in the dashboard. WP-CLI supports `--dry-run` with quarantine or deletion.

= Can I stop a long scan? =

Yes. Dashboard scans process database candidates in small batches. Stop finishes the current request, saves everything classified through the latest completed batch, and marks the result as partial. Unprocessed candidates are not included in partial findings.

= Does it support ACF? =

ACF commonly stores values in post, term, user, and options metadata. Those locations are checked. Unusual custom tables can be configured explicitly.

= What is the WP-CLI command? =

Run `wp gp media-audit`. Use `wp help gp media-audit` for all options.

= How do I inspect older, larger candidates only? =

Use `wp gp media-audit --older-than=90 --min-size=100` to report candidates older than 90 days and at least 100 KB.

= Can the command be used in automation? =

Yes. `--summary-only --fail-on-findings` provides a concise result and a non-zero exit when candidates exist.

= Does the plugin send data elsewhere? =

No. Scanning and file operations run locally within WordPress.

= Can it find Media Library records whose files are missing? =

Yes. The Library integrity tab checks attachment records in batches. It reports missing local originals and, separately, generated sizes that are absent while the original exists. Missing-original records can be deleted individually or all at once after confirmation; cleanup uses WordPress `wp_delete_attachment()` rather than direct database queries.

Sites using S3, CDN, or another media-offload plugin require special care. A valid remote attachment may intentionally have no local file, so confirm the remote object and offload configuration before deleting a reported record.

== WP-CLI Examples ==

`wp gp media-audit`

`wp gp media-audit --uploads-subdir=2025 --format=json`

`wp gp media-audit --older-than=90 --min-size=100 --summary-only`

`wp gp media-audit --quarantine --dry-run`

`wp gp media-audit --delete --dry-run`

`wp gp media-audit --backup-delete --dry-run`

Real permanent deletion requires `wp gp media-audit --delete --yes`. A real backup-and-remove operation similarly requires `wp gp media-audit --backup-delete --yes`.

== Changelog ==

= 1.0.1 =

* Matched the Settings tab width to the other Media Audit sections.
* Expanded the How it works guide with scan stages, result meanings, action guidance, and a pre-cleanup checklist.

= 1.0.0 =

* First stable public release of Media Audit.
* Added uploads auditing with attachment and database-reference checks.
* Added stoppable AJAX scans, saved partial results, filtering, sorting, and batched actions.
* Added quarantine, guarded restoration, verified ZIP backup and removal, and permanent cleanup tools.
* Added Media Library integrity checks for missing originals and generated files.
* Added slow-server controls, cleanup statistics, WP-CLI workflows, and developer documentation.
* Passed WordPress coding standards, PHP 7.4 compatibility, and Plugin Check new-submission validation.

= 0.16.0 =

* Applied the WordPress PHP Coding Standards throughout the plugin.
* Completed PHPDoc parameter and return documentation for every PHP function and method.
* Documented narrowly scoped database and filesystem exceptions used for safe batching, atomic moves, and streamed ZIP verification.
* Hardened AJAX array unslashing and sanitization while retaining server-owned finding validation.
* Modernized and formatted dashboard JavaScript and CSS using the official WordPress lint configurations.
* Added current WordPress compatibility and release metadata for directory submission.

= 0.15.4 =

* Allowed safe filenames containing consecutive dots.
* Kept traversal protection for actual current-directory and parent-directory path segments.
* Unified the corrected path rule across dashboard and WP-CLI workflows.

= 0.15.3 =

* Added consistent spacing above section-level notices.

= 0.15.2 =

* Fixed Delete all validation across batched requests.
* Preserved whole-result authorization while keeping genuine per-batch progress.
* Chunked large saved finding sets to avoid object-cache item-size failures.
* Removed repeated full-result payloads and full-result rewrites during file actions.
* Added a clear message when saved server findings have expired.

= 0.15.1 =

* Moved AJAX success, warning, and error notices into their related scan, quarantine, integrity, or CLI section.
* Added reliable dismiss controls for notices created after page load.

= 0.15.0 =

* Added a Library integrity tab for missing local attachment originals and generated sizes.
* Added batched progress, cooperative stopping, saved partial findings, and incremental rendering.
* Added selected/all attachment-record cleanup through `wp_delete_attachment()` with immediate original rechecks.
* Added explicit media-offload warnings and accurate statistics for generated files removed by WordPress.
* Reduced repeated AJAX payload size during large integrity checks.

= 0.14.1 =

* Aligned settings checkboxes with their first text line.

= 0.14.0 =

* Added incremental findings and action-report rendering with Load more controls.
* Added a configurable rows-per-view setting, defaulting to 250.
* Debounced result filtering and reduced DOM layout/paint work.
* Kept complete datasets available for whole-result actions without rendering every row.
* Avoided duplicating the complete findings array during ordinary renders.

= 0.13.3 =

* Added opt-in browser console diagnostics, disabled by default.
* Disabled heartbeat timers and diagnostic payload construction while logging is off.
* Capped enabled file-action console samples at 100 rows.

= 0.13.2 =

* Moved Storage Impact above the scan controls.
* Kept cleanup statistics hidden until tracked activity exists.

= 0.13.1 =

* Constrained large per-file reports to a keyboard-accessible fixed-height scroll area.
* Added a visible action-result count.

= 0.13.0 =

* Batched delete, Delete all, and quarantine actions with genuine progress.
* Added scan batch, file-action batch, and inter-batch pause settings.
* Made last-moment reference revalidation optional but enabled by default.
* Kept saved-finding and path validation active when revalidation is disabled.

= 0.12.0 =

* Added inline operation progress for all dashboard filesystem actions.
* Added dedicated progress feedback for quarantine loading, restore, and selected/all deletion.
* Displayed queued file counts without fabricating percentages for one-request operations.
* Added accessible busy/live states without moving the viewport.

= 0.11.0 =

* Added a persistent Storage Impact dashboard section.
* Counted reclaimed bytes only after successful permanent removal.
* Added cumulative permanent-removal, quarantine, and restore counters.
* Included quarantine cleanup in reclaimed-space totals.

= 0.10.1 =

* Fixed HTML-escaped query separators causing valid ZIP downloads to reach a missing/invalid endpoint.

= 0.10.0 =

* Added Delete all findings for the complete saved result set.
* Added quarantine selection plus permanent Delete selected and Delete all controls.
* Excluded ZIP and CLI backup artifacts from quarantine deletion.
* Used WordPress-native file deletion with explicit confirmations for quarantine cleanup.

= 0.9.2 =

* Replaced the browser-blocked hidden-frame ZIP transfer with a named attachment download.
* Made dashboard dry-run opt-in; destructive removal actions still require confirmation.

= 0.9.1 =

* Isolated ZIP downloads so they cannot move or navigate the admin page.
* Preserved timestamped Media Audit archive filenames instead of the download endpoint name.

= 0.9.0 =

* Added an in-dashboard quarantine inventory with guarded one-click restoration.
* Blocked restoration when the original uploads path is already occupied.
* Clarified quarantine behavior beside the file actions.
* Prevented notices, focused buttons, and progress visibility from moving the viewport during actions.

= 0.8.0 =

* Changed the dashboard backup action to create and automatically download one verified ZIP before removing originals.
* Added an authenticated, short-lived, current-user-only archive download endpoint.
* Prevented file-action controls and results rerendering from jumping the page to the top.
* Kept the existing WP-CLI verified backup-directory behavior.

= 0.7.0 =

* Refined dashboard hierarchy, spacing, responsive controls, progress treatment, and results table.
* Added clear Live, Partial, Limited, and Complete result states with accurate live metric labels.
* Replaced absolute scan paths with concise uploads-relative scope labels.
* Kept filtering, sorting, and row selection usable during batched scans.
* Fixed sticky table headers bleeding into result rows and improved progress accessibility.

= 0.6.1 =

* Added complete PHPDoc to the admin controller and scan-job helpers.
* Documented JavaScript batching, cancellation, race handling, and trust boundaries.
* Added a developer architecture guide covering state, safety invariants, and extension hooks.

= 0.6.0 =

* Converted dashboard reference checks to persistent batches of 25 candidates.
* Added live candidate counts and percentage progress.
* Added Stop scan with saved, clearly marked partial findings.
* Allowed dry-run and quarantine review against stopped results.
* Added race-safe stop markers and 30-minute per-user scan jobs.

= 0.5.0 =

* Revalidate attachment metadata and database references immediately before every action.
* Block files that became referenced after the scan.
* Use WordPress `wp_delete_file()` for standalone-file removal.
* Added lifecycle hooks for quarantine, backup-and-remove, and deletion.
* Excluded Media Audit's own settings and transients from reference matching.

= 0.4.0 =

* Added a CLI Reference dashboard tab.
* Documented every plugin command option and common global WP-CLI parameters.
* Added one-click copying for complete command recipes.

= 0.3.0 =

* Added verified Backup & remove actions to the dashboard and WP-CLI.
* Added live browser-console scan heartbeats and final diagnostic summaries.
* Added WP-CLI inventory, reference-index, and database-check progress messages.

= 0.2.0 =

* Rebuilt the admin experience with AJAX scanning and file actions.
* Added result summaries, filtering, sorting, persistent findings, and action reports.
* Added size and age CLI filters, summary-only and CI modes, guarded deletion, and richer metrics.
* Hardened path validation and excluded quarantine files from future scans.
* Added comprehensive project and WordPress.org documentation.

= 0.1.0 =

* Initial release.

== Upgrade Notice ==

= 1.0.1 =

Improves dashboard consistency and adds a more useful plain-language guide to scanning and safe cleanup.

= 1.0.0 =

First stable public release with uploads auditing, safe cleanup workflows, Media Library integrity checks, and WP-CLI support.

= 0.16.0 =

Standards and submission-readiness release with hardened AJAX input handling and no intended workflow changes.

= 0.15.4 =

Allows safe multi-dot filenames to be scanned and cleaned without weakening traversal protection.

= 0.15.3 =

Improves spacing around dashboard notices.

= 0.15.2 =

Fixes Delete all for large and stale browser result sets while retaining server-owned validation.

= 0.15.1 =

Keeps operational feedback beside the workflow that produced it instead of at the top of the page.

= 0.15.0 =

Adds a guarded Media Library database-integrity workflow. Review offloaded media carefully before deleting records.

= 0.14.1 =

Corrects checkbox alignment in settings cards.

= 0.14.0 =

Reduces browser memory, DOM, layout, and filtering work for very large result sets.

= 0.13.3 =

Makes browser console diagnostics opt-in to avoid unnecessary client-side work.

= 0.13.2 =

Improves statistics placement and keeps empty counters out of the interface.

= 0.13.1 =

Keeps very large deletion reports from expanding the admin page.

= 0.13.0 =

Adds real file-action progress and tuning controls for slow servers. Revalidation remains enabled by default.

= 0.12.0 =

Adds visible progress feedback to Delete all and other dashboard tasks.

= 0.11.0 =

Adds persistent cleanup and reclaimed-space statistics; tracking begins after this update.

= 0.10.1 =

Fixes authenticated dashboard ZIP downloads returning an error page.

= 0.10.0 =

Adds whole-result deletion and selected/all quarantine cleanup controls.

= 0.9.2 =

Restores automatic ZIP downloads and makes dashboard dry-run opt-in.

= 0.9.1 =

Fixes ZIP download navigation and filenames.

= 0.9.0 =

Adds guarded quarantine restoration and fixes the remaining file-action page jumps.

= 0.8.0 =

Dashboard backup-and-remove now downloads a verified ZIP and no longer jumps the page during file actions.

= 0.7.0 =

Improves the live scanning and results interface without changing the safety model or filesystem actions.

= 0.6.1 =

Adds developer-facing code documentation without changing scan behavior.

= 0.6.0 =

Adds stoppable batched dashboard scans with preserved partial results.

= 0.5.0 =

Adds last-moment reference validation and WordPress-native standalone-file deletion.

= 0.4.0 =

Adds the complete WP-CLI reference directly to the Media Audit dashboard.

= 0.3.0 =

Adds verified backups before removing original files and improved scan diagnostics.

= 0.2.0 =

Adds the AJAX dashboard and safer, more capable WP-CLI workflows. Back up before performing any filesystem action.
