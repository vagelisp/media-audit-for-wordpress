# Media Audit

Media Audit is a cautious WordPress uploads auditor. It inventories files on disk, removes known Media Library files and their generated variants from consideration, searches WordPress database content for textual references, and presents the remainder as **likely stray candidates**.

It is designed for administrators and developers who need evidence before cleaning an old or bloated `wp-content/uploads` directory.

> A missing reference is not proof that a file is unused. Back up the site, review every result, run a dry run, and prefer quarantine over permanent deletion.

## Highlights

- AJAX admin workflow under **Tools → Media Audit**—no page reload during scans or file actions.
- Batched dashboard scans with live progress, cancellation, and saved partial findings.
- Separate Media Library integrity checks for missing local originals and generated sizes.
- Result summary with scanned files, attachment matches, database matches, candidate count, and potential disk space.
- Persistent cleanup statistics for reclaimed space, permanent removals, quarantine moves, and restores.
- Search, sort, bulk selection, dry-run, quarantine, verified backup-and-remove, and guarded permanent deletion.
- Per-user results retained for 12 hours.
- Attachment-aware matching for originals, generated sizes, edited originals, and backup sizes.
- Reference checks across posts, excerpts, GUIDs, post meta, options, term meta, user meta, and comment meta.
- Optional custom-table detection and broad non-core table scanning.
- WP-CLI output in table, CSV, JSON, or YAML format.
- Complete in-dashboard CLI reference with copy-ready recipes.
- CLI filters for candidate age and size, CI exit behavior, and explicit deletion confirmation.
- Local-only operation. The plugin does not call an external service.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- WP-CLI for command-line usage
- An administrator account for the dashboard

## Installation

1. Copy the plugin directory to `wp-content/plugins/media-audit`.
2. Activate **Media Audit** in WordPress.
3. Open **Tools → Media Audit**.
4. Save any ignore or custom-table settings before the first broad scan.

## Recommended workflow

1. Make a verified filesystem and database backup.
2. Scan a narrow uploads subdirectory such as `2026/08`.
3. Keep database checking enabled for the most conservative result.
4. Filter and inspect the candidates.
5. Select a small batch and run a dry-run quarantine.
6. Disable dry-run and quarantine the files.
7. Test the public site and administrative workflows.
8. Restore any file that proves necessary, or remove old quarantine runs manually after an appropriate retention period.

The plugin deliberately labels results as candidates. References can live in encoded values, files, remote services, custom database schemas, or runtime-generated code that a textual database search cannot discover.

## Dashboard

### Scan options

- **Uploads subdirectory:** Restricts inventory to a path relative to the uploads root.
- **Database check limit:** Stops database checks after a fixed number of candidates. `0` means unlimited. A limited scan is incomplete and is marked as such.
- **Include non-media files:** Includes extensions not registered in the WordPress MIME map.
- **Fast scan:** Skips database text searches. This is faster but produces lower-confidence candidates.

Dashboard reference checks use a configurable batch size of 25 by default. **Stop scan** preserves findings through the latest completed batch and marks them as partial. Partial results never imply that the remaining candidates are clean; they simply have not been classified yet. Saved partial rows can still be reviewed or used with dry-run and quarantine. Last-moment reference validation remains enabled by default and can be disabled in advanced settings when an administrator explicitly accepts the additional risk.

### Slow-server tuning

- **Scan batch size:** Candidates checked per scan request; reduce it when scan steps time out.
- **File-action batch size:** Files deleted or quarantined per request; smaller batches provide more frequent progress updates.
- **Pause between batches:** Adds breathing room between action requests on constrained servers.
- **Rows rendered per view:** Limits findings and action-report DOM nodes while keeping the complete data available through Load more.
- **Revalidate references:** Recommended and enabled by default. Disabling it skips the expensive current-reference lookup but retains capability, nonce, saved-finding, and path-safety checks.

### Settings

- **Ignore patterns:** One glob per line. Examples: `cache/*`, `tmp/`, or `*.webp`.
- **Custom table checks:** Comma-separated `table:column` pairs. The current WordPress prefix can be omitted.
- **Scan all non-core tables:** Detects and searches text-like columns in other tables. This can be expensive.
- **Quarantine directory:** Relative to uploads. It defaults to `.media-audit-quarantine` and is automatically excluded from future audits.

### File actions

