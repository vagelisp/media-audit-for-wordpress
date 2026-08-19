# Media Audit architecture

This document describes the internal boundaries and invariants that should remain true as Media Audit evolves. User-facing installation and command documentation lives in [`README.md`](../README.md).

## Components

| Component | Responsibility |
| --- | --- |
| `media-audit.php` | Defines release constants, loads classes, registers the single public CLI command, and boots the admin controller. |
| `Media_Audit_Admin_Page` | Owns the Tools screen, settings, authorization, AJAX lifecycle, per-user scan/integrity transients, and validation of client-submitted selections. |
| `Media_Audit_CLI_Command` | Implements filesystem inventory, attachment indexing, database reference detection, batched job processing, result formatting, revalidation, and file actions. |
| `assets/admin.js` | Coordinates serial AJAX batches, cancellation, incremental rendering, debounced filtering, client-only sorting, clipboard behavior, and opt-in console diagnostics. |
| `assets/admin.css` | Styles the WordPress-native dashboard application and responsive states. |

The engine class is shared by the dashboard and WP-CLI so both interfaces use the same reference and filesystem rules. Only `media_audit()` is registered as a CLI callable; public job methods are application APIs, not CLI subcommands.

## Scan pipeline

```text
Uploads inventory
      |
      v
Ignore-pattern filtering
      |
      v
Attachment reference index
      |
      v
Unresolved candidate queue
      |
      v
Database checks (configurable 1–100 per AJAX batch)
      |
      +---- reference found ----> counted as database match
      |
      +---- no reference -------> likely-stray finding
```

The initial dashboard request inventories the selected uploads scope, builds the complete attachment reference index, and stores only unresolved candidates. This work is kept outside the database batches because repeating filesystem and attachment passes would make every step expensive and inconsistent.

Database candidates are processed serially. The browser does not send the next `media_audit_step` request until the previous response has returned and its state has been persisted.

## Scan-job state

A running job is stored for 30 minutes in a transient scoped by current user ID and a random token:

```text
media_audit_scan_job_<user-id>_<token>
```

Important job fields include:

| Field | Meaning |
| --- | --- |
| `candidates` | Unattached, non-ignored files remaining after preparation. |
| `cursor` | Index of the next candidate to process. |
| `custom_checks` | Validated database table/column checks captured at start. |
| `checked_db` / `db_matched` | Database progress counters. |
| `stray_rows` | Findings classified so far. |
| `started_at` | Used for elapsed runtime reporting. |
| `completed`, `stopped`, `limited_out` | Terminal-state flags. |

`finalize_audit_job()` removes internal query configuration and the remaining candidate queue before returning findings to the browser or storing display results.

## Cancellation semantics

Stop is cooperative; PHP cannot be interrupted safely in the middle of a database request.

1. The browser sets `stopRequested` and sends `media_audit_stop`.
2. WordPress creates a short-lived stop marker scoped to the job.
3. A batch checks that marker before and after its work, covering a stop request that races with an already-running step.
4. Processed rows are finalized with `stopped: true` and saved as the current user's findings.
5. The UI labels the result partial. Unprocessed candidates are not classified.

The JavaScript `scanSequence` is a client-side generation counter. Finishing or stopping increments it so a late response from an older request cannot overwrite the accepted terminal result or a newer scan.

## Media Library integrity lifecycle

The integrity workflow is deliberately separate from filesystem-only stray findings. It scans attachment posts in ascending ID order using direct read-only queries, then inspects each record with WordPress metadata APIs. Its state is stored for 12 hours per administrator:

```text
media_audit_integrity_<user-id>
```

The state contains an ID cursor, processed/total counters, missing-original rows, missing-generated-size rows, timestamps, and a completion flag. Each AJAX step persists the cumulative state but returns only progress plus the new rows from that batch. This avoids retransmitting an ever-growing array on every request. The initial localized state remains complete so a saved full or partial result can be restored after a page load.

Integrity cancellation is cooperative and client-side: after **Stop check**, the browser does not request another batch. The latest completed batch is already persisted and remains reviewable. Starting a new integrity check replaces that user's previous integrity state.

Missing generated sizes are report-only. Missing-original records are actionable only after their IDs are intersected with the server-owned integrity result. Immediately before mutation, the controller confirms the post is still an attachment and its unfiltered local original is still absent, then calls `wp_delete_attachment($id, true)`. Offload integrations can intentionally remove local files, so the UI treats local absence as a finding rather than proof and presents warnings before cleanup.

## Findings lifecycle

Full and partial display findings are stored for 12 hours:

```text
media_audit_last_findings_<user-id>
```

The manifest stores counters and metadata while `stray_rows` are split into bounded 500-row transients. This avoids common object-cache item-size limits on large scans. Successful action batches update only chunks containing removed paths, and AJAX responses return `removed_paths` instead of retransmitting the full remaining result after every batch.

The browser treats this payload as presentation state. For file actions, submitted paths are intersected with the server-owned saved findings. Arbitrary paths supplied by a client are discarded.

Media Audit's own option and transient prefixes are excluded from option-value reference searches. Saved findings necessarily contain candidate paths; treating those values as usage references would hide candidates on the next scan and block every action during revalidation.

## File-action safety invariants

These rules must remain true:

1. Paths are relative to the WordPress uploads directory.
2. Actual `.`/`..` traversal segments and control-character paths are rejected; consecutive dots inside a filename are valid.
3. Dashboard paths must occur in the current user's saved findings.
4. Attachment metadata and all configured database locations are checked again immediately before any action.
5. A newly referenced file is blocked, even if the earlier scan classified it as stray.
6. Registered attachments are never removed as raw files. Integrity cleanup only accepts saved missing-original IDs, rechecks local absence, and calls `wp_delete_attachment()`.
7. Standalone files are removed with `wp_delete_file()`, including its core filter.
8. Backup-and-remove deletes an original only after destination size and SHA-256 match.
9. Dry-run never creates, moves, or removes a file.

### Action behavior

| Action | Behavior |
| --- | --- |
| Quarantine | Uses `rename()` to move a standalone file into a timestamped safety tree while preserving its relative path. WordPress has no core quarantine API. |
| Restore | Moves a quarantined file back to its preserved relative uploads path and refuses to overwrite an existing destination. |
| Delete quarantine | Resolves selected/all entries from the server-owned quarantine inventory, excludes backup artifacts, and calls `wp_delete_file()`. |
| Download ZIP & remove (dashboard) | Creates one ZIP, reads every entry back to verify size and SHA-256, then calls `wp_delete_file()` and issues a user-scoped download URL. |
| Backup & remove (CLI) | Copies to a unique backup tree, verifies size and SHA-256, then calls `wp_delete_file()` on the original. |
| Delete | Calls `wp_delete_file()` after optional immediate reference revalidation; revalidation defaults on. |
| Delete missing attachment record | Calls `wp_delete_attachment($id, true)` after server-finding intersection and an immediate missing-local-original check. |

Successful dashboard actions update `media_audit_cleanup_stats`. Reclaimed bytes are recorded only for permanent removal; quarantine moves are tracked separately and never presented as freed disk space. Integrity cleanup compares known companion files before and after WordPress deletion so only files actually removed contribute to the counters.

## Extension hooks

### Filter

`media_audit_ignore_patterns`

Receives normalized ignore patterns before inventory candidates are evaluated.

### Actions

| Hook | Fired when |
| --- | --- |
| `media_audit_before_file_action` | Immediately before a real filesystem mutation. |
| `media_audit_file_quarantined` | A quarantine move succeeds. |
| `media_audit_file_restored` | A quarantined file is restored to its original uploads path. |
| `media_audit_quarantined_file_deleted` | A quarantined file is permanently removed. |
| `media_audit_file_backed_up_and_removed` | Backup verification and original removal both succeed. |
| `media_audit_file_deleted` | WordPress successfully removes a standalone file. |

The WordPress `wp_delete_file` filter also applies to backup removal and permanent deletion.

## Known scaling boundaries

- Filesystem inventory and attachment indexing occur in the preparation request and cannot currently be stopped midway.
- Serialized candidate jobs can become large on installations with unusually high numbers of filesystem-only uploads.
- Integrity state can become large when an installation has unusually many broken attachment records; responses are incremental, but restoring the saved result still transfers the complete state once.
- Integrity checks cannot establish whether an offloaded remote object exists; local absence is only a review signal.
- Database reference detection favors conservative coverage over query count; custom-table and all-table scans increase batch duration.
- WP-CLI intentionally remains a foreground process and does not use dashboard transients.

## Change checklist

When changing scan or deletion behavior:

1. Keep dashboard and CLI detection rules aligned.
2. Update the dashboard CLI Reference tab if command options change.
3. Update `README.md`, `readme.txt`, and the version constants together.
4. Preserve stop-marker checks on both sides of batch processing.
5. Never trust browser findings or arbitrary submitted paths.
6. Revalidate references at mutation time.
7. Run PHP syntax checks for every PHP file and `node --check assets/admin.js`.