- **Dry run:** Opt-in preview that reports the planned action without touching files.
- **Quarantine:** Moves files into a timestamped directory while preserving relative paths.
- **Restore:** Lists quarantined files in the dashboard and moves a selected file back to its original uploads path. An existing destination is never overwritten.
- **Quarantine deletion:** Permanently deletes selected recoverable quarantine entries or all recoverable entries; backup ZIPs and CLI backup trees are excluded.
- **Download ZIP & remove (dashboard):** Builds one ZIP, reads every archived entry back to verify its byte count and SHA-256 hash, and only then removes the originals and starts the download.
- **Backup & remove (WP-CLI):** Copies files into `<quarantine>/backups/<run>/`, verifies size and SHA-256, and only then removes the originals.
- **Delete:** Permanently unlinks selected findings or the entire saved finding set and cannot be undone. The UI requires confirmation when dry-run is disabled.

Every submitted path is checked against the current user's saved findings and normalized as a safe relative uploads path before an action is attempted.

### Media Library integrity

The **Library integrity** tab audits the other side of the uploads relationship: attachment database records whose expected local original no longer exists. It also reports attachment metadata that names generated image sizes missing from disk while the original remains available.

The check runs in configurable batches, shows real completed/total progress, and can be stopped after the current request. Completed findings remain available. Missing generated sizes are report-only and can usually be addressed with a thumbnail-regeneration tool after the source image is verified.

Only missing-original records can be selected for cleanup. Immediately before deletion, the server confirms that the attachment came from the saved integrity result and that its local original is still absent. It then calls `wp_delete_attachment($id, true)` so WordPress removes the post, attachment metadata, relationships, known remaining generated files, and runs normal attachment-deletion hooks.

WordPress can delete the database record even if an individual companion file cannot be removed. Media Audit compares known local companions before and after the call, flags any survivors in the per-record outcome, and counts only files that actually disappeared as reclaimed storage.

> Object-storage and media-offload plugins may intentionally remove local files while retaining valid remote media. The integrity screen cannot prove a remote object is absent. Review those records with the offload provider before deleting anything; both the screen and confirmations call out this risk.

## WP-CLI

The command namespace is retained for backward compatibility:

```bash
wp gp media-audit
```

### Useful examples

```bash
# Audit all registered media files.
wp gp media-audit

# Audit one year and return machine-readable results.
wp gp media-audit --uploads-subdir=2025 --format=json

# Only show candidates at least 100 KB and older than 90 days.
wp gp media-audit --min-size=100 --older-than=90

# Print counts and space without listing every file.
wp gp media-audit --summary-only

# Preview quarantine operations.
wp gp media-audit --quarantine --dry-run

# Move all findings into quarantine.
wp gp media-audit --quarantine

# Preview a verified backup-and-remove run, then explicitly confirm it.
wp gp media-audit --backup-delete --dry-run
wp gp media-audit --backup-delete --yes

# Preview permanent deletion, then explicitly confirm it.
wp gp media-audit --delete --dry-run
wp gp media-audit --delete --yes

# Fail a CI or maintenance job when candidates exist.
wp gp media-audit --summary-only --fail-on-findings
```

### Options

| Option | Purpose |
| --- | --- |
| `--uploads-subdir=<path>` | Restrict the scan below uploads. |
| `--limit=<number>` | Limit database-checked candidates; `0` is unlimited. |
| `--min-size=<kb>` | Only report candidates at least this large. |
| `--older-than=<days>` | Only report candidates older than this age. |
| `--all-files` | Include non-media extensions. |
| `--skip-db-check` | Use attachment metadata only. |
| `--ignore=<patterns>` | Add comma-separated glob patterns. |
| `--ignore-file=<path>` | Read newline-separated patterns, with `#` comments. |
| `--custom-tables=<list>` | Add `table:column` reference checks. |
| `--scan-all-tables` | Search text columns in non-core tables. |
| `--format=<format>` | Use `table`, `csv`, `json`, or `yaml`. |
| `--summary-only` | Suppress individual finding rows. |
| `--quarantine` | Move findings to quarantine. |
| `--backup-delete` | Create verified backups, then remove originals. |
| `--delete` | Permanently remove findings. |
| `--dry-run` | Simulate the chosen file action. |
| `--yes` | Required for a real `--delete`. |
| `--quarantine-dir=<path>` | Override the quarantine directory. |
| `--fail-on-findings` | Exit non-zero when candidates exist. |

`--quarantine`, `--backup-delete`, and `--delete` are mutually exclusive. Real backup-and-remove and permanent-delete operations require `--yes`.

## Scan diagnostics

Enable **Browser console diagnostics** in Settings, then open the browser developer console before starting a dashboard scan to see submitted options, a heartbeat every five seconds, errors, elapsed request time, and a final result summary. File-action samples are capped at 100 rows. Diagnostics are disabled by default, and their heartbeat timer and large payload construction do not run while disabled.

WP-CLI reports when filesystem inventory and attachment indexing complete, then prints a milestone for every 250 database candidates checked. These messages make long scans observable without changing machine-readable finding rows.

## What is checked

Media Audit builds an attachment reference index from `_wp_attached_file` and `_wp_attachment_metadata`. It recognizes the main file, generated image sizes, `original_image`, and `backup_sizes`.

For files not in that index, it searches common textual storage locations:

- `posts.post_content`, `posts.post_excerpt`, and `posts.guid`
- post, term, user, and comment metadata
- WordPress options
- explicitly configured custom table columns
- optionally detected text, character, and JSON columns in non-core tables

URL-encoded URLs, plain URLs, `/uploads/` fragments, and relative paths are considered.

## Known limitations

- Initial filesystem inventory and attachment indexing occur in the first request; exceptionally large libraries can still make that preparation request expensive. Database candidate checks are batched and stoppable.
- Binary, encrypted, compressed, or unusually encoded references may not match.
- External databases, object storage, APIs, CSS/JavaScript/PHP files, and remote content are outside the default search.
- The integrity check tests the local filesystem only, so intentionally offloaded media can appear as missing and must be verified with its remote provider.
- A database-check limit makes the result incomplete.
- The restore browser lists up to 500 quarantined files at a time; backup ZIPs and CLI backup trees are intentionally excluded.
- Files removed directly from disk do not trigger WordPress attachment lifecycle hooks.

## Security model

- Dashboard access requires `manage_options`.
- AJAX requests require a user nonce.
- Settings and submitted paths are sanitized.
- File actions only accept paths present in the current user's saved audit.
- Attachment metadata and database references are rechecked immediately before every action.
- Files that became referenced after scanning are blocked and left untouched.
- Traversal-like or control-character paths are rejected.
- Standalone files are removed through WordPress `wp_delete_file()` and its filter.
- Registered attachments are never removed as raw files. Integrity cleanup accepts only saved missing-original records, rechecks the original, and uses `wp_delete_attachment()`.
- Real CLI deletion requires `--yes`.

## Extending reference detection

Implementation boundaries, scan-job fields, cancellation semantics, and filesystem safety invariants are documented in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

Ignore patterns can be modified with the `media_audit_ignore_patterns` filter:

```php
add_filter('media_audit_ignore_patterns', function ($patterns) {
    $patterns[] = 'generated-reports/*';
    return $patterns;
});
```

File-action integrations can observe these hooks:

- `media_audit_before_file_action` — before a real quarantine, backup removal, or deletion.
- `media_audit_file_quarantined` — after a file is moved successfully.
- `media_audit_file_restored` — after a quarantined file is restored successfully.
- `media_audit_quarantined_file_deleted` — after a quarantined file is permanently removed.
- `media_audit_file_backed_up_and_removed` — after a verified backup and successful original removal.
- `media_audit_file_deleted` — after WordPress successfully removes a standalone file.

Core `wp_delete_file` filtering also applies to backup removal and permanent deletion.

## Naming recommendation

**Upload Ledger — Media Reference Auditor** is a stronger long-term name.

“Ledger” describes what the plugin actually does: reconcile files on disk against recorded references and expose discrepancies for review. It avoids promising that every candidate is safe to delete, and it distinguishes the project from existing WordPress plugins using “Media Audit” or “Media Sweep.” A future rename should keep the current option keys, text domain, and `wp gp media-audit` command as compatibility aliases.

Other viable names:

- Upload Ledger
- StrayFile Audit
- Uploads Reconciler
- Media Reference Inspector

## Creating a release

The **Create release** GitHub Actions workflow performs the complete release process manually and safely:

1. Update the version in the `media-audit.php` plugin header, `MEDIA_AUDIT_VERSION`, and the `readme.txt` stable tag.
2. Add the matching changelog entries and merge the changes into the default branch.
3. Open **Actions → Create release → Run workflow**.
4. Choose whether the release is a prerelease and run it from the commit you want to publish.

The workflow refuses mismatched versions and existing tags. It creates the `vX.Y.Z` tag, packages a clean `media-audit` plugin directory, verifies the ZIP, generates a SHA-256 checksum, and publishes both files in a GitHub Release. GitHub-generated release notes are included automatically.

## Changelog

### 1.0.1

- Matched the Settings tab width to the other Media Audit sections.

### 1.0.0

- First stable public release of Media Audit.
- Included uploads auditing, database-reference checks, stoppable AJAX scans, saved partial results, and large-list controls.
- Included quarantine and restoration, verified ZIP backup and removal, guarded permanent deletion, and storage-impact statistics.
- Included Media Library integrity checks and WordPress-native cleanup of missing-file attachment records.
- Included complete WP-CLI workflows, developer documentation, and automated GitHub release packaging.
- Passed WordPress coding standards, PHP 7.4 compatibility, and Plugin Check new-submission validation.

### 0.16.0

- Applied the WordPress PHP Coding Standards throughout the plugin and completed PHPDoc parameter and return documentation.
- Hardened AJAX array unslashing and sanitization while preserving server-owned finding validation.
- Documented safe database, atomic-move, and streamed-ZIP exceptions narrowly at their call sites.
- Modernized and formatted the dashboard JavaScript and CSS with the official WordPress lint configurations.
- Added current WordPress compatibility, license, changelog, and release metadata for directory submission.

### 0.15.4

- Allowed legitimate filenames containing consecutive dots, such as `document....-2023.pdf`.
- Narrowed traversal protection to reject actual `.` and `..` directory segments rather than harmless dots within filenames.
- Applied the same path rule consistently to dashboard actions, WP-CLI scans/actions, upload subdirectories, quarantine paths, and attachment metadata paths.

### 0.15.3

- Added vertical spacing above section-level AJAX notices.

### 0.15.2

- Fixed Delete all losing its whole-result scope when work was split into AJAX batches.
- Added a server-owned fallback batch when browser paths become stale during an authorized Delete all operation.
- Stored large finding sets in bounded transient chunks to avoid object-cache item limits.
- Stopped retransmitting and rewriting the complete remaining finding set after every successful action batch.
- Added a specific expired-results message when the server no longer has an authoritative finding set.

### 0.15.1

- Routed scan, file-action, quarantine, integrity, and CLI notices to their related dashboard sections.
- Added local dismiss controls that work for notices inserted after page load.

### 0.15.0

- Added a separate Library integrity tab for attachment records with missing local originals.
- Added report-only detection of generated image sizes missing while the original remains present.
- Added batched progress, stoppable checks, partial saved results, incremental table rendering, and slow-server action batches.
- Added selected/all missing-record cleanup through `wp_delete_attachment()` with server-owned finding intersection and immediate local-original revalidation.
- Added explicit offloaded-media warnings and counted generated files actually removed in cleanup statistics.
- Reduced long-scan response weight by sending only each batch's new findings after initialization.

### 0.14.1

- Aligned settings checkboxes with their first text line across WordPress admin control styles.

### 0.14.0

- Added incremental rendering for findings and action reports with accurate shown/total counts.
- Added a configurable Rows rendered per view setting, defaulting to 250.
- Debounced result filtering to avoid rebuilding the table on every keystroke.
- Kept complete datasets available for filtering, Delete all, selection, and Load more without placing every row in the DOM.
- Added CSS containment to action-result rows to reduce layout and paint work.
- Avoided duplicating the complete findings array during ordinary renders.

### 0.13.3

- Added a Browser console diagnostics setting, disabled by default.
- Routed scan heartbeats, batch messages, errors, summaries, and action tables through the setting.
- Avoided starting the heartbeat timer or constructing action-table payloads while diagnostics are disabled.
- Capped enabled file-action console samples at 100 rows.

### 0.13.2

- Moved Storage Impact above the scan controls for clearer dashboard hierarchy.
- Kept the statistics card hidden until at least one tracked counter becomes non-zero.

### 0.13.1

- Constrained large per-file action reports to a keyboard-accessible 420px scroll area.
- Added a visible result count so large deletion reports no longer expand the admin page.

### 0.13.0

- Batched delete, Delete all, and quarantine actions with genuine completed/total progress.
- Added configurable scan batch size, file-action batch size, and inter-batch pause.
- Made last-moment reference revalidation optional while keeping it enabled by default.
- Retained server-owned finding intersection and path validation even when revalidation is disabled.

### 0.12.0

- Added inline operation progress for quarantine, ZIP creation, selected deletion, and Delete all findings.
- Added separate progress feedback for quarantine inventory loading, restore, selected deletion, and Delete all.
- Displayed queued file counts and revalidation context without presenting a fabricated percentage for single-request filesystem work.
- Added accessible busy/live states and prevented progress panels from changing the current viewport.

### 0.11.0

- Added a persistent Storage Impact dashboard section.
- Tracked reclaimed bytes only for successful permanent removals, including quarantine cleanup.
- Added cumulative counters for permanently removed, quarantined, and restored files.
- Made result rows carry verified source sizes so cleanup totals reflect actual successful actions.

### 0.10.1

- Fixed JSON download URLs containing HTML-escaped `&amp;` separators, which caused the authenticated ZIP endpoint to reject otherwise valid downloads.

### 0.10.0

- Added Delete all findings without sending thousands of paths through PHP request limits.
- Added quarantine selection plus permanent Delete selected and Delete all controls.
- Excluded downloadable ZIPs and CLI backup trees from quarantine deletion.
- Added confirmations and WordPress-native `wp_delete_file()` removal for quarantine cleanup.

### 0.9.2

- Replaced the browser-blocked hidden-frame ZIP transfer with a named attachment download.
- Made dashboard dry-run opt-in instead of selected by default; permanent deletion and ZIP removal still require confirmation.

### 0.9.1

- Isolated ZIP downloads from the admin page so they cannot change its scroll position or navigation state.
- Preserved the generated `media-audit-backup-YYYYMMDD-HHMMSS-xxxxxx.zip` filename instead of using the `admin-post` endpoint name.

### 0.9.0

- Added an in-dashboard quarantine inventory with guarded one-click restoration.
- Blocked restoration when the original uploads path is already occupied.
- Clarified quarantine behavior directly beside the file actions.
- Stopped notices, focused buttons, and scan-progress visibility from moving the viewport during file actions.

### 0.8.0

- Changed the dashboard backup action to create and automatically download one verified ZIP before removing originals.
- Added an authenticated, short-lived, current-user-only archive download endpoint.
- Prevented file-action buttons and results rerendering from jumping the admin page to the top.
- Kept the existing verified backup-directory behavior for `wp gp media-audit --backup-delete`.

### 0.7.0

- Refined the dashboard hierarchy, spacing, responsive toolbar, progress treatment, and results table.
- Added clear Live, Partial, Limited, and Complete result states with accurate in-progress metric labels.
- Replaced noisy absolute filesystem scope paths with concise `uploads/...` labels.
- Kept filtering, sorting, and row selection available while a batched scan is running.
- Fixed sticky table headers bleeding into the first result rows and improved progress accessibility.

### 0.6.1

- Added complete PHPDoc for the dashboard controller and AJAX lifecycle.
- Documented batching, stop races, client trust boundaries, and backup verification inline.
- Added `docs/ARCHITECTURE.md` with component ownership, job payloads, cancellation semantics, safety invariants, hooks, and scaling boundaries.

### 0.6.0

- Converted dashboard database checks into persistent batches of 25 candidates.
- Added live progress counts, percentages, and batch diagnostics.
- Added a Stop scan control that saves clearly marked partial findings.
- Added per-user scan tokens, 30-minute job expiry, and race-safe stop markers.
- Kept partial findings compatible with review, dry-run, and quarantine actions.

### 0.5.0

- Added immediate attachment and database revalidation before every filesystem action.
- Blocked newly referenced files rather than acting on stale scan results.
- Replaced direct standalone-file unlinking with WordPress `wp_delete_file()` calls.
- Added action lifecycle hooks for integrations and logging.
- Excluded plugin-owned options and saved-result transients from reference matching.

### 0.4.0

- Added a dedicated CLI Reference tab to the WordPress dashboard.
- Listed every Media Audit CLI option with descriptions and safety behavior.
- Added copy-ready audit, reporting, quarantine, backup, deletion, and CI recipes.
- Added clipboard fallback behavior for non-secure admin environments.

### 0.3.0

- Added a verified backup-and-remove workflow to the dashboard and WP-CLI.
- Preserved original paths below unique timestamped backup directories.
- Required size and SHA-256 verification before removing originals.
- Added browser-console scan heartbeats, summaries, errors, and action diagnostics.
- Added WP-CLI phase and database-check progress messages.

### 0.2.0

- Rebuilt the admin screen with an AJAX scan and action workflow.
- Added persistent results, summary cards, search, sorting, and selection feedback.
- Added AJAX dry-run, quarantine, delete, and clear-result operations.
- Unified CLI reporting with the shared audit result engine.
- Added CLI size/age filters, summary-only mode, delete confirmation, and CI failure mode.
- Added candidate byte totals, extension metadata, and scan duration.
- Automatically ignored the quarantine directory.
- Hardened relative-path handling and custom-table queries.
- Removed the unused duplicate admin class.

### 0.1.0

- Initial dashboard and WP-CLI audit implementation.

## License

GPL-3.0-or-later.
